<?php
declare(strict_types=1);

namespace tests\Unit;

use core\plugin\SystemUpdater;
use tests\TestCase;
use ZipArchive;

final class MarketSourceAndUpdaterTest extends TestCase
{
    public function testDefaultMarketSourcePointsToOfficialHttpsDomain(): void
    {
        $this->assertSame('1.6.7', (string) config('hwadmin.version'));
        $this->assertSame('https://hw.baotoutv.cn', (string) config('hwadmin.market.default_url'));

        $ctrl = (new \ReflectionClass(\app\adminapi\controller\system\Plugin::class))->newInstanceWithoutConstructor();
        $rm = new \ReflectionMethod($ctrl, 'getMarketCandidateBaseUrls');
        $rm->setAccessible(true);
        $candidates = $rm->invoke($ctrl);
        $this->assertContains('https://hw.baotoutv.cn/api/plugin/market/gateway', $candidates);
        foreach ($candidates as $url) {
            $this->assertStringNotContainsString('127.0.0.1', $url);
            $this->assertStringNotContainsString('localhost', $url);
            $this->assertStringNotContainsString('hwmarket-nginx', $url);
        }
    }

    public function testResolvePatchedVersionHonorsPatchedMetadataAndUrlQuery(): void
    {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_ver_' . uniqid() . '.json';
        try {
            file_put_contents($tmpFile, json_encode(['version' => 'v1.5.12'], JSON_UNESCAPED_UNICODE));
            $this->assertSame(
                '1.5.12',
                SystemUpdater::resolvePatchedVersion(
                    $tmpFile,
                    'https://hw.baotoutv.cn/api/plugin/market/gateway/downloadFramework?version=v1.5.11',
                    '1.5.11'
                )
            );

            $this->assertSame(
                '1.5.13',
                SystemUpdater::resolvePatchedVersion(
                    $tmpFile,
                    'https://hw.baotoutv.cn/api/plugin/market/gateway/downloadFramework?version=v1.5.13',
                    '1.5.11'
                )
            );
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testProtectedPatchPathsAreBlockedAndServerPrefixedPatchAppliesCleanly(): void
    {
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('.env'));
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('.env.production'));
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('config/install.lock'));
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('config/database.php'));
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('runtime/hwadmin.sqlite'));
        $this->assertTrue(SystemUpdater::isProtectedPatchPath('public/uploads/2026/a.png'));
        $this->assertFalse(SystemUpdater::isProtectedPatchPath('core/plugin/SystemUpdater.php'));
        $this->assertFalse(SystemUpdater::isProtectedPatchPath('public/admin/index.html'));

        $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_patch_root_' . uniqid() . DIRECTORY_SEPARATOR;
        @mkdir($tmpRoot . 'config', 0755, true);
        @mkdir($tmpRoot . 'core', 0755, true);
        @mkdir($tmpRoot . 'runtime/backups', 0755, true);
        file_put_contents($tmpRoot . '.env', "APP_KEY=keep_me\n");
        file_put_contents($tmpRoot . 'config/install.lock', "locked\n");

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_patch_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('server/core/patch_probe.txt', 'patched-v1.5.12');
        $zip->addFromString('server/version.json', '{"version":"1.5.12"}');
        $zip->addFromString('server/.env', 'OVERWRITTEN');
        $zip->addFromString('server/config/install.lock', 'WIPED');
        $zip->close();

        try {
            $fakeApp = new \think\App(rtrim($tmpRoot, DIRECTORY_SEPARATOR));
            $updater = new SystemUpdater($fakeApp);
            $count = $updater->applyPatchArchive($zipPath);
            $this->assertSame(2, $count);
            $this->assertSame('patched-v1.5.12', (string) file_get_contents($tmpRoot . 'core/patch_probe.txt'));
            $this->assertSame("APP_KEY=keep_me\n", (string) file_get_contents($tmpRoot . '.env'));
            $this->assertSame("locked\n", (string) file_get_contents($tmpRoot . 'config/install.lock'));
        } finally {
            @unlink($zipPath);
            @unlink($tmpRoot . 'core/patch_probe.txt');
            @unlink($tmpRoot . 'version.json');
            @unlink($tmpRoot . '.env');
            @unlink($tmpRoot . 'config/install.lock');
            @rmdir($tmpRoot . 'config');
            @rmdir($tmpRoot . 'core');
            @rmdir($tmpRoot . 'runtime/backups');
            @rmdir($tmpRoot . 'runtime');
            @rmdir($tmpRoot);
        }
    }

    public function testAdaptSqlDialectHandlesInlineIndexesAndUniqueKeys(): void
    {
        $pm = new \core\plugin\PluginManager(app());
        $rm = new \ReflectionMethod($pm, 'adaptSqlDialect');
        $rm->setAccessible(true);

        $mysqlSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `hw_quiz_order` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '单号',
  `player_id` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_order_no` (`order_no`),
  INDEX `idx_player_id` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';
SQL;

        $sqliteSql = (string) $rm->invoke($pm, $mysqlSql, 'sqlite');
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSql);
        $this->assertStringContainsString('CONSTRAINT `uniq_order_no` UNIQUE (`order_no`)', $sqliteSql);
        $this->assertDoesNotMatchRegularExpression('/\bINDEX\b/i', $sqliteSql);
        $this->assertStringNotContainsString('ENGINE=', $sqliteSql);

        $pgsqlSql = (string) $rm->invoke($pm, $mysqlSql, 'pgsql');
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $pgsqlSql);
        $this->assertStringContainsString('CONSTRAINT "uniq_order_no" UNIQUE ("order_no")', $pgsqlSql);
        $this->assertDoesNotMatchRegularExpression('/\bINDEX\b/i', $pgsqlSql);
    }

    public function testExtractPluginPackageSafelySupportsWrappedDirsAndBlocksTraversal(): void
    {
        $updater = $this->app->make(SystemUpdater::class);
        $tmpTarget = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_plug_target_' . uniqid();
        $wrappedZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_plug_wrap_' . uniqid() . '.zip';
        $badZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_plug_bad_' . uniqid() . '.zip';

        $z1 = new ZipArchive();
        $z1->open($wrappedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z1->addFromString('crud/plugin.json', '{"name":"crud","title":"应用开发","version":"1.0.1"}');
        $z1->addFromString('crud/public/admin.html', '<div>ok</div>');
        $z1->close();

        $z2 = new ZipArchive();
        $z2->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z2->addFromString('../evil.php', '<?php echo 1;');
        $z2->close();

        try {
            $updater->extractPluginPackageSafely($wrappedZip, $tmpTarget, true);
            $this->assertFileExists($tmpTarget . DIRECTORY_SEPARATOR . 'plugin.json');
            $this->assertFileExists($tmpTarget . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'admin.html');

            $this->expectException(\core\exception\ApiException::class);
            $this->expectExceptionMessage('非法路径');
            $updater->extractPluginPackageSafely($badZip, $tmpTarget, true);
        } finally {
            @unlink($wrappedZip);
            @unlink($badZip);
            if (is_dir($tmpTarget)) {
                $rm = new \ReflectionMethod($updater, 'removeDirectoryRecursively');
                $rm->setAccessible(true);
                $rm->invoke($updater, $tmpTarget);
            }
        }
    }

    public function testSyncMarketLicensesHandles401WithoutTypeErrorOrAdminLogout(): void
    {
        $ctrl = new class($this->app) extends \app\adminapi\controller\system\Plugin {
            protected function initialize(): void
            {
            }

            protected function getStoredMarketAuth(): array
            {
                return [
                    'bound'       => true,
                    'token'       => 'mkt_expired_test_token',
                    'site_domain' => 'https://example.com',
                ];
            }

            protected function requestMarketGateway(string $endpoint, array $params = [], string $method = 'GET', array $headers = []): array
            {
                return [
                    'code' => 401,
                    'msg'  => '登录态已失效或会话已过期，请重新登录',
                    'data' => ['need_login' => true],
                ];
            }
        };

        try {
            $ctrl->syncMarketLicenses();
            $this->fail('预期应抛出 HttpResponseException');
        } catch (\think\exception\HttpResponseException $e) {
            $resp = $e->getResponse();
            $this->assertSame(200, $resp->getCode(), '市场登录过期不应返回 401/500，以免误登出本地管理员或触发服务器内部错误');
            $payload = json_decode((string) $resp->getContent(), true);
            $this->assertSame(0, (int) ($payload['code'] ?? -1));
            $this->assertSame('登录态已失效或会话已过期，请重新登录', $payload['msg'] ?? '');
            $this->assertTrue((bool) ($payload['data']['need_login'] ?? false));
        }
    }

    public function testClaimLicensePersistsIssuedKeyAndFallbackCatalogIsAligned(): void
    {
        $savedAuth = null;
        $this->app->request->withPost(['plugin_name' => 'oss', 'client_domain' => 'demo.example.com']);
        $ctrl = new class($this->app, $savedAuth) extends \app\adminapi\controller\system\Plugin {
            public $captured;

            public function __construct($app, &$ref)
            {
                parent::__construct($app);
                $this->captured = &$ref;
            }

            protected function initialize(): void
            {
            }

            protected function getStoredMarketAuth(): array
            {
                return [
                    'bound'            => true,
                    'token'            => 'mkt_valid_test_token',
                    'site_domain'      => 'demo.example.com',
                    'licensed_plugins' => [],
                    'license_map'      => [],
                ];
            }

            protected function saveStoredMarketAuth(?array $auth): void
            {
                $this->captured = $auth;
            }

            protected function requestMarketGateway(string $endpoint, array $params = [], string $method = 'GET', array $headers = []): array
            {
                return [
                    'code' => 1,
                    'msg'  => '授权签发成功',
                    'data' => [
                        'plugin_name'   => $params['plugin_name'] ?? 'oss',
                        'license_key'   => 'HWMK-2026-TEST-9988',
                        'client_domain' => $params['client_domain'] ?? 'demo.example.com',
                    ],
                ];
            }
        };

        try {
            $ctrl->claimLicense();
            $this->fail('预期应抛出 HttpResponseException');
        } catch (\think\exception\HttpResponseException $e) {
            $payload = json_decode((string) $e->getResponse()->getContent(), true);
            $this->assertSame(1, (int) ($payload['code'] ?? 0));
            $this->assertSame('HWMK-2026-TEST-9988', $payload['data']['license_key'] ?? '');
            $this->assertContains('oss', $payload['data']['licensed_plugins'] ?? []);
            $this->assertSame('HWMK-2026-TEST-9988', $savedAuth['license_map']['oss'] ?? '');
        }

        $ref = new \ReflectionClass(\app\adminapi\controller\system\Plugin::class);
        $catalogConst = $ref->getConstant('MARKET_CATALOG');
        $byName = array_column(is_array($catalogConst) ? $catalogConst : [], null, 'name');
        $this->assertSame('1.0.2', $byName['oss']['latest_version'] ?? '');
        $this->assertSame('2.1.2', $byName['cms']['latest_version'] ?? '');
        $this->assertSame('1.0.1', $byName['crud']['latest_version'] ?? '');
    }
}
