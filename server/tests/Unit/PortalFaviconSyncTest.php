<?php
declare(strict_types=1);

namespace tests\Unit;

use app\index\controller\Index as IndexController;
use core\support\CrudScaffoldBuilder;
use tests\TestCase;

final class PortalFaviconSyncTest extends TestCase
{
    public function testRootFaviconsMatchAdminPublicAssets(): void
    {
        $serverRoot = dirname(__DIR__, 2);
        $rootFaviconIco = $serverRoot . '/public/favicon.ico';
        $rootFaviconSvg = $serverRoot . '/public/favicon.svg';

        $this->assertFileExists($rootFaviconIco);
        $this->assertFileExists($rootFaviconSvg);

        $expectedIcoSha256 = 'fcc07bed0b0756458b135113a2ba1ffbfb730adf6d6f0b74a9e3deddb0b1dbb8';
        $expectedSvgSha256 = '26890e6dba3b95fd1fb39b75140234f883d6ebfa47b19865db152ef2189f708c';

        $this->assertSame($expectedIcoSha256, hash_file('sha256', $rootFaviconIco), '站点根 /favicon.ico 应为 HwAdmin 品牌图标');
        $this->assertSame($expectedSvgSha256, hash_file('sha256', $rootFaviconSvg), '站点根 /favicon.svg 应为 HwAdmin 品牌图标');

        foreach ([
            dirname($serverRoot) . '/admin-ui/public',
            $serverRoot . '/sources/admin-ui/public',
            $serverRoot . '/public/admin',
        ] as $dir) {
            if (is_file($dir . '/favicon.ico')) {
                $this->assertSame(hash_file('sha256', $dir . '/favicon.ico'), hash_file('sha256', $rootFaviconIco));
            }
            if (is_file($dir . '/favicon.svg')) {
                $this->assertSame(hash_file('sha256', $dir . '/favicon.svg'), hash_file('sha256', $rootFaviconSvg));
            }
        }
    }

    public function testPortalReplacesFaviconWithAdminDefaultOrConfiguredValue(): void
    {
        $ctrl = new class extends IndexController {
            public function exposeReplace(string $content, array $configs): string
            {
                return $this->replaceTemplateVars($content, $configs);
            }
        };

        $tpl = '<head><link rel="icon" href="{{site_favicon}}"></head><body>{{site_logo_render}}</body>';

        // 空字符串配置时默认与后台 favicon.svg 一致，不再回落为 empty href
        $defaultHtml = $ctrl->exposeReplace($tpl, [
            'site_name'    => 'HwAdmin',
            'site_favicon' => '',
            'site_logo'    => '',
        ]);
        $this->assertStringContainsString('href="/favicon.svg"', $defaultHtml);

        // 相对上传路径自动补齐前导斜杠，同步支持后台修改后的自定义图标
        $customHtml = $ctrl->exposeReplace($tpl, [
            'site_name'    => '智慧平台',
            'site_favicon' => 'uploads/20261116/brand.png',
            'site_logo'    => 'uploads/20261116/logo.png',
        ]);
        $this->assertStringContainsString('href="/uploads/20261116/brand.png"', $customHtml);
        $this->assertStringContainsString('<img src="/uploads/20261116/logo.png"', $customHtml);

        // 支持外链自定义图标
        $cdnHtml = $ctrl->exposeReplace($tpl, [
            'site_favicon' => 'https://cdn.example.com/icon.ico',
        ]);
        $this->assertStringContainsString('href="https://cdn.example.com/icon.ico"', $cdnHtml);
    }

    public function testScaffoldedFrontendPageIncludesConfigurableFavicon(): void
    {
        $builder = new CrudScaffoldBuilder();
        $viewHtml = $builder->renderForegroundPageView('demo', '示范页面');
        $ctrlPhp = $builder->renderForegroundPageController('demo', 'Demo', '示范页面');

        $this->assertStringContainsString('<link rel="icon" href="{{site_favicon}}" />', $viewHtml);
        $this->assertStringContainsString("'site_favicon'", $ctrlPhp);
        $this->assertStringContainsString("'{{site_favicon}}'", $ctrlPhp);
    }
}
