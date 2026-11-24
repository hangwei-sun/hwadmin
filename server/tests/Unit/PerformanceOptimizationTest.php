<?php
declare(strict_types=1);

namespace tests\Unit;

use core\auth\AdminAuth;
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
}
