<?php
declare(strict_types=1);

namespace app\install\controller;

use core\base\BaseController;
use core\exception\ApiException;
use core\install\Installer;
use app\common\service\DeploymentService;
use core\auth\AdminAuth;
use core\auth\TokenService;
use think\exception\HttpResponseException;
use think\facade\Db;
use think\Response;
use Throwable;

/**
 * HwAdmin Web 可视化安装向导控制器
 *
 * 遵循敏捷无锁、零外部 CDN 依赖的设计原则，具备安全门禁、SSRF 防护与并发互斥锁。
 */
class Index extends BaseController
{
    /**
     * 安全门禁：若已安装锁定，拦截所有请求
     */
    protected function initialize(): void
    {
        parent::initialize();

        // 仅部署诊断可在安装锁定后访问，诊断操作仍各自校验短期凭据或超级管理员。
        if (in_array($this->actionName(), ['deploymentconfig', 'deploymentprepare', 'deploymentcleanup', 'deploymentroute', 'deploymentapplybaota'], true)) {
            return;
        }

        if (is_installed()) {
            if ($this->request->isAjax() || $this->request->isJson() || $this->request->isPost()) {
                $this->ensureNotInstalled();
            }
            throw new HttpResponseException(response($this->renderLockedHtml()));
        }
    }

    /**
     * 校验系统未安装锁定
     */
    protected function ensureNotInstalled(): void
    {
        if (is_installed()) {
            throw new ApiException('系统已安装，如需重新安装请先删除 server/config/install.lock', 0, null, 403);
        }
    }

