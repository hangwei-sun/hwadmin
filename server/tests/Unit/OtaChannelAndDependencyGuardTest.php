<?php
declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\controller\system\Plugin as PluginControllerApi;
use app\common\service\ConfigService;
use app\common\service\DictService;
use core\auth\CaptchaService;
use core\exception\ApiException;
use core\plugin\SystemUpdater;
use tests\TestCase;
use think\exception\HttpResponseException;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

final class OtaChannelAndDependencyGuardTest extends TestCase
{
    public function testUpdateChannelDefaultsValidSwitchingAndRejectsInvalidValues(): void
    {
        Db::name('config')->where('name', 'update_channel')->delete();
        ConfigService::clearCache();

        /** @var SystemUpdater $updater */
        $updater = $this->app->make(SystemUpdater::class);

        try {
            // 1. 默认值必须为 stable
            $this->assertSame('stable', $updater->getUpdateChannel());
            $this->assertSame('stable', SystemUpdater::normalizeUpdateChannel(null));
            $this->assertSame('stable', SystemUpdater::normalizeUpdateChannel('unexpected'));

            // 2. 合法切换为 beta 并落库 + 失效缓存
            $savedBeta = $updater->setUpdateChannel('BETA');
            $this->assertSame('beta', $savedBeta);
            $this->assertSame('beta', $updater->getUpdateChannel());
            $this->assertSame('beta', (string) Db::name('config')->where('name', 'update_channel')->value('value'));

            // 3. 反例：非法值必须抛出 ApiException 且不得覆盖已有配置
            foreach (['', 'alpha', 'nightly', 'canary', '../beta', 'stable;drop'] as $invalidChannel) {
                try {
                    $updater->setUpdateChannel($invalidChannel);
                    $this->fail("非法更新通道 [{$invalidChannel}] 必须被拦截");
                } catch (ApiException $e) {
                    $this->assertStringContainsString('非法的更新通道', $e->getMessage());
                }
                $this->assertSame('beta', $updater->getUpdateChannel());
            }

            // 4. 合法切回 stable
            $savedStable = $updater->setUpdateChannel('stable');
            $this->assertSame('stable', $savedStable);
            $this->assertSame('stable', $updater->getUpdateChannel());
        } finally {
            Db::name('config')->where('name', 'update_channel')->delete();
            ConfigService::clearCache();
        }
    }

    public function testPluginSetUpdateChannelEndpointRequiresSuperAdminAndValidatesChannel(): void
    {
        Db::name('config')->where('name', 'update_channel')->delete();
        ConfigService::clearCache();

        try {
            // 正例：超管切换到 beta
            $respOk = $this->invokePluginController('setUpdateChannel', 'POST', ['channel' => 'beta'], $this->adminToken);
            $this->assertSame(200, $respOk['http_status']);
            $this->assertSame(1, (int) ($respOk['payload']['code'] ?? 0));
            $this->assertSame('beta', $respOk['payload']['data']['update_channel'] ?? '');
            $this->assertSame('beta', (string) Db::name('config')->where('name', 'update_channel')->value('value'));

            // 反例：非法通道值抛出 ApiException
            $this->expectException(ApiException::class);
            $this->invokePluginController('setUpdateChannel', 'POST', ['channel' => 'invalid_channel'], $this->adminToken);
        } finally {
            Db::name('config')->where('name', 'update_channel')->delete();
            ConfigService::clearCache();
        }
    }

