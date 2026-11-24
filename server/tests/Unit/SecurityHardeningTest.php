<?php
declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\controller\auth\Admin as AdminControllerApi;
use app\adminapi\controller\auth\Group as GroupControllerApi;
use app\adminapi\controller\system\Config as ConfigControllerApi;
use app\adminapi\controller\system\Crontab as CrontabControllerApi;
use app\adminapi\controller\system\Devops as DevopsControllerApi;
use app\adminapi\controller\system\Plugin as PluginControllerApi;
use app\common\model\system\Crontab as CrontabModel;
use core\auth\PasswordHash;
use core\auth\PasswordHasher;
use core\auth\TokenService;
use core\console\InstallCommand;
use core\exception\ApiException;
use core\install\Installer;
use core\plugin\SystemUpdater;
use core\support\CrontabExecutor;
use core\support\OutboundHttpGuard;
use plugins\crud\controller\adminapi\Generator as CrudGeneratorController;
use plugins\crud\service\CrudGeneratorService;
use tests\TestCase;
use think\exception\HttpResponseException;
use think\facade\Cache;
use think\facade\Db;
use think\Request;
use ZipArchive;

final class SecurityHardeningTest extends TestCase
{
    private function invokeController(string $class, string $method, string $httpMethod, array $post = [], array $get = [], string $token = ''): array
    {
        $this->closeDbConnections();
        $app = $this->createIsolatedApp();

        $req = $app->make(Request::class, [], true);
        $req->setMethod(strtoupper($httpMethod));
        $req->setAction($method);
        $req->withGet($get);
        if (strtoupper($httpMethod) === 'POST') {
            $req->withPost($post);
        }
        $headers = ['accept' => 'application/json'];
        if ($token !== '') {
            $headers['authorization'] = 'Bearer ' . $token;
            $headers['x-token'] = $token;
        }
        $req->withHeader($headers);
        $app->instance('request', $req);

        try {
            $ctrl = $app->make($class, [], true);
            $ctrl->$method();
            $this->fail("{$class}::{$method} 未按预期抛出 HttpResponseException");
        } catch (HttpResponseException $e) {
            $resp = $e->getResponse();
            $payload = json_decode((string) $resp->getContent(), true);
            return [
                'http_status' => $resp->getCode(),
                'payload'     => is_array($payload) ? $payload : ['code' => -1, 'msg' => (string) $resp->getContent()],
            ];
        }
    }

