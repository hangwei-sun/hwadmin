<?php
declare(strict_types=1);

namespace tests\Unit;

use core\console\DoctorCommand;
use core\console\PageCommand;
use core\install\Installer;
use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\DoctorService;
use tests\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class V150DeliveryOptimizationTest extends TestCase
{
    public function testInstallerSeedPreservesMigrationRegisteredBusinessMenus(): void
    {
        $customMenu = [
            'type'     => 'menu',
            'name'     => 'v150_guestbook_custom',
            'title'    => '留言管理',
            'path'     => 'guestbook',
            'component'=> '@/views/guestbook/index.vue',
            'icon'     => 'chat-1-line',
            'weight'   => 99,
            'children' => [
                ['type' => 'button', 'name' => 'v150_guestbook_custom/index', 'title' => '查看'],
            ],
        ];

        MenuSync::ensureNode($customMenu, 0, '');
        (new Installer($this->app))->seed();

        $parent = Db::name('auth_rule')->where('name', 'v150_guestbook_custom')->find();
        $child = Db::name('auth_rule')->where('name', 'v150_guestbook_custom/index')->find();

        $this->assertNotEmpty($parent, 'Installer::seed() 不应删除迁移已注册的业务菜单');
        $this->assertNotEmpty($child, 'Installer::seed() 不应删除迁移已注册的业务按钮权限');
    }

    public function testAppExpressEnabledAndSqliteDefaultUnifiedInConfig(): void
    {
        $this->assertTrue(
            (bool) $this->app->config->get('app.app_express', false),
            'server/config/app.php 应默认开启 app_express => true'
        );

        $sqliteDefault = (string) $this->app->config->get('database.connections.sqlite.database', '');
        $normalized = str_replace('\\', '/', $sqliteDefault);
        $this->assertTrue(
            str_ends_with($normalized, 'runtime/hwadmin.sqlite') || $normalized === HWADMIN_TEST_DB,
            'SQLite 默认路径应统一为 runtime/hwadmin.sqlite'
        );

        $dbConfigRaw = (string) file_get_contents(dirname(__DIR__, 2) . '/config/database.php');
        $this->assertStringContainsString("runtime/hwadmin.sqlite", $dbConfigRaw);
    }

    public function testCrudScaffoldBuilderNormalizesSqliteDefaultsWithoutDoubleQuotes(): void
    {
        $builder = new CrudScaffoldBuilder();

        $this->assertSame('', $builder->normalizeSqlDefault("''", 'string'));
        $this->assertSame('draft', $builder->normalizeSqlDefault("'draft'", 'string'));
        $this->assertSame("it's ok", $builder->normalizeSqlDefault("'it''s ok'", 'string'));
        $this->assertSame(0, $builder->normalizeSqlDefault('0', 'integer'));
        $this->assertNull($builder->normalizeSqlDefault('NULL', 'string'));

        $fieldDetails = [
            'id' => ['name' => 'id', 'type' => 'integer', 'notnull' => true, 'primary' => true],
            'nickname' => ['name' => 'nickname', 'type' => 'varchar(64)', 'notnull' => true, 'default' => "''"],
            'tag' => ['name' => 'tag', 'type' => 'varchar(32)', 'notnull' => true, 'default' => "'guest'"],
            'status' => ['name' => 'status', 'type' => 'integer', 'notnull' => true, 'default' => '1'],
        ];
        $menuNode = [
            'type' => 'menu',
            'name' => 'v150_msg',
            'title' => 'Msg',
            'path' => 'v150_msg',
            'component' => '@/views/v150_msg/index.vue',
            'children' => [],
        ];

        $php = $builder->renderMigration('CreateV150MsgTable', 'v150_msg', 'id', $fieldDetails, $menuNode, false);
        $this->assertStringContainsString("'default' => ''", $php);
        $this->assertStringContainsString("'default' => 'guest'", $php);
        $this->assertStringContainsString("'default' => 1", $php);
        $this->assertStringNotContainsString("'default' => '\\'\\''", $php);
    }

    public function testPublicApiAndForegroundPageScaffoldContracts(): void
    {
        $builder = new CrudScaffoldBuilder();

        $apiCode = $builder->renderPublicApiController(
            'Guestbook',
            'app\\common\\model\\Guestbook',
            'app\\common\\validate\\Guestbook',
            ['id', 'nickname', 'content', 'create_time'],
            ['nickname', 'content'],
            ['nickname', 'content'],
            'id',
            true
        );

        $this->assertStringContainsString("extends ApiController", $apiCode);
        $this->assertStringContainsString("field(\$this->publicFields)", $apiCode);
        $this->assertStringContainsString("@hw-custom-start: api_actions", $apiCode);
        $this->assertStringNotContainsString("'password'", $apiCode);
        $this->assertStringNotContainsString("'token'", $apiCode);

        $routeBefore = "<?php\ndeclare(strict_types=1);\nuse think\\facade\\Route;\nRoute::get('/', 'Index/index');\n// @hw-page-routes-start\n// @hw-page-routes-end\nRoute::any(':entry', 'Index/entry');\n";
        $routeAfter = $builder->buildIndexRouteUpdate($routeBefore, 'guestbook', 'Guestbook');
        $this->assertLessThan(
            strpos($routeAfter, "Route::any(':entry'"),
            strpos($routeAfter, "Route::get('guestbook', 'Guestbook/index');"),
            '自定义单段前台路由必须插入在 :entry 兜底路由之前'
        );

        $viewHtml = $builder->renderForegroundPageView('guestbook', '在线留言板', ['id', 'nickname', 'content'], ['nickname', 'content'], [], 'id', 'dark', true);
        $this->assertStringContainsString('data-theme="dark"', $viewHtml);
        $this->assertStringContainsString('/api/guestbook', $viewHtml);
        $this->assertStringContainsString('@hw-custom-start: page_body', $viewHtml);
    }

    public function testPageCommandPlanOutputsExpectedJson(): void
    {
        $command = new PageCommand();
        $command->setApp($this->app);

        $input = new Input(['--name=v150_demo_page', '--title=DemoPage', '--plan', '--json']);
        $output = new \think\console\Output('buffer');
        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $data = json_decode($output->fetch(), true);
        $this->assertIsArray($data);
        $this->assertSame('ready', $data['status']);
        $this->assertSame('/v150_demo_page', $data['url']);
        $this->assertNotEmpty($data['files_to_create']);
    }

    public function testDoctorServiceDiagnosesDevAndProdModes(): void
    {
        $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hwadmin-doctor-test-' . bin2hex(random_bytes(4));
        @mkdir($tmpRoot . '/config', 0755, true);
        @mkdir($tmpRoot . '/runtime', 0755, true);
        @mkdir($tmpRoot . '/public/uploads', 0755, true);
        @mkdir($tmpRoot . '/public/plugins', 0755, true);
        @mkdir($tmpRoot . '/public/admin', 0755, true);
        @mkdir($tmpRoot . '/database/migrations', 0755, true);

        try {
            file_put_contents($tmpRoot . '/config/app.php', "<?php return ['app_express' => true];\n");
            file_put_contents($tmpRoot . '/public/admin/index.html', "<html></html>");
            file_put_contents($tmpRoot . '/.env', "APP_ENV=local\nAPP_DEBUG=true\nDB_DRIVER=sqlite\nDB_NAME=runtime/hwadmin.sqlite\n");

            $service = new DoctorService($this->app);

            // 1. 生产态应检出 sqlite、APP_DEBUG=true、缺少 install.lock 三项 fail
            $prodFail = $service->diagnose('prod', $tmpRoot);
            $this->assertSame('fail', $prodFail['status']);
            $byId = [];
            foreach ($prodFail['checks'] as $c) {
                $byId[$c['id']] = $c;
            }
            $this->assertSame('fail', $byId['prod_database']['status']);
            $this->assertSame('fail', $byId['app_debug']['status']);
            $this->assertSame('fail', $byId['install_lock']['status']);

            // 2. 修复环境文件后关键阻断项应转为 pass
            file_put_contents($tmpRoot . '/.env', "APP_ENV=production\nAPP_DEBUG=false\nDB_DRIVER=mysql\nDB_HOST=127.0.0.1\nDB_NAME=hwadmin\n");
            file_put_contents($tmpRoot . '/config/install.lock', 'installed');
            $prodFixed = $service->diagnose('prod', $tmpRoot);
            $fixedById = [];
            foreach ($prodFixed['checks'] as $c) {
                $fixedById[$c['id']] = $c;
            }
            $this->assertSame('pass', $fixedById['prod_database']['status']);
            $this->assertSame('pass', $fixedById['app_debug']['status']);
            $this->assertSame('pass', $fixedById['install_lock']['status']);
            $this->assertSame('pass', $fixedById['app_express']['status']);

            // 3. CLI 命令本身 --mode=dev --json 应返回结构化报告
            $cmd = new DoctorCommand();
            $cmd->setApp($this->app);
            $out = new Output('buffer');
            $rc = $cmd->run(new Input(['--mode=dev', '--json']), $out);
            $decoded = json_decode($out->fetch(), true);
            $this->assertSame(0, $rc);
            $this->assertSame('dev', $decoded['mode']);
            $this->assertArrayHasKey('deploy_checklist', $decoded);
        } finally {
            $this->recursiveDelete($tmpRoot);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
