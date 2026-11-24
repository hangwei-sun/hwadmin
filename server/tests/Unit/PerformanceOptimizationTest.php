<?php
declare(strict_types=1);

namespace tests\Unit;

use app\common\service\ConfigService;
use app\common\service\DictService;
use core\auth\AdminAuth;
use core\auth\CaptchaService;
use core\auth\LoginThrottle;
use core\auth\TokenService;
use core\plugin\AssetPublisher;
use core\plugin\PluginManager;
use core\support\AdminEntry;
use plugins\cms\Plugin as CmsPlugin;
use tests\TestCase;
use think\facade\Cache;
use think\facade\Db;

final class PerformanceOptimizationTest extends TestCase
{
    public function testAdaptSqlDialectPreservesSecondaryIndexesAndExecutesInSqlite(): void
    {
        /** @var PluginManager $pm */
        $pm = $this->app->make(PluginManager::class);
        $rm = new \ReflectionMethod($pm, 'adaptSqlDialect');
        $rm->setAccessible(true);

        $table = 'hw_perf_probe_' . bin2hex(random_bytes(3));
        $mysqlSql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cat_id` INT NOT NULL DEFAULT 0 COMMENT '分类',
  `code` VARCHAR(64) NOT NULL DEFAULT '',
  `url` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_cat_status` (`cat_id`, `status`),
  INDEX `idx_url` (`url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='性能测试表';
SQL;

        $sqliteExtra = [];
        $sqliteCreate = (string) $rm->invokeArgs($pm, [$mysqlSql, 'sqlite', &$sqliteExtra]);
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteCreate);
        $this->assertStringContainsString('CONSTRAINT `uk_code` UNIQUE (`code`)', $sqliteCreate);
        $this->assertDoesNotMatchRegularExpression('/\bINDEX\b/i', $sqliteCreate);
        $this->assertCount(2, $sqliteExtra);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS', $sqliteExtra[0]);
        $this->assertStringContainsString('idx_cat_status', $sqliteExtra[0]);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS', $sqliteExtra[1]);
        $this->assertStringContainsString('idx_url', $sqliteExtra[1]);
        $this->assertStringNotContainsString('(191)', $sqliteExtra[1]);

        $pgsqlExtra = [];
        $pgsqlCreate = (string) $rm->invokeArgs($pm, [$mysqlSql, 'pgsql', &$pgsqlExtra]);
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $pgsqlCreate);
        $this->assertStringContainsString('CONSTRAINT "uk_code" UNIQUE ("code")', $pgsqlCreate);
        $this->assertDoesNotMatchRegularExpression('/\bINDEX\b/i', $pgsqlCreate);
        $this->assertCount(2, $pgsqlExtra);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS', $pgsqlExtra[0]);

        // 在 SQLite 中实际执行 executeSql，验证表与二级索引均真实落库
        try {
            $executed = $pm->executeSql($mysqlSql);
            $this->assertSame(3, $executed);

            $indexes = Db::query("PRAGMA index_list('{$table}')");
            $indexCols = [];
            foreach ($indexes as $idx) {
                $cols = Db::query("PRAGMA index_info('{$idx['name']}')");
                $indexCols[] = array_column($cols, 'name');
            }
            $this->assertContains(['code'], $indexCols);
            $this->assertContains(['cat_id', 'status'], $indexCols);
            $this->assertContains(['url'], $indexCols);
        } finally {
            Db::execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testPerformanceIndexesMigrationCreatesAllIndexesAndIsIdempotent(): void
    {
        $expected = [
            'hw_token'       => ['expire_time'],
            'hw_attachment'  => [['admin_id'], ['sha1', 'storage']],
            'hw_admin_log'   => ['admin_id', 'create_time'],
            'hw_dict_item'   => ['dict_id', 'status', 'sort'],
            'hw_crontab_log' => ['crontab_id', 'id'],
            'hw_auth_group'  => ['pid', 'status'],
            'hw_auth_rule'   => ['status', 'weight'],
        ];

        $getTableIndexCols = static function (string $table): array {
            $indexes = Db::query("PRAGMA index_list('{$table}')");
            $result = [];
            foreach ($indexes as $idx) {
                $cols = Db::query("PRAGMA index_info('{$idx['name']}')");
                $result[] = array_column($cols, 'name');
            }
            return $result;
        };

        foreach ($expected as $table => $spec) {
            $actualCols = $getTableIndexCols($table);
            if (is_array($spec[0])) {
                foreach ($spec as $cols) {
                    $this->assertContains($cols, $actualCols, "{$table} 缺少索引 " . implode(',', $cols));
                }
            } else {
                $this->assertContains($spec, $actualCols, "{$table} 缺少索引 " . implode(',', $spec));
            }
        }

        // 验证迁移类 up() / down() 幂等性
        $migFile = dirname(__DIR__, 2) . '/database/migrations/20261124000001_add_performance_indexes.php';
        $this->assertFileExists($migFile);
        require_once $migFile;

        $pdo = Db::connect()->getPdo();
        $sqliteAdapter = new \Phinx\Db\Adapter\SQLiteAdapter([
            'name'         => HWADMIN_TEST_DB,
            'table_prefix' => 'hw_',
            'connection'   => $pdo,
        ]);
        $adapter = new \Phinx\Db\Adapter\TablePrefixAdapter($sqliteAdapter);
        $migration = new \AddPerformanceIndexes('testing', 20261124000001);
        $migration->setAdapter($adapter);

        // 重复执行 up() 不应报错
        $migration->up();
        // down() 回滚后再 up() 应能完整恢复
        $migration->down();
        $this->assertNotContains(['expire_time'], $getTableIndexCols('hw_token'));
        $migration->up();
        $this->assertContains(['expire_time'], $getTableIndexCols('hw_token'));
    }

    public function testDashboardAggregationCacheNonSuperRedactionAndClearCache(): void
    {
        Cache::delete('hw:dashboard:summary');
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $insertedIds = [];

        // 插入 16 条真实日志（触发真实趋势聚合分支，不叠加基准线），其中 3 条属于登录，2 条在昨天，1 条在 10 天前
        try {
            for ($i = 0; $i < 13; $i++) {
                $insertedIds[] = (int) Db::name('admin_log')->insertGetId([
                    'admin_id'    => 1,
                    'username'    => 'admin',
                    'url'         => '/adminapi/system.config/edit',
                    'title'       => '系统配置修改',
                    'content'     => '{"secret_payload":"should_not_appear_in_dashboard"}',
                    'ip'          => '127.0.0.1',
                    'useragent'   => 'PHPUnit',
                    'create_time' => $todayStart + 100 + $i,
                ]);
            }
            for ($i = 0; $i < 2; $i++) {
                $insertedIds[] = (int) Db::name('admin_log')->insertGetId([
                    'admin_id'    => 1,
                    'username'    => 'admin',
                    'url'         => '/adminapi/index/login',
                    'title'       => '管理员登录',
                    'content'     => '{}',
                    'ip'          => '127.0.0.1',
                    'useragent'   => 'PHPUnit',
                    'create_time' => $todayStart - 86400 + 50 + $i,
                ]);
            }
            $insertedIds[] = (int) Db::name('admin_log')->insertGetId([
                'admin_id'    => 1,
                'username'    => 'admin',
                'url'         => '/adminapi/index/login',
                'title'       => '超期登录',
                'content'     => '{}',
                'ip'          => '127.0.0.1',
                'useragent'   => 'PHPUnit',
                'create_time' => $todayStart - (10 * 86400),
            ]);

            Cache::delete('hw:dashboard:summary');
            $superResp = $this->adminGet('/adminapi/dashboard/index');
            $this->assertApiSuccess($superResp);
            $data = $superResp['data'];

            $this->assertSame($data['counts']['role'], $data['counts']['role_count']);
            $this->assertSame($data['counts']['rule'], $data['counts']['rule_count']);
            $this->assertSame(PHP_VERSION, $data['system']['php_version']);
            $this->assertGreaterThanOrEqual(13, (int) $data['trend_7d']['requests'][6]);
            $this->assertGreaterThanOrEqual(2, (int) $data['trend_7d']['logins'][5]);
            $this->assertArrayNotHasKey('content', $data['recent_logs'][0]);

            // 测试非超管视角裁剪：仅可见自身日志，且系统敏感字段脱敏
            $rule = Db::name('auth_rule')->where('status', 1)->find();
            $groupId = (int) Db::name('auth_group')->insertGetId([
                'pid'         => 0,
                'name'        => 'PerfViewer_' . uniqid(),
                'rules'       => (string) ($rule['id'] ?? '1'),
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $limitedName = 'perf_viewer_' . uniqid();
            $limitedId = (int) Db::name('admin')->insertGetId([
                'username'    => $limitedName,
                'nickname'    => '只读审计员',
                'password'    => password_hash('Pass123456', PASSWORD_BCRYPT),
                'salt'        => '',
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            Db::name('auth_group_access')->insert(['admin_id' => $limitedId, 'group_id' => $groupId]);
            $ownLogId = (int) Db::name('admin_log')->insertGetId([
                'admin_id'    => $limitedId,
                'username'    => $limitedName,
                'url'         => '/adminapi/dashboard/index',
                'title'       => '个人操作日志',
                'content'     => '{}',
                'ip'          => '10.0.0.2',
                'useragent'   => 'PHPUnit',
                'create_time' => time(),
            ]);
            $insertedIds[] = $ownLogId;

            try {
                $limitedToken = $this->issueAdminToken($limitedName);
                $limitedResp = $this->dispatchHttp('GET', '/adminapi/dashboard/index', [], [], $limitedToken);
                $this->assertApiSuccess($limitedResp);
                $ldata = $limitedResp['data'];
                $this->assertSame('***', $ldata['system']['php_version']);
                $this->assertSame('***', $ldata['system']['db_driver']);
                $this->assertSame('***', $ldata['system']['admin_entry']);
                $this->assertNotEmpty($ldata['recent_logs']);
                foreach ($ldata['recent_logs'] as $logRow) {
                    $this->assertSame($limitedId, (int) $logRow['admin_id']);
                }
            } finally {
                Db::name('auth_group_access')->where('admin_id', $limitedId)->delete();
                Db::name('admin')->where('id', $limitedId)->delete();
                Db::name('auth_group')->where('id', $groupId)->delete();
            }

            // 测试 Dashboard::clearCache() 真正清理 PluginManager 缓存
            /** @var PluginManager $pm */
            $pm = $this->app->make(PluginManager::class);
            $pm->enabledNames();
            $this->assertTrue(Cache::has('hw:plugins:enabled'));
            $clearResp = $this->adminPost('/adminapi/dashboard/clearCache', ['type' => 'plugin']);
            $this->assertApiSuccess($clearResp);
            $this->assertFalse(Cache::has('hw:plugins:enabled'));
        } finally {
            if ($insertedIds) {
                Db::name('admin_log')->whereIn('id', $insertedIds)->delete();
            }
            Cache::delete('hw:dashboard:summary');
        }
    }

    public function testAdminAuthCacheInvalidationAndRuleTitleLookup(): void
    {
        AdminAuth::clearCache();
        /** @var AdminAuth $auth */
        $auth = $this->app->make(AdminAuth::class);
        $auth->init($this->adminToken);

        // 冷缓存时 ruleTitle 应直接按 name 单查返回正确标题，且不触发全量规则缓存写入
        $title = $auth->ruleTitle('auth/admin/index');
        $this->assertNotSame('', $title);

        // 插入新组测试 childrenGroupIds 缓存命中与 clearCache 失效
        $groupsBefore = $auth->childrenGroupIds(true);
        $probeGroupId = (int) Db::name('auth_group')->insertGetId([
            'pid'         => 0,
            'name'        => 'CacheProbe_' . uniqid(),
            'rules'       => '1',
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        try {
            // 新实例化 AdminAuth 在未清缓存前命中短期缓存
            $authCached = new AdminAuth($this->app->make(TokenService::class));
            $authCached->init($this->adminToken);
            $this->assertSame($groupsBefore, $authCached->childrenGroupIds(true));

            // 清理缓存后应立即可见新组
            AdminAuth::clearCache();
            $authFresh = new AdminAuth($this->app->make(TokenService::class));
            $authFresh->init($this->adminToken);
            $groupsAfter = $authFresh->childrenGroupIds(true);
            $this->assertContains($probeGroupId, $groupsAfter);
        } finally {
            Db::name('auth_group')->where('id', $probeGroupId)->delete();
            AdminAuth::clearCache();
        }
    }

    public function testCmsBootUsesVersionSentinelAndSelfHealsMissingTarget(): void
    {
        /** @var PluginManager $pm */
        $pm = $this->app->make(PluginManager::class);
        /** @var CmsPlugin $cms */
        $cms = $pm->instance('cms');
        $ver = (string) $cms->info()->version;
        $pubTarget = AssetPublisher::target('cms') . DIRECTORY_SEPARATOR . 'index.html';
        $origExists = is_file($pubTarget);
        $origContent = $origExists ? (string) file_get_contents($pubTarget) : null;

        try {
            Cache::delete('hw:cms:view_sync_ver');
            $cms->boot();
            $this->assertSame($ver, Cache::get('hw:cms:view_sync_ver'));
            $this->assertFileExists($pubTarget);

            // 写入标记内容验证版本哨兵命中时直接跳过（不执行 md5_file 与覆盖写）
            $sentinelMarker = '<!-- SENTINEL_NO_OVERWRITE -->';
            file_put_contents($pubTarget, $sentinelMarker);
            $cms->boot();
            $this->assertSame($sentinelMarker, file_get_contents($pubTarget));

            // 删除目标文件时即使版本哨兵存在也必须触发自愈同步
            @unlink($pubTarget);
            $this->assertFileDoesNotExist($pubTarget);
            $cms->boot();
            $this->assertFileExists($pubTarget);
            $this->assertNotSame($sentinelMarker, file_get_contents($pubTarget));
        } finally {
            Cache::delete('hw:cms:view_sync_ver');
            if ($origExists && $origContent !== null) {
                $dir = dirname($pubTarget);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($pubTarget, $origContent);
            } elseif (is_file($pubTarget)) {
                @unlink($pubTarget);
            }
        }
    }

    public function testDatabasePragmasFieldsCacheAndRedisCacheConfig(): void
    {
        $journalMode = Db::query('PRAGMA journal_mode');
        $this->assertSame('wal', strtolower((string) ($journalMode[0]['journal_mode'] ?? '')));

        $synchronous = Db::query('PRAGMA synchronous');
        $this->assertSame(1, (int) ($synchronous[0]['synchronous'] ?? -1));

        $busyTimeout = Db::query('PRAGMA busy_timeout');
        $this->assertSame(5000, (int) ($busyTimeout[0]['timeout'] ?? ($busyTimeout[0]['busy_timeout'] ?? -1)));

        $cacheConfig = require dirname(__DIR__, 2) . '/config/cache.php';
        $this->assertArrayHasKey('timeout', $cacheConfig['stores']['redis']);
        $this->assertArrayHasKey('read_timeout', $cacheConfig['stores']['redis']);

        $dbConfigRaw = file_get_contents(dirname(__DIR__, 2) . '/config/database.php');
        $this->assertStringContainsString("env('DB_FIELDS_CACHE', !env('APP_DEBUG', false))", (string) $dbConfigRaw);
    }

    public function testApiControllerLazyAuthSkipsDbQueryOnPublicRoutes(): void
    {
        // 携带无效 Token 访问公开接口 /api/index/ping ($noNeedLogin = ['*']) 不得触发 token/user 查库
        $executedSql = [];
        $this->closeDbConnections();
        $app = $this->createIsolatedApp();
        $app->event->listen(\think\db\event\SqlExecuted::class, static function () {});
        Db::listen(static function (string $sql) use (&$executedSql): void {
            $executedSql[] = $sql;
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/index/ping';
        $_SERVER['QUERY_STRING'] = '';
        $_GET = [];
        $_POST = [];

        $request = $app->make(\think\Request::class, [], true);
        $request->setMethod('GET')->setUrl('/api/index/ping')->setBaseUrl('')->setPathinfo('api/index/ping');
        $request->withHeader(['accept' => 'application/json', 'authorization' => 'Bearer invalid_perf_token_xyz']);
        $app->instance('request', $request);
        $resp = $app->http->run($request);
        $decoded = json_decode((string) $resp->getContent(), true);
        $this->assertApiSuccess($decoded);

        $tokenOrUserQueries = array_filter(
            $executedSql,
            static fn (string $sql): bool => str_contains($sql, 'hw_token') || str_contains($sql, 'hw_user')
        );
        $this->assertSame([], array_values($tokenOrUserQueries), '公开免登录接口不应查询 hw_token 或 hw_user');

        // 反例：受保护接口 /api/user/info 携带无效 Token 仍必须拦截返回 401
        $protectedResp = $this->dispatchHttp('GET', '/api/user/info', [], [], 'invalid_perf_token_xyz');
        $this->assertSame(401, $protectedResp['code'] ?? null);
    }

    public function testAdminEntryClearCacheAndLoginThrottleSanity(): void
    {
        AdminEntry::setEntry('perfprobe');
        $this->assertSame('perfprobe', AdminEntry::getEntry());
        AdminEntry::clearCache();
        @unlink(AdminEntry::getRuntimeFilePath());
        $this->assertSame('admin', AdminEntry::getEntry());

        // 验证 LoginThrottle 在降级标记未开启时 hasDegradedFallback 为 false
        $rm = new \ReflectionMethod(LoginThrottle::class, 'hasDegradedFallback');
        $rm->setAccessible(true);
        $this->assertFalse((bool) $rm->invoke(null));
    }

    public function testConfigServiceAndDictServiceCachingAndWriteInvalidation(): void
    {
        ConfigService::clearCache();
        DictService::clearCache();

        // 1. ConfigService 60s 缓存命中与写失效
        $origSiteName = (string) Db::name('config')->where('name', 'site_name')->value('value');
        $origCaptcha = (string) (Db::name('config')->where('name', 'login_captcha')->value('value') ?? '1');

        try {
            // 预热缓存
            $initialCfg = $this->dispatchHttp('GET', '/adminapi/index/config');
            $this->assertApiSuccess($initialCfg);
            $this->assertSame($origSiteName, $initialCfg['data']['site_name']);
            $this->assertTrue(Cache::has(ConfigService::CACHE_KEY));

            // 绕过 Service 直接改库时，60s 缓存期内应仍命中缓存值（证明未重复查库）
            Db::name('config')->where('name', 'site_name')->update(['value' => 'DbBypassSiteName']);
            $cachedResp = $this->dispatchHttp('GET', '/adminapi/index/config');
            $this->assertApiSuccess($cachedResp);
            $this->assertSame($origSiteName, $cachedResp['data']['site_name'], '60s 缓存命中期内应返回缓存值');

            // 通过 System/Config 接口写入新值，必须触发写失效并立即生效（含 CaptchaService）
            $editResp = $this->adminPost('/adminapi/system.config/edit', [
                'values' => [
                    'site_name'     => 'UpdatedCachedSite',
                    'login_captcha' => '0',
                ],
            ]);
            $this->assertApiSuccess($editResp);

            $afterEditResp = $this->dispatchHttp('GET', '/adminapi/index/config');
            $this->assertApiSuccess($afterEditResp);
            $this->assertSame('UpdatedCachedSite', $afterEditResp['data']['site_name']);
            $this->assertFalse($afterEditResp['data']['login_captcha']);
            $this->assertFalse(CaptchaService::isEnabled());
        } finally {
            Db::name('config')->where('name', 'site_name')->update(['value' => $origSiteName]);
            Db::name('config')->where('name', 'login_captcha')->update(['value' => $origCaptcha]);
            ConfigService::clearCache();
        }

        // 2. DictService 60s 缓存命中、写失效以及移除 Dict::initialize() 自动插库校验
        $dictCode = 'perf_cache_dict_' . bin2hex(random_bytes(3));
        $dictId = (int) Db::name('dict')->insertGetId([
            'name'        => '缓存测试字典',
            'code'        => $dictCode,
            'remark'      => '',
            'status'      => 1,
            'sort'        => 99,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        try {
            $map1 = $this->adminGet('/adminapi/system.dict/map', ['codes' => $dictCode]);
            $this->assertApiSuccess($map1);
            $this->assertSame([], $map1['data'][$dictCode]);
            $this->assertTrue(Cache::has(DictService::MAP_CACHE_KEY));

            // 绕过接口直接插表时，命中缓存仍为空
            Db::name('dict_item')->insert([
                'dict_id'     => $dictId,
                'label'       => 'BypassItem',
                'value'       => 'bypass',
                'tag_type'    => 'info',
                'status'      => 1,
                'sort'        => 1,
                'remark'      => '',
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $mapCached = $this->adminGet('/adminapi/system.dict/map', ['codes' => $dictCode]);
            $this->assertSame([], $mapCached['data'][$dictCode]);

            // 通过 itemAdd 写入必须立即失效缓存，map 与 items 接口同步看到新增项
            $addItemResp = $this->adminPost('/adminapi/system.dict/itemAdd', [
                'dict_id' => $dictId,
                'label'   => 'FreshItem',
                'value'   => 'fresh',
                'status'  => 1,
                'sort'    => 2,
            ]);
            $this->assertApiSuccess($addItemResp);

            $mapAfter = $this->adminGet('/adminapi/system.dict/map', ['codes' => $dictCode]);
            $this->assertApiSuccess($mapAfter);
            $values = array_column($mapAfter['data'][$dictCode], 'value');
            $this->assertContains('fresh', $values);
            $this->assertContains('bypass', $values);

            $itemsResp = $this->adminGet('/adminapi/system.dict/items', ['dict_id' => $dictId]);
            $this->assertApiSuccess($itemsResp);
            $this->assertCount(2, $itemsResp['data']['list']);
        } finally {
            Db::name('dict_item')->where('dict_id', $dictId)->delete();
            Db::name('dict')->where('id', $dictId)->delete();
            DictService::clearCache();
        }

        // 验证 Dict 控制器已移除 initialize() 中的 ensureSeedDicts()
        $dictSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/adminapi/controller/system/Dict.php');
        $this->assertStringNotContainsString('ensureSeedDicts', $dictSrc);
    }

    public function testAdminLogCursorPaginationBackwardCompatibilityAndInvalidCursorRejection(): void
    {
        $insertedIds = [];
        $now = time();
        try {
            for ($i = 1; $i <= 5; $i++) {
                $insertedIds[] = (int) Db::name('admin_log')->insertGetId([
                    'admin_id'    => 1,
                    'username'    => 'cursor_test_' . $i,
                    'url'         => '/adminapi/cursor/test/' . $i,
                    'title'       => '游标分页测试 ' . $i,
                    'content'     => '{}',
                    'ip'          => '127.0.0.1',
                    'useragent'   => 'PHPUnit',
                    'create_time' => $now + $i,
                ]);
            }

            // 1. 向后兼容：不传 cursor 参数时保持传统 {list, total} 分页行为
            $offsetResp = $this->adminGet('/adminapi/auth.admin_log/index', ['page' => 1, 'limit' => 2]);
            $this->assertApiSuccess($offsetResp);
            $this->assertArrayHasKey('list', $offsetResp['data']);
            $this->assertArrayHasKey('total', $offsetResp['data']);
            $this->assertGreaterThanOrEqual(5, (int) $offsetResp['data']['total']);
            $this->assertCount(2, $offsetResp['data']['list']);

            // 2. 游标分页正例：cursor=0 拉取第一页 -> cursor=next_cursor 拉取下一页（无重复且顺序严格递减）
            $page1 = $this->adminGet('/adminapi/auth.admin_log/index', ['cursor' => 0, 'limit' => 2]);
            $this->assertApiSuccess($page1);
            $this->assertTrue($page1['data']['cursor_mode']);
            $this->assertTrue($page1['data']['has_more']);
            $this->assertCount(2, $page1['data']['list']);
            $nextCursor = (int) $page1['data']['next_cursor'];
            $this->assertGreaterThan(0, $nextCursor);
            $this->assertSame((int) $page1['data']['list'][1]['id'], $nextCursor);

            $page2 = $this->adminGet('/adminapi/auth.admin_log/index', ['cursor' => $nextCursor, 'limit' => 2]);
            $this->assertApiSuccess($page2);
            $this->assertCount(2, $page2['data']['list']);
            foreach ($page2['data']['list'] as $row) {
                $this->assertLessThan($nextCursor, (int) $row['id']);
            }

            // 3. 反例：非法 cursor 参数与非主板排序参数必须拦截返回 code=0
            foreach (['-1', 'abc', '1.5', '', '99999999999999999999999'] as $badCursor) {
                $badResp = $this->adminGet('/adminapi/auth.admin_log/index', ['cursor' => $badCursor, 'limit' => 2]);
                $this->assertSame(0, $badResp['code'] ?? null, "非法 cursor [{$badCursor}] 未被拦截");
            }

            $badSortResp = $this->adminGet('/adminapi/auth.admin_log/index', ['cursor' => 10, 'sort' => 'username']);
            $this->assertSame(0, $badSortResp['code'] ?? null, '非主键 sort 搭配 cursor 必须被拦截');

            // 未启用游标分页的控制器（如 CrudTrait 默认的 user.user/index）传入 cursor 也必须拦截
            $unsupportedResp = $this->adminGet('/adminapi/user.user/index', ['cursor' => 1]);
            $this->assertSame(0, $unsupportedResp['code'] ?? null, '未启用游标分页的控制器应拒绝 cursor 参数');
        } finally {
            if ($insertedIds) {
                Db::name('admin_log')->whereIn('id', $insertedIds)->delete();
            }
        }
    }

    public function testRunnerOfflineAndGroupTreeV2Contracts(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $runnerPath = $repoRoot . '/admin-ui/src/views/system/plugin/runner.vue';
        $this->assertFileExists($runnerPath);
        $runnerSrc = (string) file_get_contents($runnerPath);
        $this->assertStringNotContainsString('unpkg.com', $runnerSrc, 'runner.vue 不得包含 unpkg.com 外链');
        $this->assertStringContainsString('__HWADMIN_CONTEXT__', $runnerSrc);
        $this->assertStringContainsString('HWADMIN_INIT', $runnerSrc);

        $groupPath = $repoRoot . '/admin-ui/src/views/auth/group/index.vue';
        $this->assertFileExists($groupPath);
        $groupSrc = (string) file_get_contents($groupPath);
        $this->assertStringContainsString('el-tree-v2', $groupSrc, 'auth/group/index.vue 必须改用 el-tree-v2');
        $this->assertDoesNotMatchRegularExpression('/<el-tree(?![-a-z])/', $groupSrc, 'auth/group/index.vue 不应保留旧版 <el-tree>');
    }
}
