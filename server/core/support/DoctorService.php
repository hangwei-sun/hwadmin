<?php
declare(strict_types=1);

namespace core\support;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\App;
use Throwable;

/**
 * 开发态与生产交付态统一诊断服务（php think hw:doctor --mode=dev|prod --json）
 */
class DoctorService
{
    public const SCHEMA_VERSION = '1.0';
    public const MIN_PHP = '8.2.0';

    public function __construct(protected App $app)
    {
    }

    /**
     * 执行 dev / prod 交付体检
     *
     * @return array<string, mixed>
     */
    public function diagnose(string $mode = 'dev', ?string $serverRoot = null): array
    {
        $mode = strtolower(trim($mode)) === 'prod' ? 'prod' : 'dev';
        $root = rtrim($serverRoot ?? $this->app->getRootPath(), '/\\');
        $ds = DIRECTORY_SEPARATOR;

        $envPath = $root . $ds . '.env';
        $hasEnv = is_file($envPath);
        $envVars = $hasEnv ? $this->parseEnvFile($envPath) : [];

        $driver = strtolower((string) ($envVars['DB_DRIVER'] ?? $envVars['DB_TYPE'] ?? env('DB_DRIVER') ?: env('DB_TYPE') ?: 'mysql'));
        $appDebug = $this->parseBool($envVars['APP_DEBUG'] ?? env('APP_DEBUG', false));
        $appEnv = strtolower((string) ($envVars['APP_ENV'] ?? env('APP_ENV', 'production')));
        $dbPrefix = (string) ($envVars['DB_PREFIX'] ?? env('DB_PREFIX', 'hw_'));

        $checks = [];

        // 1. PHP 版本
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = $this->item(
            'php_version',
            'PHP 版本 >= ' . self::MIN_PHP,
            $phpOk ? 'pass' : 'fail',
            true,
            '当前 PHP ' . PHP_VERSION,
            $phpOk ? '' : '请升级 PHP 至 8.2.0 及以上（推荐 8.3 / 8.4 LTS）'
        );

        // 2. PHP 核心与数据库扩展
        $requiredExts = ['pdo', 'mbstring', 'json', 'openssl', 'fileinfo', 'curl', 'ctype'];
        if ($driver === 'sqlite') {
            $requiredExts[] = 'pdo_sqlite';
        } elseif ($driver === 'pgsql') {
            $requiredExts[] = 'pdo_pgsql';
        } else {
            $requiredExts[] = 'pdo_mysql';
        }
        $missingExts = array_values(array_filter($requiredExts, static fn (string $ext): bool => !extension_loaded($ext)));
        $checks[] = $this->item(
            'php_extensions',
            'PHP 核心与驱动扩展',
            empty($missingExts) ? 'pass' : 'fail',
            true,
            empty($missingExts)
                ? '已满足所需扩展 (' . implode(', ', $requiredExts) . ')'
                : '缺少必需扩展: ' . implode(', ', $missingExts),
            empty($missingExts) ? '' : '请在 php.ini 中启用缺失扩展：' . implode(', ', $missingExts)
        );

        // 3. .env 环境文件检查
        if ($mode === 'prod') {
            $checks[] = $this->item(
                'env_file',
                '.env 生产配置',
                $hasEnv ? 'pass' : 'fail',
                true,
                $hasEnv ? "已检测到 .env（驱动: {$driver}）" : '缺少 .env 配置文件',
                $hasEnv ? '' : '请执行 php think hw:install 或 cp .example.env .env 并配置生产数据库连接'
            );
        } else {
            $checks[] = $this->item(
                'env_file',
                '.env 开发配置',
                $hasEnv ? 'pass' : 'warn',
                false,
                $hasEnv ? "已检测到 .env（驱动: {$driver}）" : '尚未生成 .env（将回退默认配置）',
                $hasEnv ? '' : '可执行 bash scripts/wsl-dev.sh install-sqlite 一键生成开发配置'
            );
        }

        // 4. 生产模式专属检查：SQLite / APP_DEBUG / APP_ENV / install.lock
        if ($mode === 'prod') {
            $isSqlite = $driver === 'sqlite';
            $checks[] = $this->item(
                'prod_database',
                '生产数据库选型',
                $isSqlite ? 'fail' : 'pass',
                true,
                $isSqlite
                    ? '当前 .env 仍使用 SQLite（仅适合本地开发与低并发预览）'
                    : "当前使用生产数据库驱动: {$driver}",
                $isSqlite ? '上线前请切换 DB_DRIVER=mysql 或 DB_DRIVER=pgsql 并执行 php think migrate:run' : ''
            );

            $checks[] = $this->item(
                'app_debug',
                '关闭 APP_DEBUG 调试模式',
                $appDebug ? 'fail' : 'pass',
                true,
                $appDebug ? '当前 APP_DEBUG=true，生产环境会暴露调试堆栈' : 'APP_DEBUG=false',
                $appDebug ? '请在 .env 中设置 APP_DEBUG = false' : ''
            );

            $isLocalEnv = in_array($appEnv, ['local', 'testing', 'dev', 'development'], true);
            $checks[] = $this->item(
                'app_env',
                '应用运行环境 APP_ENV',
                $isLocalEnv ? 'warn' : 'pass',
                false,
                "当前 APP_ENV={$appEnv}",
                $isLocalEnv ? '请在 .env 中将 APP_ENV 设置为 production（避免开启开发便利接口）' : ''
            );

            $hasLock = is_file($root . $ds . 'config' . $ds . 'install.lock');
            $hasToken = is_file($root . $ds . 'config' . $ds . 'install.token');
            $checks[] = $this->item(
                'install_lock',
                '系统安装锁 config/install.lock',
                $hasLock ? ($hasToken ? 'warn' : 'pass') : 'fail',
                true,
                $hasLock
                    ? ($hasToken ? 'install.lock 已就绪，但仍残留 config/install.token' : 'config/install.lock 已锁定')
                    : '缺少 config/install.lock，系统未完成安装或安装锁丢失',
                !$hasLock
                    ? '请在目标库迁移后运行 php think hw:install 完成初始化'
                    : ($hasToken ? '请删除残留的 config/install.token 文件' : '')
            );
        } elseif ($driver === 'sqlite') {
            $dbName = (string) ($envVars['DB_NAME'] ?? 'runtime/hwadmin.sqlite');
            $inRuntime = str_starts_with(str_replace('\\', '/', ltrim($dbName, './')), 'runtime/')
                || str_contains(str_replace('\\', '/', $dbName), '/runtime/');
            $checks[] = $this->item(
                'sqlite_path',
                'SQLite 存储路径规范',
                $inRuntime ? 'pass' : 'warn',
                false,
                "当前 DB_NAME={$dbName}",
                $inRuntime ? '' : '建议将 DB_NAME 统一放在 runtime/hwadmin.sqlite 以便 backup_restore.py 正常备份恢复'
            );
        }

        // 5. app_express 路由设置检查
        $appConfigFile = $root . $ds . 'config' . $ds . 'app.php';
        $appExpress = false;
        if (is_file($appConfigFile)) {
            $appCfg = @include $appConfigFile;
            if (is_array($appCfg)) {
                $appExpress = !empty($appCfg['app_express']);
            }
        }
        $checks[] = $this->item(
            'app_express',
            '单段前台路由 app_express',
            $appExpress ? 'pass' : 'warn',
            false,
            $appExpress ? 'config/app.php 已开启 app_express => true' : 'config/app.php 未开启 app_express',
            $appExpress ? '' : "请在 config/app.php 中设置 'app_express' => true，避免 /guestbook 等单段前台路径返回 404"
        );

        // 6. 目录可写权限
        $checkDirs = $mode === 'prod'
            ? ['runtime', 'public/uploads', 'public/plugins']
            : ['runtime', 'config', 'public/uploads', 'public/plugins'];
        $unwritable = [];
        foreach ($checkDirs as $dir) {
            $dirPath = $root . $ds . str_replace('/', $ds, $dir);
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0755, true);
            }
            if (!is_dir($dirPath) || !is_writable($dirPath)) {
                $unwritable[] = $dir;
            }
        }
        $checks[] = $this->item(
            'writable_dirs',
            '关键运行时目录可写权限',
            empty($unwritable) ? 'pass' : 'fail',
            true,
            empty($unwritable)
                ? '目录全部可写 (' . implode(', ', $checkDirs) . ')'
                : '以下目录不可写: ' . implode(', ', $unwritable),
            empty($unwritable) ? '' : '请赋权目录可写：chmod -R 755 ' . implode(' ', $unwritable)
        );

        // 7. 数据库待执行迁移与后台安全入口检查
        $pdo = $hasEnv ? $this->tryConnectPdo($root, $envVars) : null;
        $migrationStatus = $this->checkPendingMigrations($root, $pdo, $dbPrefix);
        $checks[] = $this->item(
            'pending_migrations',
            '数据库迁移同步状态',
            $migrationStatus['status'],
            $mode === 'prod',
            $migrationStatus['message'],
            $migrationStatus['hint']
        );

        if ($mode === 'prod' && $pdo instanceof PDO) {
            $adminEntry = $this->queryAdminEntry($pdo, $dbPrefix);
            if ($adminEntry !== null) {
                $isDefault = $adminEntry === 'admin';
                $checks[] = $this->item(
                    'admin_entry',
                    '后台安全访问入口',
                    $isDefault ? 'warn' : 'pass',
                    false,
                    $isDefault ? '当前管理入口仍为默认 /admin' : "已配置独立安全入口 /{$adminEntry}",
                    $isDefault ? '建议上线前在「系统配置 -> 安全入口」修改默认 /admin 路径' : ''
                );
            }
        }

        // 8. 前端源码与 public/admin 构建产物同步状态
        $frontendSrcDir = $this->detectFrontendSrcDir($root);
        $publicAdminIndex = $root . $ds . 'public' . $ds . 'admin' . $ds . 'index.html';
        if (!is_file($publicAdminIndex)) {
            $checks[] = $this->item(
                'frontend_sync',
                '管理端前端构建产物 public/admin',
                $mode === 'prod' ? 'fail' : 'warn',
                $mode === 'prod',
                '未找到 public/admin/index.html',
                '请在 admin-ui 目录执行 pnpm check && pnpm build:sync'
            );
        } elseif ($frontendSrcDir !== null) {
            $srcMtime = $this->latestMtime($frontendSrcDir);
            $distMtime = (int) @filemtime($publicAdminIndex);
            $stale = $srcMtime > ($distMtime + 2);
            $checks[] = $this->item(
                'frontend_sync',
                '前端源码与 public/admin 构建同步',
                $stale ? 'warn' : 'pass',
                false,
                $stale
                    ? '检测到前端 src/ 文件修改时间晚于 public/admin/index.html，构建产物可能已过期'
                    : 'public/admin/index.html 已与前端源码保持同步',
                $stale ? '请执行 pnpm --dir admin-ui check && pnpm --dir admin-ui build:sync 更新构建产物' : ''
            );
        } else {
            $checks[] = $this->item(
                'frontend_sync',
                '管理端前端构建产物 public/admin',
                'pass',
                false,
                'public/admin/index.html 已就绪',
                ''
            );
        }

        // 9. 开发态专属：Node / pnpm 工具链检测
        if ($mode === 'dev') {
            $hasNode = $this->commandExists('node');
            $hasPnpm = $this->commandExists('pnpm');
            $toolOk = $hasNode && $hasPnpm;
            $checks[] = $this->item(
                'node_pnpm',
                '前端构建工具链 (Node / pnpm)',
                $toolOk ? 'pass' : 'warn',
                false,
                sprintf('node=%s, pnpm=%s', $hasNode ? '可用' : '缺失', $hasPnpm ? '可用' : '缺失'),
                $toolOk ? '' : '开发前端界面需安装 Node.js >= 20 与 pnpm'
            );
        }

        $summary = ['total' => count($checks), 'pass' => 0, 'warn' => 0, 'fail' => 0];
        $checklist = [];
        foreach ($checks as $c) {
            $summary[$c['status']]++;
            if ($c['status'] !== 'pass' && $c['hint'] !== '') {
                $checklist[] = "[{$c['status']}] {$c['name']}: {$c['hint']}";
            }
        }

        if ($mode === 'prod') {
            $checklist[] = '[确认] Nginx/Apache 已配置 ThinkPHP 伪静态与禁止访问 .env/.sqlite/.git';
            $checklist[] = '[确认] 已通过 python3 scripts/backup_restore.py backup 建立首次生产快照';
        }

        $overallStatus = $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'pass');

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'mode'            => $mode,
            'status'          => $overallStatus,
            'summary'         => $summary,
            'checks'          => $checks,
            'deploy_checklist'=> $checklist,
        ];
    }

    private function item(string $id, string $name, string $status, bool $required, string $message, string $hint): array
    {
        return [
            'id'       => $id,
            'name'     => $name,
            'status'   => $status,
            'required' => $required,
            'message'  => $message,
            'hint'     => $hint,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(string $path): array
    {
        $vars = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $vars;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $k = strtoupper(trim(substr($line, 0, $pos)));
            $v = trim(substr($line, $pos + 1));
            $v = trim($v, "\"'");
            $vars[$k] = $v;
        }
        return $vars;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function tryConnectPdo(string $root, array $env): ?PDO
    {
        $driver = strtolower((string) ($env['DB_DRIVER'] ?? $env['DB_TYPE'] ?? ''));
        try {
            if ($driver === 'sqlite') {
                $name = str_replace('\\', '/', trim((string) ($env['DB_NAME'] ?? 'runtime/hwadmin.sqlite')));
                if ($name === '') {
                    $name = 'runtime/hwadmin.sqlite';
                }
                $isAbs = str_starts_with($name, '/') || (bool) preg_match('/^[a-zA-Z]:/', $name);
                $dbPath = $isAbs ? $name : ($root . DIRECTORY_SEPARATOR . ltrim($name, '/'));
                if (!is_file($dbPath)) {
                    return null;
                }
                return new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }

            if (in_array($driver, ['mysql', 'pgsql'], true)) {
                $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');
                $port = (string) ($env['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
                $name = (string) ($env['DB_NAME'] ?? '');
                $user = (string) ($env['DB_USER'] ?? '');
                $pass = (string) ($env['DB_PASS'] ?? '');
                if ($name === '') {
                    return null;
                }
                $dsn = $driver === 'pgsql'
                    ? "pgsql:host={$host};port={$port};dbname={$name}"
                    : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /**
     * @return array{status: string, message: string, hint: string}
     */
    private function checkPendingMigrations(string $root, ?PDO $pdo, string $prefix): array
    {
        $migDir = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migDir)) {
            return ['status' => 'pass', 'message' => '无迁移目录', 'hint' => ''];
        }
        $files = glob($migDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $fileVersions = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^(\d+)_/', $base, $m)) {
                $fileVersions[$m[1]] = $base;
            }
        }

        if (!$pdo instanceof PDO) {
            return [
                'status'  => 'warn',
                'message' => '无法连接数据库以校验待执行迁移（共 ' . count($fileVersions) . ' 个迁移脚本）',
                'hint'    => '请确认数据库已启动且 .env 配置正确后执行 php think migrate:run',
            ];
        }

        $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        $candidateTables = array_values(array_unique([
            $safePrefix . 'migrations',
            'migrations',
            $safePrefix . 'phinxlog',
            'phinxlog',
        ]));

        $applied = null;
        foreach ($candidateTables as $table) {
            try {
                $stmt = $pdo->query("SELECT version FROM {$table}");
                if ($stmt !== false) {
                    $applied = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    break;
                }
            } catch (Throwable) {
            }
        }

        if ($applied === null) {
            return [
                'status'  => 'warn',
                'message' => '尚无迁移记录表 (migrations/phinxlog)，数据库可能尚未执行迁移',
                'hint'    => '请执行 php think migrate:run',
            ];
        }

        $pending = [];
        foreach ($fileVersions as $ver => $fileName) {
            if (!in_array((string) $ver, $applied, true)) {
                $pending[] = $fileName;
            }
        }

        if (!empty($pending)) {
            return [
                'status'  => 'fail',
                'message' => '检测到 ' . count($pending) . ' 个未执行迁移: ' . implode(', ', array_slice($pending, 0, 5)),
                'hint'    => '请先执行 php think migrate:run 完成数据库结构同步',
            ];
        }

        return [
            'status'  => 'pass',
            'message' => '全部 ' . count($fileVersions) . ' 个数据库迁移已执行同步',
            'hint'    => '',
        ];
    }

    private function queryAdminEntry(PDO $pdo, string $prefix): ?string
    {
        try {
            $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
            $stmt = $pdo->query("SELECT value FROM {$safePrefix}config WHERE name = 'admin_entry' LIMIT 1");
            if ($stmt && ($val = $stmt->fetchColumn()) !== false) {
                return (string) $val;
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function detectFrontendSrcDir(string $serverRoot): ?string
    {
        $candidates = [
            dirname($serverRoot) . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'src',
            $serverRoot . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'src',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    private function latestMtime(string $dir): int
    {
        $latest = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $mt = (int) $file->getMTime();
                    if ($mt > $latest) {
                        $latest = $mt;
                    }
                }
            }
        } catch (Throwable) {
        }
        return $latest;
    }

    private function commandExists(string $cmd): bool
    {
        $escaped = escapeshellarg($cmd);
        $checkCmd = DIRECTORY_SEPARATOR === '\\' ? "where {$escaped} 2>NUL" : "command -v {$escaped} 2>/dev/null";
        $out = @shell_exec($checkCmd);
        return is_string($out) && trim($out) !== '';
    }
}
