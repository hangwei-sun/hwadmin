<?php
declare(strict_types=1);

namespace tests\Unit;

use core\auth\AdminAuth;
use core\auth\PasswordRecoveryChannelService;
use core\auth\TokenService;
use core\exception\ApiException;
use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\WechatMiniAuthService;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Db\Adapter\PostgresAdapter;
use tests\TestCase;
use think\App;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\migration\Migrator;

/**
 * T05 / T07 交付审计测试：
 * - T05: MySQL 8.0+ & PostgreSQL 16+ Phinx 迁移 DDL 生成校验、custom string PK record_key、
 *        参数化 MenuSync 防注入、created_by 归属隔离与非超管 403 拦截；
 * - T07: PasswordRecoveryChannelService（未配置 503、production 拒用测试传输、真实本地签名 Webhook 投递、
 *        脱敏审计日志、一次性凭证消耗与重放拦截）与 WechatMiniAuthService + hw:wechat:migrate-bindings
 *        （--list --json 扫描、冲突拒绝、--confirm 安全迁移与迁移后原账号登录）。
 */
class T05T06T07DeliveryAuditTest extends TestCase
{
    private bool $forceProductionEnv = false;
    private bool $recoveryTestingWithoutDebug = false;
    private bool $attachRecoveryListener = false;
    /** @var array<string, mixed> */
    private array $overrideAppConfig = [];
    /** @var array<int, string> */
    private array $cleanupPaths = [];
    /** @var array<string, string|false> */
    private array $savedEnvVars = [];