    private function createLimitedAdmin(string $prefix = 'sec_sub', array $extraRuleNames = []): array
    {
        $now = time();
        $suffix = bin2hex(random_bytes(3));
        $ruleIds = ['1'];
        if ($extraRuleNames) {
            $foundIds = Db::name('auth_rule')->whereIn('name', $extraRuleNames)->column('id');
            foreach ($foundIds as $rid) {
                $ruleIds[] = (string) $rid;
            }
        }
        $groupId = (int) Db::name('auth_group')->insertGetId([
            'pid'         => 1,
            'name'        => "{$prefix}_group_{$suffix}",
            'rules'       => implode(',', array_unique($ruleIds)),
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $adminId = (int) Db::name('admin')->insertGetId([
            'username'    => "{$prefix}_{$suffix}",
            'nickname'    => 'SubAdmin',
            'password'    => PasswordHasher::hash('SubPass123!'),
            'salt'        => '',
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('auth_group_access')->insert([
            'admin_id' => $adminId,
            'group_id' => $groupId,
        ]);
        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $token = $tokens->issue('admin', $adminId, 3600);
        return ['admin_id' => $adminId, 'group_id' => $groupId, 'token' => $token];
    }

    public function testCrudGeneratorSecurityAndValidation(): void
    {
        // 1. Generator::$noNeedRight 必须为空且非超管禁止访问
        $ref = new \ReflectionClass(CrudGeneratorController::class);
        $prop = $ref->getProperty('noNeedRight');
        $prop->setAccessible(true);
        $noNeedRight = $prop->getValue($ref->newInstanceWithoutConstructor());
        $this->assertSame([], $noNeedRight, 'CRUD 生成器不得豁免任何权限节点');

        $sub = $this->createLimitedAdmin('crudsub');
        try {
            $deny = $this->invokeController(CrudGeneratorController::class, 'tables', 'GET', [], [], $sub['token']);
            $this->assertSame(403, $deny['http_status'], '非超管访问 CRUD 生成器必须被 403 拦截');

            // 正例：超管可正常读取表列表
            $ok = $this->invokeController(CrudGeneratorController::class, 'tables', 'GET', [], [], $this->adminToken);
            $this->assertSame(1, $ok['payload']['code'] ?? 0);

            // 2. Service 标识符注入、路径穿越与系统表保护测试
            $svc = new CrudGeneratorService();

            // 合法子目录控制器可正常预览
            $previewOk = $svc->preview([
                'table'      => 'hw_user',
                'controller' => 'shop/MemberNote',
                'model'      => 'MemberNote',
                'columns'    => [
                    ['name' => 'title', 'title' => '标题', 'type' => 'varchar', 'show_in_list' => true, 'show_in_form' => true],
                ],
            ]);
            $this->assertStringContainsString('class MemberNote extends AdminController', $previewOk['controller_code']);

            // 非法 plugin / controller / model / column 必须阻断
            foreach ([
                ['table' => 'hw_user', 'plugin' => '../evil'],
                ['table' => 'hw_user', 'controller' => '../../Evil'],
                ['table' => 'hw_user', 'controller' => 'Foo;phpinfo()'],
                ['table' => 'hw_user', 'model' => 'Bad;system'],
                ['table' => 'hw_user', 'columns' => [['name' => "x'];eval(1);//", 'title' => 'bad']]],
            ] as $badCfg) {
                try {
                    $svc->preview($badCfg);
                    $this->fail('非法 CRUD 配置应当被拒绝: ' . json_encode($badCfg));
                } catch (ApiException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }

            // 系统核心表（含新增的 config/plugin/token/crontab/dict 等）禁止 generate 与 createTable
            foreach (['config', 'plugin', 'token', 'attachment', 'crontab', 'crontab_log', 'dict', 'dict_item', 'admin_log', 'auth_group', 'auth_group_access', 'auth_rule'] as $sysTable) {
                try {
                    $svc->generate([
                        'table'   => 'hw_' . $sysTable,
                        'columns' => [['name' => 'title', 'title' => '标题', 'type' => 'varchar']],
                    ]);
                    $this->fail("系统保护表 [{$sysTable}] 不应允许 generate");
                } catch (ApiException $e) {
                    $this->assertStringContainsString('系统核心', $e->getMessage());
                }
            }

            // createTable DDL SQL 注入防护：非法字段类型、非法长度、系统表名均须拦截
            foreach ([
                [
                    'table_name' => 'sec_probe_badtype',
                    'columns'    => [['name' => 'title', 'type' => 'INT); DROP TABLE hw_admin; --', 'length' => '11']],
                ],
                [
                    'table_name' => 'sec_probe_badlen',
                    'columns'    => [['name' => 'title', 'type' => 'varchar', 'length' => '255), evil INT']],
                ],
                [
                    'table_name' => 'config',
                    'columns'    => [['name' => 'title', 'type' => 'varchar', 'length' => '50']],
                ],
            ] as $badDdl) {
                try {
                    $svc->createTable($badDdl);
                    $this->fail('恶意建表参数必须被拦截: ' . json_encode($badDdl));
                } catch (ApiException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        } finally {
            Db::name('auth_group_access')->where('admin_id', $sub['admin_id'])->delete();
            Db::name('admin')->where('id', $sub['admin_id'])->delete();
            Db::name('auth_group')->where('id', $sub['group_id'])->delete();
            Db::name('token')->where('type', 'admin')->where('user_id', $sub['admin_id'])->delete();
        }
    }

    public function testPluginAndSystemUpdaterSecurity(): void
    {
        // 1. Plugin::$noNeedRight 不可包含写操作、更新/回滚、市场源或账号接口
        $ref = new \ReflectionClass(PluginControllerApi::class);
        $prop = $ref->getProperty('noNeedRight');
        $prop->setAccessible(true);
        $noNeedRight = $prop->getValue($ref->newInstanceWithoutConstructor());
        foreach (['saveMarketUrl', 'updateFramework', 'updatePlugin', 'rollbackUpdate', 'marketAccount', 'loginMarketAccount', 'syncMarketLicenses', 'logoutMarketAccount', 'downloadMarket'] as $protectedAction) {
            $this->assertNotContains($protectedAction, $noNeedRight, "敏感操作 [{$protectedAction}] 不得放入 noNeedRight");
        }

        // 2. 非超管调用高危操作必须返回 403
        $sub = $this->createLimitedAdmin('plugsub');
        try {
            foreach ([
                ['saveMarketUrl', ['market_server_url' => '']],
                ['updateFramework', ['download_url' => '']],
                ['updatePlugin', ['name' => 'demo']],
                ['rollbackUpdate', ['backup_id' => 'fake']],
                ['downloadMarket', ['name' => 'demo']],
            ] as [$act, $post]) {
                $resp = $this->invokeController(PluginControllerApi::class, $act, 'POST', $post, [], $sub['token']);
                $this->assertSame(403, $resp['http_status'], "非超管调用 Plugin::{$act} 必须被 403 拦截");
            }

            // 3. downloadMarket 入口强制校验 PluginInfo::NAME_PATTERN
            $badNameResp = $this->invokeController(PluginControllerApi::class, 'downloadMarket', 'POST', ['name' => '../evil'], [], $this->adminToken);
            $this->assertSame(0, $badNameResp['payload']['code'] ?? -1);

            // 4. saveMarketUrl 拦截 SSRF 元数据/回环地址
            $ssrfMarket = $this->invokeController(PluginControllerApi::class, 'saveMarketUrl', 'POST', ['market_server_url' => 'http://169.254.169.254/latest/meta-data/'], [], $this->adminToken);
            $this->assertSame(0, $ssrfMarket['payload']['code'] ?? -1);
        } finally {
            Db::name('auth_group_access')->where('admin_id', $sub['admin_id'])->delete();
            Db::name('admin')->where('id', $sub['admin_id'])->delete();
            Db::name('auth_group')->where('id', $sub['group_id'])->delete();
            Db::name('token')->where('type', 'admin')->where('user_id', $sub['admin_id'])->delete();
        }

        // 5. ZipSlip & Symlink 防护与权限收敛 (0755/0644)
        $updater = $this->app->make(SystemUpdater::class);
        $symlinkZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_symlink_' . uniqid() . '.zip';
        $z = new ZipArchive();
        $z->open($symlinkZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('plugin.json', '{"name":"demo","version":"1.0.0"}');
        $z->addFromString('evil_link', '/etc/passwd');
        $z->setExternalAttributesName('evil_link', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $z->close();

        $tmpTarget = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_sym_target_' . uniqid();
        try {
            try {
                $updater->extractPluginPackageSafely($symlinkZip, $tmpTarget, true);
                $this->fail('包含符号链接的插件压缩包必须被拒绝');
            } catch (ApiException $e) {
                $this->assertStringContainsString('符号链接', $e->getMessage());
            }

            // 同理测试 Plugin::extractPluginZip 拦截符号链接与 .. 路径
            $pluginCtrl = $ref->newInstanceWithoutConstructor();
            $extractMethod = $ref->getMethod('extractPluginZip');
            $extractMethod->setAccessible(true);
            try {
                $extractMethod->invoke($pluginCtrl, $symlinkZip, 'demo');
                $this->fail('Plugin::extractPluginZip 必须拦截符号链接条目');
            } catch (ApiException $e) {
                $this->assertStringContainsString('符号链接', $e->getMessage());
            }
        } finally {
            @unlink($symlinkZip);
        }

        // 6. SystemUpdater::updateFramework 强制要求同源下载且拦截恶意外部域/元数据
        foreach ([
            'https://evil.example.com/patch.zip',
            'http://169.254.169.254/latest/meta-data/',
            'file:///etc/passwd',
        ] as $untrustedUrl) {
            try {
                $updater->updateFramework($untrustedUrl);
                $this->fail("updateFramework 必须拦截非同源或危险 URL: {$untrustedUrl}");
            } catch (ApiException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testOutboundHttpGuardCrontabAndDevops(): void
    {
        // 1. OutboundHttpGuard 正反例
        $this->assertSame(
            'https://hw.baotoutv.cn/api/plugin/market/gateway/catalog',
            OutboundHttpGuard::assertSafeUrl('https://hw.baotoutv.cn/api/plugin/market/gateway/catalog')
        );
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:8080/admin',
            'http://10.0.0.1/internal',
            'http://192.168.1.1/',
            'http://[::1]/',
            'http://localhost/test',
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/_INFO',
        ] as $badUrl) {
            try {
                OutboundHttpGuard::assertSafeUrl($badUrl);
                $this->fail("OutboundHttpGuard 应拦截危险 URL: {$badUrl}");
            } catch (ApiException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // 2. Crontab 控制器不应保留 public executeTask，且 CrontabExecutor 强制安全边界
        $this->assertFalse(method_exists(CrontabControllerApi::class, 'executeTask'));

        CrontabExecutor::assertAllowedTask('command', 'cache:clear');
        CrontabExecutor::assertAllowedTask('command', 'hw:doctor --mode=dev');
        CrontabExecutor::assertAllowedTask('url', 'https://hw.baotoutv.cn/health');

        foreach ([
            ['sql', 'DROP TABLE hw_admin'],
            ['command', 'hw:dev:token --username=admin'],
            ['command', 'hw:crud --table=evil'],
            ['command', 'migrate:run'],
            ['command', 'cache:clear; rm -rf /'],
            ['url', 'http://169.254.169.254/latest/meta-data/'],
        ] as [$type, $target]) {
            try {
                CrontabExecutor::assertAllowedTask($type, $target);
                $this->fail("CrontabExecutor 应拦截高危任务 [{$type}: {$target}]");
            } catch (\Throwable $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // CrontabExecutor::executeTask 对存量恶意 sql 任务也会失败记录，不会执行 SQL
        $now = time();
        $sqlTask = new CrontabModel();
        $sqlTask->save([
            'title'       => '恶意SQL阻断测试',
            'type'        => 'sql',
            'target'      => 'DELETE FROM hw_admin',
            'cron'        => '* * * * *',
            'status'      => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        try {
            $execRes = CrontabExecutor::executeTask($sqlTask);
            $this->assertSame(0, $execRes['status']);
            $this->assertStringContainsString('禁用 SQL', $execRes['output']);
            $this->assertGreaterThan(0, Db::name('admin')->count());
        } finally {
            Db::name('crontab_log')->where('crontab_id', $sqlTask->id)->delete();
            Db::name('crontab')->where('id', $sqlTask->id)->delete();
        }

        // 3. Devops::optimize 正反例
        $optOk = $this->invokeController(DevopsControllerApi::class, 'optimize', 'POST', ['table' => 'hw_config'], [], $this->adminToken);
        $this->assertSame(1, $optOk['payload']['code'] ?? 0);

        foreach (['hw_config`; DROP TABLE hw_admin;--', 'non_existent_table_xyz'] as $badTable) {
            try {
                $this->invokeController(DevopsControllerApi::class, 'optimize', 'POST', ['table' => $badTable], [], $this->adminToken);
                $this->fail("Devops::optimize 必须拒绝非法表名: {$badTable}");
            } catch (ApiException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testCmsMemberAndArticleSecurity(): void
    {
        /** @var \core\plugin\PluginManager $pm */
        $pm = $this->app->make(\core\plugin\PluginManager::class);
        $origCmsRow = Db::name('plugin')->where('name', 'cms')->find();
        if (!$origCmsRow) {
            $pm->install('cms', true);
        } elseif ((int) $origCmsRow['status'] !== 1) {
            $pm->enable('cms');
        }
        $pm->clearCache();

        $now = time();
        $uname = 'cmssec_' . bin2hex(random_bytes(3));
        $salt = 's12345';
        $plain = 'LegacyPass123!';
        $legacyHash = md5(md5($plain) . $salt);
        $testIp = '198.51.100.' . random_int(10, 240);
        $origRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $testIp;

        $uid = (int) Db::name('user')->insertGetId([
            'group_id'    => 1,
            'username'    => $uname,
            'nickname'    => 'CMS测试会员',
            'password'    => $legacyHash,
            'salt'        => $salt,
            'score'       => 10,
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        try {
            // 1. 失败 5 次后触发 LoginThrottle
            for ($i = 0; $i < 5; $i++) {
                $badLogin = $this->guestPost('/api/plugin/cms/member/login', [
                    'account'  => $uname,
                    'password' => 'WrongPass_' . $i,
                ]);
                $this->assertApiFail($badLogin, 0);
            }
            $throttled = $this->guestPost('/api/plugin/cms/member/login', [
                'account'  => $uname,
                'password' => $plain,
            ]);
            $this->assertApiFail($throttled, 0);
            $this->assertStringContainsString('频繁', (string) ($throttled['msg'] ?? ''));

            // 清理限流后正常登录，验证旧 MD5 密码自动升级 (needsRehash)
            \core\auth\LoginThrottle::clear('user', $uname, $testIp);
            $okLogin = $this->guestPost('/api/plugin/cms/member/login', [
                'account'  => $uname,
                'password' => $plain,
            ]);
            $this->assertApiSuccess($okLogin);
            $memberToken = (string) ($okLogin['data']['token'] ?? '');
            $this->assertNotEmpty($memberToken);

            $userRow = Db::name('user')->where('id', $uid)->find();
            $this->assertSame('', (string) ($userRow['salt'] ?? 'x'));
            $this->assertFalse(PasswordHash::needsRehash((string) $userRow['password'], ''));

            // 2. 头像 URL 协议校验 + 改密吊销全量令牌
            $badAvatar = $this->dispatchHttp('POST', '/api/plugin/cms/member/updateProfile', [], [
                'avatar' => 'javascript:alert(1)',
            ], $memberToken);
            $this->assertApiFail($badAvatar, 0);

            $goodAvatar = $this->dispatchHttp('POST', '/api/plugin/cms/member/updateProfile', [], [
                'avatar' => '/uploads/avatar_ok.png',
            ], $memberToken);
            $this->assertApiSuccess($goodAvatar);

            $pwdUpdate = $this->dispatchHttp('POST', '/api/plugin/cms/member/updateProfile', [], [
                'old_password' => $plain,
                'new_password' => 'NewCmsPass456!',
            ], $memberToken);
            $this->assertApiSuccess($pwdUpdate);

            $afterRevoke = $this->dispatchHttp('GET', '/api/plugin/cms/member/profile', [], [], $memberToken);
            $this->assertApiFail($afterRevoke, 401, 'CMS 会员改密后旧令牌必须被吊销');

            // 重新登录获取有效新令牌测试 Article::submitInquiry
            $reLogin = $this->guestPost('/api/plugin/cms/member/login', [
                'account'  => $uname,
                'password' => 'NewCmsPass456!',
            ]);
            $this->assertApiSuccess($reLogin);
            $newToken = (string) ($reLogin['data']['token'] ?? '');

            // 3. siteInit 仅展示 status=1 留言；submitInquiry 内容超长拦截 + 每日积分上限 + 限流
            $pendingId = (int) Db::name('cms_inquiry')->insertGetId([
                'scene'       => 'blog_comment',
                'name'        => '未审核用户',
                'contact'     => '13800138000',
                'content'     => 'PENDING_SECRET_COMMENT_XYZ',
                'status'      => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $approvedId = (int) Db::name('cms_inquiry')->insertGetId([
                'scene'       => 'blog_comment',
                'name'        => '已审核用户',
                'contact'     => '13800138001',
                'content'     => 'APPROVED_PUBLIC_COMMENT_XYZ',
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            $siteInit = $this->guestGet('/api/plugin/cms/article/siteInit');
            $this->assertApiSuccess($siteInit);
            $commentTexts = array_column((array) ($siteInit['data']['recent_comments'] ?? []), 'content');
            $this->assertNotContains('PENDING_SECRET_COMMENT_XYZ', $commentTexts);
            $this->assertContains('APPROVED_PUBLIC_COMMENT_XYZ', $commentTexts);

            // 内容超长拦截
            $tooLong = $this->dispatchHttp('POST', '/api/plugin/cms/article/submitInquiry', [], [
                'scene'   => 'blog_comment',
                'contact' => '13800138000',
                'content' => str_repeat('a', 2001),
            ], $newToken);
            $this->assertApiFail($tooLong, 0);

            // 清理限流与积分计数缓存，验证每日 50 积分上限与第 11 次限流
            Cache::delete('cms_inquiry_rate_ip_' . sha1($testIp));
            Cache::delete('cms_inquiry_rate_user_' . $uid);
            Cache::delete('cms_inquiry_daily_score_' . $uid . '_' . date('Ymd'));

            $totalRewarded = 0;
            $createdInqIds = [$pendingId, $approvedId];
            for ($i = 1; $i <= 6; $i++) {
                $inq = $this->dispatchHttp('POST', '/api/plugin/cms/article/submitInquiry', [], [
                    'scene'   => 'blog_comment',
                    'contact' => '13800138000',
                    'content' => 'Valid inquiry #' . $i,
                ], $newToken);
                $this->assertApiSuccess($inq);
                $createdInqIds[] = (int) ($inq['data']['id'] ?? 0);
                $totalRewarded += (int) ($inq['data']['reward_score'] ?? 0);
            }
            $this->assertSame(50, $totalRewarded, '每日互动积分奖励不得超过 50 上限');

            for ($i = 7; $i <= 10; $i++) {
                $inq = $this->dispatchHttp('POST', '/api/plugin/cms/article/submitInquiry', [], [
                    'scene'   => 'blog_comment',
                    'contact' => '13800138000',
                    'content' => 'Rate probe #' . $i,
                ], $newToken);
                $this->assertApiSuccess($inq);
                $createdInqIds[] = (int) ($inq['data']['id'] ?? 0);
            }
            $rateBlocked = $this->dispatchHttp('POST', '/api/plugin/cms/article/submitInquiry', [], [
                'scene'   => 'blog_comment',
                'contact' => '13800138000',
                'content' => 'Should be rate limited #11',
            ], $newToken);
            $this->assertApiFail($rateBlocked, 0);

            // tag/keyword LIKE 通配符安全查询
            $likeResp = $this->guestGet('/api/plugin/cms/article/index', ['tag' => '100%__test', 'keyword' => '%%%']);
            $this->assertApiSuccess($likeResp);

            Db::name('cms_inquiry')->whereIn('id', array_filter($createdInqIds))->delete();
        } finally {
            \core\auth\LoginThrottle::clear('user', $uname, $testIp);
            Cache::delete('cms_inquiry_rate_ip_' . sha1($testIp));
            Cache::delete('cms_inquiry_rate_user_' . $uid);
            Cache::delete('cms_inquiry_daily_score_' . $uid . '_' . date('Ymd'));
            if ($origRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $origRemoteAddr;
            }
            Db::name('token')->where('type', 'user')->where('user_id', $uid)->delete();
            Db::name('user')->where('id', $uid)->delete();
            if ($origCmsRow) {
                Db::name('plugin')->where('name', 'cms')->update([
                    'status' => $origCmsRow['status'],
                ]);
                $pm->clearCache();
            }
        }
    }

    public function testAdminGroupConfigInstallerAndCookieHardening(): void
    {
        $nowRule = time();
        $tempRuleIds = [];
        foreach (['auth/admin/multi' => '批量修改管理员', 'auth/group/multi' => '批量修改角色组'] as $rName => $rTitle) {
            if (!Db::name('auth_rule')->where('name', $rName)->find()) {
                $tempRuleIds[] = (int) Db::name('auth_rule')->insertGetId([
                    'pid'         => 0,
                    'type'        => 'button',
                    'name'        => $rName,
                    'title'       => $rTitle,
                    'status'      => 1,
                    'create_time' => $nowRule,
                    'update_time' => $nowRule,
                ]);
            }
        }
        $this->app->make(\core\plugin\PluginManager::class)->clearCache();

        $sub = $this->createLimitedAdmin('authsub', ['auth/admin/multi', 'auth/group/multi']);
        $other = $this->createLimitedAdmin('authother');
        try {
            // 1. Admin::multi() 反例与正例
            foreach ([
                ['ids' => [1], 'field' => 'status', 'value' => 0],
                ['ids' => [$other['admin_id']], 'field' => 'status', 'value' => 99],
            ] as $badMulti) {
                try {
                    $this->invokeController(AdminControllerApi::class, 'multi', 'POST', $badMulti, [], $this->adminToken);
                    $this->fail('Admin::multi 必须拦截非法状态或 id=1');
                } catch (ApiException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
            try {
                $this->invokeController(AdminControllerApi::class, 'multi', 'POST', ['ids' => [$sub['admin_id']], 'field' => 'status', 'value' => 0], [], $sub['token']);
                $this->fail('Admin::multi 必须拦截修改自身');
            } catch (ApiException $e) {
                $this->assertStringContainsString('自身', $e->getMessage());
            }
            $nonSubMulti = $this->invokeController(AdminControllerApi::class, 'multi', 'POST', ['ids' => [$other['admin_id']], 'field' => 'status', 'value' => 0], [], $sub['token']);
            $this->assertSame(403, $nonSubMulti['http_status']);

            // 正例：超管禁用普通管理员并联动吊销令牌
            $okDisable = $this->invokeController(AdminControllerApi::class, 'multi', 'POST', ['ids' => [$other['admin_id']], 'field' => 'status', 'value' => 0], [], $this->adminToken);
            $this->assertSame(1, $okDisable['payload']['code'] ?? 0);
            $this->assertSame(0, Db::name('token')->where('type', 'admin')->where('user_id', $other['admin_id'])->count());

            // Admin::profile() 头像 URL 校验
            try {
                $this->invokeController(AdminControllerApi::class, 'profile', 'POST', ['avatar' => 'javascript:alert(1)'], [], $this->adminToken);
                $this->fail('Admin::profile 必须拒绝 javascript: 头像');
            } catch (ApiException $e) {
                $this->assertNotEmpty($e->getMessage());
            }

            // 2. Group::multi() 反例
            try {
                $this->invokeController(GroupControllerApi::class, 'multi', 'POST', ['ids' => [1], 'field' => 'status', 'value' => 0], [], $this->adminToken);
                $this->fail('Group::multi 必须拦截 id=1');
            } catch (ApiException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $selfGroup = $this->invokeController(GroupControllerApi::class, 'multi', 'POST', ['ids' => [$sub['group_id']], 'field' => 'status', 'value' => 0], [], $sub['token']);
            $this->assertSame(403, $selfGroup['http_status']);

            // 3. Config::index() 严禁返回 market_auth_info 或明文 token
            $now = time();
            $insertedCfg = false;
            $existingAuthCfg = Db::name('config')->where('name', 'market_auth_info')->find();
            $secretToken = 'mkt_secret_tok_' . bin2hex(random_bytes(6));
            if ($existingAuthCfg) {
                Db::name('config')->where('name', 'market_auth_info')->update(['value' => json_encode(['bound' => true, 'token' => $secretToken])]);
            } else {
                Db::name('config')->insert([
                    'group'       => 'market',
                    'name'        => 'market_auth_info',
                    'title'       => '官网账号授权',
                    'type'        => 'string',
                    'value'       => json_encode(['bound' => true, 'token' => $secretToken]),
                    'options'     => '',
                    'tip'         => '',
                    'weight'      => 20,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $insertedCfg = true;
            }
            try {
                $cfgResp = $this->invokeController(ConfigControllerApi::class, 'index', 'GET', [], [], $this->adminToken);
                $this->assertSame(1, $cfgResp['payload']['code'] ?? 0);
                $encoded = json_encode($cfgResp['payload'], JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('market_auth_info', (string) $encoded);
                $this->assertStringNotContainsString($secretToken, (string) $encoded);
            } finally {
                if ($insertedCfg) {
                    Db::name('config')->where('name', 'market_auth_info')->delete();
                } else {
                    Db::name('config')->where('name', 'market_auth_info')->update(['value' => $existingAuthCfg['value']]);
                }
            }

            // 4. Installer::writeEnv SQLite 默认 APP_DEBUG = false
            $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hw_inst_env_' . uniqid() . DIRECTORY_SEPARATOR;
            @mkdir($tmpRoot, 0755, true);
            $oldApp = \think\Container::getInstance();
            $fakeApp = new \think\App(rtrim($tmpRoot, DIRECTORY_SEPARATOR));
            $installer = new Installer($fakeApp);
            \think\Container::setInstance($fakeApp);
            try {
                $installer->writeEnv(['type' => 'sqlite', 'name' => 'runtime/hwadmin.sqlite']);
                $envContent = (string) file_get_contents($tmpRoot . '.env');
                $this->assertStringContainsString('APP_DEBUG = false', $envContent);
                $this->assertStringNotContainsString('APP_DEBUG = true', $envContent);
            } finally {
                \think\Container::setInstance($oldApp);
                $this->app = $this->createIsolatedApp();
                @unlink($tmpRoot . '.env');
                @rmdir($tmpRoot);
            }

            // 5. InstallCommand 随机强密码与 cookie.php 安全默认
            $pwd1 = InstallCommand::generateStrongPassword(16);
            $pwd2 = InstallCommand::generateStrongPassword(16);
            $this->assertGreaterThanOrEqual(16, strlen($pwd1));
            $this->assertNotSame($pwd1, $pwd2);
            $this->assertNotSame('Admin12345', $pwd1);
            $this->assertMatchesRegularExpression('/[A-Z]/', $pwd1);
            $this->assertMatchesRegularExpression('/[a-z]/', $pwd1);
            $this->assertMatchesRegularExpression('/\d/', $pwd1);

            $cookieCfg = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cookie.php';
            $this->assertTrue((bool) ($cookieCfg['httponly'] ?? false));
            $this->assertSame('Lax', $cookieCfg['samesite'] ?? '');
        } finally {
            foreach ([$sub, $other] as $acc) {
                Db::name('auth_group_access')->where('admin_id', $acc['admin_id'])->delete();
                Db::name('admin')->where('id', $acc['admin_id'])->delete();
                Db::name('auth_group')->where('id', $acc['group_id'])->delete();
                Db::name('token')->where('type', 'admin')->where('user_id', $acc['admin_id'])->delete();
            }
            if (!empty($tempRuleIds)) {
                Db::name('auth_rule')->whereIn('id', $tempRuleIds)->delete();
            }
        }
    }
}
