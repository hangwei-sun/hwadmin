<?php
declare(strict_types=1);

namespace tests\Unit;

use core\console\CrudCommand;
use core\support\CrudScaffoldBuilder;
use core\support\ProjectSchemaService;
use think\console\Input;
use think\console\Output;
use tests\TestCase;

final class V152DevExperienceOptimizationTest extends TestCase
{
    public function testInlineFieldsParserProducesCompleteSchemaAndValidMigration(): void
    {
        $builder = new CrudScaffoldBuilder();
        $parsed = $builder->parseInlineFields(
            'nickname:string(64):昵称,contact:string(100)?:联系方式,bio:?text:个人简介,content:text:留言内容,amount:decimal(10,2):打赏,status:int=1:状态'
        );

        $this->assertSame('id', $parsed['pk']);
        $this->assertSame(
            ['id', 'nickname', 'contact', 'bio', 'content', 'amount', 'status', 'create_time', 'update_time'],
            $parsed['fields']
        );

        $details = $parsed['field_details'];
        $this->assertSame('varchar(64)', $details['nickname']['type']);
        $this->assertSame(64, $details['nickname']['length']);
        $this->assertTrue($details['nickname']['notnull']);
        $this->assertFalse($details['contact']['notnull']);
        $this->assertFalse($details['bio']['notnull']);
        $this->assertSame('text', $details['bio']['type']);
        $this->assertSame('decimal(10,2)', $details['amount']['type']);
        $this->assertSame(10, $details['amount']['precision']);
        $this->assertSame(2, $details['amount']['scale']);
        $this->assertSame(1, $details['status']['default']);

        try {
            $builder->parseInlineFields('bad:unknown_type');
            $this->fail('不支持的类型应抛出 InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('?text', $e->getMessage());
            $this->assertStringContainsString('text?', $e->getMessage());
        }

        $menuNode = ['name' => 'guestbook', 'title' => '留言板', 'children' => []];
        $migCode = $builder->renderMigration('CreateGuestbookTable', 'guestbook', 'id', $details, $menuNode, false);
        $this->assertStringContainsString("->addColumn('nickname', 'string', ['limit' => 64", $migCode);
        $this->assertStringContainsString("->addColumn('contact', 'string', ['limit' => 100, 'null' => true", $migCode);
        $this->assertStringContainsString("->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2", $migCode);
        $this->assertStringContainsString("->addColumn('create_time', 'biginteger'", $migCode);
    }

    public function testPartitionPublicApiFieldsSupportsSubmitOnlyAndPublicOnlyOverridesWithHardSecurityGuard(): void
    {
        $builder = new CrudScaffoldBuilder();
        $fields = ['id', 'nickname', 'contact', 'content', 'reply', 'is_top', 'password', 'admin_id', 'is_admin', 'status', 'create_time'];
        $allowFields = ['nickname', 'contact', 'content', 'reply', 'is_top', 'status'];

        $warnings = [];
        $partitioned = $builder->partitionPublicApiFields(
            $fields,
            $allowFields,
            'id',
            ['id', 'nickname', 'content', 'reply', 'is_top', 'create_time', 'password'],
            ['nickname', 'contact', 'content', 'admin_id', 'is_admin'],
            $warnings
        );

        // 只展不收（reply/is_top 公开显示、拒绝访客提交）；只收不展（contact 访客可提交、不公开显示）
        $this->assertSame(['id', 'nickname', 'content', 'reply', 'is_top', 'create_time'], $partitioned['public_read_fields']);
        $this->assertSame(['nickname', 'contact', 'content'], $partitioned['submit_fields']);
        $this->assertGreaterThanOrEqual(3, count($warnings));

        // 校验 ProjectSchemaService 对 hw.project.json 实体级 public_fields / submit_fields 校验
        $schemaService = new ProjectSchemaService($this->app);
        $validResult = $schemaService->validate([
            'app_id'            => 'guestbook-app',
            'app_name'          => '留言板',
            'app_version'       => '1.0.0',
            'framework_version' => '1.5.4',
            'clients'           => ['admin-pc'],
            'entities'          => [
                'guestbook' => [
                    'table'         => 'guestbook',
                    'title'         => '留言',
                    'fields'        => [
                        ['name' => 'id', 'type' => 'integer', 'primary' => true],
                        ['name' => 'nickname', 'type' => 'string', 'length' => 64],
                        ['name' => 'contact', 'type' => 'string', 'length' => 100, 'nullable' => true],
                        ['name' => 'reply', 'type' => 'text', 'nullable' => true],
                    ],
                    'public_fields' => ['id', 'nickname', 'reply'],
                    'submit_fields' => ['nickname', 'contact'],
                ],
            ],
        ]);
        $this->assertTrue($validResult['valid'], implode('; ', $validResult['errors']));

        $invalidResult = $schemaService->validate([
            'app_id'            => 'guestbook-app',
            'app_name'          => '留言板',
            'app_version'       => '1.0.0',
            'framework_version' => '1.5.4',
            'clients'           => ['admin-pc'],
            'entities'          => [
                'guestbook' => [
                    'table'         => 'guestbook',
                    'title'         => '留言',
                    'fields'        => [
                        ['name' => 'id', 'type' => 'integer', 'primary' => true],
                        ['name' => 'nickname', 'type' => 'string'],
                    ],
                    'public_fields' => ['nonexistent_col'],
                ],
            ],
        ]);
        $this->assertFalse($invalidResult['valid']);
    }

    public function testElementPlusRadioValueAndPortalNavigationHelpers(): void
    {
        $builder = new CrudScaffoldBuilder();

        $vueCode = $builder->renderFrontendView(
            'DemoStatus',
            '状态测试',
            '/@/api/demo_status',
            'id',
            ['id', 'title', 'status', 'create_time'],
            ['title', 'status'],
            [],
            [
                'title'  => ['name' => 'title', 'type' => 'varchar(64)', 'comment' => '标题'],
                'status' => ['name' => 'status', 'type' => 'tinyint', 'comment' => '状态'],
            ]
        );
        $this->assertStringContainsString('<el-radio :value="1">启用</el-radio>', $vueCode);
        $this->assertStringContainsString('<el-radio :value="0">禁用</el-radio>', $vueCode);
        $this->assertStringNotContainsString('<el-radio :label=', $vueCode);

        // 验证内置 admin-ui/src/views 下不存在旧式 <el-radio(-button) :label=/label=" 写法
        $viewsRoot = dirname(__DIR__, 3) . '/admin-ui/src/views';
        if (is_dir($viewsRoot)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsRoot));
            foreach ($iter as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'vue') {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/<el-radio(?:-button)?\b[^>]*>/s', $content, $matches)) {
                    foreach ($matches[0] as $tag) {
                        $this->assertDoesNotMatchRegularExpression(
                            '/(?<=[\s\n]):?label=/',
                            $tag,
                            'Found deprecated el-radio label attribute in ' . $file->getPathname()
                        );
                    }
                }
            }
        }

        // 验证前台骨架页返回首页入口与 buildPortalNavUpdate 幂等性
        $pageView = $builder->renderForegroundPageView('guestbook', '留言板', ['id', 'content'], ['content'], [], 'id', 'light', true);
        $this->assertStringContainsString('<a href="/" class="hw-btn hw-btn-ghost">返回首页</a>', $pageView);

        $portalPath = dirname(__DIR__, 2) . '/app/index/view/portal/index.html';
        $portalHtml = (string) file_get_contents($portalPath);
        $updatedOnce = $builder->buildPortalNavUpdate($portalHtml, 'guestbook', '留言板');
        $this->assertStringContainsString('<a href="/guestbook" class="btn btn-secondary">留言板</a>', $updatedOnce);
        $updatedTwice = $builder->buildPortalNavUpdate($updatedOnce, 'guestbook', '留言板');
        $this->assertSame($updatedOnce, $updatedTwice);
    }

    public function testCrudCommandSkipsFeatureTestWhenBootstrapMissingUnlessExplicit(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/hwadmin-deploy-crud-' . bin2hex(random_bytes(4));
        @mkdir($tmpRoot . '/app/common/model', 0755, true);
        @mkdir($tmpRoot . '/app/common/validate', 0755, true);
        @mkdir($tmpRoot . '/app/adminapi/controller', 0755, true);
        @mkdir($tmpRoot . '/app/api/controller', 0755, true);
        @mkdir($tmpRoot . '/app/index/controller', 0755, true);
        @mkdir($tmpRoot . '/app/index/route', 0755, true);
        @mkdir($tmpRoot . '/database/migrations', 0755, true);

        try {
            // 1. 精简包缺少 tests/bootstrap.php 时，--all --plan 不应计划创建 FeatureTest，且需输出提示
            $cmd = new CrudCommand();
            $cmd->setApp($this->app);
            $cmd->setCustomRootPath($tmpRoot);
            $inputPlan = new Input([
                '--table=quick_note',
                '--fields=nickname:string(64),contact:string(100)?,content:text',
                '--title=随手记',
                '--all',
                '--with-api',
                '--public-fields=id,nickname,content,create_time',
                '--submit-fields=nickname,contact,content',
                '--plan',
                '--json',
            ]);
            $buf1 = new Output('buffer');
            $code1 = $cmd->run($inputPlan, $buf1);
            $raw1 = $buf1->fetch();
            $this->assertSame(0, $code1, $raw1);
            $planJson = json_decode($raw1, true);
            $this->assertIsArray($planJson, $raw1);
            $joinedCreate = implode("\n", $planJson['files_to_create'] ?? []);
            $this->assertStringNotContainsString('CrudTest.php', $joinedCreate);
            $this->assertStringContainsString('tests/bootstrap.php', implode("\n", $planJson['advisories'] ?? []));
            $this->assertStringContainsString('$publicFields=[id, nickname, content, create_time]', implode("\n", $planJson['advisories'] ?? []));

            // 2. 显式传入 --with-test 时仍按指令生成测试骨架并附带引导提示
            $inputExplicit = new Input([
                '--table=quick_note',
                '--fields=nickname:string(64),content:text',
                '--title=随手记',
                '--all',
                '--with-test',
                '--plan',
                '--json',
            ]);
            $buf2 = new Output('buffer');
            $code2 = $cmd->run($inputExplicit, $buf2);
            $raw2 = $buf2->fetch();
            $this->assertSame(0, $code2, $raw2);
            $explicitJson = json_decode($raw2, true);
            $this->assertStringContainsString('QuickNoteCrudTest.php', implode("\n", $explicitJson['files_to_create'] ?? []));
            $this->assertStringContainsString('hwadmin-dev', implode("\n", $explicitJson['advisories'] ?? []));
        } finally {
            $this->deleteTree($tmpRoot);
        }
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
