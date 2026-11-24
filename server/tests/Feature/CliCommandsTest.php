<?php
declare(strict_types=1);

namespace tests\Feature;

use core\console\CrudCommand;
use core\console\CrudScaffoldBuilder;
use core\console\DevTokenCommand;
use core\console\InspectCommand;
use core\console\MenuSyncCommand;
use core\console\TestCommand;
use core\plugin\MenuSync;
use tests\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class CliCommandsTest extends TestCase
{
    public function testInspectCommandOutputsStructuredJson(): void
    {
        $output = new Output('buffer');
        $command = new InspectCommand();
        $command->setApp($this->app);
        $code = $command->run(new Input(['--json', '--section=framework,database,routes,plugins']), $output);

        $this->assertSame(0, $code);
        $raw = $output->fetch();
        $data = json_decode($raw, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('framework', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('routes', $data);
        $this->assertArrayHasKey('plugins', $data);
        $this->assertTrue($data['database']['connected']);
        $this->assertNotEmpty($data['routes']['adminapi']);
    }

    public function testDevTokenCommandIssuesValidAdminTokenInTestingEnv(): void
    {
        $output = new Output('buffer');
        $command = new DevTokenCommand();
        $command->setApp($this->app);
        $code = $command->run(new Input(['--username=admin', '--json']), $output);

        $this->assertSame(0, $code);
        $payload = json_decode($output->fetch(), true);
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['token'] ?? '');

        $me = $this->dispatchHttp('GET', '/adminapi/index/info', [], [], (string) $payload['token']);
        $this->assertApiSuccess($me);
        $this->assertSame('admin', $me['data']['admin']['username'] ?? null);
    }

    public function testMenuSyncCommandRunsCleanly(): void
    {
        $output = new Output('buffer');
        $command = new MenuSyncCommand();
        $command->setApp($this->app);
        $code = $command->run(new Input([]), $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('核心菜单同步完成', $output->fetch());
    }

    public function testCrudCommandAllScaffoldsFullStackWithValidPhpAndVueTemplates(): void
    {
        $table = 'phpunit_note';
        $root = dirname(__DIR__, 2);
        $frontendSrc = (new CrudScaffoldBuilder())->detectFrontendSrcDir($root);
        $this->assertNotNull($frontendSrc, '应自动定位到 admin-ui/src 或 sources/admin-ui/src');
        Db::execute("CREATE TABLE IF NOT EXISTS hw_{$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )");

        $createdFiles = [
            $root . '/app/common/model/PhpunitNote.php',
            $root . '/app/common/validate/PhpunitNote.php',
            $root . '/app/adminapi/controller/probe/PhpunitNote.php',
            $root . '/tests/Feature/PhpunitNoteCrudTest.php',
            $frontendSrc . '/api/probe/phpunit_note.ts',
            $frontendSrc . '/views/probe/phpunit_note/index.vue',
        ];
        $migPattern = $root . '/database/migrations/*_create_' . $table . '_table.php';

        try {
            $output = new Output('buffer');
            $command = new CrudCommand();
            $command->setApp($this->app);
            $code = $command->run(new Input([
                '--table=' . $table,
                '--controller=probe/PhpunitNote',
                '--title=自检笔记',
                '--all',
                '--force',
            ]), $output);

            $this->assertSame(0, $code, 'hw:crud --all failed: ' . $output->fetch());

            $migFiles = glob($migPattern) ?: [];
            $this->assertNotEmpty($migFiles, '应当生成对应的 Phinx 迁移文件');
            $createdFiles = array_merge($createdFiles, $migFiles);

            foreach ($createdFiles as $file) {
                $this->assertFileExists($file);
            }

            // 验证所有生成的 PHP 文件语法均通过 php -l
            foreach ($createdFiles as $file) {
                if (str_ends_with($file, '.php')) {
                    $lintOut = [];
                    $lintCode = 0;
                    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOut, $lintCode);
                    $this->assertSame(0, $lintCode, "生成 PHP 语法检查失败 [{$file}]: " . implode("\n", $lintOut));
                }
            }

            // 验证前端强类型表格骨架（HwTable + useCrudTable）及权限节点同步
            $vueSource = (string) file_get_contents($frontendSrc . '/views/probe/phpunit_note/index.vue');
            $this->assertStringContainsString('<HwTable', $vueSource);
            $this->assertStringContainsString('useCrudTable', $vueSource);

            $rule = Db::name('auth_rule')->where('name', 'probe/phpunit_note')->find();
            $this->assertNotEmpty($rule, '应幂等写入 auth_rule 菜单节点');
        } finally {
            foreach (array_merge($createdFiles, glob($migPattern) ?: []) as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($root . '/app/adminapi/controller/probe');
            @rmdir($frontendSrc . '/api/probe');
            @rmdir($frontendSrc . '/views/probe/phpunit_note');
            @rmdir($frontendSrc . '/views/probe');
            MenuSync::removeNodes([
                'probe',
                'probe/phpunit_note',
                'probe/phpunit_note/index',
                'probe/phpunit_note/read',
                'probe/phpunit_note/add',
                'probe/phpunit_note/edit',
                'probe/phpunit_note/del',
                'probe/phpunit_note/multi',
            ]);
            Db::execute("DROP TABLE IF EXISTS hw_{$table}");
        }
    }

    public function testTestCommandDiagnosesSourceRepoVsReleaseRootAccurately(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_test_diag_' . uniqid();
        $repoServer = $base . DIRECTORY_SEPARATOR . 'repo' . DIRECTORY_SEPARATOR . 'server';
        $repoUi = $base . DIRECTORY_SEPARATOR . 'repo' . DIRECTORY_SEPARATOR . 'admin-ui';
        $releaseRoot = $base . DIRECTORY_SEPARATOR . 'release';
        $releaseUi = $releaseRoot . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'admin-ui';
        @mkdir($repoServer, 0777, true);
        @mkdir($repoUi, 0777, true);
        @mkdir($releaseUi, 0777, true);

        try {
            $repoMsg = TestCommand::diagnoseEnvironment($repoServer);
            $this->assertNotNull($repoMsg);
            $this->assertStringContainsString('server/ 目录', $repoMsg);

            $releaseMsg = TestCommand::diagnoseEnvironment($releaseRoot);
            $this->assertNotNull($releaseMsg);
            $this->assertStringContainsString('当前项目根目录', $releaseMsg);
            $this->assertStringNotContainsString('server/', $releaseMsg);

            @mkdir($releaseRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', 0777, true);
            file_put_contents($releaseRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit', '');
            $missingTestsMsg = TestCommand::diagnoseEnvironment($releaseRoot);
            $this->assertNotNull($missingTestsMsg);
            $this->assertStringContainsString('phpunit.xml', $missingTestsMsg);
        } finally {
            @unlink($releaseRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit');
            @rmdir($releaseRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin');
            @rmdir($releaseRoot . DIRECTORY_SEPARATOR . 'vendor');
            @rmdir($releaseUi);
            @rmdir($releaseRoot . DIRECTORY_SEPARATOR . 'sources');
            @rmdir($releaseRoot);
            @rmdir($repoUi);
            @rmdir($repoServer);
            @rmdir($base . DIRECTORY_SEPARATOR . 'repo');
            @rmdir($base);
        }
    }
}
