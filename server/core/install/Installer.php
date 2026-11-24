<?php
declare(strict_types=1);

namespace core\install;

use core\auth\PasswordHasher;
use core\exception\ApiException;
use core\plugin\MenuSync;
use think\App;
use think\facade\Console;
use think\facade\Db;

/**
 * 安装器：命令行安装（hw:install）与网页安装向导共用。
 *
 * 步骤：checkEnvironment → writeEnv（仅网页向导）→ migrate → seed → createSuperAdmin → lock
 * 每一步都应可重复执行（幂等），便于安装中断后重试。
 */
class Installer
{
    public const MIN_PHP = '8.2.0';

    public function __construct(protected App $app)
    {
    }

    /**
     * 环境检查，返回 [{name, required, ok, message}]
     * @param string|null $dbType 目标数据库类型 (sqlite|mysql|pgsql)，若为 null 自动从环境探测
     */
    public function checkEnvironment(?string $dbType = null): array
    {
        if ($dbType === null) {
            $dbType = strtolower((string) (env('DB_DRIVER') ?: env('DB_TYPE') ?: env('database.driver') ?: ''));
        }
        $dbType = strtolower($dbType);

        $items = [];
        $items[] = ['name' => 'PHP >= ' . self::MIN_PHP, 'required' => true, 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'message' => '当前 ' . PHP_VERSION];
        foreach (['pdo', 'mbstring', 'json', 'openssl', 'fileinfo', 'curl', 'ctype'] as $ext) {
            $items[] = ['name' => "扩展 {$ext}", 'required' => true, 'ok' => extension_loaded($ext), 'message' => extension_loaded($ext) ? '已安装' : '未安装'];
        }

        if ($dbType === 'sqlite') {
            $hasSqlite = extension_loaded('pdo_sqlite') || extension_loaded('sqlite3');
            $items[] = ['name' => '扩展 pdo_sqlite', 'required' => true, 'ok' => $hasSqlite, 'message' => $hasSqlite ? '已安装' : '未安装'];
        } elseif ($dbType === 'mysql') {
            $hasMysql = extension_loaded('pdo_mysql');
            $items[] = ['name' => '扩展 pdo_mysql', 'required' => true, 'ok' => $hasMysql, 'message' => $hasMysql ? '已安装' : '未安装'];
        } elseif ($dbType === 'pgsql') {
            $hasPgsql = extension_loaded('pdo_pgsql');
            $items[] = ['name' => '扩展 pdo_pgsql', 'required' => true, 'ok' => $hasPgsql, 'message' => $hasPgsql ? '已安装' : '未安装'];
        } else {
            $hasAnyDb = extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql') || extension_loaded('pdo_sqlite');
            $items[] = ['name' => '数据库扩展 (pdo_mysql / pdo_pgsql / pdo_sqlite)', 'required' => true, 'ok' => $hasAnyDb, 'message' => $hasAnyDb ? '已检测到可用驱动' : '未安装任何支持的数据库驱动'];
        }

        foreach (['gd', 'zip', 'redis'] as $ext) {
            $items[] = ['name' => "扩展 {$ext}（可选）", 'required' => false, 'ok' => extension_loaded($ext), 'message' => extension_loaded($ext) ? '已安装' : '未安装'];
        }
        foreach (['runtime', 'config', 'public/uploads', 'public/plugins', 'plugins'] as $dir) {
            $path = root_path() . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $items[] = ['name' => "目录可写 {$dir}", 'required' => true, 'ok' => is_writable($path), 'message' => is_writable($path) ? '可写' : '不可写，请设置权限'];
        }
        return $items;
    }

