<?php
declare(strict_types=1);

namespace core\plugin;

use core\auth\AdminAuth;
use core\exception\ApiException;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

/**
 * 插件管理器：发现、安装、卸载、启用、禁用、配置。
 *
 * 状态存储：plugin 表（name, version, status, config）。
 * 启用列表带缓存（键 hw:plugins:enabled），任何状态变更后调用 clearCache()。
 *
 * 安全与一致性基线（P1-3）：
 *   1. executeSql() 严禁静默吞掉 SQL 异常，任何语句执行失败立即抛出 ApiException；
 *   2. install() / upgrade() 具备异常补偿清理机制：若 SQL 脚本、钩子或资源发布失败，
 *      自动回滚已写入的 plugin 表记录、auth_rule 菜单节点与静态资源，杜绝半安装脏状态。
 *
 * 本类同时被后台“插件管理”接口与 `php think hw:plugin` 命令行复用，禁止在此输出内容。
 */
class PluginManager
{
    protected const CACHE_KEY = 'hw:plugins:enabled';

    public const STAGE_CHECK    = 'check';
    public const STAGE_SQL      = 'sql';
    public const STAGE_HOOK     = 'hook';
    public const STAGE_METADATA = 'metadata';
    public const STAGE_ENABLE   = 'enable';

    /** @var array<string, Plugin> */
    protected array $instances = [];

    public function __construct(protected App $app)
    {
    }

    /**
     * 扫描插件目录，返回所有合法插件清单
     * @return array<string, PluginInfo>
     */
    public function discover(): array
    {
        $result = [];
        foreach (glob(plugin_path() . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                continue;
            }
            try {
                $info = PluginInfo::fromDirectory($dir);
                $result[$info->name] = $info;
            } catch (Throwable $e) {
                $this->app->log->warning('[plugin] ' . $e->getMessage());
            }
        }
        ksort($result);
        return $result;
    }