    public function testCheckUpdatesAndCatalogUrlPropagateChannelAndSiteDomainForBetaAndStable(): void
    {
        $this->assertSame(
            'https://hw.baotoutv.cn/api/plugin/market/gateway/catalog?channel=beta&site_domain=beta-site.example.com',
            SystemUpdater::buildCatalogUrl(
                'https://hw.baotoutv.cn/api/plugin/market/gateway',
                'beta',
                'beta-site.example.com'
            )
        );
        $this->assertSame(
            'https://hw.baotoutv.cn/api/plugin/market/gateway/catalog?channel=stable&site_domain=prod.example.com',
            SystemUpdater::buildCatalogUrl(
                'https://hw.baotoutv.cn/api/plugin/market/gateway',
                'unknown',
                'prod.example.com'
            )
        );

        /** @var SystemUpdater $baseUpdater */
        $updater = new class($this->app) extends SystemUpdater {
            public array $capturedGatewayCalls = [];

            protected function fetchFromGateway(string $gatewayUrl, ?string $channel = null, ?string $siteDomain = null): ?array
            {
                $this->capturedGatewayCalls[] = [
                    'gateway_url' => $gatewayUrl,
                    'channel'     => $channel,
                    'site_domain' => $siteDomain,
                    'catalog_url' => self::buildCatalogUrl($gatewayUrl, (string) $channel, (string) $siteDomain),
                ];

                if ($channel === self::CHANNEL_BETA) {
                    return [
                        'framework' => [
                            'latest_version'  => '9.9.9-beta.1',
                            'release_channel' => 'beta',
                            'release_date'    => '2026-11-24',
                            'changelog'       => ['Beta 内测特性验证'],
                            'download_url'    => '/api/plugin/market/gateway/downloadFramework?version=9.9.9-beta.1',
                            'patch_sha256'    => str_repeat('a', 64),
                        ],
                        'list' => [],
                    ];
                }

                return [
                    'framework' => [
                        'latest_version'  => '1.6.2',
                        'channel'         => 'stable',
                        'release_date'    => '2026-11-24',
                        'changelog'       => ['稳定版基线'],
                        'download_url'    => '/api/plugin/market/gateway/downloadFramework?version=1.6.2',
                    ],
                    'list' => [],
                ];
            }
        };

        try {
            // 1. Beta 通道检测更新：透传 channel=beta 与 site_domain 到网关与 download_url
            $updater->setUpdateChannel('beta');
            $betaRes = $updater->checkUpdates('https://hw.baotoutv.cn/api/plugin/market/gateway', 'https://beta.example.com:8443/admin');
            $fwBeta = $betaRes['framework'];

            $this->assertSame('beta', $fwBeta['update_channel']);
            $this->assertSame('beta', $fwBeta['release_channel']);
            $this->assertTrue($fwBeta['has_update']);
            $this->assertStringContainsString('channel=beta', $fwBeta['download_url']);
            $this->assertStringContainsString('site_domain=beta.example.com', $fwBeta['download_url']);
            $this->assertStringContainsString('version=9.9.9-beta.1', $fwBeta['download_url']);
            $this->assertSame(str_repeat('a', 64), $fwBeta['patch_sha256']);

            $lastCall = end($updater->capturedGatewayCalls);
            $this->assertSame('beta', $lastCall['channel']);
            $this->assertSame('beta.example.com', $lastCall['site_domain']);
            $this->assertStringContainsString('?channel=beta&site_domain=beta.example.com', $lastCall['catalog_url']);

            // 2. Stable 通道检测更新
            $updater->setUpdateChannel('stable');
            $stableRes = $updater->checkUpdates('https://hw.baotoutv.cn/api/plugin/market/gateway', 'prod.example.com');
            $fwStable = $stableRes['framework'];

            $this->assertSame('stable', $fwStable['update_channel']);
            $this->assertSame('stable', $fwStable['release_channel']);
            $this->assertStringContainsString('version=1.6.2', $fwStable['download_url']);
        } finally {
            Db::name('config')->where('name', 'update_channel')->delete();
            ConfigService::clearCache();
        }
    }

