<?php
declare(strict_types=1);

namespace tests\Unit;

use app\common\service\BookingService;
use app\common\service\EnterpriseProjectService;
use app\common\service\SiteArticleService;
use core\console\CrudCommand;
use core\console\ProjectCommand;
use core\exception\ApiException;
use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\ProjectSchemaService;
use tests\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * R06 / R07 / R08 与生成器安全撤回边界 独立正反例回归测试套件
 */
final class R06R07R08TemplateCrudAuditTest extends TestCase
{
    /**
     * R06 反例：模板缺少源文件或迁移文件时，apply-template 必须立即报错并返回非零退出码
     */
    public function testR06ApplyTemplateRejectsMissingSourceOrMigrationFiles(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $brokenTplName = '_broken_audit_tpl_' . uniqid();
        $brokenTplDir = $projectRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $brokenTplName;
        @mkdir($brokenTplDir, 0755, true);

        file_put_contents($brokenTplDir . DIRECTORY_SEPARATOR . 'template.json', json_encode([
            'name'       => $brokenTplName,
            'title'      => '损坏模板测试',
            'version'    => '1.0.0',
            'migrations' => ['20990101000001_non_existent_migration.php'],
            'files'      => [
                [
                    'source' => 'backend/non_existent_model.php',
                    'target' => 'server/app/common/model/NonExistentModel.php',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            $output = new Output('buffer');
            $cmd = new ProjectCommand();
            $cmd->setApp($this->app);
            $code = $cmd->run(new Input(['apply-template', $brokenTplName, '--json']), $output);

            $this->assertSame(1, $code, '缺失源文件或迁移文件时退出码必须为 1');
            $res = json_decode($output->fetch(), true);
            $this->assertIsArray($res);
            $this->assertSame('error', $res['status'] ?? '');
            $this->assertNotEmpty($res['missing_sources'] ?? []);
        } finally {
            @unlink($brokenTplDir . DIRECTORY_SEPARATOR . 'template.json');
            @rmdir($brokenTplDir);
        }
    }

    /**
     * R06 正反例：三套模板真实安装（复制迁移 + 建表 + 同步菜单 + 冲突拦截 + 三套完整业务闭环）
     */
    public function testR06ThreeTemplatesFullInstallMigrationConflictAndBusinessClosedLoop(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $serverRoot = dirname(__DIR__, 2);
        $service = new ProjectSchemaService($this->app);
        $cmd = new ProjectCommand();
        $cmd->setApp($this->app);

        $templates = ['corporate-site', 'booking-system', 'enterprise-admin'];
        $projectJsonPath = $projectRoot . DIRECTORY_SEPARATOR . 'hw.project.json';
        $originalProjectJson = is_file($projectJsonPath) ? (string) file_get_contents($projectJsonPath) : null;

        try {
            // 1. 依次应用三套模板并执行迁移（--with-migrate）
            foreach ($templates as $tpl) {
                $out = new Output('buffer');
                $code = $cmd->executeApplyTemplate($tpl, false, true, $out, $service, true, true);
                $rawOut = $out->fetch();
                $this->assertSame(0, $code, "应用模板 [{$tpl}] 失败: " . $rawOut);

                $payload = json_decode($rawOut, true);
                $this->assertSame('success', $payload['status'] ?? '');
                $this->assertNotEmpty($payload['applied_migrations'] ?? [], "模板 [{$tpl}] 必须实际复制迁移文件");
                $this->assertNotEmpty($payload['executed_migrations'] ?? [], "模板 [{$tpl}] 必须执行迁移");
                $this->assertNotEmpty($payload['merged_entities'] ?? [], "模板 [{$tpl}] 必须合并实体定义到 hw.project.json");
                $this->assertNotEmpty($payload['merged_modules'] ?? [], "模板 [{$tpl}] 必须合并模块定义到 hw.project.json");
                $this->assertFileExists($payload['manifest'] ?? '');

                foreach ($payload['applied_migrations'] as $migRel) {
                    $migFull = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migRel);
                    $this->assertFileExists($migFull, "迁移文件必须被复制到 database/migrations: {$migFull}");
                }
            }

            if ($originalProjectJson !== null) {
                $mergedProjectData = json_decode((string) file_get_contents($projectJsonPath), true);
                $this->assertArrayHasKey('booking_order', $mergedProjectData['entities'] ?? []);
                $this->assertArrayHasKey('site_article', $mergedProjectData['entities'] ?? []);
                $this->assertArrayHasKey('enterprise_project', $mergedProjectData['entities'] ?? []);
                $moduleIds = array_column($mergedProjectData['modules'] ?? [], 'id');
                $this->assertContains('corporate-site', $moduleIds);
                $this->assertContains('booking-system', $moduleIds);
                $this->assertContains('enterprise-admin', $moduleIds);
            }

            // 2. 验证冲突保护：手写修改已安装文件后，不传 --force 再次 apply 必须拒绝覆盖并返回 1
            $siteServiceFile = $serverRoot . '/app/common/service/SiteArticleService.php';
            $this->assertFileExists($siteServiceFile);
            $originalContent = (string) file_get_contents($siteServiceFile);
            $tamperedContent = $originalContent . "\n// USER_MANUAL_CUSTOMIZATION_MARKER\n";
            file_put_contents($siteServiceFile, $tamperedContent);

            $conflictOut = new Output('buffer');
            $conflictCode = $cmd->executeApplyTemplate('corporate-site', false, true, $conflictOut, $service, false, false);
            $this->assertSame(1, $conflictCode, '检测到用户修改目标文件且未传 --force 时必须拒绝覆盖');
            $conflictRes = json_decode($conflictOut->fetch(), true);
            $this->assertSame('conflict', $conflictRes['status'] ?? '');
            $this->assertContains('server/app/common/service/SiteArticleService.php', $conflictRes['conflicts'] ?? []);
            $this->assertStringContainsString('USER_MANUAL_CUSTOMIZATION_MARKER', (string) file_get_contents($siteServiceFile));

            // 恢复原文件内容以便后续干净回滚
            file_put_contents($siteServiceFile, $originalContent);

            // 3. 验证三套模板的数据表均已在隔离 SQLite 库中真实创建
            $dbTables = Db::connect()->getTables();
            $expectedTables = [
                'hw_site_category', 'hw_site_article', 'hw_site_inquiry',
                'hw_booking_service', 'hw_booking_slot', 'hw_booking_order',
                'hw_enterprise_project', 'hw_enterprise_task', 'hw_enterprise_attachment',
            ];
            foreach ($expectedTables as $expectedTable) {
                $this->assertContains($expectedTable, $dbTables, "数据表 [{$expectedTable}] 必须真实存在于数据库中");
            }

            // 4. 业务闭环一：corporate-site（栏目、文章/产品独立详情、SEO/Canonical、Sitemap/Robots、询盘与跟进）
            $siteService = new SiteArticleService();
            $catId = (int) Db::name('site_category')->insertGetId([
                'name'        => '核心产品',
                'slug'        => 'products',
                'sort'        => 10,
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $articleId = (int) Db::name('site_article')->insertGetId([
                'category_id'     => $catId,
                'type'            => 'article',
                'title'           => 'HwAdmin 1.4 发布公告',
                'slug'            => 'hwadmin-1-4-release',
                'summary'         => '全栈 AI 原生开发框架发布',
                'content'         => '<p>详细发布内容</p>',
                'seo_title'       => 'HwAdmin 1.4 正式发布 - 官方动态',
                'seo_keywords'    => 'HwAdmin,ThinkPHP8,Vue3',
                'seo_description' => 'HwAdmin 1.4 提供完整模板与生成能力',
                'canonical_url'   => '',
                'status'          => 1,
                'create_time'     => time(),
                'update_time'     => time(),
            ]);
            $productId = (int) Db::name('site_article')->insertGetId([
                'category_id'     => $catId,
                'type'            => 'product',
                'title'           => '企业智能网关 Pro',
                'slug'            => 'smart-gateway-pro',
                'summary'         => '高并发边缘计算网关',
                'content'         => '<p>产品规格参数详情</p>',
                'price'           => '2999.00',
                'seo_title'       => '企业智能网关 Pro - 产品中心',
                'seo_keywords'    => '智能网关,边缘计算',
                'seo_description' => '高并发边缘计算网关产品页',
                'canonical_url'   => 'https://corp.example.com/products/smart-gateway-pro',
                'status'          => 1,
                'create_time'     => time(),
                'update_time'     => time(),
            ]);

            $artDetail = $siteService->getArticleDetail($articleId, 'https://corp.example.com');
            $this->assertSame('HwAdmin 1.4 正式发布 - 官方动态', $artDetail['seo']['title']);
            $this->assertSame('https://corp.example.com/article/hwadmin-1-4-release', $artDetail['seo']['canonical']);
            $this->assertSame(1, $artDetail['views']);

            $prodDetail = $siteService->getProductDetail($productId, 'https://corp.example.com');
            $this->assertSame('https://corp.example.com/products/smart-gateway-pro', $prodDetail['seo']['canonical']);
            $this->assertSame('product', $prodDetail['type']);

            $sitemap = $siteService->buildSitemap('https://corp.example.com');
            $this->assertGreaterThanOrEqual(4, $sitemap['total']);
            $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $sitemap['xml']);
            $this->assertStringContainsString('https://corp.example.com/products/smart-gateway-pro', $sitemap['xml']);

            $robots = $siteService->buildRobots('https://corp.example.com');
            $this->assertStringContainsString('Sitemap: https://corp.example.com/api/site/sitemap', $robots['content']);

            $inquiry = $siteService->submitInquiry([
                'name'      => '李总监',
                'mobile'    => '13900139000',
                'email'     => 'director@example.com',
                'company'   => '远航科技',
                'target_id' => $productId,
                'content'   => '采购 50 台企业智能网关 Pro，请提供报价单',
            ]);
            $this->assertSame('李总监', $inquiry['name']);
            $this->assertSame(0, (int) $inquiry['status']);

            // 5. 业务闭环二：booking-system（服务与时段动态选择、容量并发冲突拦截、跨账号隔离、取消释放配额）
            $bookingMobileVue = (string) file_get_contents($projectRoot . '/templates/booking-system/mobile/pages/booking/index.vue');
            $this->assertStringNotContainsString('slot_id: 1', $bookingMobileVue, '移动端预约页严禁写死 slot_id: 1');
            $this->assertStringContainsString('/booking/slots', $bookingMobileVue);
            $this->assertStringContainsString('/booking/myOrders', $bookingMobileVue);
            $this->assertStringContainsString('/booking/cancel', $bookingMobileVue);

            $bookingService = new BookingService();
            $serviceId = (int) Db::name('booking_service')->insertGetId([
                'title'       => '专家一对一架构咨询',
                'duration'    => 60,
                'price'       => '500.00',
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $slotId = (int) Db::name('booking_slot')->insertGetId([
                'service_id'   => $serviceId,
                'date'         => '2026-11-20',
                'start_time'   => '14:00',
                'end_time'     => '15:00',
                'capacity'     => 1,
                'booked_count' => 0,
                'status'       => 1,
                'create_time'  => time(),
                'update_time'  => time(),
            ]);

            $availSlots = $bookingService->getAvailableSlots($serviceId, '2026-11-20');
            $this->assertCount(1, $availSlots);
            $this->assertSame(1, $availSlots[0]['remaining_capacity']);

            // 用户 101 预约成功（占满容量 1）
            $order1 = $bookingService->createBooking(101, $serviceId, $slotId, '王先生', '13811112222', '准时参加');
            $this->assertSame(BookingService::STATUS_PENDING, (int) $order1['status']);

            // 用户 202 尝试预约已满时段 -> 必须被容量锁拦截
            try {
                $bookingService->createBooking(202, $serviceId, $slotId, '赵女士', '13833334444');
                $this->fail('时段容量已满时再次预约必须抛出异常');
            } catch (ApiException $e) {
                $this->assertStringContainsString('名额已满', $e->getMessage());
            }

            // 用户 202 尝试越权取消用户 101 的预约 -> 必须 403 拦截
            try {
                $bookingService->transition((int) $order1['id'], BookingService::STATUS_CANCELLED, 202);
                $this->fail('跨账号取消他人预约必须抛出 403 异常');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getCode());
            }

            // 查询“我的预约”严格隔离
            $user101Orders = $bookingService->getMemberOrders(101);
            $user202Orders = $bookingService->getMemberOrders(202);
            $this->assertSame(1, $user101Orders['total']);
            $this->assertSame(0, $user202Orders['total']);

            // 用户 101 取消本人预约 -> 释放时段容量
            $cancelled = $bookingService->transition((int) $order1['id'], BookingService::STATUS_CANCELLED, 101);
            $this->assertSame(BookingService::STATUS_CANCELLED, (int) $cancelled['status']);

            // 此时用户 202 可以成功预约该时段
            $order2 = $bookingService->createBooking(202, $serviceId, $slotId, '赵女士', '13833334444');
            $this->assertSame(202, (int) $order2['user_id']);

            // 6. 业务闭环三：enterprise-admin（主从表协同、附件白名单校验、导出与 created_by 数据归属隔离）
            $entService = new EnterpriseProjectService();
            $prjAdmin1 = $entService->createProjectWithTasks(
                ['title' => 'A部门核心改造项目', 'priority' => 4, 'budget' => '120000.00'],
                [
                    ['title' => '完成数据库迁移设计', 'assignee' => '工程师甲', 'deadline' => '2026-11-25'],
                    ['title' => '完成前端类型校验', 'assignee' => '工程师乙', 'deadline' => '2026-11-26'],
                ],
                11,
                [
                    [
                        'file_name' => '架构方案.pdf',
                        'file_url'  => '/uploads/202611/arch.pdf',
                        'file_size' => 204800,
                    ],
                ]
            );
            $this->assertSame(11, (int) $prjAdmin1['created_by']);
            $this->assertCount(2, $prjAdmin1['tasks']);
            $this->assertCount(1, $prjAdmin1['attachments']);

            // 管理员 22 创建自己的项目
            $prjAdmin2 = $entService->createProjectWithTasks(
                ['title' => 'B部门内部运营项目', 'priority' => 2, 'budget' => '30000.00'],
                [['title' => '整理月度报表', 'assignee' => '运营丙', 'deadline' => '2026-11-28']],
                22
            );
            $this->assertSame(22, (int) $prjAdmin2['created_by']);

            // 附件反例：非法后缀 (.php) 必须拦截
            try {
                $entService->addAttachment([
                    'target_type' => 'project',
                    'target_id'   => (int) $prjAdmin1['id'],
                    'file_name'   => 'shell.php',
                    'file_url'    => '/uploads/shell.php',
                    'file_size'   => 512,
                ], 11, false);
                $this->fail('上传 .php 附件必须被安全白名单拦截');
            } catch (ApiException $e) {
                $this->assertStringContainsString('不支持的附件类型', $e->getMessage());
            }

            // 数据归属隔离反例：非超管管理员 22 访问管理员 11 的主从详情或上传附件必须 403 拒绝
            try {
                $entService->getProjectWithTasks((int) $prjAdmin1['id'], 22, false);
                $this->fail('非超管越权读取他人创建的项目详情必须被拦截');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getCode());
            }

            // 数据归属隔离正例：列表与导出按 created_by 严格隔离，超管可见全部
            $listForAdmin11 = $entService->listProjects([], 11, false);
            $listForAdmin22 = $entService->listProjects([], 22, false);
            $listForSuper = $entService->listProjects([], 1, true);
            $this->assertSame(1, $listForAdmin11['total']);
            $this->assertSame('A部门核心改造项目', $listForAdmin11['list'][0]['title']);
            $this->assertSame(1, $listForAdmin22['total']);
            $this->assertSame('B部门内部运营项目', $listForAdmin22['list'][0]['title']);
            $this->assertSame(2, $listForSuper['total']);

            $exportAdmin11 = $entService->exportProjects([], 11, false);
            $this->assertSame(1, $exportAdmin11['total']);
            $this->assertStringContainsString('A部门核心改造项目', $exportAdmin11['csv']);
            $this->assertStringNotContainsString('B部门内部运营项目', $exportAdmin11['csv']);
        } finally {
            foreach (array_reverse($templates) as $tpl) {
                $out = new Output('buffer');
                $cmd->executeRollbackTemplate($tpl, true, true, $out, $service);
            }
            foreach ([
                'site_inquiry', 'site_article', 'site_category',
                'booking_order', 'booking_slot', 'booking_service',
                'enterprise_attachment', 'enterprise_task', 'enterprise_project',
            ] as $tbl) {
                Db::execute("DROP TABLE IF EXISTS hw_{$tbl}");
            }
            @rmdir($serverRoot . '/app/adminapi/controller/site');
            @rmdir($serverRoot . '/app/adminapi/controller/booking');
            @rmdir($serverRoot . '/app/adminapi/controller/enterprise');
            @rmdir($projectRoot . '/admin-ui/src/views/site/article');
            @rmdir($projectRoot . '/admin-ui/src/views/site/inquiry');
            @rmdir($projectRoot . '/admin-ui/src/views/site');
            @rmdir($projectRoot . '/admin-ui/src/views/booking/order');
            @rmdir($projectRoot . '/admin-ui/src/views/booking');
            @rmdir($projectRoot . '/admin-ui/src/views/enterprise/project');
            @rmdir($projectRoot . '/admin-ui/src/views/enterprise');
            @rmdir($projectRoot . '/admin-ui/mobile/src/pages/booking');
            if ($originalProjectJson !== null) {
                file_put_contents($projectJsonPath, $originalProjectJson);
            }
        }
    }

    /**
     * R07 & R08：验证生成页面与三套模板页面的 HwTable cell-* 插槽契约、el-tag 联合类型与按钮权限节点
     */
    public function testR07AndR08FrontendViewSlotsTagTypesAndAuthNodes(): void
    {
        $builder = new CrudScaffoldBuilder();
        $projectRoot = dirname(__DIR__, 3);

        // 1. 验证 demo_item 生成的 Vue 页面使用 #cell-status / #cell-create_time / #cell-update_time 且按钮权限为 demo_item/add|edit|del
        $vueContent = $builder->renderFrontendView(
            'DemoItem',
            '示例条目',
            '/@/api/demo_item',
            'id',
            ['id', 'title', 'status', 'create_time', 'update_time'],
            ['title', 'status'],
            [],
            [
                'id'          => ['type' => 'integer', 'comment' => 'ID'],
                'title'       => ['type' => 'string', 'notnull' => true, 'comment' => '标题'],
                'status'      => ['type' => 'integer', 'comment' => '状态'],
                'create_time' => ['type' => 'biginteger', 'comment' => '创建时间'],
                'update_time' => ['type' => 'biginteger', 'comment' => '更新时间'],
            ],
            'demo_item'
        );

        $this->assertStringContainsString('#cell-status="{ row }"', $vueContent);
        $this->assertStringContainsString('#cell-create_time="{ row }"', $vueContent);
        $this->assertStringContainsString('#cell-update_time="{ row }"', $vueContent);
        $this->assertStringNotContainsString('#status="{ row }"', $vueContent);
        $this->assertStringNotContainsString('#create_time="{ row }"', $vueContent);
        $this->assertStringNotContainsString('#update_time="{ row }"', $vueContent);

        $this->assertStringContainsString("v-auth=\"'demo_item/add'\"", $vueContent);
        $this->assertStringContainsString("v-auth=\"'demo_item/edit'\"", $vueContent);
        $this->assertStringContainsString("v-auth=\"'demo_item/del'\"", $vueContent);
        $this->assertStringNotContainsString("business/id/", $vueContent);

        // 2. 验证三套模板中的所有 PC 页面均使用 #cell-* 插槽且无裸 #status / #priority
        $templateViews = [
            $projectRoot . '/templates/corporate-site/frontend/views/site/article/index.vue',
            $projectRoot . '/templates/corporate-site/frontend/views/site/inquiry/index.vue',
            $projectRoot . '/templates/booking-system/frontend/views/booking/order/index.vue',
            $projectRoot . '/templates/enterprise-admin/frontend/views/enterprise/project/index.vue',
        ];
        foreach ($templateViews as $viewPath) {
            $this->assertFileExists($viewPath);
            $src = (string) file_get_contents($viewPath);
            $this->assertStringContainsString('#cell-status="{ row }"', $src, "模板页面 [{$viewPath}] 必须使用 #cell-status 插槽");
            $this->assertStringNotContainsString('#status="{ row }"', $src);
            $this->assertStringNotContainsString('#priority="{ row }"', $src);
        }

        $bookingOrderVue = (string) file_get_contents($projectRoot . '/templates/booking-system/frontend/views/booking/order/index.vue');
        $this->assertStringContainsString("type ElTagType = 'primary' | 'success' | 'warning' | 'info' | 'danger'", $bookingOrderVue);
    }

    /**
     * R08 正反例：拒绝复合主键与非法字段类型；支持自定义单列字符串主键 record_key 并真实落库建表
     */
    public function testR08CustomStringPrimaryKeyMigrationAndCompositeRejection(): void
    {
        $builder = new CrudScaffoldBuilder();

        // 1. 反例：数组复合主键与逗号分隔复合主键必须抛出异常
        try {
            $builder->renderMigrationFromEntity('CreateCompositeTable', [
                'table'       => 'composite_order',
                'primary_key' => ['order_id', 'item_id'],
                'fields'      => [
                    ['name' => 'order_id', 'type' => 'integer'],
                    ['name' => 'item_id', 'type' => 'integer'],
                ],
            ], ['name' => 'composite_order']);
            $this->fail('数组复合主键必须被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('不支持复合主键', $e->getMessage());
        }

        // 2. 反例：不支持的字段类型必须抛出异常
        try {
            $builder->renderMigrationFromEntity('CreateInvalidTypeTable', [
                'table'       => 'invalid_type_tbl',
                'primary_key' => 'id',
                'fields'      => [
                    ['name' => 'id', 'type' => 'integer', 'primary' => true],
                    ['name' => 'payload', 'type' => 'unsupported_geometry_type'],
                ],
            ], ['name' => 'invalid_type_tbl']);
            $this->fail('不支持的字段类型必须被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('不受支持', $e->getMessage());
        }

        // 3. 正例：自定义单列字符串主键 primary_key="record_key", type="string"
        $table = 'custom_pk_probe';
        $migClass = 'CreateCustomPkProbeTable';
        $migCode = $builder->renderMigrationFromEntity($migClass, [
            'table'       => $table,
            'title'       => '自定义主键探针',
            'primary_key' => 'record_key',
            'fields'      => [
                ['name' => 'record_key', 'type' => 'string', 'length' => 64, 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'length' => 128, 'nullable' => false],
                ['name' => 'status', 'type' => 'integer', 'nullable' => false, 'default' => 1],
            ],
        ], [
            'type'      => 'menu',
            'name'      => 'custom_pk_probe',
            'title'     => '自定义主键探针',
            'path'      => 'custom_pk_probe',
            'component' => '@/views/custom_pk_probe/index.vue',
            'children'  => [],
        ]);

        $this->assertStringContainsString("['id' => false, 'primary_key' => ['record_key']]", $migCode);
        $this->assertStringContainsString("->addColumn('record_key', 'string', ['limit' => 64, 'null' => false])", $migCode);

        // 4. 在隔离 SQLite 空库中真实执行该迁移并验证表结构包含 record_key 主键且无默认 id 列
        $tmpMigFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '20261115999999_create_custom_pk_probe_table.php';
        file_put_contents($tmpMigFile, $migCode);
        try {
            Db::execute("DROP TABLE IF EXISTS hw_{$table}");
            $projectCmd = new ProjectCommand();
            $projectCmd->setApp($this->app);
            $projectCmd->executeSingleMigrationFile($tmpMigFile);

            $columns = Db::query("PRAGMA table_info(hw_{$table})");
            $this->assertNotEmpty($columns, '执行迁移后 hw_custom_pk_probe 表必须真实存在');
            $colMap = [];
            foreach ($columns as $col) {
                $colMap[$col['name']] = $col;
            }
            $this->assertArrayHasKey('record_key', $colMap, '表必须包含自定义主键列 record_key');
            $this->assertSame(1, (int) $colMap['record_key']['pk'], 'record_key 必须为主键列');
            $this->assertArrayNotHasKey('id', $colMap, '自定义主键表不应额外生成默认自增 id 列');

            // 真实插入并按 record_key 查询
            Db::name($table)->insert([
                'record_key' => 'rk_alpha_001',
                'title'      => '首条自定义主键记录',
                'status'     => 1,
            ]);
            $row = Db::name($table)->where('record_key', 'rk_alpha_001')->find();
            $this->assertNotEmpty($row);
            $this->assertSame('首条自定义主键记录', $row['title']);
        } finally {
            @unlink($tmpMigFile);
            Db::execute("DROP TABLE IF EXISTS hw_{$table}");
            MenuSync::removeNodes(['custom_pk_probe']);
        }
    }

    /**
     * 静态复核项：验证生成器 rollback-manifest 的哈希冲突拦截、modified_files 恢复与菜单节点撤销
     */
    public function testCrudRollbackManifestSafetyBoundaryAndMenuCleanup(): void
    {
        $table = 'rollback_guard_probe';
        $serverRoot = dirname(__DIR__, 2);

        Db::execute("CREATE TABLE IF NOT EXISTS hw_{$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(100) NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )");

        $modelFile = $serverRoot . '/app/common/model/RollbackGuardProbe.php';
        $validateFile = $serverRoot . '/app/common/validate/RollbackGuardProbe.php';
        $ctrlFile = $serverRoot . '/app/adminapi/controller/RollbackGuardProbe.php';
        $manifestPath = '';

        try {
            // 预先放一个已有文件以测试 modified_files 备份与恢复（全新开发包默认尚无 app/common/validate/ 目录）
            if (!is_dir(dirname($validateFile))) {
                @mkdir(dirname($validateFile), 0755, true);
            }
            $this->assertNotFalse(file_put_contents($validateFile, "<?php\n// PRE_EXISTING_VALIDATE_CONTENT\n"));

            $cmd = new CrudCommand();
            $cmd->setApp($this->app);
            $genOut = new Output('buffer');
            $code = $cmd->run(new Input([
                '--table=' . $table,
                '--with-menu',
                '--force',
                '--json',
            ]), $genOut);

            $this->assertSame(0, $code);
            $genRes = json_decode($genOut->fetch(), true);
            $manifestPath = (string) ($genRes['manifest'] ?? '');
            $this->assertFileExists($manifestPath);
            $this->assertFileExists($modelFile);
            $this->assertFileExists($ctrlFile);

            // 验证菜单节点已写入 auth_rule
            $menuRow = Db::name('auth_rule')->where('name', 'rollback_guard_probe')->find();
            $this->assertNotEmpty($menuRow, '生成后应存在菜单节点 rollback_guard_probe');

            // 1. 反例：手动修改新建的控制器文件后，不带 --force 执行 --rollback-manifest 必须报错拦截且保留文件
            $originalCtrl = (string) file_get_contents($ctrlFile);
            file_put_contents($ctrlFile, $originalCtrl . "\n// MANUAL_EDIT_AFTER_SCAFFOLD\n");

            $rbConflictOut = new Output('buffer');
            $rbConflictCode = $cmd->run(new Input([
                '--rollback-manifest=' . $manifestPath,
                '--json',
            ]), $rbConflictOut);

            $this->assertSame(1, $rbConflictCode, '生成文件被用户手动修改后，无 --force 的回滚必须返回非零退出码');
            $rbConflictData = json_decode($rbConflictOut->fetch(), true);
            $this->assertSame('conflict', $rbConflictData['status'] ?? '');
            $this->assertFileExists($ctrlFile, '冲突拦截时严禁删除用户已修改的文件');
            $this->assertFileExists($manifestPath, '冲突拦截时清单文件必须保留');

            // 2. 正例：还原手写改动（或使用正常未篡改状态）后再次执行 --rollback-manifest，验证删除新建文件、恢复 modified_files 并清理菜单
            file_put_contents($ctrlFile, $originalCtrl);
            $rbOkOut = new Output('buffer');
            $rbOkCode = $cmd->run(new Input([
                '--rollback-manifest=' . $manifestPath,
                '--json',
            ]), $rbOkOut);

            $rbOkRaw = $rbOkOut->fetch();
            $this->assertSame(0, $rbOkCode, '安全回滚应成功退出 0: ' . $rbOkRaw);
            $rbOkData = json_decode($rbOkRaw, true);
            $this->assertSame('success', $rbOkData['status'] ?? '');

            $this->assertFileDoesNotExist($modelFile, '新建的模型文件应被删除');
            $this->assertFileDoesNotExist($ctrlFile, '新建的控制器文件应被删除');
            $this->assertFileExists($validateFile, '原先已存在的验证器文件应被恢复而非删除');
            $this->assertStringContainsString('PRE_EXISTING_VALIDATE_CONTENT', (string) file_get_contents($validateFile));

            $menuAfter = Db::name('auth_rule')->where('name', 'rollback_guard_probe')->find();
            $this->assertEmpty($menuAfter, '回滚后新增的菜单节点应从 auth_rule 中撤销');
        } finally {
            @unlink($modelFile);
            @unlink($validateFile);
            @unlink($ctrlFile);
            if ($manifestPath !== '' && is_file($manifestPath)) {
                @unlink($manifestPath);
            }
            MenuSync::removeNodes([
                'rollback_guard_probe',
                'rollback_guard_probe/index',
                'rollback_guard_probe/read',
                'rollback_guard_probe/add',
                'rollback_guard_probe/edit',
                'rollback_guard_probe/del',
                'rollback_guard_probe/multi',
            ]);
            Db::execute("DROP TABLE IF EXISTS hw_{$table}");
        }
    }

    /**
     * R08 非超管角色权限实测：生成 demo_item 按钮权限与 auth_rule 对齐，有限权限角色允许 add、拒绝 del (403)
     */
    public function testR08NonSuperAdminButtonPermissionAlignment(): void
    {
        $serverRoot = dirname(__DIR__, 2);
        $projectRoot = dirname(__DIR__, 3);
        $table = 'demo_item';

        Db::execute("CREATE TABLE IF NOT EXISTS hw_{$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(128) NOT NULL DEFAULT '',
            content TEXT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )");

        $cmd = new CrudCommand();
        $cmd->setApp($this->app);
        $manifestPath = '';
        $limitedAdminId = 0;
        $limitedGroupId = 0;

        try {
            $genOut = new Output('buffer');
            $code = $cmd->run(new Input([
                '--from-project=demo_item',
                '--with-ui',
                '--with-menu',
                '--force',
                '--json',
            ]), $genOut);
            $genRaw = $genOut->fetch();
            $this->assertSame(0, $code, '生成 demo_item 失败: ' . $genRaw);
            $genRes = json_decode($genRaw, true);
            $manifestPath = (string) ($genRes['manifest'] ?? '');

            $viewFile = $projectRoot . '/admin-ui/src/views/demo_item/index.vue';
            $this->assertFileExists($viewFile);
            $viewContent = (string) file_get_contents($viewFile);
            $this->assertStringContainsString("v-auth=\"'demo_item/add'\"", $viewContent);
            $this->assertStringContainsString("v-auth=\"'demo_item/edit'\"", $viewContent);
            $this->assertStringContainsString("v-auth=\"'demo_item/del'\"", $viewContent);

            $indexRule = Db::name('auth_rule')->where('name', 'demo_item/index')->find();
            $addRule = Db::name('auth_rule')->where('name', 'demo_item/add')->find();
            $delRule = Db::name('auth_rule')->where('name', 'demo_item/del')->find();
            $this->assertNotEmpty($indexRule);
            $this->assertNotEmpty($addRule);
            $this->assertNotEmpty($delRule);

            // 创建仅拥有 demo_item/index 与 demo_item/add 权限的非超管角色和管理员
            $now = time();
            $limitedGroupId = (int) Db::name('auth_group')->insertGetId([
                'pid'         => 0,
                'name'        => 'DemoItemOperator_' . uniqid(),
                'rules'       => $indexRule['id'] . ',' . $addRule['id'],
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $username = 'limited_demo_' . uniqid();
            $limitedAdminId = (int) Db::name('admin')->insertGetId([
                'username'    => $username,
                'nickname'    => '受限运营员',
                'password'    => password_hash('Pass123456', PASSWORD_BCRYPT),
                'salt'        => '',
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            Db::name('auth_group_access')->insert([
                'admin_id' => $limitedAdminId,
                'group_id' => $limitedGroupId,
            ]);

            $limitedToken = $this->issueAdminToken($username);

            // 正例：受限管理员可访问 demo_item/index 和 demo_item/add
            $listResp = $this->dispatchHttp('GET', '/adminapi/demo_item/index', [], [], $limitedToken);
            $this->assertApiSuccess($listResp);

            $addResp = $this->dispatchHttp('POST', '/adminapi/demo_item/add', [], [
                'title'  => '受限角色新增测试',
                'amount' => 88.50,
                'status' => 1,
            ], $limitedToken);
            $this->assertApiSuccess($addResp);
            $createdId = (int) ($addResp['data']['id'] ?? 0);
            $this->assertGreaterThan(0, $createdId, 'addResp=' . json_encode($addResp, JSON_UNESCAPED_UNICODE));

            // 反例：受限管理员无 demo_item/del 权限，请求必须被 403 拦截
            $delResp = $this->dispatchHttp('POST', '/adminapi/demo_item/del', [], ['ids' => [$createdId]], $limitedToken);
            $this->assertSame(403, $delResp['code'], '未授权 demo_item/del 的非超管请求必须返回 403');
        } finally {
            if ($limitedAdminId > 0) {
                Db::name('auth_group_access')->where('admin_id', $limitedAdminId)->delete();
                Db::name('admin')->where('id', $limitedAdminId)->delete();
            }
            if ($limitedGroupId > 0) {
                Db::name('auth_group')->where('id', $limitedGroupId)->delete();
            }
            if ($manifestPath !== '' && is_file($manifestPath)) {
                $rbOut = new Output('buffer');
                $cmd->run(new Input(['--rollback-manifest=' . $manifestPath, '--force', '--json']), $rbOut);
            }
            @rmdir($projectRoot . '/admin-ui/src/views/demo_item');
            Db::execute("DROP TABLE IF EXISTS hw_{$table}");
        }
    }
}
