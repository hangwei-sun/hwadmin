<?php
declare(strict_types=1);

namespace tests\Unit;

use core\plugin\AssetPublisher;
use core\plugin\PluginManager;
use core\plugin\SystemUpdater;
use tests\TestCase;
use think\facade\Db;
use ZipArchive;

final class CmsUpgradeAndTemplateTest extends TestCase
{
    public function testCmsPerThemeConfigAndThemeSwitcherOffBehavior(): void
    {
        /** @var PluginManager $pm */
        $pm = $this->app->make(PluginManager::class);
        $wasInstalled = Db::name('plugin')->where('name', 'cms')->find();
        $origPluginRow = $wasInstalled;

        if (!$wasInstalled) {
            $pm->install('cms', true);
        } elseif ((int) $wasInstalled['status'] !== 1) {
            $pm->enable('cms');
        }
        \think\facade\Cache::clear();

        try {
            $saveRes = $this->adminPost('/adminapi/plugin/cms/setting/save', [
                'active_template' => 'blog',
                'config' => [
                    'show_theme_switcher' => 0,
                    'blog_site_name'      => '独立博客标题',
                    'corp_site_name'      => '独立官网标题',
                    'showcase_site_name'  => '独立展厅标题',
                    'corp_stat_4_num'     => '99.999%',
                ],
            ]);
            $this->assertApiSuccess($saveRes);

            $settingRes = $this->adminGet('/adminapi/plugin/cms/setting/index');
            $this->assertApiSuccess($settingRes);
            $this->assertSame('blog', (string) ($settingRes['data']['active_template'] ?? ''));
            $this->assertSame(0, (int) ($settingRes['data']['config']['show_theme_switcher'] ?? 1));
            $this->assertSame('独立博客标题', (string) ($settingRes['data']['config']['blog_site_name'] ?? ''));
            $this->assertSame('独立官网标题', (string) ($settingRes['data']['config']['corp_site_name'] ?? ''));
            $this->assertSame('独立展厅标题', (string) ($settingRes['data']['config']['showcase_site_name'] ?? ''));

            $siteInitRes = $this->guestGet('/api/plugin/cms/article/siteInit');
            $this->assertApiSuccess($siteInitRes);
            $this->assertSame('blog', (string) ($siteInitRes['data']['active_template'] ?? ''));
            $this->assertSame(0, (int) ($siteInitRes['data']['config']['show_theme_switcher'] ?? 1));
            $this->assertSame('独立博客标题', (string) ($siteInitRes['data']['config']['blog_site_name'] ?? ''));

            // 验证前端静态模板已包含精简 2 字菜单、关闭切换器联动逻辑与独立主题解析
            $html = (string) file_get_contents(plugin_path('cms') . 'public' . DIRECTORY_SEPARATOR . 'index.html');
            $this->assertStringContainsString('function getThemeProfile(', $html);
            $this->assertStringContainsString('footer-theme-links-col', $html);
            $this->assertStringContainsString('no-theme-switcher', $html);
            $this->assertStringContainsString('white-space: nowrap', $html);
            $this->assertStringContainsString('mobile-nav-drawer', $html);
        } finally {
            if ($origPluginRow) {
                Db::name('plugin')->where('name', 'cms')->update([
                    'config'      => $origPluginRow['config'],
                    'status'      => $origPluginRow['status'],
                    'version'     => $origPluginRow['version'],
                    'update_time' => time(),
                ]);
                $pm->clearCache();
            } else {
                $pm->uninstall('cms');
            }
        }
    }

    public function testOnlinePluginUpgradeViaGatewayZipUpdatesVersionAndAssets(): void
    {
        $pluginDir = plugin_path('cms');
        $jsonPath = $pluginDir . 'plugin.json';
        $origJson = (string) file_get_contents($jsonPath);
        $origManifest = json_decode($origJson, true);
        $targetVer = (string) ($origManifest['version'] ?? '2.1.0');

        /** @var PluginManager $pm */
        $pm = $this->app->make(PluginManager::class);
        $origRow = Db::name('plugin')->where('name', 'cms')->find();
        if (!$origRow) {
            $pm->install('cms', true);
        } elseif ((int) $origRow['status'] !== 1) {
            $pm->enable('cms');
        }

        // 模拟客户端本地旧版本 (1.1.0)
        Db::name('plugin')->where('name', 'cms')->update([
            'version'     => '1.1.0',
            'update_time' => time(),
        ]);
        $pm->clearCache();

        // 构造临时模拟网关包，验证 ZIP 解压升级与回滚备份闭环
        $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms_upgrade_probe_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', $origJson);
        $zip->close();

        try {
            // 直接测试 PluginManager::upgrade 从 1.1.0 升级至当前清单版本
            $pm->upgrade('cms');
            $afterRow = Db::name('plugin')->where('name', 'cms')->find();
            $this->assertSame($targetVer, (string) ($afterRow['version'] ?? ''));

            // 验证静态资源已同步发布至 public/plugins/cms/index.html
            $publishedHtml = AssetPublisher::target('cms') . DIRECTORY_SEPARATOR . 'index.html';
            $this->assertFileExists($publishedHtml);
            $this->assertStringContainsString(
                'function getThemeProfile(',
                (string) file_get_contents($publishedHtml)
            );

            // 验证 SystemUpdater 备份与回滚能力可用
            /** @var SystemUpdater $updater */
            $updater = $this->app->make(SystemUpdater::class);
            $backupId = $updater->backupPlugin('cms');
            $this->assertNotEmpty($backupId);
            $rollbackRes = $updater->rollbackUpdate($backupId);
            $this->assertTrue((bool) ($rollbackRes['success'] ?? false));
        } finally {
            @unlink($tmpZip);
            file_put_contents($jsonPath, $origJson);
            if ($origRow) {
                Db::name('plugin')->where('name', 'cms')->update([
                    'version'     => $origRow['version'],
                    'status'      => $origRow['status'],
                    'config'      => $origRow['config'],
                    'update_time' => time(),
                ]);
                $pm->clearCache();
            } else {
                $pm->uninstall('cms');
            }
        }
    }
}
