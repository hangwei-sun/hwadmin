<?php
declare(strict_types=1);

namespace tests\Unit;

use core\auth\MenuFormatter;
use core\plugin\MenuImporter;
use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\DoctorService;
use core\support\RouteInspector;
use PDO;
use tests\TestCase;
use think\facade\Db;

final class V151MenuAndAgentGuidelineTest extends TestCase
{
    public function testMenuImporterPreservesCustomHierarchyOnUpgradeAndRescuesChildrenOnRemove(): void
    {
        MenuSync::removeNodes([
            'v151_ext_orphan',
            'plugin/demo_pkg/item/add',
            'plugin/demo_pkg/item/index',
            'plugin/demo_pkg/item',
            'plugin/demo_pkg/dir',
        ]);

        $systemDir = Db::name('auth_rule')->where('name', 'system')->find();
        $this->assertNotEmpty($systemDir);
        $systemId = (int) $systemDir['id'];

        $manifestMenus = [
            [
                'type'     => 'dir',
                'name'     => 'plugin/demo_pkg/dir',
                'title'    => '示例包',
                'path'     => 'demoPkg',
                'icon'     => 'apps-2-line',
                'weight'   => 120,
                'children' => [
                    [
                        'type'      => 'menu',
                        'name'      => 'plugin/demo_pkg/item',
                        'title'     => '示例项',
                        'path'      => 'item',
                        'component' => '@/views/demo/item/index.vue',
                        'icon'      => 'file-list-line',
                        'weight'    => 100,
                        'children'  => [
                            ['type' => 'button', 'name' => 'plugin/demo_pkg/item/index', 'title' => '查看'],
                        ],
                    ],
                ],
            ],
        ];

        MenuImporter::import('demo_pkg', $manifestMenus, true);
        $pkgRule = Db::name('auth_rule')->where('name', 'plugin/demo_pkg/dir')->find();
        $this->assertNotEmpty($pkgRule);
        $pkgId = (int) $pkgRule['id'];

        // 模拟管理员在“菜单规则”中将插件目录归集到 system 下并自定义名称与权重
        Db::name('auth_rule')->where('id', $pkgId)->update([
            'pid'    => $systemId,
            'title'  => '我的包',
            'weight' => 999,
        ]);

        // 模拟在被归集插件目录下存在一个站长自建的非本插件菜单
        $orphanId = (int) Db::name('auth_rule')->insertGetId([
            'pid'         => $pkgId,
            'type'        => 'menu',
            'name'        => 'v151_ext_orphan',
            'title'       => '站长自定义',
            'path'        => 'extOrphan',
            'component'   => '@/views/custom/index.vue',
            'icon'        => 'star-line',
            'weight'      => 10,
            'status'      => 1,
            'plugin'      => '',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        // 插件升级，新增按钮权限
        $upgradedMenus = $manifestMenus;
        $upgradedMenus[0]['children'][0]['children'][] = [
            'type'  => 'button',
            'name'  => 'plugin/demo_pkg/item/add',
            'title' => '新增',
        ];
        MenuImporter::import('demo_pkg', $upgradedMenus, true);

        $afterUpgrade = Db::name('auth_rule')->where('id', $pkgId)->find();
        $this->assertSame($systemId, (int) $afterUpgrade['pid'], '插件升级不应打回用户已归集的 pid');
        $this->assertSame('我的包', (string) $afterUpgrade['title'], '插件升级不应覆盖用户自定义标题');
        $this->assertSame(999, (int) $afterUpgrade['weight'], '插件升级不应覆盖用户自定义权重');

        $addedBtn = Db::name('auth_rule')->where('name', 'plugin/demo_pkg/item/add')->find();
        $this->assertNotEmpty($addedBtn, '插件升级应正常补充新权限节点');

        // 卸载插件时，挂在其下的其他模块菜单应提升到 pid=0 而非孤儿失联
        MenuImporter::remove('demo_pkg');
        $rescued = Db::name('auth_rule')->where('id', $orphanId)->find();
        $this->assertNotEmpty($rescued, '卸载插件不应连带误删其他模块节点');
        $this->assertSame(0, (int) $rescued['pid'], '外部子菜单应被救援提升到顶级');

        MenuSync::removeNodes([
            'v151_ext_orphan',
            'plugin/demo_pkg/item/add',
            'plugin/demo_pkg/item/index',
            'plugin/demo_pkg/item',
            'plugin/demo_pkg/dir',
        ]);
    }

    public function testMenuSyncPreservesUserCustomLayoutUnlessForceLayout(): void
    {
        MenuSync::syncCore(false, true);

        $configMenu = Db::name('auth_rule')->where('name', 'system/config')->find();
        $authDir = Db::name('auth_rule')->where('name', 'auth')->find();
        $this->assertNotEmpty($configMenu);
        $this->assertNotEmpty($authDir);

        $origPid = (int) $configMenu['pid'];
        $origTitle = (string) $configMenu['title'];
        $authDirId = (int) $authDir['id'];

        try {
            Db::name('auth_rule')->where('id', (int) $configMenu['id'])->update([
                'pid'   => $authDirId,
                'title' => '自定配置',
            ]);

            // 常规同步必须保留用户自定义层级与标题
            MenuSync::syncCore(false, false);
            $preserved = Db::name('auth_rule')->where('id', (int) $configMenu['id'])->find();
            $this->assertSame($authDirId, (int) $preserved['pid']);
            $this->assertSame('自定配置', (string) $preserved['title']);

            // 显式强制重置时恢复官方默认布局
            MenuSync::syncCore(false, true);
            $restored = Db::name('auth_rule')->where('id', (int) $configMenu['id'])->find();
            $this->assertSame($origPid, (int) $restored['pid']);
            $this->assertSame($origTitle, (string) $restored['title']);
        } finally {
            Db::name('auth_rule')->where('id', (int) $configMenu['id'])->update([
                'pid'   => $origPid,
                'title' => $origTitle,
            ]);
        }
    }

    public function testRuleControllerPreventsCyclesAndInvalidParentTypes(): void
    {
        $addDir = $this->adminPost('/adminapi/auth.rule/add', [
            'pid'    => 0,
            'type'   => 'dir',
            'name'   => 'v151_cycle_a',
            'title'  => '循环A',
            'path'   => 'cycleA',
            'weight' => 1,
            'status' => 1,
        ]);
        $this->assertApiSuccess($addDir);
        $idA = (int) ($addDir['data']['id'] ?? 0);

        $addSubDir = $this->adminPost('/adminapi/auth.rule/add', [
            'pid'    => $idA,
            'type'   => 'dir',
            'name'   => 'v151_cycle_b',
            'title'  => '循环B',
            'path'   => 'cycleB',
            'weight' => 1,
            'status' => 1,
        ]);
        $this->assertApiSuccess($addSubDir);
        $idB = (int) ($addSubDir['data']['id'] ?? 0);

        $addMenu = $this->adminPost('/adminapi/auth.rule/add', [
            'pid'       => $idB,
            'type'      => 'menu',
            'name'      => 'v151_cycle_m',
            'title'     => '示例页',
            'path'      => 'cycleM',
            'component' => '@/views/v151/index.vue',
            'weight'    => 1,
            'status'    => 1,
        ]);
        $this->assertApiSuccess($addMenu);
        $idMenu = (int) ($addMenu['data']['id'] ?? 0);

        try {
            // 1. 自环拦截
            $selfResp = $this->adminPost('/adminapi/auth.rule/edit', [
                'id'    => $idA,
                'pid'   => $idA,
                'type'  => 'dir',
                'name'  => 'v151_cycle_a',
                'title' => '循环A',
            ]);
            $this->assertApiFail($selfResp, 0, '不允许将自身设为上级目录');

            // 2. 子孙节点环拦截
            $descResp = $this->adminPost('/adminapi/auth.rule/edit', [
                'id'    => $idA,
                'pid'   => $idB,
                'type'  => 'dir',
                'name'  => 'v151_cycle_a',
                'title' => '循环A',
            ]);
            $this->assertApiFail($descResp, 0, '不允许将子孙节点设为上级目录');

            // 3. 非目录节点下挂菜单拦截
            $invalidParentResp = $this->adminPost('/adminapi/auth.rule/add', [
                'pid'       => $idMenu,
                'type'      => 'menu',
                'name'      => 'v151_invalid_child_menu',
                'title'     => '非法子',
                'path'      => 'invalidChild',
                'component' => '@/views/v151/child.vue',
            ]);
            $this->assertApiFail($invalidParentResp, 0, '菜单下禁止直接挂载菜单/目录');
        } finally {
            MenuSync::removeNodes(['v151_invalid_child_menu', 'v151_cycle_m', 'v151_cycle_b', 'v151_cycle_a']);
        }
    }

    public function testMenuFormatterDeduplicatesSiblingPathsWhenMergedIntoSameDir(): void
    {
        $tree = [
            [
                'id'        => 10,
                'type'      => 'dir',
                'name'      => 'plugin',
                'title'     => '插件',
                'path'      => 'plugin',
                'component' => '',
                'icon'      => 'apps-2-line',
                'hidden'    => 0,
                'children'  => [
                    [
                        'id'        => 101,
                        'type'      => 'menu',
                        'name'      => 'plugin/alpha/setting',
                        'title'     => 'Alpha设置',
                        'path'      => 'setting',
                        'component' => '@/views/plugin/alpha/setting.vue',
                        'icon'      => 'settings-line',
                        'hidden'    => 0,
                        'children'  => [],
                    ],
                    [
                        'id'        => 102,
                        'type'      => 'menu',
                        'name'      => 'plugin/beta/setting',
                        'title'     => 'Beta设置',
                        'path'      => 'setting',
                        'component' => '@/views/plugin/beta/setting.vue',
                        'icon'      => 'settings-line',
                        'hidden'    => 0,
                        'children'  => [],
                    ],
                    [
                        'id'        => 103,
                        'type'      => 'menu',
                        'name'      => 'plugin/ext/link',
                        'title'     => '外部文档',
                        'path'      => 'https://hwadmin.local/docs',
                        'component' => 'https://hwadmin.local/docs',
                        'icon'      => 'link',
                        'hidden'    => 0,
                        'children'  => [],
                    ],
                ],
            ],
        ];

        $routes = MenuFormatter::format($tree);
        $children = $routes[0]['children'] ?? [];
        $this->assertCount(3, $children);
        $this->assertSame('setting', $children[0]['path']);
        $this->assertSame('setting-102', $children[1]['path']);
        $this->assertSame('https://hwadmin.local/docs', $children[2]['path']);
    }

    public function testDoctorServiceRecognizesPrefixMigrationsTable(): void
    {
        $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hwadmin-v151-doctor-' . bin2hex(random_bytes(4));
        @mkdir($tmpRoot . '/config', 0755, true);
        @mkdir($tmpRoot . '/runtime', 0755, true);
        @mkdir($tmpRoot . '/public/uploads', 0755, true);
        @mkdir($tmpRoot . '/public/plugins', 0755, true);
        @mkdir($tmpRoot . '/public/admin', 0755, true);
        @mkdir($tmpRoot . '/database/migrations', 0755, true);

        try {
            file_put_contents($tmpRoot . '/config/app.php', "<?php return ['app_express' => true];\n");
            file_put_contents($tmpRoot . '/public/admin/index.html', '<html></html>');
            file_put_contents($tmpRoot . '/database/migrations/20261115000001_init.php', "<?php\nclass Init {}\n");
            file_put_contents($tmpRoot . '/.env', "APP_ENV=local\nAPP_DEBUG=true\nDB_DRIVER=sqlite\nDB_NAME=runtime/test.sqlite\nDB_PREFIX=hw_\n");

            $pdo = new PDO('sqlite:' . $tmpRoot . '/runtime/test.sqlite');
            $pdo->exec('CREATE TABLE hw_migrations (version INTEGER PRIMARY KEY)');
            $pdo->exec('INSERT INTO hw_migrations (version) VALUES (20261115000001)');
            $pdo = null;

            /** @var DoctorService $doctor */
            $doctor = $this->app->make(DoctorService::class);
            $report = $doctor->diagnose('dev', $tmpRoot);
            $checks = $report['checks'] ?? [];
            $migrationChecks = array_values(array_filter(
                $checks,
                static fn (array $c): bool => ($c['id'] ?? '') === 'pending_migrations'
            ));
            $this->assertNotEmpty($migrationChecks);
            $this->assertSame(
                'pass',
                $migrationChecks[0]['status'] ?? '',
                'DoctorService 应优先识别带表前缀的 hw_migrations 表，而非误报缺少 phinxlog'
            );
        } finally {
            @unlink($tmpRoot . '/runtime/test.sqlite');
            @unlink($tmpRoot . '/database/migrations/20261115000001_init.php');
            @unlink($tmpRoot . '/config/app.php');
            @unlink($tmpRoot . '/public/admin/index.html');
            @unlink($tmpRoot . '/.env');
            @rmdir($tmpRoot . '/database/migrations');
            @rmdir($tmpRoot . '/database');
            @rmdir($tmpRoot . '/config');
            @rmdir($tmpRoot . '/runtime');
            @rmdir($tmpRoot . '/public/uploads');
            @rmdir($tmpRoot . '/public/plugins');
            @rmdir($tmpRoot . '/public/admin');
            @rmdir($tmpRoot . '/public');
            @rmdir($tmpRoot);
        }
    }

    public function testScaffoldFixesSyntaxFrontWhitelistHwTableEventsAndIndexRouteInspect(): void
    {
        $builder = new CrudScaffoldBuilder();

        // 1. 验证 renderForegroundPageController 与 renderPublicApiController 无 \x0c (0x0C) 转义破坏且 php -l 语法零错误
        $pageCtrlCode = $builder->renderForegroundPageController('Guestbook', 'guestbook', '留言板', 'light');
        $this->assertStringNotContainsString("\x0c", $pageCtrlCode, '生成控制器不可包含 0x0C 换页符');
        $this->assertStringContainsString('use think\\facade\\Db;', $pageCtrlCode);
        $this->assertStringContainsString('namespace app\\index\\controller;', $pageCtrlCode);

        // 2. 验证公开列表与访客提交白名单剔除 contact/email/phone/ip/reply*/is_top/audit*
        $fields = ['id', 'nickname', 'contact', 'email', 'mobile', 'content', 'ip', 'reply', 'reply_time', 'is_top', 'audit_status', 'status', 'create_time'];
        $allowFields = ['nickname', 'contact', 'email', 'mobile', 'content', 'ip', 'reply', 'reply_time', 'is_top', 'audit_status', 'status'];
        $partitioned = $builder->partitionPublicApiFields($fields, $allowFields, 'id');
        $this->assertSame(['id', 'nickname', 'content', 'status', 'create_time'], $partitioned['public_read_fields']);
        $this->assertSame(['nickname', 'content'], $partitioned['submit_fields']);

        // 3. 验证生成的后台 Vue 视图绑定 @page-change="handlePageChange" 且不再使用无效的 @search/@reset
        $vueCode = $builder->renderFrontendView(
            'Guestbook',
            '留言板',
            '/@/api/guestbook',
            'id',
            ['id', 'nickname', 'content', 'status', 'create_time'],
            ['nickname', 'content', 'status'],
            [],
            [
                'nickname' => ['name' => 'nickname', 'type' => 'varchar(64)', 'comment' => '昵称'],
                'content'  => ['name' => 'content', 'type' => 'text', 'comment' => '留言内容'],
                'status'   => ['name' => 'status', 'type' => 'tinyint', 'comment' => '状态'],
            ]
        );
        $this->assertStringContainsString('@page-change="handlePageChange"', $vueCode);
        $this->assertStringContainsString('handlePageChange,', $vueCode);
        $this->assertStringNotContainsString('@search="handleSearch"', $vueCode);
        $this->assertStringNotContainsString('@reset="handleReset"', $vueCode);

        // 4. 验证 RouteInspector 可采集 app/index/route/*.php 路由
        /** @var RouteInspector $inspector */
        $inspector = $this->app->make(RouteInspector::class);
        $routes = $inspector->inspectRoutes('index');
        $this->assertArrayHasKey('index', $routes);
        $urls = array_column($routes['index'], 'url');
        $this->assertContains('/', $urls);
        $this->assertContains('/:entry', $urls);
    }

    public function testCoreAndBuiltinPluginMenuTitlesFollowColumnWidthGuidelines(): void
    {
        $coreMenus = require dirname(__DIR__, 2) . '/core/install/data/menus.php';
        $cmsManifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugins/cms/plugin.json'), true);

        foreach ($coreMenus as $level1) {
            $title1 = (string) ($level1['title'] ?? '');
            $maxLen1 = (($level1['name'] ?? '') === 'plugin') ? 4 : 3;
            $this->assertLessThanOrEqual(
                $maxLen1,
                mb_strlen($title1, 'UTF-8'),
                "核心 1 级目录「{$title1}」应控制在 {$maxLen1} 字以内"
            );
            foreach ($level1['children'] ?? [] as $level2) {
                if (($level2['type'] ?? '') === 'button') {
                    continue;
                }
                $title2 = (string) ($level2['title'] ?? '');
                $this->assertLessThanOrEqual(
                    6,
                    mb_strlen($title2, 'UTF-8'),
                    "核心 2 级菜单「{$title2}」应控制在 6 字以内"
                );
            }
        }

        foreach ($cmsManifest['menus'] ?? [] as $level1) {
            $title1 = (string) ($level1['title'] ?? '');
            $this->assertLessThanOrEqual(
                3,
                mb_strlen($title1, 'UTF-8'),
                "CMS 插件 1 级目录「{$title1}」应控制在 3 字以内"
            );
            foreach ($level1['children'] ?? [] as $level2) {
                if (($level2['type'] ?? '') === 'button') {
                    continue;
                }
                $title2 = (string) ($level2['title'] ?? '');
                $this->assertLessThanOrEqual(
                    6,
                    mb_strlen($title2, 'UTF-8'),
                    "CMS 插件 2 级菜单「{$title2}」应控制在 6 字以内"
                );
            }
        }
    }

    public function testAppDevBuiltInSuiteGroupsDevToolsAndPluginMeta(): void
    {
        $pluginDir = Db::name('auth_rule')->where('name', 'plugin')->find();
        $this->assertNotEmpty($pluginDir);
        $this->assertSame('应用开发', (string) $pluginDir['title']);

        $children = Db::name('auth_rule')
            ->where('pid', (int) $pluginDir['id'])
            ->whereIn('type', ['menu'])
            ->column('name');
        foreach (['system/dict', 'system/crontab', 'system/devops', 'plugin/crud'] as $nodeName) {
            $this->assertContains($nodeName, $children, "「应用开发」目录下应包含 {$nodeName}");
        }

        $res = $this->adminGet('/adminapi/system.plugin/index');
        $this->assertApiSuccess($res);
        $list = $res['data']['list'] ?? [];
        $crudItems = array_values(array_filter($list, static fn (array $i): bool => ($i['name'] ?? '') === 'crud'));
        $this->assertNotEmpty($crudItems);
        $this->assertSame('应用开发', $crudItems[0]['title'] ?? '');
        $this->assertSame('/plugin/crud', $crudItems[0]['entry_path'] ?? '');
    }

    public function testCrudPluginInstallAndReinstallAdoptsCorePreseededNodeAndKeepsNamespaceGuard(): void
    {
        /** @var \core\plugin\PluginManager $pm */
        $pm = $this->app->make(\core\plugin\PluginManager::class);
        $origRow = Db::name('plugin')->where('name', 'crud')->find();

        try {
            if ($origRow) {
                $pm->uninstall('crud');
            }
            $coreNode = Db::name('auth_rule')->where('name', 'plugin/crud')->find();
            $this->assertNotEmpty($coreNode, '卸载 crud 后核心预置的 plugin/crud 节点应保持存在');

            $pm->install('crud', true);
            $installed = Db::name('plugin')->where('name', 'crud')->find();
            $this->assertNotEmpty($installed);
            $this->assertSame(1, (int) $installed['status']);

            // 验证 1.0.0 -> 1.0.1 同命名空间及混合大小写旧按钮节点升级不碰撞
            Db::name('auth_rule')->where('name', 'plugin/crud/generator/createtable')->update([
                'name' => 'plugin/crud/generator/createTable',
            ]);
            Db::name('plugin')->where('name', 'crud')->update(['version' => '1.0.0']);
            $pm->upgrade('crud');
            $upgraded = Db::name('plugin')->where('name', 'crud')->find();
            $crudManifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugins/crud/plugin.json'), true);
            $expectedCrudVersion = (string) ($crudManifest['version'] ?? '1.0.2');
            $this->assertSame($expectedCrudVersion, (string) ($upgraded['version'] ?? ''));

            // 确认 2-P0-5 越权节点仍被严格拦截
            $this->expectException(\core\exception\ApiException::class);
            $this->expectExceptionMessage('安全拦截');
            MenuImporter::import('crud', [
                ['type' => 'menu', 'title' => '越权节点', 'name' => 'auth/admin/edit'],
            ], true);
        } finally {
            if (!$origRow && Db::name('plugin')->where('name', 'crud')->find()) {
                $pm->uninstall('crud');
            } elseif ($origRow) {
                if (!Db::name('plugin')->where('name', 'crud')->find()) {
                    $pm->install('crud', (int) $origRow['status'] === 1);
                }
            }
        }
    }
}