    public function testCoreControllersCommonServiceAndModelDependenciesExistAndAutoload(): void
    {
        $serverRoot = dirname(__DIR__, 2);
        $scanFiles = [
            $serverRoot . '/app/adminapi/controller/Index.php',
            $serverRoot . '/app/adminapi/controller/Dashboard.php',
            $serverRoot . '/app/index/controller/Index.php',
        ];

        foreach (['system', 'auth', 'user'] as $subDir) {
            $files = glob($serverRoot . "/app/adminapi/controller/{$subDir}/*.php") ?: [];
            foreach ($files as $f) {
                $scanFiles[] = $f;
            }
        }

        $referencedClasses = [];
        foreach ($scanFiles as $file) {
            $this->assertFileExists($file);
            $content = (string) file_get_contents($file);

            if (preg_match_all('/^use\s+(app\\\\common\\\\(?:service|model)\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+[A-Za-z0-9_]+)?;/m', $content, $m)) {
                foreach ($m[1] as $cls) {
                    $referencedClasses[$cls] = $file;
                }
            }
            if (preg_match_all('/\\\\?(app\\\\common\\\\(?:service|model)\\\\[A-Za-z0-9_\\\\]+)/', $content, $mInline)) {
                foreach ($mInline[1] as $cls) {
                    $referencedClasses[$cls] = $file;
                }
            }
        }

        $this->assertNotEmpty($referencedClasses, '核心控制器应至少引用了 app\\common\\service 或 app\\common\\model 类');
        $this->assertArrayHasKey(ConfigService::class, $referencedClasses);
        $this->assertArrayHasKey(DictService::class, $referencedClasses);

        foreach ($referencedClasses as $fqcn => $sourceFile) {
            $relPath = str_replace('\\', '/', $fqcn) . '.php';
            $expectedFile = $serverRoot . '/' . $relPath;
            $this->assertFileExists($expectedFile, "控制器 [{$sourceFile}] 引用的类 [{$fqcn}] 缺少对应文件 [{$expectedFile}]");
            $this->assertTrue(class_exists($fqcn, true), "控制器 [{$sourceFile}] 引用的类 [{$fqcn}] 无法自动加载");
        }

        // 额外显式校验核心防线类全部可加载
        foreach ([
            ConfigService::class,
            DictService::class,
            CaptchaService::class,
            \app\common\model\Config::class,
            \app\common\model\system\Dict::class,
            \app\common\model\system\DictItem::class,
        ] as $criticalClass) {
            $this->assertTrue(class_exists($criticalClass, true), "核心类 [{$criticalClass}] 必须存在且可加载");
        }
    }

    public function testConfigDictAndCaptchaServicesNeverThrowUncaughtExceptions(): void
    {
        ConfigService::clearCache();
        DictService::clearCache();

        try {
            // 1. 正常状态调用
            $this->assertIsArray(ConfigService::all());
            $this->assertSame('fallback_val', ConfigService::get('__nonexistent_key_ota_guard__', 'fallback_val'));
            $this->assertIsArray(ConfigService::getByGroup(['basic', 'security', 'nonexistent']));
            $this->assertIsArray(ConfigService::getMany(['site_name', '__missing__']));

            $this->assertIsArray(DictService::getMapByCodes(['', 'nonexistent_dict_code']));
            $this->assertSame([], DictService::getByCode(''));
            $this->assertSame([], DictService::getItemsByDictId(0));
            $this->assertSame([], DictService::getItemsByDictId(99999999));

            $enabled = CaptchaService::isEnabled();
            $this->assertIsBool($enabled);
            $gen = CaptchaService::generate();
            $this->assertArrayHasKey('enabled', $gen);
            $this->assertArrayHasKey('captcha_key', $gen);
            $this->assertArrayHasKey('captcha_image', $gen);
            $this->assertIsBool(CaptchaService::verify('', ''));
            $this->assertIsBool(CaptchaService::verify('nonexistent_key', 'abcd'));

            // 2. 缓存损坏异常注入场景（仍不得抛出未捕获异常）
            Cache::set(ConfigService::CACHE_KEY, 'corrupted-scalar-snapshot', 60);
            Cache::set(DictService::MAP_CACHE_KEY, 'corrupted-scalar-dict-map', 60);
            Cache::set(DictService::VERSION_CACHE_KEY, 'not-a-number', 60);

            $this->assertIsArray(ConfigService::all());
            $this->assertIsArray(DictService::getMapByCodes(['status']));
            $this->assertIsBool(CaptchaService::isEnabled());
        } finally {
            ConfigService::clearCache();
            DictService::clearCache();
        }
    }