    protected function tearDown(): void
    {
        PasswordRecoveryChannelService::resetTestState();
        WechatMiniAuthService::resetTestState();

        foreach ($this->savedEnvVars as $key => $val) {
            if ($val === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
        $this->savedEnvVars = [];

        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanupPaths = [];

        $this->forceProductionEnv = false;
        $this->recoveryTestingWithoutDebug = false;
        $this->attachRecoveryListener = false;
        $this->overrideAppConfig = [];

        parent::tearDown();
    }

    protected function createIsolatedApp(): App
    {
        $app = parent::createIsolatedApp();

        if ($this->forceProductionEnv) {
            $app->debug(false);
            $app->env->set([
                'APP_ENV'   => 'production',
                'APP_DEBUG' => 'false',
            ]);
        }
        if ($this->recoveryTestingWithoutDebug) {
            $app->debug(false);
            $app->env->set(['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);
        }

        if ($this->overrideAppConfig !== []) {
            $hwConfig = $app->config->get('hwadmin', []);
            if (!is_array($hwConfig)) {
                $hwConfig = [];
            }
            $app->config->set(array_replace_recursive($hwConfig, $this->overrideAppConfig), 'hwadmin');
        }

        if ($this->attachRecoveryListener) {
            PasswordRecoveryChannelService::register($app);
        }

        return $app;
    }

    private function setScopedEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedEnvVars)) {
            $prev = getenv($key);
            $this->savedEnvVars[$key] = $prev === false ? false : (string) $prev;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    #[RunInSeparateProcess]
    public function testDualDatabaseMysqlAndPostgresMigrationDdlGeneration(): void
    {
        $root = dirname(__DIR__, 2);
        $repoRoot = dirname($root);

        $migrations = [
            $repoRoot . '/templates/corporate-site/migrations/20261115100001_create_corporate_site_tables.php' => 'CreateCorporateSiteTables',
            $repoRoot . '/templates/booking-system/migrations/20261115100002_create_booking_system_tables.php' => 'CreateBookingSystemTables',
            $repoRoot . '/templates/enterprise-admin/migrations/20261115100003_create_enterprise_admin_tables.php' => 'CreateEnterpriseAdminTables',
        ];

        foreach ($migrations as $file => $className) {
            $this->assertFileExists($file);
            if (!class_exists($className, false)) {
                require_once $file;
            }
        }

        $mysqlAdapter = $this->createRecordingMysqlAdapter();
        $pgAdapter = $this->createRecordingPostgresAdapter();

        foreach ($migrations as $className) {
            /** @var Migrator $mysqlMigration */
            $mysqlMigration = new $className('testing', 20261115100001);
            $mysqlMigration->setAdapter($mysqlAdapter);
            $mysqlMigration->up();

            /** @var Migrator $pgMigration */
            $pgMigration = new $className('testing', 20261115100001);
            $pgMigration->setAdapter($pgAdapter);
            $pgMigration->up();
        }

        $mysqlSql = implode("\n", $mysqlAdapter->recordedSql);
        $pgSql = implode("\n", $pgAdapter->recordedSql);

        // MySQL 8.0+ DDL 断言
        $this->assertStringContainsString('CREATE TABLE `site_article`', $mysqlSql);
        $this->assertStringContainsString('CREATE TABLE `booking_order`', $mysqlSql);
        $this->assertStringContainsString('CREATE TABLE `enterprise_project`', $mysqlSql);
        $this->assertStringContainsString('DECIMAL(12,2)', strtoupper($mysqlSql));
        $this->assertStringContainsString('ENGINE = InnoDB', $mysqlSql);

        // PostgreSQL 16+ DDL 断言（双引号标识 + 无 MySQL 专有关键字）
        $this->assertStringContainsString('CREATE TABLE "PUBLIC"."SITE_ARTICLE"', strtoupper($pgSql));
        $this->assertStringContainsString('CREATE TABLE "PUBLIC"."BOOKING_ORDER"', strtoupper($pgSql));
        $this->assertStringContainsString('CREATE TABLE "PUBLIC"."ENTERPRISE_PROJECT"', strtoupper($pgSql));
        $this->assertStringContainsString('DECIMAL (12, 2)', strtoupper($pgSql));
        $this->assertStringContainsString('CHARACTER VARYING', strtoupper($pgSql));
        $this->assertStringContainsString('SERIAL NOT NULL', strtoupper($pgSql));
        $this->assertStringNotContainsString('ENGINE = INNODB', strtoupper($pgSql));
        $this->assertStringNotContainsString('AUTO_INCREMENT', strtoupper($pgSql));
        $this->assertStringNotContainsString('TINYINT', strtoupper($pgSql));
    }

    public function testCustomStringPrimaryKeyRecordKeyMigrationAndScaffoldDdl(): void
    {
        $builder = new CrudScaffoldBuilder($this->app);
        $migrationPhp = $builder->renderMigrationFromEntity(
            'CreateAuditCustomPkTable',
            [
                'table'       => 'audit_custom_pk',
                'primary_key' => 'record_key',
                'comment'     => '自定义字符串主键审计表',
                'fields'      => [
                    ['name' => 'record_key', 'type' => 'string', 'limit' => 64, 'comment' => '业务编码主键'],
                    ['name' => 'title', 'type' => 'string', 'limit' => 128, 'comment' => '名称'],
                    ['name' => 'amount', 'type' => 'decimal', 'precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => '金额'],
                    ['name' => 'created_by', 'type' => 'integer', 'default' => 0, 'comment' => '创建人'],
                ],
            ],
            [
                'name'      => 'audit/custom_pk',
                'title'     => '自定义主键审计',
                'path'      => '/audit/custom_pk',
                'component' => '@/views/audit/custom_pk/index.vue',
            ]
        );

        $this->assertStringContainsString("'id' => false", $migrationPhp);
        $this->assertStringContainsString("'primary_key' => ['record_key']", $migrationPhp);

        // 验证直接通过 MySQL 与 PostgreSQL Phinx Adapter 生成自定义字符串主键 DDL
        $mysqlAdapter = $this->createRecordingMysqlAdapter();
        $pgAdapter = $this->createRecordingPostgresAdapter();

        foreach ([$mysqlAdapter, $pgAdapter] as $adapter) {
            $table = new \Phinx\Db\Table('audit_custom_pk', ['id' => false, 'primary_key' => ['record_key']], $adapter);
            $table->addColumn('record_key', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('title', 'string', ['limit' => 128, 'default' => ''])
                ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
                ->addColumn('created_by', 'integer', ['default' => 0])
                ->create();
        }

        $mysqlSql = implode("\n", $mysqlAdapter->recordedSql);
        $pgSql = implode("\n", $pgAdapter->recordedSql);
        $this->assertStringContainsString('PRIMARY KEY (`record_key`)', $mysqlSql);
        $this->assertStringContainsString('PRIMARY KEY ("record_key")', $pgSql);
    }

    public function testParameterizedMenuSyncInjectionResilienceAndCleanup(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE hw_auth_rule (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pid INTEGER NOT NULL DEFAULT 0,
            type INTEGER NOT NULL DEFAULT 1,
            name TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT "",
            icon TEXT NOT NULL DEFAULT "",
            path TEXT NOT NULL DEFAULT "",
            component TEXT NOT NULL DEFAULT "",
            weight INTEGER NOT NULL DEFAULT 0,
            hidden INTEGER NOT NULL DEFAULT 0,
            plugin TEXT NOT NULL DEFAULT "",
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )');

        $adapter = $this->createRecordingMysqlAdapter($pdo);
        $injectionTitle = "安全节点'; DROP TABLE hw_auth_rule; --";

        // 1) 反例：插件空间禁止越界注册非 plugin/<name>/ 节点
        $blocked = false;
        try {
            MenuSync::withAdapter($adapter, function () use ($injectionTitle): int {
                return MenuSync::ensureNode([
                    'name'  => 't05/audit',
                    'title' => $injectionTitle,
                ], 0, 't05audit');
            });
        } catch (ApiException $e) {
            $blocked = str_contains($e->getMessage(), 'plugin/t05audit/');
        }
        $this->assertTrue($blocked, '插件越权注册核心节点必须被拦截');

        // 2) 正例：合法插件命名空间 + SQL 注入载荷参数化安全写入与清理
        $nodeId = MenuSync::withAdapter($adapter, function () use ($injectionTitle): int {
            return MenuSync::ensureNode([
                'name'      => 'plugin/t05audit/audit',
                'title'     => $injectionTitle,
                'path'      => '/t05/audit',
                'component' => '@/views/t05/audit/index.vue',
                'children'  => [
                    ['name' => 'plugin/t05audit/audit/del', 'title' => '删除', 'type' => 2],
                ],
            ], 0, 't05audit');
        });

        $this->assertGreaterThan(0, $nodeId);
        $saved = $pdo->query("SELECT title FROM hw_auth_rule WHERE name = 'plugin/t05audit/audit'")->fetchColumn();
        $this->assertSame($injectionTitle, $saved);

        $removed = MenuSync::withAdapter($adapter, fn () => MenuSync::removeNodes(['plugin/t05audit/audit', 'plugin/t05audit/audit/del']));
        $this->assertSame(2, $removed);
        $remaining = (int) $pdo->query("SELECT COUNT(*) FROM hw_auth_rule WHERE plugin = 't05audit'")->fetchColumn();
        $this->assertSame(0, $remaining);
    }

    public function testNonSuperAdminPermissionAndCreatedByOwnershipIsolation(): void
    {
        $groupId = (int) Db::name('auth_group')->insertGetId([
            'name'        => '审计受限角色_' . time(),
            'rules'       => '1',
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $saltA = 'salta1';
        $saltB = 'saltb2';
        $hashA = sha1(md5('Pass123456') . $saltA);
        $hashB = sha1(md5('Pass123456') . $saltB);

        $adminAId = (int) Db::name('admin')->insertGetId([
            'username'    => 't05_op_a_' . time(),
            'nickname'    => '操作员A',
            'password'    => $hashA,
            'salt'        => $saltA,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $adminBId = (int) Db::name('admin')->insertGetId([
            'username'    => 't05_op_b_' . time(),
            'nickname'    => '操作员B',
            'password'    => $hashB,
            'salt'        => $saltB,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        Db::name('auth_group_access')->insertAll([
            ['admin_id' => $adminAId, 'group_id' => $groupId],
            ['admin_id' => $adminBId, 'group_id' => $groupId],
        ]);

        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $tokenA = $tokens->issue(AdminAuth::TOKEN_TYPE, $adminAId, 3600);

        // 非超管未授权菜单与操作节点，访问 /adminapi/auth.admin/index 与 /adminapi/auth.admin/del 应返回 403
        $forbiddenList = $this->dispatchHttp('GET', '/adminapi/auth.admin/index', [], [], $tokenA);
        $this->assertApiFail($forbiddenList, 403, '非超管越权访问管理员列表必须被 403 拦截');

        $forbiddenDel = $this->dispatchHttp('POST', '/adminapi/auth.admin/del', [], ['ids' => [$adminBId]], $tokenA);
        $this->assertApiFail($forbiddenDel, 403, '非超管越权删除其他管理员必须被 403 拦截');

        // 测试企业项目管理模板的 created_by 归属隔离
        $repoRoot = dirname(__DIR__, 3);
        $projectServiceFile = $repoRoot . '/templates/enterprise-admin/backend/app/common/service/EnterpriseProjectService.php';
        $projectModelFile = $repoRoot . '/templates/enterprise-admin/backend/app/common/model/EnterpriseProject.php';
        $this->assertFileExists($projectServiceFile);
        $this->assertFileExists($projectModelFile);

        Db::execute('CREATE TABLE IF NOT EXISTS hw_enterprise_project (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_no TEXT NOT NULL DEFAULT "",
            title TEXT NOT NULL DEFAULT "",
            admin_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            priority INTEGER NOT NULL DEFAULT 2,
            budget TEXT NOT NULL DEFAULT "0.00",
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hw_enterprise_task (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL DEFAULT "",
            assignee TEXT NOT NULL DEFAULT "",
            deadline TEXT NOT NULL DEFAULT "",
            created_by INTEGER NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 0,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hw_enterprise_attachment (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_type TEXT NOT NULL DEFAULT "",
            target_id INTEGER NOT NULL DEFAULT 0,
            file_name TEXT NOT NULL DEFAULT "",
            file_url TEXT NOT NULL DEFAULT "",
            file_size INTEGER NOT NULL DEFAULT 0,
            file_ext TEXT NOT NULL DEFAULT "",
            admin_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            create_time INTEGER NOT NULL DEFAULT 0
        )');

        $suffix = (string) random_int(100, 999);
        $ownedId = (int) Db::name('enterprise_project')->insertGetId([
            'project_no'  => 'PRJ_A_' . $suffix,
            'title'       => '操作员A私有项目',
            'admin_id'    => $adminAId,
            'created_by'  => $adminAId,
            'priority'    => 1,
            'budget'      => '12500.50',
            'status'      => 2,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $otherId = (int) Db::name('enterprise_project')->insertGetId([
            'project_no'  => 'PRJ_B_' . $suffix,
            'title'       => '操作员B私有项目',
            'admin_id'    => $adminBId,
            'created_by'  => $adminBId,
            'priority'    => 2,
            'budget'      => '98000.00',
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        // 加载模板模型与服务类至当前孤立测试进程（避免与模板安装测试已加载的 server/app 同名类重复声明）
        foreach ([
            'EnterpriseProject.php'    => \app\common\model\EnterpriseProject::class,
            'EnterpriseTask.php'       => \app\common\model\EnterpriseTask::class,
            'EnterpriseAttachment.php' => \app\common\model\EnterpriseAttachment::class,
        ] as $mFile => $fqcn) {
            $mPath = $repoRoot . '/templates/enterprise-admin/backend/app/common/model/' . $mFile;
            $this->assertFileExists($mPath);
            if (!class_exists($fqcn, false)) {
                require_once $mPath;
            }
        }
        if (!class_exists(\app\common\service\EnterpriseProjectService::class, false)) {
            require_once $projectServiceFile;
        }

        $service = new \app\common\service\EnterpriseProjectService();
        $this->assertSame('12500.50', \app\common\service\EnterpriseProjectService::normalizeBudget('12500.5'));

        // 非超管导出只应看到本人 created_by 数据
        $exported = $service->exportProjects([], $adminAId, false);
        $this->assertSame(1, $exported['total']);
        $this->assertStringContainsString('操作员A私有项目', $exported['csv']);
        $this->assertStringNotContainsString('操作员B私有项目', $exported['csv']);

        // 非超管越权删除他人项目必须抛出 403
        try {
            $service->deleteProject($otherId, $adminAId, false);
            $this->fail('非超管越权删除他人项目必须被 403 拦截');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 本人可正常删除自己的项目
        $service->deleteProject($ownedId, $adminAId, false);
        $this->assertEmpty(Db::name('enterprise_project')->where('id', $ownedId)->find());
        $this->assertNotEmpty(Db::name('enterprise_project')->where('id', $otherId)->find());
    }

    public function testPasswordRecoveryChannelRejectsUnconfiguredAndTestTransportInProduction(): void
    {
        $email = 't07_prod_' . random_int(1000, 9999) . '@example.com';
        $salt = 't07salt';
        Db::name('user')->insert([
            'username'    => 't07_user_' . random_int(1000, 9999),
            'nickname'    => '恢复测试员',
            'email'       => $email,
            'mobile'      => '',
            'password'    => sha1(md5('Init123456') . $salt),
            'salt'        => $salt,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        // 即使在测试之外误注入了 testTransport，APP_ENV=production 也必须直接拒绝并回退 503 CONFIGURE_PASSWORD_RECOVERY_DELIVERY
        PasswordRecoveryChannelService::setTestTransport(fn () => ['ok' => true, 'provider_id' => 'should-be-blocked']);

        $this->forceProductionEnv = true;
        $this->attachRecoveryListener = true;
        $this->setScopedEnv('APP_ENV', 'production');
        $this->setScopedEnv('APP_DEBUG', 'false');
        $this->setScopedEnv('HW_RECOVERY_CHANNEL', '');
        $this->setScopedEnv('HW_RECOVERY_WEBHOOK_URL', '');
        $this->setScopedEnv('HW_RECOVERY_SMTP_HOST', '');
        $this->setScopedEnv('HW_RECOVERY_SMS_ENDPOINT', '');

        $resp = $this->guestPost('/api/user/sendResetCode', ['account' => $email]);
        $this->assertApiFail($resp, 0, 'production 未配置恢复渠道必须失败');
        $this->assertSame('CONFIGURE_PASSWORD_RECOVERY_DELIVERY', $resp['data']['todo'] ?? null);
        $this->assertArrayNotHasKey('reset_token', $resp['data'] ?? []);
    }

    public function testRecoveryProductionRejectsPlaintextChannels(): void
    {
        $this->app->env->set(['APP_ENV' => 'production']);
        $this->setScopedEnv('APP_ENV', 'production');
        $this->setScopedEnv('HW_RECOVERY_WEBHOOK_URL', 'http://127.0.0.1/hook');
        $this->setScopedEnv('HW_RECOVERY_WEBHOOK_SECRET', 'secure-channel-secret');
        $this->setScopedEnv('HW_RECOVERY_SMTP_HOST', 'mail.example.com');
        $this->setScopedEnv('HW_RECOVERY_SMTP_FROM', 'noreply@example.com');
        $this->setScopedEnv('HW_RECOVERY_SMTP_ENCRYPTION', 'none');
        $service = new PasswordRecoveryChannelService($this->app);
        $this->assertFalse($service->diagnoseChannels()['webhook']['ready']);
        $this->assertFalse($service->diagnoseChannels()['smtp']['ready']);
        $this->setScopedEnv('HW_RECOVERY_WEBHOOK_URL', 'https://delivery.example.com/hook');
        $this->setScopedEnv('HW_RECOVERY_SMTP_ENCRYPTION', 'tls');
        $this->assertTrue($service->diagnoseChannels()['webhook']['ready']);
        $this->assertTrue($service->diagnoseChannels()['smtp']['ready']);
    }

    public function testPasswordRecoverySignedWebhookDeliveryAndAuditLogInTestingWithoutDebug(): void
    {
        $suffix = (string) random_int(1000, 9999);
        $email = "t07_hook_{$suffix}@example.com";
        $salt = 'hk1234';
        $userId = (int) Db::name('user')->insertGetId([
            'username'    => "t07_hook_user_{$suffix}",
            'nickname'    => 'Webhook恢复用户',
            'email'       => $email,
            'mobile'      => '',
            'password'    => sha1(md5('OldPass123') . $salt),
            'salt'        => $salt,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        // 本地 HTTP 仅在 testing 验证真实发送；生产强制加密传输。
        $captureFile = sys_get_temp_dir() . '/hw_t07_webhook_' . uniqid('', true) . '.json';
        $serverScript = sys_get_temp_dir() . '/hw_t07_server_' . uniqid('', true) . '.php';
        $this->cleanupPaths[] = $captureFile;
        $this->cleanupPaths[] = $serverScript;

        $serverCode = <<<'PHP'
<?php
$captureFile = getenv('HW_T07_CAPTURE_FILE');
$raw = file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
file_put_contents($captureFile, json_encode(['body' => $raw, 'headers' => $headers], JSON_UNESCAPED_UNICODE));
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
PHP;
        file_put_contents($serverScript, $serverCode);

        $port = $this->findFreeLocalPort();
        $env = array_merge($_ENV, ['HW_T07_CAPTURE_FILE' => $captureFile]);
        $cmd = sprintf('%s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverScript));
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);

        try {
            $this->waitForTcpPort('127.0.0.1', $port);

            $secret = 'hw-t07-webhook-secret-' . $suffix;
            $auditLog = dirname(__DIR__, 2) . '/runtime/logs/password_recovery_audit.log';
            @unlink($auditLog);

            $this->recoveryTestingWithoutDebug = true;
            $this->attachRecoveryListener = true;
            $this->setScopedEnv('APP_ENV', 'testing');
            $this->setScopedEnv('APP_DEBUG', 'false');
            $this->setScopedEnv('HW_RECOVERY_CHANNEL', 'webhook');
            $this->setScopedEnv('HW_RECOVERY_WEBHOOK_URL', "http://127.0.0.1:{$port}/hook");
            $this->setScopedEnv('HW_RECOVERY_WEBHOOK_SECRET', $secret);

            $sendResp = $this->guestPost('/api/user/sendResetCode', ['account' => $email]);
            $this->assertApiSuccess($sendResp, '有效签名 Webhook 在关闭 debug 时仍须投递成功');
            $this->assertArrayNotHasKey('reset_token', $sendResp['data'] ?? [], '关闭 debug 时绝不能回显明文 reset_token');

            $this->assertFileExists($captureFile, '本地签名 Webhook 服务器必须实际收到 HTTP POST');
            $captured = json_decode((string) file_get_contents($captureFile), true);
            $this->assertIsArray($captured);
            $rawBody = (string) ($captured['body'] ?? '');
            $headers = (array) ($captured['headers'] ?? []);

            // 校验 HMAC-SHA256 签名正反例
            $this->assertTrue(PasswordRecoveryChannelService::verifyWebhookSignature(
                $rawBody,
                (string) ($headers['x-hw-timestamp'] ?? ''),
                (string) ($headers['x-hw-nonce'] ?? ''),
                (string) ($headers['x-hw-signature'] ?? ''),
                $secret
            ));
            $this->assertFalse(PasswordRecoveryChannelService::verifyWebhookSignature(
                $rawBody . 'tamper',
                (string) ($headers['x-hw-timestamp'] ?? ''),
                (string) ($headers['x-hw-nonce'] ?? ''),
                (string) ($headers['x-hw-signature'] ?? ''),
                $secret
            ));

            $envelope = json_decode($rawBody, true);
            $resetToken = (string) ($envelope['reset_token'] ?? '');
            $this->assertNotSame('', $resetToken);
            $this->assertSame($userId, (int) ($envelope['user_id'] ?? 0));

            // 审计日志存在且绝不落盘明文凭证或密钥
            $this->assertFileExists($auditLog);
            $auditContent = (string) file_get_contents($auditLog);
            $this->assertStringContainsString('"channel":"webhook"', $auditContent);
            $this->assertStringContainsString('"delivered":true', $auditContent);
            $this->assertStringNotContainsString($resetToken, $auditContent);
            $this->assertStringNotContainsString($secret, $auditContent);

            // 凭 Webhook 收到的 reset_token 重置密码并验证单次消耗（重放失败）
            $resetResp = $this->guestPost('/api/user/resetPassword', [
                'account'      => $email,
                'reset_token'  => $resetToken,
                'new_password' => 'NewSec123456',
            ]);
            $this->assertApiSuccess($resetResp, '通过 Webhook 送达的恢复凭证重置密码应成功');

            $replayResp = $this->guestPost('/api/user/resetPassword', [
                'account'      => $email,
                'reset_token'  => $resetToken,
                'new_password' => 'Replay999999',
            ]);
            $this->assertApiFail($replayResp, 0, '单次恢复凭证使用后再次重放必须被拒绝');
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testWechatLegacyBindingInspectAndSafeMigrateTool(): void
    {
        $suffix = (string) random_int(1000, 9999);
        $safeOpenId = "oSafeLegacy_{$suffix}";
        $missingUserOpenId = "oMissingLegacy_{$suffix}";
        $conflictOpenId = "oConflictLegacy_{$suffix}";
        $targetAppId = 'wx_t07_migrated_' . $suffix;

        $salt = 'wxmig';
        $existingUserId = (int) Db::name('user')->insertGetId([
            'username'    => "wx_mig_user_{$suffix}",
            'nickname'    => '旧绑定待迁移用户',
            'password'    => sha1(md5('WxPass123') . $salt),
            'salt'        => $salt,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $service = new WechatMiniAuthService($this->app);
        $bindDir = $service->getBindingsDirectory();
        if (!is_dir($bindDir)) {
            mkdir($bindDir, 0700, true);
        }

        $safeLegacyFile = $bindDir . DIRECTORY_SEPARATOR . 'openid_' . hash('sha256', $safeOpenId) . '.json';
        $missingLegacyFile = $bindDir . DIRECTORY_SEPARATOR . 'openid_' . hash('sha256', $missingUserOpenId) . '.json';
        $conflictLegacyFile = $bindDir . DIRECTORY_SEPARATOR . 'openid_' . hash('sha256', $conflictOpenId) . '.json';
        $conflictScopedFile = $service->getScopedBindingFilePath($conflictOpenId, $targetAppId);
        $safeScopedFile = $service->getScopedBindingFilePath($safeOpenId, $targetAppId);

        $this->cleanupPaths = array_merge($this->cleanupPaths, [
            $safeLegacyFile,
            $missingLegacyFile,
            $conflictLegacyFile,
            $conflictScopedFile,
            $safeScopedFile,
        ]);

        file_put_contents($safeLegacyFile, json_encode([
            'openid'   => $safeOpenId,
            'user_id'  => $existingUserId,
            'username' => "wx_mig_user_{$suffix}",
            'bound_at' => time() - 100,
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($missingLegacyFile, json_encode([
            'openid'   => $missingUserOpenId,
            'user_id'  => 99999999,
            'username' => 'ghost_user',
            'bound_at' => time() - 200,
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($conflictLegacyFile, json_encode([
            'openid'   => $conflictOpenId,
            'user_id'  => $existingUserId,
            'username' => "wx_mig_user_{$suffix}",
            'bound_at' => time() - 300,
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($conflictScopedFile, json_encode([
            'app_id'  => $targetAppId,
            'db_key'  => (string) ($service->inspectLegacyBindings($targetAppId)['db_key'] ?? ''),
            'openid'  => $conflictOpenId,
            'user_id' => $existingUserId + 1,
        ], JSON_UNESCAPED_UNICODE));

        // 1. 测试 --list --json 命令行输出与冲突/缺失分类检测
        $listCmd = $this->app->make(\core\console\WechatMigrateCommand::class);
        $listOutput = new Output('buffer');
        $listExit = $listCmd->run(new Input(['--list', '--json', "--app-id={$targetAppId}"]), $listOutput);
        $this->assertSame(0, $listExit);
        $listReport = json_decode($listOutput->fetch(), true);
        $this->assertIsArray($listReport);
        $byOpenid = [];
        foreach ($listReport['items'] ?? [] as $item) {
            $byOpenid[$item['openid']] = $item;
        }
        $this->assertSame('ready', $byOpenid[$safeOpenId]['status'] ?? null);
        $this->assertSame('missing_user', $byOpenid[$missingUserOpenId]['status'] ?? null);
        $this->assertSame('conflict', $byOpenid[$conflictOpenId]['status'] ?? null);

        // 2. 尝试迁移冲突记录时必须明确拒绝覆盖并保留原目标绑定
        $conflictOutput = new Output('buffer');
        $conflictExit = $listCmd->run(
            new Input(['--confirm', '--json', "--app-id={$targetAppId}", "--openid={$conflictOpenId}"]),
            $conflictOutput
        );
        $this->assertSame(1, $conflictExit, '存在目标冲突的旧绑定必须拒绝覆盖并退出非零状态');
        $stillConflictTarget = json_decode((string) file_get_contents($conflictScopedFile), true);
        $this->assertSame($existingUserId + 1, (int) ($stillConflictTarget['user_id'] ?? 0));

        // 3. 对安全旧绑定执行管理员确认迁移：迁入 app_id + db_key 隔离文件，且后续微信登录直连原账号
        $migrateOutput = new Output('buffer');
        $migrateExit = $listCmd->run(
            new Input(['--confirm', '--json', "--app-id={$targetAppId}", "--openid={$safeOpenId}"]),
            $migrateOutput
        );
        $this->assertSame(0, $migrateExit);
        $this->assertFileExists($safeScopedFile);
        $this->assertFileDoesNotExist($safeLegacyFile);

        $this->setScopedEnv('WECHAT_MINI_APPID', $targetAppId);
        $this->setScopedEnv('WECHAT_MINI_SECRET', 'wx-test-secret-' . $suffix);
        $this->setScopedEnv('WECHAT_MINI_MOCK_CODES', '');
        WechatMiniAuthService::setSessionResolver(fn (string $code): array => [
            'openid'      => $safeOpenId,
            'session_key' => 'session-migrated',
            'unionid'     => '',
        ]);

        $loginRes = $this->guestPost('/api/user/wechatLogin', ['code' => 'code_after_migration']);
        $this->assertApiSuccess($loginRes, '迁移完成后微信登录应无缝衔接原绑定会员');
        $this->assertSame($existingUserId, (int) ($loginRes['data']['user']['id'] ?? 0));
        $this->assertFalse((bool) ($loginRes['data']['is_new'] ?? true), '迁移后绝不能新建替代账号');
    }

    private function findFreeLocalPort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($sock, "无法分配本地临时端口: {$errstr}");
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $parts = explode(':', $name);
        return (int) end($parts);
    }

    private function waitForTcpPort(string $host, int $port): void
    {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(50000);
        }
        $this->fail("本地 Webhook 测试服务器未能及时监听 {$host}:{$port}");
    }

    private function createRecordingMysqlAdapter(?PDO $pdo = null): MysqlAdapter
    {
        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new class(['name' => 'hw_audit', 'table_prefix' => 'hw_'], null, null, $pdo) extends MysqlAdapter {
            /** @var array<int, string> */
            public array $recordedSql = [];

            public function __construct(array $options, ?Input $input = null, ?Output $output = null, ?PDO $mockPdo = null)
            {
                parent::__construct($options, $input, $output);
                $this->connection = $mockPdo;
            }

            public function connect(): void {}
            public function disconnect(): void {}

            public function hasTable(string $tableName): bool
            {
                return $this->hasCreatedTable($tableName);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->recordedSql[] = $sql;
                return 0;
            }
        };
    }

    private function createRecordingPostgresAdapter(?PDO $pdo = null): PostgresAdapter
    {
        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new class(['name' => 'hw_audit', 'table_prefix' => 'hw_', 'schema' => 'public'], null, null, $pdo) extends PostgresAdapter {
            /** @var array<int, string> */
            public array $recordedSql = [];

            public function __construct(array $options, ?Input $input = null, ?Output $output = null, ?PDO $mockPdo = null)
            {
                parent::__construct($options, $input, $output);
                $this->connection = $mockPdo;
            }

            public function connect(): void {}
            public function disconnect(): void {}

            public function hasTable(string $tableName): bool
            {
                return $this->hasCreatedTable($tableName);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->recordedSql[] = $sql;
                return 0;
            }
        };
    }
}