    /**
     * 写入 .env（网页安装向导使用），$db = [type, host, port, name, user, pass, prefix]
     */
    public function writeEnv(array $db): void
    {
        $rawType = strtolower((string) ($db['type'] ?? $db['driver'] ?? 'mysql'));
        $type = in_array($rawType, ['mysql', 'pgsql', 'sqlite'], true) ? $rawType : 'mysql';
        $prefix = (string) ($db['prefix'] ?? 'hw_');
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $prefix)) {
            throw new ApiException('表前缀只能包含小写字母、数字和下划线');
        }

        if ($type === 'sqlite') {
            $rawName = trim((string) ($db['name'] ?? ''));
            $dbName = $rawName !== '' ? $rawName : 'runtime/hwadmin.sqlite';
            $lines = [
                'APP_DEBUG = false',
                'DEFAULT_LANG = zh-cn',
                '',
                'DB_DRIVER = sqlite',
                'DB_TYPE = sqlite',
                'DB_NAME = ' . $this->envValue($dbName),
                'DB_HOST = ',
                'DB_PORT = ',
                'DB_USER = ',
                'DB_PASS = ',
                "DB_PREFIX = {$prefix}",
                '',
                'CACHE_DRIVER = file',
            ];
        } else {
            $lines = [
                'APP_DEBUG = false',
                'DEFAULT_LANG = zh-cn',
                '',
                "DB_DRIVER = {$type}",
                "DB_TYPE = {$type}",
                'DB_HOST = ' . $this->envValue($db['host'] ?? '127.0.0.1'),
                'DB_PORT = ' . (int) ($db['port'] ?? ($type === 'pgsql' ? 5432 : 3306)),
                'DB_NAME = ' . $this->envValue($db['name'] ?? 'hwadmin'),
                'DB_USER = ' . $this->envValue($db['user'] ?? 'root'),
                'DB_PASS = ' . $this->envValue($db['pass'] ?? ''),
                'DB_CHARSET = ' . ($type === 'pgsql' ? 'utf8' : 'utf8mb4'),
                "DB_PREFIX = {$prefix}",
                '',
                'CACHE_DRIVER = file',
            ];
        }
        $path = root_path() . '.env';
        $tmp = tempnam(dirname($path), '.env-write-');
        if ($tmp === false) {
            throw new ApiException('创建环境配置临时文件失败，请检查目录权限');
        }
        try {
            $contents = implode(PHP_EOL, $lines) . PHP_EOL;
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents) || !rename($tmp, $path)) {
                throw new ApiException('写入 .env 失败，请检查目录权限');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * 执行核心数据库迁移（database/migrations）
     */
    public function migrate(): void
    {
        $output = Console::call('migrate:run');
        $text = $output->fetch();
        if (stripos($text, 'exception') !== false || stripos($text, 'error') !== false) {
            throw new ApiException('数据库迁移失败：' . $text);
        }
    }

    /**
     * 初始化基础数据（幂等）
     */
    public function seed(): void
    {
        Db::transaction(function () {
            MenuSync::syncCore(false);

            $now = time();
            if (!Db::name('auth_group')->where('id', 1)->find()) {
                Db::name('auth_group')->insert([
                    'id' => 1, 'pid' => 0, 'name' => '超级管理员', 'rules' => '*', 'status' => 1,
                    'create_time' => $now, 'update_time' => $now,
                ]);
            }
            if (!Db::name('user_group')->where('id', 1)->find()) {
                Db::name('user_group')->insert([
                    'id' => 1, 'name' => '默认组', 'rules' => '', 'status' => 1,
                    'create_time' => $now, 'update_time' => $now,
                ]);
            }
            foreach (require __DIR__ . '/data/configs.php' as $config) {
                if (!Db::name('config')->where('name', $config['name'])->find()) {
                    Db::name('config')->insert($config + ['create_time' => $now, 'update_time' => $now]);
                }
            }
            if (is_dir($this->app->getRootPath() . 'plugins/crud') && !Db::name('plugin')->where('name', 'crud')->find()) {
                Db::name('plugin')->insert([
                    'name' => 'crud', 'title' => '应用开发', 'version' => '1.0.0', 'status' => 1,
                    'config' => '{}', 'create_time' => $now, 'update_time' => $now,
                ]);
            }
        });
        $this->fixSequences();
    }

    /**
     * 创建/重置超级管理员（幂等支持：若超管已存在且密码为空，保留原密码）
     */
    public function createSuperAdmin(string $username, string $password, string $email = ''): void
    {
        $exists = Db::name('admin')->where('username', $username)->find();
        if ($exists && $password === '') {
            $id = (int) $exists['id'];
        } else {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $username)) {
                throw new ApiException('管理员用户名须以字母开头，3-30 位字母/数字/下划线');
            }
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                throw new ApiException('管理员密码至少 8 位，且必须同时包含字母和数字');
            }
            $now = time();
            $data = [
                'username'    => $username,
                'nickname'    => $username,
                'password'    => PasswordHasher::hash($password),
                'salt'        => '',
                'email'       => $email,
                'status'      => 1,
                'update_time' => $now,
            ];
            if ($exists) {
                Db::name('admin')->where('id', $exists['id'])->update($data);
                $id = (int) $exists['id'];
            } else {
                $id = (int) Db::name('admin')->insertGetId($data + ['create_time' => $now]);
            }
        }
        if (!Db::name('auth_group_access')->where(['admin_id' => $id, 'group_id' => 1])->find()) {
            Db::name('auth_group_access')->insert(['admin_id' => $id, 'group_id' => 1]);
        }
    }

    public function lock(): void
    {
        $lockFile = config_path() . 'install.lock';
        $written = @file_put_contents($lockFile, date('c') . ' v' . hw_version());
        if ($written === false || !is_file($lockFile)) {
            throw new \RuntimeException('写入安装锁失败，请检查 config 目录写权限：' . $lockFile);
        }
    }

    /**
     * PostgreSQL 显式插入 id 后需同步自增序列
     */
    protected function fixSequences(): void
    {
        $config = Db::connect()->getConfig();
        if (($config['type'] ?? '') !== 'pgsql') {
            return;
        }
        $prefix = (string) ($config['prefix'] ?? '');
        foreach (['auth_group', 'user_group', 'auth_rule', 'config'] as $table) {
            $t = $prefix . $table;
            Db::execute("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
        }
    }

    private function envValue(mixed $value): string
    {
        $value = str_replace(["\r", "\n"], '', (string) $value);
        return preg_match('/[\s#"\'=]/', $value) ? '"' . addcslashes($value, '"\\') . '"' : $value;
    }
}