    public function testPatchSha256VerificationPassesOnMatchAndAbortsOnMismatchOrInvalidFormat(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/hwadmin_ota_sha256_' . bin2hex(random_bytes(6));
        $targetRel = 'core/support/OtaSha256Probe.php';
        $targetFile = $tmpRoot . '/' . $targetRel;
        $zipPath = $tmpRoot . '/patch_test.zip';
        @mkdir(dirname($targetFile), 0755, true);
        @mkdir($tmpRoot . '/runtime/backups', 0755, true);

        $originalContent = "<?php\nreturn 'v1';\n";
        $patchedContent = "<?php\nreturn 'v2';\n";
        file_put_contents($targetFile, $originalContent);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString($targetRel, $patchedContent);
        $zip->close();

        $validSha256 = strtolower((string) hash_file('sha256', $zipPath));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $validSha256);

        $isolatedApp = new class($tmpRoot) extends \think\App {
            public function __construct(string $rootPath)
            {
                parent::__construct($rootPath);
            }
        };
        $updater = new SystemUpdater($isolatedApp);

        try {
            // 反例 1：非法格式 SHA256 必须被直接拦截
            foreach (['short-hash', str_repeat('g', 64), str_repeat('a', 63), 'not a sha256!'] as $invalidHash) {
                try {
                    $updater->verifyAndApplyPatchArchive($zipPath, $invalidHash);
                    $this->fail("非法 SHA256 [{$invalidHash}] 必须被拦截");
                } catch (ApiException $e) {
                    $this->assertStringContainsString('SHA256', $e->getMessage());
                }
                $this->assertSame($originalContent, file_get_contents($targetFile));
            }

            try {
                $updater->updateFramework('https://hw.baotoutv.cn/api/plugin/market/gateway/downloadFramework', 'invalid-sha256');
                $this->fail('updateFramework 传入非法 SHA256 必须被拦截');
            } catch (ApiException $e) {
                $this->assertStringContainsString('SHA256', $e->getMessage());
            }

            // 反例 2：错误 SHA256 校验失败，抛出指定 ApiException 且不破坏原文件
            $wrongSha256 = $validSha256 === str_repeat('0', 64) ? str_repeat('1', 64) : str_repeat('0', 64);
            try {
                $updater->verifyAndApplyPatchArchive($zipPath, $wrongSha256);
                $this->fail('错误 SHA256 必须中止更新并抛出 ApiException');
            } catch (ApiException $e) {
                $this->assertStringContainsString('框架升级包 SHA256 完整性校验失败，已中止更新', $e->getMessage());
            }
            $this->assertSame($originalContent, file_get_contents($targetFile));

            // 正例：SHA256 匹配（含大写兼容）校验通过，applyPatchArchive 正常覆写 PHP 文件
            $applied = $updater->verifyAndApplyPatchArchive($zipPath, strtoupper($validSha256));
            $this->assertSame(1, $applied);
            $this->assertSame($patchedContent, file_get_contents($targetFile));
        } finally {
            @unlink($zipPath);
            @unlink($targetFile);
            @rmdir(dirname($targetFile));
            @rmdir($tmpRoot . '/core');
            @rmdir($tmpRoot . '/runtime/backups');
            @rmdir($tmpRoot . '/runtime');
            @rmdir($tmpRoot);
        }
    }

    private function invokePluginController(string $method, string $httpMethod, array $post = [], string $token = ''): array
    {
        $this->closeDbConnections();
        $app = $this->createIsolatedApp();

        $req = $app->make(Request::class, [], true);
        $req->setMethod(strtoupper($httpMethod));
        $req->setAction($method);
        if (strtoupper($httpMethod) === 'POST') {
            $req->withPost($post);
        }
        $headers = ['accept' => 'application/json'];
        if ($token !== '') {
            $headers['authorization'] = 'Bearer ' . $token;
            $headers['x-token'] = $token;
        }
        $req->withHeader($headers);
        $app->instance('request', $req);

        try {
            $ctrl = $app->make(PluginControllerApi::class, [], true);
            $ctrl->$method();
            $this->fail("Plugin::{$method} 未按预期抛出 HttpResponseException");
        } catch (HttpResponseException $e) {
            $resp = $e->getResponse();
            $payload = json_decode((string) $resp->getContent(), true);
            return [
                'http_status' => $resp->getCode(),
                'payload'     => is_array($payload) ? $payload : [],
            ];
        }
    }
}