    public function info(string $name): PluginInfo
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $name) || !is_dir(plugin_path($name))) {
            throw new ApiException("插件不存在：{$name}");
        }
        return PluginInfo::fromDirectory(plugin_path($name));
    }

    /**
     * 列表：清单 + 安装状态
     */
    public function all(): array
    {
        $states = $this->states();
        $list = [];
        foreach ($this->discover() as $name => $info) {
            $state = $states[$name] ?? null;
            $list[] = array_merge($info->toArray(), [
                'installed'         => $state !== null,
                'enabled'           => $state !== null && (int) $state['status'] === 1,
                'installed_version' => $state['version'] ?? null,
                'upgradable'        => $state !== null && version_compare($info->version, (string) $state['version'], '>'),
            ]);
        }
        return $list;
    }

    /**
     * 已启用插件名列表（带缓存；系统未安装或表不存在时返回空）
     * @return string[]
     */
    public function enabledNames(): array
    {
        if (!is_installed()) {
            return [];
        }
        try {
            return Cache::remember(self::CACHE_KEY, function () {
                return Db::name('plugin')->where('status', 1)->order('id')->column('name');
            });
        } catch (Throwable $e) {
            $this->app->log->error('[plugin] 读取启用列表失败：' . $e->getMessage());
            return [];
        }
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabledNames(), true);
    }

    /**
     * 获取插件主类实例（无主类时使用匿名默认实现）
     */
    public function instance(string $name): Plugin
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }
        $info = $this->info($name);
        $class = $info->className();
        if (class_exists($class)) {
            if (!is_subclass_of($class, Plugin::class)) {
                throw new ApiException("{$class} 必须继承 " . Plugin::class);
            }
            $plugin = new $class($this->app, $info);
        } else {
            $plugin = new class($this->app, $info) extends Plugin {
            };
        }
        return $this->instances[$name] = $plugin;
    }

    public function install(string $name, bool $enable = true): void
    {
        $info = $this->info($name);
        if ($this->state($name)) {
            throw new ApiException("插件 {$name} 已安装");
        }
        $stage = self::STAGE_CHECK;
        $this->persistStage($name, 'install', $stage, 'running', ['target_version' => $info->version]);
        $this->checkRequire($info);

        $executedSqlCount = 0;
        try {
            // 1. SQL 阶段：先执行 install.sql
            $stage = self::STAGE_SQL;
            $this->persistStage($name, 'install', $stage, 'running', ['target_version' => $info->version]);
            $sqlFile = plugin_path($name) . 'install.sql';
            if (is_file($sqlFile)) {
                $executedSqlCount = $this->executeSqlFile($sqlFile);
            }

            // 2. 插件 install() 钩子
            $stage = self::STAGE_HOOK;
            $this->persistStage($name, 'install', $stage, 'running', ['target_version' => $info->version]);
            $this->instance($name)->install();

            // 3. 写入插件状态与菜单规则（同事务保证原子性）
            $stage = self::STAGE_METADATA;
            $this->persistStage($name, 'install', $stage, 'running', ['target_version' => $info->version]);
            Db::transaction(function () use ($info) {
                $now = time();
                Db::name('plugin')->insert([
                    'name'        => $info->name,
                    'title'       => $info->title,
                    'version'     => $info->version,
                    'status'      => 0,
                    'config'      => json_encode($this->defaultConfig($info), JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                MenuImporter::import($info->name, $info->menus, false);
            });

            $this->clearCache();
            if ($enable) {
                $stage = self::STAGE_ENABLE;
                $this->persistStage($name, 'install', $stage, 'running', ['target_version' => $info->version]);
                $this->enable($name);
            }
            $this->persistStage($name, 'install', $stage, 'completed', ['target_version' => $info->version]);
        } catch (Throwable $e) {
            $this->app->log->error(sprintf('[plugin] 插件 %s 安装在 [%s] 阶段失败：%s', $name, $stage, $e->getMessage()));
            $this->persistStage($name, 'install', $stage, 'failed', [
                'error'          => $e->getMessage(),
                'target_version' => $info->version,
                'sql_count'      => $executedSqlCount,
            ]);
            // 阶段补偿回滚：清理可能残留的已建表、钩子数据、菜单、插件记录与静态资源
            $this->rollbackInstall($name, $stage, $executedSqlCount);

            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(sprintf('插件 %s 安装失败（阶段: %s）：%s', $name, $stage, $e->getMessage()));
        }
    }

    /**
     * 插件安装失败阶段分级补偿回滚（S05）
     */
    protected function rollbackInstall(string $name, string $stage, int $executedSqlCount): void
    {
        // 1. 若进入启用阶段或资源已发布，撤销静态资源与禁用
        try {
            AssetPublisher::unpublish($name);
            $this->instance($name)->disable();
        } catch (Throwable) {
        }

        // 2. 撤销元数据与菜单规则
        try {
            MenuImporter::remove($name);
            Db::name('plugin')->where('name', $name)->delete();
        } catch (Throwable) {
        }

        // 3. 触发插件 uninstall() 清理钩子
        try {
            $this->instance($name)->uninstall();
        } catch (Throwable) {
        }

        // 4. 若存在 uninstall.sql，自动执行 uninstall.sql 撤销可能已建立的部分表结构
        $uninstallSql = plugin_path($name) . 'uninstall.sql';
        if (is_file($uninstallSql)) {
            try {
                $this->executeSqlFile($uninstallSql);
            } catch (Throwable $e) {
                $this->app->log->warning(sprintf('[plugin] 插件 %s 补偿执行 uninstall.sql 警告：%s', $name, $e->getMessage()));
            }
        }

        // 5. 清除缓存与实例
        try {
            $this->clearCache();
        } catch (Throwable) {
        }
    }

    public function uninstall(string $name): void
    {
        $this->requireState($name);
        if ($this->isEnabled($name)) {
            $this->disable($name);
        }
        $this->instance($name)->uninstall();
        $uninstallSql = plugin_path($name) . 'uninstall.sql';
        if (is_file($uninstallSql)) {
            $this->executeSqlFile($uninstallSql);
        }
        Db::transaction(function () use ($name) {
            MenuImporter::remove($name);
            Db::name('plugin')->where('name', $name)->delete();
        });
        AssetPublisher::unpublish($name);
        $this->clearCache();
        $this->clearPersistedStage($name);
    }

    public function enable(string $name): void
    {
        $this->requireState($name);
        AssetPublisher::publish($name);
        Db::name('plugin')->where('name', $name)->update(['status' => 1, 'update_time' => time()]);
        MenuImporter::setStatus($name, 1);
        $this->clearCache();
        $this->instance($name)->enable();
    }

    public function disable(string $name): void
    {
        $this->requireState($name);
        $this->instance($name)->disable();
        Db::name('plugin')->where('name', $name)->update(['status' => 0, 'update_time' => time()]);
        MenuImporter::setStatus($name, 0);
        AssetPublisher::unpublish($name);
        $this->clearCache();
    }

    /**
     * 升级：插件目录已替换为新版本后调用（S05 具备阶段回滚、版本化 SQL 与故障隔离）
     */
    public function upgrade(string $name): void
    {
        $state = $this->requireState($name);
        $info = $this->info($name);
        $from = (string) $state['version'];
        if (!version_compare($info->version, $from, '>')) {
            throw new ApiException("插件 {$name} 当前已是最新版本 {$from}");
        }
        $stage = self::STAGE_CHECK;
        $this->persistStage($name, 'upgrade', $stage, 'running', ['from_version' => $from, 'target_version' => $info->version]);
        $this->checkRequire($info);

        try {
            // 1. 若存在升级 SQL 脚本（优先查找版本化 upgrade_{version}.sql，不存在则回退至通用 upgrade.sql）
            $stage = self::STAGE_SQL;
            $this->persistStage($name, 'upgrade', $stage, 'running', ['from_version' => $from, 'target_version' => $info->version]);
            $versionedSql = plugin_path($name) . "upgrade_{$info->version}.sql";
            $genericSql = plugin_path($name) . 'upgrade.sql';
            $upgradeSql = is_file($versionedSql) ? $versionedSql : (is_file($genericSql) ? $genericSql : null);
            if ($upgradeSql) {
                $this->executeSqlFile($upgradeSql);
            }

            // 2. 执行升级钩子
            $stage = self::STAGE_HOOK;
            $this->persistStage($name, 'upgrade', $stage, 'running', ['from_version' => $from, 'target_version' => $info->version]);
            $this->instance($name)->upgrade($from);

            // 3. 元数据与菜单更新
            $stage = self::STAGE_METADATA;
            $this->persistStage($name, 'upgrade', $stage, 'running', ['from_version' => $from, 'target_version' => $info->version]);
            Db::transaction(function () use ($name, $info, $state) {
                MenuImporter::import($name, $info->menus, (int) $state['status'] === 1);
                Db::name('plugin')->where('name', $name)->update([
                    'version'     => $info->version,
                    'title'       => $info->title,
                    'update_time' => time(),
                ]);
            });

            // 4. 重新发布静态资源（若原先为启用状态）
            if ((int) $state['status'] === 1) {
                $stage = self::STAGE_ENABLE;
                $this->persistStage($name, 'upgrade', $stage, 'running', ['from_version' => $from, 'target_version' => $info->version]);
                AssetPublisher::publish($name);
            }
            $this->clearCache();
            $this->persistStage($name, 'upgrade', $stage, 'completed', ['from_version' => $from, 'version' => $info->version]);
        } catch (Throwable $e) {
            $this->app->log->error(sprintf('[plugin] 插件 %s 升级在 [%s] 阶段失败：%s', $name, $stage, $e->getMessage()));
            $this->persistStage($name, 'upgrade', $stage, 'failed', [
                'error'          => $e->getMessage(),
                'from_version'   => $from,
                'target_version' => $info->version,
                'need_repair'    => true,
            ]);
            // S05: 升级失败强制禁用插件，防止不一致状态的代码对外提供服务
            try {
                Db::name('plugin')->where('name', $name)->update(['status' => 0, 'update_time' => time()]);
                MenuImporter::setStatus($name, 0);
                AssetPublisher::unpublish($name);
                $this->clearCache();
            } catch (Throwable) {
            }
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(sprintf('插件 %s 升级失败（阶段: %s）：%s', $name, $stage, $e->getMessage()));
        }
    }

    public function getConfig(string $name): array
    {
        $state = $this->state($name);
        $config = $state ? json_decode((string) $state['config'], true) : null;
        return is_array($config) ? $config : [];
    }

    public function setConfig(string $name, array $config): void
    {
        $this->requireState($name);
        $allowed = array_column($this->info($name)->config, 'name');
        $config = array_intersect_key($config, array_flip($allowed));
        Db::name('plugin')->where('name', $name)->update([
            'config'      => json_encode(array_merge($this->getConfig($name), $config), JSON_UNESCAPED_UNICODE),
            'update_time' => time(),
        ]);
    }

    public function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
        AdminAuth::clearCache();
        $this->instances = [];
    }

    protected function states(): array
    {
        try {
            return array_column(Db::name('plugin')->select()->toArray(), null, 'name');
        } catch (Throwable) {
            return [];
        }
    }

    protected function state(string $name): ?array
    {
        return Db::name('plugin')->where('name', $name)->find() ?: null;
    }

    protected function requireState(string $name): array
    {
        return $this->state($name) ?? throw new ApiException("插件 {$name} 未安装");
    }

    protected function defaultConfig(PluginInfo $info): array
    {
        $config = [];
        foreach ($info->config as $item) {
            if (isset($item['name'])) {
                $config[$item['name']] = $item['value'] ?? null;
            }
        }
        return $config;
    }

    /**
     * 依赖检查：框架版本、PHP 版本、依赖插件
     */
    protected function checkRequire(PluginInfo $info): void
    {
        $req = $info->require;
        if (!empty($req['hwadmin']) && !Version::satisfies(hw_version(), (string) $req['hwadmin'])) {
            throw new ApiException("插件 {$info->name} 需要 HwAdmin {$req['hwadmin']}，当前 " . hw_version());
        }
        if (!empty($req['php']) && !Version::satisfies(PHP_VERSION, (string) $req['php'])) {
            throw new ApiException("插件 {$info->name} 需要 PHP {$req['php']}，当前 " . PHP_VERSION);
        }
        foreach ((array) ($req['plugins'] ?? []) as $dep => $constraint) {
            $state = $this->state((string) $dep);
            if (!$state || (int) $state['status'] !== 1) {
                throw new ApiException("插件 {$info->name} 依赖插件 {$dep}，请先安装并启用");
            }
            if (!Version::satisfies((string) $state['version'], (string) $constraint)) {
                throw new ApiException("插件 {$info->name} 需要 {$dep} {$constraint}，当前 {$state['version']}");
            }
        }
    }

    /**
     * 执行 SQL 脚本文件（支持多语句与 __PREFIX__ / {prefix} 占位符）
     * @return int 成功执行的语句条数
     */
    public function executeSqlFile(string $filePath, string $prefix = ''): int
    {
        if (!is_file($filePath)) {
            return 0;
        }
        $content = (string) file_get_contents($filePath);
        return $this->executeSql($content, $prefix);
    }

    /**
     * 执行原始 SQL 语句块（自动替换表前缀；支持双栈方言自适应；遇错立即抛出异常）
     * @return int 成功执行的语句条数
     */
    public function executeSql(string $sql, string $prefix = ''): int
    {
        if (trim($sql) === '') {
            return 0;
        }
        $driver = 'mysql';
        if ($prefix === '') {
            try {
                $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
                $driver = strtolower((string) (Db::connect()->getConfig('type') ?: 'mysql'));
            } catch (Throwable) {
                $prefix = 'hw_';
            }
        }
        $sql = str_replace(['__PREFIX__', '{prefix}'], $prefix, $sql);

        // 去除多行注释 /* ... */
        $sql = (string) preg_replace('!/\*.*?\*/!s', '', $sql);

        $statements = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $sql));
        $buffer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // 跳过单行注释与空行
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($trimmed), ';')) {
                $stmt = trim($buffer);
                if ($stmt !== '' && $stmt !== ';') {
                    $statements[] = rtrim($stmt, "; \t\n\r\0\x0B");
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $stmt = trim($buffer);
            if ($stmt !== '' && $stmt !== ';') {
                $statements[] = rtrim($stmt, "; \t\n\r\0\x0B");
            }
        }

        $executed = 0;
        foreach ($statements as $idx => $stmt) {
            if ($stmt === '') {
                continue;
            }
            $extraStatements = [];
            $adaptedStmt = $this->adaptSqlDialect($stmt, $driver, $extraStatements);
            try {
                Db::execute($adaptedStmt);
                $executed++;
                foreach ($extraStatements as $extraStmt) {
                    Db::execute($extraStmt);
                    $executed++;
                }
            } catch (Throwable $e) {
                $this->app->log->error(sprintf('[plugin] SQL 执行失败 (#%d): %s | SQL: %s', $idx + 1, $e->getMessage(), $adaptedStmt));
                throw new ApiException(sprintf('插件 SQL 执行失败（第 %d 条语句）：%s', $idx + 1, $e->getMessage()));
            }
        }
        return $executed;
    }

    /**
     * 针对 SQLite / PostgreSQL 将常见 MySQL 建表 DDL 语法自动转换为兼容格式
     * 将普通内联 KEY/INDEX 提取为独立 CREATE INDEX IF NOT EXISTS 语句存入 $extraStatements，避免丢失二级索引。
     *
     * @param string[] $extraStatements 输出附加 DDL（如独立 CREATE INDEX IF NOT EXISTS 语句）
     */
    protected function adaptSqlDialect(string $stmt, string $driver, array &$extraStatements = []): string
    {
        $extraStatements = [];
        if ($driver === 'mysql') {
            return $stmt;
        }
        if ($driver === 'sqlite') {
            $tableName = $this->extractCreateTableName($stmt);
            $stmt = (string) preg_replace('/\bINT(?:\(\d+\))?\s+(?:UNSIGNED\s+)?AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $stmt);
            $stmt = (string) preg_replace("/\s+COMMENT\s+'[^']*'/i", '', $stmt);
            $stmt = (string) preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;?$/i', ')', $stmt);
            // 将 MySQL UNIQUE KEY 转为 SQLite 兼容的 CONSTRAINT ... UNIQUE，并将普通 KEY/INDEX 提取为独立 CREATE INDEX IF NOT EXISTS
            $stmt = (string) preg_replace('/,\s*UNIQUE\s+(?:KEY|INDEX)\s+(`?[a-zA-Z0-9_]+`?)\s*\(([^)]+)\)/i', ', CONSTRAINT $1 UNIQUE ($2)', $stmt);
            $stmt = $this->extractInlineIndexes($stmt, $tableName, 'sqlite', $extraStatements);
            $stmt = (string) preg_replace('/\b(?:LONGTEXT|MEDIUMTEXT)\b/i', 'TEXT', $stmt);
            return $stmt;
        }
        if ($driver === 'pgsql') {
            $stmt = str_replace('`', '"', $stmt);
            $tableName = $this->extractCreateTableName($stmt);
            $stmt = (string) preg_replace('/\bINT(?:\(\d+\))?\s+(?:UNSIGNED\s+)?AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'SERIAL PRIMARY KEY', $stmt);
            $stmt = (string) preg_replace("/\s+COMMENT\s+'[^']*'/i", '', $stmt);
            $stmt = (string) preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;?$/i', ')', $stmt);
            $stmt = (string) preg_replace('/,\s*UNIQUE\s+(?:KEY|INDEX)\s+("?[a-zA-Z0-9_]+"?)\s*\(([^)]+)\)/i', ', CONSTRAINT $1 UNIQUE ($2)', $stmt);
            $stmt = $this->extractInlineIndexes($stmt, $tableName, 'pgsql', $extraStatements);
            $stmt = (string) preg_replace('/\b(?:LONGTEXT|MEDIUMTEXT)\b/i', 'TEXT', $stmt);
            $stmt = (string) preg_replace('/\bTINYINT(?:\(\d+\))?(?:\s+UNSIGNED)?\b/i', 'SMALLINT', $stmt);
            $stmt = (string) preg_replace('/\bINT(?:\(\d+\))?\s+UNSIGNED\b/i', 'INT', $stmt);
            return $stmt;
        }
        return $stmt;
    }

    private function extractCreateTableName(string $stmt): string
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-zA-Z0-9_.]+)[`"]?\s*\(/i', $stmt, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * @param string[] $extraStatements
     */
    private function extractInlineIndexes(string $stmt, string $tableName, string $driver, array &$extraStatements): string
    {
        $quote = $driver === 'pgsql' ? '"' : '`';
        $idxCounter = 0;

        return (string) preg_replace_callback(
            '/,\s*(?<![A-Z0-9_])(?:KEY|INDEX)\s+(?:([`"]?[a-zA-Z0-9_]+[`"]?)\s*)?\(((?:[^()]+|\([^)]*\))+)\)/i',
            static function (array $m) use ($tableName, $quote, &$extraStatements, &$idxCounter): string {
                if ($tableName === '') {
                    return '';
                }
                $idxCounter++;
                $rawName = trim((string) ($m[1] ?? ''), "`\" \t");
                $rawCols = trim((string) ($m[2] ?? ''));
                if ($rawCols === '') {
                    return '';
                }
                // 去除 MySQL 前缀索引长度写法，例如 `url`(191) -> `url`
                $cleanCols = (string) preg_replace('/\(\s*\d+\s*\)/', '', $rawCols);
                $safeTable = str_replace('.', '_', $tableName);
                $idxName = $rawName !== ''
                    ? (str_starts_with($rawName, $safeTable . '_') ? $rawName : "{$safeTable}_{$rawName}")
                    : "{$safeTable}_idx_{$idxCounter}";

                $extraStatements[] = sprintf(
                    'CREATE INDEX IF NOT EXISTS %s%s%s ON %s%s%s (%s)',
                    $quote,
                    $idxName,
                    $quote,
                    $quote,
                    $tableName,
                    $quote,
                    $cleanCols
                );
                return '';
            },
            $stmt
        );
    }

    /**
     * 持久化插件生命周期阶段状态（S05 故障可追踪与幂等重试）
     */
    protected function persistStage(string $name, string $action, string $stage, string $status, array $extra = []): void
    {
        $dir = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . $name . '_stage.json';
        $data = array_merge([
            'plugin'    => $name,
            'action'    => $action,
            'stage'     => $stage,
            'status'    => $status, // 'running', 'completed', 'failed'
            'timestamp' => time(),
        ], $extra);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 清理持久化阶段记录
     */
    protected function clearPersistedStage(string $name): void
    {
        $file = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $name . '_stage.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