    /**
     * 获取服务端期望的安装安全令牌
     */
    protected function getExpectedInstallToken(): string
    {
        $envToken = (string) (env('HW_INSTALL_TOKEN') ?: getenv('HW_INSTALL_TOKEN') ?: '');
        if ($envToken !== '') {
            return $envToken;
        }

        $tokenFile = $this->app->getConfigPath() . 'install.token';
        $oldMask = umask(0077);
        try {
            $handle = @fopen($tokenFile, 'c+');
        } finally {
            umask($oldMask);
        }
        if ($handle === false) {
            throw new ApiException('无法创建安装令牌，请检查配置目录权限', 0, null, 503);
        }
        try {
            if (!flock($handle, LOCK_EX) || !chmod($tokenFile, 0600)) {
                throw new ApiException('无法保护安装令牌，请检查配置目录权限', 0, null, 503);
            }
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new ApiException('无法读取安装令牌', 0, null, 503);
            }
            $token = trim($contents);
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $token) !== strlen($token) || !fflush($handle)) {
                    throw new ApiException('无法保存安装令牌', 0, null, 503);
                }
            }
            return $token;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 获取客户端传入的安装安全令牌
     */
    protected function getClientInstallToken(): string
    {
        $token = (string) $this->request->header('X-Install-Token', '');
        if ($token === '') {
            $token = (string) $this->request->post('install_token', '');
        }
        return trim($token);
    }

    /**
     * 所有 HTTP 安装请求都必须携带令牌，包括经本机代理转发的请求
     */
    protected function ensureInstallAccess(): void
    {
        $expected = $this->getExpectedInstallToken();
        $clientToken = $this->getClientInstallToken();

        if ($clientToken === '' || !hash_equals($expected, $clientToken)) {
            throw new ApiException('安装需要有效的安装令牌（请查看服务器 server/config/install.token）', 0, null, 403);
        }
    }

    /**
     * 校验数据库主机与端口（防止 SSRF 与非法端口探测）
     */
    protected function validateDbHost(string $host, int $port = 0): void
    {
        if ($port !== 0 && ($port < 1 || $port > 65535)) {
            throw new ApiException('数据库端口不合法，必须在 1 ~ 65535 之间');
        }

        if (str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '\\') || preg_match('/\s/', $host)) {
            throw new ApiException('数据库主机地址不合法，严禁包含协议头、路径或空白字符');
        }

        $isIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isDomain = (bool) preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $host);

        if (!$isIpv4 && !$isIpv6 && !$isDomain) {
            throw new ApiException('数据库主机地址格式错误，仅允许合法 IP 或域名');
        }
    }

    /**
     * Action 1: 安装向导页面
     */
    public function index(): Response
    {
        $viewFile = app_path() . 'view/index.html';
        if (!is_file($viewFile)) {
            return response('安装向导视图文件丢失，请检查 server/app/install/view/index.html', 404);
        }
        return response((string) file_get_contents($viewFile));
    }

    private function ensureDeploymentAccess(): DeploymentService
    {
        if (!$this->request->isPost()) {
            throw new ApiException('部署检查需要 POST 请求', 0, null, 405);
        }
        $service = new DeploymentService($this->app);
        if (!is_installed()) {
            $this->ensureInstallAccess();
        } elseif (!$service->acceptsGrant((string) $this->request->header('X-Deployment-Token', ''))) {
            $auth = $this->app->make(AdminAuth::class);
            if (!$auth->init(TokenService::fromRequest($this->request)) || !$auth->isSuper()) {
                throw new ApiException('请以超级管理员登录后复查，安装完成页的检查凭据仅 30 分钟有效', 0, null, 403);
            }
        }
        return $service;
    }

    public function deploymentConfig(): void
    {
        $service = $this->ensureDeploymentAccess();
        $this->success($service->configuration());
    }

    public function deploymentApplyBaota(): void
    {
        $service = $this->ensureDeploymentAccess();
        $result = $service->applyBaotaRewrite(
            (string) $this->request->post('panel_url', ''),
            (string) $this->request->post('api_key', '')
        );
        $this->success($result, '宝塔伪静态已自动备份、写入并重载 Nginx，请点击“实际检查”确认生效');
    }

    public function deploymentPrepare(): void
    {
        $this->success($this->ensureDeploymentAccess()->prepare());
    }

    public function deploymentCleanup(): void
    {
        $service = $this->ensureDeploymentAccess();
        $service->cleanup((string) $this->request->post('id', ''));
        $this->success(null, '临时检查文件已清理');
    }

    /** 公开、无状态的伪静态通路检查，不能读取配置或执行诊断任务。 */
    public function deploymentRoute(): void
    {
        $challenge = (string) $this->request->get('challenge', '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $challenge)) {
            $this->error('无效的检查标识', 0, null, 400);
        }
        $this->success(['challenge' => $challenge]);
    }

    /**
     * Action 2: 运行环境检测
     */
    public function check(): void
    {
        $this->ensureNotInstalled();
        $installer = new Installer($this->app);

        $expectedToken = $this->getExpectedInstallToken();
        $clientToken = $this->getClientInstallToken();
        $hasValidToken = $clientToken !== '' && hash_equals($expectedToken, $clientToken);

        if (!$hasValidToken) {
            $this->success([
                'require_token' => true,
                'token_hint'    => '请在宝塔文件中打开站点根目录的 config/install.token，复制内容并验证（源码部署位于 server/config/install.token）',
                'hw_version'    => hw_version(),
            ]);
            return;
        }

        $data = [
            'require_token' => false,
            'items'         => $installer->checkEnvironment(),
            'php_version'   => PHP_VERSION,
            'hw_version'    => hw_version(),
        ];

        $this->success($data);
    }

    /**
     * Action 3: 测试数据库连接
     */
    public function testDb(): void
    {
        $this->ensureNotInstalled();
        $this->ensureInstallAccess();

        $type       = (string) $this->request->post('type', 'mysql');
        $host       = (string) $this->request->post('host', '127.0.0.1');
        $port       = (int) $this->request->post('port', $type === 'pgsql' ? 5432 : 3306);
        $name       = (string) $this->request->post('name', 'hwadmin');
        $user       = (string) $this->request->post('user', $type === 'pgsql' ? 'postgres' : 'root');
        $pass       = (string) $this->request->post('pass', '');
        $autoCreate = (bool) $this->request->post('auto_create', true);

        if (!in_array($type, ['mysql', 'pgsql', 'sqlite'], true)) {
            $this->error('不支持的数据库类型，仅支持 mysql, pgsql 或 sqlite');
        }

        if ($type === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->error('当前 PHP 未启用 pdo_sqlite 扩展');
            }
            $cleanName = str_replace('\\', '/', trim($name));
            if ($cleanName === '') {
                $cleanName = 'runtime/hwadmin.sqlite';
            }
            if (str_contains($cleanName, '..') || str_contains($cleanName, "\0") || str_contains($cleanName, '://')) {
                $this->error('SQLite 数据库文件路径不合法，严禁跨目录探测');
            }
            $dbPath = (str_starts_with($cleanName, '/') || preg_match('/^[a-zA-Z]:/', $cleanName))
                ? $cleanName
                : $this->app->getRootPath() . ltrim($cleanName, '/');
            $dir = dirname($dbPath);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $this->error("无法创建 SQLite 数据库存储目录: {$dir}");
            }
            if (!is_writable($dir)) {
                $this->error("SQLite 存储目录不可写: {$dir}");
            }
            try {
                $pdo = new \PDO("sqlite:{$dbPath}", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->query('SELECT sqlite_version()');
                $version = 'SQLite ' . (string) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $this->error('SQLite 连接/初始化失败: ' . $e->getMessage());
                return;
            }
            $this->success(['server_version' => $version], 'SQLite 数据库就绪');
            return;
        }

        $this->validateDbHost($host, $port);

        if (!extension_loaded("pdo_{$type}")) {
            $this->error("当前 PHP 未启用 pdo_{$type} 扩展");
        }

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            $this->error('数据库名不合法，仅支持字母、数字与下划线，长度 1-64 位');
        }

        try {
            $version = $this->connectAndEnsureDatabase($type, $host, $port, $name, $user, $pass, $autoCreate);
        } catch (ApiException $e) {
            $this->app->log->error('[install] testDb failed: ' . $e->getMessage());
            $this->error($e->getMessage(), 0, null, $e->getHttpStatus() ?: 400);
        } catch (Throwable $e) {
            $this->app->log->error('[install] testDb exception: ' . $e->getMessage(), ['exception' => $e]);
            $this->error('数据库连接失败，请检查主机地址、端口或账号密码');
        }
        $this->success(['server_version' => $version], '数据库连接成功');
    }

    /**
     * Action 4: 执行完整安装
     */
    public function run(): void
    {
        $this->ensureNotInstalled();
        $this->ensureInstallAccess();

        $type       = (string) $this->request->post('type', 'mysql');
        $host       = (string) $this->request->post('host', '127.0.0.1');
        $port       = (int) $this->request->post('port', $type === 'pgsql' ? 5432 : 3306);
        $name       = (string) $this->request->post('name', 'hwadmin');
        $user       = (string) $this->request->post('user', $type === 'pgsql' ? 'postgres' : 'root');
        $pass       = (string) $this->request->post('pass', '');
        $prefix     = (string) $this->request->post('prefix', 'hw_');
        $autoCreate = (bool) $this->request->post('auto_create', true);

        $adminUser  = (string) $this->request->post('admin_user', 'admin');
        $adminPass  = (string) $this->request->post('admin_pass', '');
        $adminEmail = (string) $this->request->post('admin_email', '');

        if (!in_array($type, ['mysql', 'pgsql', 'sqlite'], true)) {
            $this->error('不支持的数据库类型，仅支持 mysql, pgsql 或 sqlite');
        }

        if ($type === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->error('当前 PHP 未启用 pdo_sqlite 扩展');
            }
            $cleanName = str_replace('\\', '/', trim($name));
            if ($cleanName === '') {
                $cleanName = 'runtime/hwadmin.sqlite';
            }
            if (str_contains($cleanName, '..') || str_contains($cleanName, "\0") || str_contains($cleanName, '://')) {
                $this->error('SQLite 数据库文件路径不合法');
            }
        } else {
            $this->validateDbHost($host, $port);

            if (!extension_loaded("pdo_{$type}")) {
                $this->error("当前 PHP 未启用 pdo_{$type} 扩展");
            }

            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
                $this->error('数据库名不合法，仅支持字母、数字与下划线，长度 1-64 位');
            }
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $prefix)) {
            $this->error('表前缀只能以小写字母开头，由小写字母、数字和下划线组成（1-16位）');
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,29}$/', $adminUser)) {
            $this->error('管理员用户名须以字母开头，3-30 位字母/数字/下划线');
        }

        if (strlen($adminPass) < 8 || !preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/\d/', $adminPass)) {
            $this->error('管理员密码至少 8 位，且必须同时包含字母和数字');
        }

        // 并发互斥文件锁控制
        $lockFile = $this->app->getRuntimePath() . 'install.installing.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $lockFp = fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockFp)) {
                fclose($lockFp);
            }
            throw new ApiException('另一个安装进程正在执行中，请勿重复提交', 0, null, 409);
        }

        $envPath = $this->app->getRootPath() . '.env';
        $envBak = '';
        $hadExistingEnv = is_file($envPath);
        $envWriteAttempted = false;
        $keepEnvBackup = false;

        try {
            // Another request may have completed installation while this request waited.
            $this->ensureNotInstalled();
            if ($hadExistingEnv) {
                $envBak = tempnam($this->app->getRootPath(), '.env-install-');
                if ($envBak === false || !chmod($envBak, 0600) || !copy($envPath, $envBak)) {
                    throw new ApiException('无法备份现有环境配置', 0, null, 500);
                }
            }
            // 1. 连通性与自动建库
            $sqliteAbsPath = '';
            if ($type === 'sqlite') {
                $cleanName = str_replace('\\', '/', trim($name));
                if ($cleanName === '') {
                    $cleanName = 'runtime/hwadmin.sqlite';
                }
                $sqliteAbsPath = (str_starts_with($cleanName, '/') || preg_match('/^[a-zA-Z]:/', $cleanName))
                    ? $cleanName
                    : $this->app->getRootPath() . ltrim($cleanName, '/');
                $sqliteDir = dirname($sqliteAbsPath);
                if (!is_dir($sqliteDir)) {
                    @mkdir($sqliteDir, 0755, true);
                }
                if (!is_writable($sqliteDir)) {
                    throw new ApiException("SQLite 存储目录不可写: {$sqliteDir}");
                }
                $testPdo = new \PDO("sqlite:{$sqliteAbsPath}", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                unset($testPdo);
            } else {
                $this->connectAndEnsureDatabase($type, $host, $port, $name, $user, $pass, $autoCreate);
            }

            // 2. 检查必选环境项
            $installer = new Installer($this->app);
            foreach ($installer->checkEnvironment() as $item) {
                if (!empty($item['required']) && empty($item['ok'])) {
                    throw new ApiException("环境检查未通过：{$item['name']}（{$item['message']}）");
                }
            }

            // 3. 写入 .env 配置文件
            $envWriteAttempted = true;
            if ($type === 'sqlite') {
                $cleanName = str_replace('\\', '/', trim($name));
                if ($cleanName === '') {
                    $cleanName = 'runtime/hwadmin.sqlite';
                }
                $isAbsSqlite = str_starts_with($cleanName, '/') || (bool) preg_match('/^[a-zA-Z]:/', $cleanName);
                $dbNameRel = $isAbsSqlite ? $cleanName : ltrim($cleanName, '/');
                $envLines = [
                    'APP_DEBUG = false',
                    'DEFAULT_LANG = zh-cn',
                    '',
                    'DB_DRIVER = sqlite',
                    'DB_TYPE = sqlite',
                    'DB_HOST =',
                    'DB_PORT =',
                    "DB_NAME = {$dbNameRel}",
                    'DB_USER =',
                    'DB_PASS =',
                    'DB_CHARSET = utf8',
                    "DB_PREFIX = {$prefix}",
                    '',
                    'CACHE_DRIVER = file',
                ];
                file_put_contents($envPath, implode(PHP_EOL, $envLines) . PHP_EOL, LOCK_EX);
            } else {
                $installer->writeEnv([
                    'type'   => $type,
                    'host'   => $host,
                    'port'   => $port,
                    'name'   => $name,
                    'user'   => $user,
                    'pass'   => $pass,
                    'prefix' => $prefix,
                ]);
            }

            // 4. 动态更新当前内存数据库配置，确保同一请求内后续 migrate 与 seed 生效
            $databaseConfig = $this->app->config->get('database', []);
            $databaseConfig['default'] = $type;
            if ($type === 'sqlite') {
                $databaseConfig['connections']['sqlite'] = [
                    'type'     => 'sqlite',
                    'database' => $sqliteAbsPath,
                    'prefix'   => $prefix,
                    'charset'  => 'utf8',
                    'params'   => [\PDO::ATTR_TIMEOUT => 30],
                    'hostname' => '',
                    'username' => '',
                    'password' => '',
                    'hostport' => '',
                ];
            } else {
                if (!isset($databaseConfig['connections'][$type])) {
                    $databaseConfig['connections'][$type] = [];
                }
                $databaseConfig['connections'][$type]['type']     = $type;
                $databaseConfig['connections'][$type]['hostname'] = $host;
                $databaseConfig['connections'][$type]['hostport'] = (string) $port;
                $databaseConfig['connections'][$type]['database'] = $name;
                $databaseConfig['connections'][$type]['username'] = $user;
                $databaseConfig['connections'][$type]['password'] = $pass;
                $databaseConfig['connections'][$type]['prefix']   = $prefix;
                $databaseConfig['connections'][$type]['charset']  = $type === 'pgsql' ? 'utf8' : 'utf8mb4';
            }

            $this->app->config->set($databaseConfig, 'database');

            // 5. 依次执行数据库迁移、基础数据初始化、创建超级管理员与生成安装锁
            $installer->migrate();
            $installer->seed();
            $installer->createSuperAdmin($adminUser, $adminPass, $adminEmail);
            $deploymentToken = (new DeploymentService($this->app))->issueGrant();
            $installer->lock();

            // 6. 安装成功后自动删除 config/install.token 一次性令牌文件
            $tokenFile = $this->app->getConfigPath() . 'install.token';
            if (is_file($tokenFile)) {
                @unlink($tokenFile);
            }

        } catch (ApiException $e) {
            // 安装失败时恢复环境配置；恢复失败时保留备份供人工处理。
            if ($envWriteAttempted && $hadExistingEnv && $envBak && is_file($envBak)) {
                if (!@copy($envBak, $envPath)) {
                    $keepEnvBackup = true;
                    $this->app->log->error('[install] 无法恢复原环境配置，备份保留于 ' . $envBak);
                }
            } elseif ($envWriteAttempted && !$hadExistingEnv && is_file($envPath)) {
                @unlink($envPath);
            }
            $this->app->log->error('[install] run failed: ' . $e->getMessage());
            $this->error($e->getMessage(), 0, null, $e->getHttpStatus() ?: 400);
        } catch (Throwable $e) {
            if ($envWriteAttempted && $hadExistingEnv && $envBak && is_file($envBak)) {
                if (!@copy($envBak, $envPath)) {
                    $keepEnvBackup = true;
                    $this->app->log->error('[install] 无法恢复原环境配置，备份保留于 ' . $envBak);
                }
            } elseif ($envWriteAttempted && !$hadExistingEnv && is_file($envPath)) {
                @unlink($envPath);
            }
            $this->app->log->error('[install] run exception: ' . $e->getMessage(), ['exception' => $e]);
            $this->error('安装失败，请检查数据库配置、系统权限或查看 server/runtime/log 日志');
        } finally {
            if (!$keepEnvBackup && $envBak && is_file($envBak)) {
                @unlink($envBak);
            }
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
        $this->success(['admin_url' => '/admin/', 'admin_user' => $adminUser, 'deployment_token' => $deploymentToken], '安装成功，部署安全待实测确认');
    }

    /**
     * 连接数据库并在需要时自动建库，返回数据库服务端版本
     */
    private function connectAndEnsureDatabase(
        string $type,
        string $host,
        int $port,
        string $name,
        string $user,
        string $pass,
        bool $autoCreate
    ): string {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ];

        if ($type === 'mysql') {
            if ($autoCreate) {
                // 先连接 MySQL 服务器进行建库操作
                $serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new \PDO($serverDsn, $user, $pass, $options);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$name}`");
            } else {
                $dbDsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new \PDO($dbDsn, $user, $pass, $options);
            }
            $stmt = $pdo->query('SELECT VERSION()');
            return (string) $stmt->fetchColumn();
        }

        if ($type === 'pgsql') {
            try {
                $dbDsn = "pgsql:host={$host};port={$port};dbname={$name}";
                $pdo = new \PDO($dbDsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                if ($autoCreate) {
                    // 连接 postgres 管理库创建目标数据库
                    $adminDsn = "pgsql:host={$host};port={$port};dbname=postgres";
                    $adminPdo = new \PDO($adminDsn, $user, $pass, $options);

                    $checkStmt = $adminPdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
                    $checkStmt->execute(['name' => $name]);
                    if (!$checkStmt->fetchColumn()) {
                        $adminPdo->exec("CREATE DATABASE \"{$name}\" ENCODING 'UTF8'");
                    }

                    // 重连新创建的目标数据库
                    $pdo = new \PDO($dbDsn, $user, $pass, $options);
                } else {
                    throw $e;
                }
            }
            $stmt = $pdo->query('SELECT version()');
            return (string) $stmt->fetchColumn();
        }

        throw new \InvalidArgumentException("不支持的数据库驱动: {$type}");
    }

    /**
     * 生成安全锁定提示页面 HTML
     */
    private function renderLockedHtml(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统已完成安装 - HwAdmin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            color: #334155;
            padding: 24px;
        }
        .lock-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.08);
            max-width: 520px;
            width: 100%;
            padding: 48px 36px;
            text-align: center;
        }
        .icon-wrap {
            width: 72px;
            height: 72px;
            margin: 0 auto 24px;
            background: #eef2ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f46e5;
        }
        .icon-wrap svg { width: 36px; height: 36px; }
        h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
        p { font-size: 14px; line-height: 1.6; color: #64748b; margin-bottom: 24px; }
        .tip-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13px;
            color: #475569;
            text-align: left;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .tip-box code {
            display: inline-block;
            margin-top: 4px;
            color: #ef4444;
            font-family: Consolas, Monaco, monospace;
            background: #fee2e2;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }
        .btn-primary {
            display: inline-block;
            width: 100%;
            padding: 12px 24px;
            background: #4f46e5;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            transition: background 0.2s ease, transform 0.1s ease;
        }
        .btn-primary:hover { background: #4338ca; }
        .btn-primary:active { transform: translateY(1px); }
    </style>
</head>
<body>
    <div class="lock-card">
        <div class="icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
        </div>
        <h1>系统已完成安装</h1>
        <p>数据库安装已完成，安装程序已锁定。部署安全是否通过，请登录后台复查。</p>
        <div class="tip-box">
            登录管理后台 → 系统配置 → 部署安全复查，即可生成宝塔配置并检查实际效果。<br>
            <strong>复查无需删除安装锁，也不会重新安装数据库。</strong>
        </div>
        <a href="/admin/" class="btn-primary">进入管理后台</a>
    </div>
</body>
</html>
HTML;
    }
}
