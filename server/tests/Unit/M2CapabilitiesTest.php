<?php
declare(strict_types=1);

namespace tests\Unit;

use core\console\CrudCommand;
use core\console\InspectCommand;
use core\support\CrudScaffoldBuilder;
use core\support\OpenApiExporter;
use core\support\ProjectSchemaService;
use core\support\RouteInspector;
use tests\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class M2CapabilitiesTest extends TestCase
{
    /**
     * A08: 精确自省与稳定机器协议
     */
    public function testRouteInspectorSchemaAndSourceAnnotations(): void
    {
        $inspector = new RouteInspector($this->app);
        $data = $inspector->inspect(['framework', 'database', 'routes']);

        // 契约元数据
        $this->assertSame('2.0.0', $data['schema_version']);
        $this->assertContains($data['status'], ['complete', 'partial']);
        $this->assertIsArray($data['errors']);
        $this->assertIsArray($data['warnings']);
        $this->assertArrayHasKey('framework', $data['sources']);
        $this->assertArrayHasKey('database', $data['sources']);
        $this->assertArrayHasKey('routes', $data['sources']);

        // 默认不统计行数
        $tables = $data['database']['tables'] ?? [];
        if (!empty($tables)) {
            $this->assertNull($tables[0]['rows']);
        }

        // with_counts 显式统计
        $dataWithCounts = $inspector->inspect(['database'], '', ['with_counts' => true]);
        $tablesWithCounts = $dataWithCounts['database']['tables'] ?? [];
        if (!empty($tablesWithCounts)) {
            $this->assertIsInt($tablesWithCounts[0]['rows']);
        }

        // 接口方法来源标注
        $adminRoutes = $data['routes']['adminapi'] ?? [];
        $this->assertNotEmpty($adminRoutes);
        $firstEp = $adminRoutes[0]['endpoints'][0] ?? null;
        $this->assertNotNull($firstEp);
        $this->assertContains($firstEp['method_source'], ['explicit', 'convention']);
        $this->assertContains($firstEp['login_source'], ['explicit', 'convention']);
    }

    /**
     * A08: 未知 section 报错并以非零退出码返回
     */
    public function testInspectCommandRejectsUnknownSectionWithNonZeroExitCode(): void
    {
        $output = new Output('buffer');
        $command = new InspectCommand();
        $command->setApp($this->app);
        $code = $command->run(new Input(['--json', '--section=unknown_section_probe']), $output);

        $this->assertSame(1, $code);
        $raw = $output->fetch();
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $this->assertSame('degraded', $data['status']);
        $this->assertNotEmpty($data['errors']);
    }

    /**
     * A10: 应用描述文件与需求建模校验
     */
    public function testProjectSchemaServiceValidationAndStatus(): void
    {
        $service = new ProjectSchemaService($this->app);
        $validation = $service->validate();
        $this->assertTrue($validation['valid'], 'hw.project.json 应通过结构合规校验');

        $status = $service->getStatus();
        $this->assertSame('hwadmin-starter', $status['app_id']);
        $this->assertSame('1.0.0', $status['app_version']);
        $this->assertSame((string) config('hwadmin.version', '1.4.0'), $status['framework_version']);
        $this->assertGreaterThan(0, $status['entities_count']);

        $extCheck = $service->checkExternal();
        $this->assertTrue($extCheck['all_required_met']);
        $this->assertGreaterThan(0, $extCheck['total_items']);
    }

    /**
     * A10 & A12: 显式拒绝复合主键
     */
    public function testProjectSchemaServiceRejectsCompositePrimaryKey(): void
    {
        $service = new ProjectSchemaService($this->app);
        $invalidData = [
            'app_id'            => 'composite-test',
            'app_name'          => 'Composite Test',
            'app_version'       => '1.0.0',
            'framework_version' => '1.3.0',
            'clients'           => ['admin-pc'],
            'entities'          => [
                'order_detail' => [
                    'table'  => 'order_detail',
                    'title'  => '订单详情',
                    'fields' => [
                        ['name' => 'order_id', 'type' => 'integer', 'primary' => true],
                        ['name' => 'item_id', 'type' => 'integer', 'primary' => true],
                        ['name' => 'price', 'type' => 'decimal', 'precision' => 10, 'scale' => 2],
                    ],
                ],
            ],
        ];

        $res = $service->validate($invalidData);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('不支持复合主键，HwAdmin 实体仅支持单列主键', implode('; ', $res['errors']));
    }

    /**
     * A11: 增量更新与手写代码保护区测试
     */
    public function testCrudScaffoldBuilderIncrementalCustomSections(): void
    {
        $builder = new CrudScaffoldBuilder();

        $existingFile = <<<PHP
<?php
class TestController {
    /* @hw-custom-start: controller-actions */
    public function customAction() {
        return 'protected custom logic';
    }
    /* @hw-custom-end: controller-actions */
}
PHP;

        $sections = $builder->extractCustomSections($existingFile);
        $this->assertArrayHasKey('controller-actions', $sections);
        $this->assertStringContainsString('protected custom logic', $sections['controller-actions']);

        $newGenerated = <<<PHP
<?php
class TestController {
    /* @hw-custom-start: controller-actions */
    // 默认空占位
    /* @hw-custom-end: controller-actions */
}
PHP;

        $merged = $builder->mergeCustomSections($newGenerated, $sections);
        $this->assertStringContainsString('protected custom logic', $merged);
    }

    /**
     * A11: --plan / --dry-run 不写文件且输出完整计划
     */
    public function testCrudCommandPlanDryRunOutputsPlanWithoutWritingFiles(): void
    {
        $output = new Output('buffer');
        $command = new CrudCommand();
        $command->setApp($this->app);

        $code = $command->run(new Input([
            '--table=demo_item',
            '--plan',
            '--json',
        ]), $output);

        $this->assertSame(0, $code);
        $raw = $output->fetch();
        $plan = json_decode($raw, true);

        $this->assertIsArray($plan);
        $this->assertSame('ready', $plan['status']);
        $this->assertSame('demo_item', $plan['table']);
        $this->assertNotEmpty($plan['files_to_create']);
        $this->assertNotEmpty($plan['pending_business_rules']);

        // 验证文件未被物理写入
        foreach ($plan['files_to_create'] as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    /**
     * A12: OpenAPI 3.0 规范导出验证
     */
    public function testOpenApiExporterProducesValidSpec(): void
    {
        $exporter = new OpenApiExporter($this->app);
        $spec = $exporter->export();

        $this->assertSame('3.0.0', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('ApiResponse', $spec['components']['schemas']);
        $this->assertArrayHasKey('DemoItemItem', $spec['components']['schemas']);
    }

    /**
     * A13: 三个业务起步模板完整性校验
     */
    public function testThreeBusinessTemplatesManifestsAndStructure(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $templatesDir = $projectRoot . DIRECTORY_SEPARATOR . 'templates';

        $templates = ['corporate-site', 'booking-system', 'enterprise-admin'];
        foreach ($templates as $tpl) {
            $dir = $templatesDir . DIRECTORY_SEPARATOR . $tpl;
            $this->assertDirectoryExists($dir);
            $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'template.json');
            $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'verify.sh');

            $manifest = json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'template.json'), true);
            $this->assertIsArray($manifest);
            $this->assertSame($tpl, $manifest['name']);
            $this->assertNotEmpty($manifest['entities']);
            $this->assertNotEmpty($manifest['migrations']);
            $this->assertNotEmpty($manifest['files']);
        }
    }

    /**
     * A14: 移动端可选基础能力与外部配置诊断
     */
    public function testUserApiControllerRegistrationAndWechatDiagnosis(): void
    {
        // 测试账号注册（必须为字母和数字）
        $username = 'm2user' . time();
        $resp = $this->guestPost('/api/user/register', [
            'username' => $username,
            'password' => 'Password123!',
            'nickname' => '测试会员',
        ]);

        $this->assertApiSuccess($resp);
        $this->assertNotEmpty($resp['data']['token'] ?? '');
        $this->assertSame($username, $resp['data']['user']['username'] ?? '');

        // 验证微信登录未配置凭据时返回结构化错误码与待办标识，严禁伪造成功
        $wechatResp = $this->guestPost('/api/user/wechatLogin', [
            'code' => 'mock_wechat_js_code',
        ]);

        $this->assertSame(10001, $wechatResp['code']);
        $this->assertSame('CONFIGURE_WECHAT_CREDENTIALS', $wechatResp['data']['todo'] ?? '');
    }
}
