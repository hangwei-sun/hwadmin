<?php
declare(strict_types=1);

namespace tests\Unit;

use core\support\OpenApiExporter;
use core\support\ProjectSchemaService;
use core\support\RouteInspector;
use core\support\WechatMiniAuthService;
use tests\TestCase;
use think\facade\Db;
use think\file\UploadedFile;

/**
 * R01 / R11 / R12 专项安全阻断与契约一致性正反例回归测试
 */
final class R01R11R12SecurityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        WechatMiniAuthService::resetTestState();
        unset($_ENV['WECHAT_MINI_APPID'], $_ENV['WECHAT_MINI_SECRET'], $_ENV['WECHAT_MINI_MOCK_CODES']);
        $_FILES = [];
        parent::tearDown();
    }

    /**
     * R01: 阻断未认证密码重置、校验限时单次恢复凭证、防重放与全量旧会话吊销
     */
    public function testR01PasswordResetBlocksUnauthenticatedAndRevokesOldSessions(): void
    {
        $username = 'r01user' . random_int(10000, 99999);
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewPassword456!';

        // 1. 注册测试会员并获取初始会话 Token
        $regResp = $this->guestPost('/api/user/register', [
            'username' => $username,
            'password' => $oldPassword,
            'nickname' => 'R01安全测试员',
        ]);
        $this->assertApiSuccess($regResp, '注册会员应成功');
        $oldToken = (string) ($regResp['data']['token'] ?? '');
        $userId = (int) ($regResp['data']['user']['id'] ?? 0);
        $this->assertNotEmpty($oldToken);
        $this->assertGreaterThan(0, $userId);

        // 确认旧 Token 可正常访问个人资料
        $infoBefore = $this->dispatchHttp('GET', '/api/user/info', [], [], $oldToken);
        $this->assertApiSuccess($infoBefore, '初始 Token 访问 info 应成功');

        // 2. 反例一：匿名仅提供 account + new_password 必须拒绝 (code=0)
        $anonReset = $this->guestPost('/api/user/resetPassword', [
            'account'      => $username,
            'new_password' => 'HackedPass999!',
        ]);
        $this->assertApiFail($anonReset, 0, '匿名无凭证重置密码必须被拒绝');

        // 确认原密码未被篡改，原会话依然有效
        $loginStillWorks = $this->guestPost('/api/user/login', [
            'account'  => $username,
            'password' => $oldPassword,
        ]);
        $this->assertApiSuccess($loginStillWorks, '被拦截后原密码仍应可正常登录');
        $secondOldToken = (string) ($loginStillWorks['data']['token'] ?? '');

        // 3. 申请恢复凭证并验证 60 秒发送冷却限制
        $sendResp = $this->guestPost('/api/user/sendResetCode', [
            'account' => $username,
        ]);
        $this->assertApiSuccess($sendResp, '申请密码恢复凭证应成功');
        $resetToken = (string) ($sendResp['data']['reset_token'] ?? '');
        $this->assertNotEmpty($resetToken);
        $this->assertSame(600, $sendResp['data']['expires_in'] ?? 0);

        // 反例二：60 秒冷却期内重复发送必须被拒绝
        $cooldownResp = $this->guestPost('/api/user/sendResetCode', [
            'account' => $username,
        ]);
        $this->assertApiFail($cooldownResp, 0, '60秒冷却期内重复请求恢复凭证应被拒绝');

        // 4. 反例三：错误恢复凭证必须失败，且连续 5 次错误触发锁定
        for ($i = 1; $i <= 5; $i++) {
            $wrongResp = $this->guestPost('/api/user/resetPassword', [
                'account'      => $username,
                'new_password' => $newPassword,
                'reset_token'  => 'wrong_token_attempt_' . $i,
            ]);
            $this->assertApiFail($wrongResp, 0, "第 {$i} 次错误恢复凭证应被拒绝");
        }

        // 锁定后即使传入刚才生成的正确 reset_token 也必须失败
        $lockedResp = $this->guestPost('/api/user/resetPassword', [
            'account'      => $username,
            'new_password' => $newPassword,
            'reset_token'  => $resetToken,
        ]);
        $this->assertApiFail($lockedResp, 0, '错误达5次后凭证应锁定失效');

        // 5. 反例四：过期恢复凭证必须失败
        $resetFile = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'password_resets' . DIRECTORY_SEPARATOR . 'user_' . $userId . '.json';
        $expiredToken = bin2hex(random_bytes(16));
        file_put_contents($resetFile, json_encode([
            'user_id'      => $userId,
            'account'      => $username,
            'token_hash'   => hash('sha256', $expiredToken),
            'created_at'   => time() - 700,
            'last_sent_at' => time() - 700,
            'expires_at'   => time() - 10,
            'attempts'     => 0,
            'max_attempts' => 5,
            'locked_until' => 0,
            'consumed'     => false,
        ]));

        $expiredResp = $this->guestPost('/api/user/resetPassword', [
            'account'      => $username,
            'new_password' => $newPassword,
            'reset_token'  => $expiredToken,
        ]);
        $this->assertApiFail($expiredResp, 0, '已过期的恢复凭证必须被拒绝');

        // 6. 正例：重新申请合法未过期的恢复凭证并完成密码重置
        $sendResp2 = $this->guestPost('/api/user/sendResetCode', [
            'account' => $username,
        ]);
        $this->assertApiSuccess($sendResp2, '过期后重新申请恢复凭证应成功');
        $validResetToken = (string) ($sendResp2['data']['reset_token'] ?? '');
        $this->assertNotEmpty($validResetToken);

        $resetOk = $this->guestPost('/api/user/resetPassword', [
            'account'      => $username,
            'new_password' => $newPassword,
            'reset_token'  => $validResetToken,
        ]);
        $this->assertApiSuccess($resetOk, '携带合法单次恢复凭证重置密码应成功');

        // 7. 反例五：同一恢复凭证二次重放必须失败
        $replayResp = $this->guestPost('/api/user/resetPassword', [
            'account'      => $username,
            'new_password' => 'AnotherPass789!',
            'reset_token'  => $validResetToken,
        ]);
        $this->assertApiFail($replayResp, 0, '单次恢复凭证二次重放必须失败');

        // 8. 验证该用户此前签发的所有旧会员 Token 均已被吊销（访问 /api/user/info 返回 401）
        $infoWithOldToken1 = $this->dispatchHttp('GET', '/api/user/info', [], [], $oldToken);
        $this->assertApiFail($infoWithOldToken1, 401, '改密后旧 Token 1 必须立即失效返回 401');

        $infoWithOldToken2 = $this->dispatchHttp('GET', '/api/user/info', [], [], $secondOldToken);
        $this->assertApiFail($infoWithOldToken2, 401, '改密后旧 Token 2 必须立即失效返回 401');

        // 9. 验证旧密码无法登录，新密码可以正常登录
        $oldLoginFail = $this->guestPost('/api/user/login', [
            'account'  => $username,
            'password' => $oldPassword,
        ]);
        $this->assertApiFail($oldLoginFail, 0, '改密后旧密码登录应失败');

        $newLoginOk = $this->guestPost('/api/user/login', [
            'account'  => $username,
            'password' => $newPassword,
        ]);
        $this->assertApiSuccess($newLoginOk, '改密后新密码登录应成功');
        $activeToken = (string) ($newLoginOk['data']['token'] ?? '');
        $this->assertNotEmpty($activeToken);

        // 10. 路径一：已登录态凭 old_password 改密（正反例与全量吊销）
        $otherUsername = 'r01other' . random_int(10000, 99999);
        $otherReg = $this->guestPost('/api/user/register', [
            'username' => $otherUsername,
            'password' => 'OtherPass123!',
        ]);
        $this->assertApiSuccess($otherReg);

        $wrongOldPwdResp = $this->dispatchHttp('POST', '/api/user/resetPassword', [], [
            'old_password' => 'WrongOldPass999!',
            'new_password' => 'FinalPass789!',
        ], $activeToken);
        $this->assertApiFail($wrongOldPwdResp, 0, '已登录态原密码错误时应拒绝改密');

        $crossAccountResp = $this->dispatchHttp('POST', '/api/user/resetPassword', [], [
            'account'      => $otherUsername,
            'old_password' => $newPassword,
            'new_password' => 'FinalPass789!',
        ], $activeToken);
        $this->assertApiFail($crossAccountResp, 0, '已登录态不得越权修改其他账号密码');

        $selfResetOk = $this->dispatchHttp('POST', '/api/user/resetPassword', [], [
            'old_password' => $newPassword,
            'new_password' => 'FinalPass789!',
        ], $activeToken);
        $this->assertApiSuccess($selfResetOk, '已登录态提供正确原密码应允许改密');

        $afterSelfResetInfo = $this->dispatchHttp('GET', '/api/user/info', [], [], $activeToken);
        $this->assertApiFail($afterSelfResetInfo, 401, '凭原密码改密后当前会话 Token 也必须被吊销');
    }

    /**
     * R11: 微信登录缺凭证诊断、可控适配器正反例（合法 code / 失效 code / 平台报错）与会员绑定签发
     */
    public function testR11WechatMiniAuthAdapterAndIdentityBinding(): void
    {
        // 1. 缺 WECHAT_MINI_APPID / WECHAT_MINI_SECRET 时返回 code=0 及 missing_env 诊断
        WechatMiniAuthService::setTestCredentials('', '');
        $missingResp = $this->guestPost('/api/user/wechatLogin', [
            'code' => 'wx_probe_code_001',
        ]);
        $this->assertApiFail($missingResp, 10001, '缺少微信凭据时应返回稳定的配置待办码');
        $this->assertSame('CONFIGURE_WECHAT_CREDENTIALS', $missingResp['data']['todo'] ?? '');
        $this->assertContains('WECHAT_MINI_APPID', $missingResp['data']['missing_env'] ?? []);
        $this->assertContains('WECHAT_MINI_SECRET', $missingResp['data']['missing_env'] ?? []);

        // 2. 配置微信凭据与 WECHAT_MINI_MOCK_CODES 模拟环境变量，测试合法 code、失效 code 与平台错误
        $testOpenId = 'oWx_member_alpha_' . bin2hex(random_bytes(4));
        $testUnionId = 'uWx_union_alpha_' . bin2hex(random_bytes(4));
        WechatMiniAuthService::setTestCredentials('wx_test_appid_123456', 'wx_test_secret_abcdef');
        $_ENV['WECHAT_MINI_MOCK_CODES'] = json_encode([
            'valid_wx_code_1' => [
                'errcode'     => 0,
                'openid'      => $testOpenId,
                'unionid'     => $testUnionId,
                'session_key' => 'sk_test_001',
            ],
            'valid_wx_code_2' => [
                'errcode'     => 0,
                'openid'      => $testOpenId,
                'unionid'     => $testUnionId,
                'session_key' => 'sk_test_002',
            ],
            'expired_wx_code' => [
                'errcode' => 40029,
                'errmsg'  => 'invalid code',
            ],
            'busy_wx_code' => [
                'errcode' => -1,
                'errmsg'  => 'system busy',
            ],
        ], JSON_UNESCAPED_UNICODE);

        // 反例一：失效 code 返回 code=0
        $invalidCodeResp = $this->guestPost('/api/user/wechatLogin', [
            'code' => 'expired_wx_code',
        ]);
        $this->assertApiFail($invalidCodeResp, 0, '失效微信 code 应返回失败');
        $this->assertSame(40029, $invalidCodeResp['data']['errcode'] ?? 0);

        // 反例二：微信平台繁忙报错返回 code=0
        $platformErrResp = $this->guestPost('/api/user/wechatLogin', [
            'code' => 'busy_wx_code',
        ]);
        $this->assertApiFail($platformErrResp, 0, '微信平台错误应返回失败');
        $this->assertSame(-1, $platformErrResp['data']['errcode'] ?? 0);

        // 正例一：首次使用合法 code 登录，自动创建 hw_user 并签发有效 Token
        $firstLogin = $this->guestPost('/api/user/wechatLogin', [
            'code'     => 'valid_wx_code_1',
            'nickname' => '微信测试会员A',
        ]);
        $this->assertApiSuccess($firstLogin, '合法微信 code 登录应成功');
        $this->assertTrue((bool) ($firstLogin['data']['is_new'] ?? false));
        $this->assertSame($testOpenId, $firstLogin['data']['openid'] ?? '');
        $wxToken = (string) ($firstLogin['data']['token'] ?? '');
        $wxUserId = (int) ($firstLogin['data']['user']['id'] ?? 0);
        $this->assertNotEmpty($wxToken);
        $this->assertGreaterThan(0, $wxUserId);

        // 使用签发的微信会员 Token 访问 /api/user/info 应返回 200 & code=1
        $profileResp = $this->dispatchHttp('GET', '/api/user/info', [], [], $wxToken);
        $this->assertApiSuccess($profileResp, '微信登录签发的 Token 应能正常读取会员资料');
        $this->assertSame($wxUserId, (int) ($profileResp['data']['id'] ?? 0));

        // 正例二：同一 openid 再次使用新 code 登录，应命中已绑定会员而非重复建号
        $secondLogin = $this->guestPost('/api/user/wechatLogin', [
            'code' => 'valid_wx_code_2',
        ]);
        $this->assertApiSuccess($secondLogin, '同一 openid 二次微信登录应成功');
        $this->assertFalse((bool) ($secondLogin['data']['is_new'] ?? true));
        $this->assertSame($wxUserId, (int) ($secondLogin['data']['user']['id'] ?? 0));

        // 正例三：通过自定义 sessionResolver 适配器校验登录与绑定
        $resolverOpenId = 'oWx_resolver_beta_' . bin2hex(random_bytes(4));
        unset($_ENV['WECHAT_MINI_MOCK_CODES']);
        WechatMiniAuthService::setSessionResolver(static function (string $code, string $appId, string $secret) use ($resolverOpenId): array {
            if ($code === 'resolver_ok_code') {
                return ['errcode' => 0, 'openid' => $resolverOpenId, 'session_key' => 'sk_res'];
            }
            return ['errcode' => 40163, 'errmsg' => 'code been used'];
        });

        $resolverFail = $this->guestPost('/api/user/wechatLogin', ['code' => 'resolver_used_code']);
        $this->assertApiFail($resolverFail, 0, '自定义适配器返回微信错误码时应失败');

        $resolverOk = $this->guestPost('/api/user/wechatLogin', ['code' => 'resolver_ok_code']);
        $this->assertApiSuccess($resolverOk, '自定义适配器返回合法 openid 时应签发真实 Token');
        $this->assertSame($resolverOpenId, $resolverOk['data']['openid'] ?? '');
    }

    /**
     * 静态复核项：会员头像上传真实图片 MIME (finfo_file) 与图像结构 (getimagesize) 校验
     */
    public function testUserAvatarUploadRejectsDisguisedScriptAndAcceptsValidPng(): void
    {
        $username = 'uploaduser' . random_int(10000, 99999);
        $regResp = $this->guestPost('/api/user/register', [
            'username' => $username,
            'password' => 'UploadPass123!',
        ]);
        $this->assertApiSuccess($regResp);
        $token = (string) $regResp['data']['token'];

        $tmpDir = sys_get_temp_dir();

        // 1. 反例：伪装为 .png 的脚本/文本内容必须被拦截
        $fakePngPath = $tmpDir . DIRECTORY_SEPARATOR . 'fake_script_' . uniqid() . '.png';
        file_put_contents($fakePngPath, "<?php echo 'hacked'; ?>\nNOT_A_PNG_FILE");
        $_FILES['file'] = new UploadedFile($fakePngPath, 'avatar.png', 'image/png', UPLOAD_ERR_OK, true);

        $fakeResp = $this->dispatchHttp('POST', '/api/user/upload', [], [], $token);
        @unlink($fakePngPath);
        $this->assertApiFail($fakeResp, 0, '伪装成 .png 的非图片内容必须被拒绝');

        // 1b. 反例：双扩展名 evil.php.png 与真实 PNG 伪装为 .jpg 扩展名均必须被拦截
        $realPngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $doubleExtPath = $tmpDir . DIRECTORY_SEPARATOR . 'double_ext_' . uniqid() . '.png';
        file_put_contents($doubleExtPath, $realPngBytes);
        $_FILES['file'] = new UploadedFile($doubleExtPath, 'evil.php.png', 'image/png', UPLOAD_ERR_OK, true);
        $doubleExtResp = $this->dispatchHttp('POST', '/api/user/upload', [], [], $token);
        @unlink($doubleExtPath);
        $this->assertApiFail($doubleExtResp, 0, '双扩展名 evil.php.png 必须被拒绝');

        $mismatchPath = $tmpDir . DIRECTORY_SEPARATOR . 'mismatch_' . uniqid() . '.jpg';
        file_put_contents($mismatchPath, $realPngBytes);
        $_FILES['file'] = new UploadedFile($mismatchPath, 'mismatch.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);
        $mismatchResp = $this->dispatchHttp('POST', '/api/user/upload', [], [], $token);
        @unlink($mismatchPath);
        $this->assertApiFail($mismatchResp, 0, 'PNG 内容伪装为 .jpg 扩展名必须被 finfo_file 拦截');

        // 2. 正例：真实 1x1 PNG 图像应通过校验、落盘到 public/uploads/avatar/ 并更新头像
        $realPngPath = $tmpDir . DIRECTORY_SEPARATOR . 'real_img_' . uniqid() . '.png';
        file_put_contents($realPngPath, $realPngBytes);
        $_FILES['file'] = new UploadedFile($realPngPath, 'avatar.png', 'image/png', UPLOAD_ERR_OK, true);

        $realResp = $this->dispatchHttp('POST', '/api/user/upload', [], [], $token);
        @unlink($realPngPath);
        $this->assertApiSuccess($realResp, '真实 PNG 图片上传应成功');
        $this->assertSame('image/png', $realResp['data']['mime'] ?? '');
        $this->assertSame(1, $realResp['data']['width'] ?? 0);
        $this->assertSame(1, $realResp['data']['height'] ?? 0);
        $uploadedUrl = (string) ($realResp['data']['url'] ?? '');
        $this->assertStringStartsWith('/uploads/avatar/', $uploadedUrl);

        $savedFile = $this->app->getRootPath() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $uploadedUrl);
        $this->assertFileExists($savedFile, '上传成功的头像文件应真实存在于 public/uploads/avatar/ 目录下');
        @unlink($savedFile);
    }

    /**
     * R12: 描述协议完整 Schema 校验、RouteInspector 显式动词自省与 OpenAPI 契约一致性
     */
    public function testR12ProjectSchemaValidationAndIntrospectionOpenApiContract(): void
    {
        $schemaService = new ProjectSchemaService($this->app);

        // 1. 反例：非法版本号 not-a-version/invalid、非法 clients 字符串、非法 entities 字符串必须返回 valid=false
        $invalidDescriptor = [
            'app_id'            => 'hwadmin-bad',
            'app_name'          => 'Bad Descriptor',
            'app_version'       => 'not-a-version/invalid',
            'framework_version' => '1.4.0',
            'clients'           => 'not-an-array-client',
            'entities'          => 'not-an-object-entity',
        ];
        $valRes1 = $schemaService->validate($invalidDescriptor);
        $this->assertFalse($valRes1['valid'], '包含非法版本号及非法 clients/entities 类型的描述必须被拒绝');
        $this->assertNotEmpty($valRes1['errors']);

        // 2. 反例：非法客户端枚举与悬空关联引用必须返回 valid=false
        $invalidEnumAndRelation = [
            'app_id'            => 'hwadmin-bad-rel',
            'app_name'          => 'Bad Relation',
            'app_version'       => '1.0.0',
            'framework_version' => '1.4.0',
            'clients'           => ['admin-pc', 'unknown-client-platform'],
            'entities'          => [
                'post' => [
                    'table'  => 'post',
                    'title'  => '文章',
                    'fields' => [
                        ['name' => 'id', 'type' => 'integer', 'primary' => true],
                    ],
                ],
            ],
            'relations' => [
                [
                    'from'        => 'post',
                    'to'          => 'non_existent_entity',
                    'type'        => 'belongs_to',
                    'foreign_key' => 'category_id',
                ],
            ],
        ];
        $valRes2 = $schemaService->validate($invalidEnumAndRelation);
        $this->assertFalse($valRes2['valid'], '非法客户端枚举与悬空关联实体必须被拒绝');

        // 2b. 反例：显式空数组、无实体时的悬空 relations、primary_key 不存在均必须返回 valid=false
        $this->assertFalse($schemaService->validate([])['valid'], '显式传入空对象描述必须被拒绝');
        $this->assertFalse($schemaService->validate([
            'app_id'            => 'hwadmin-bad-empty-rel',
            'app_name'          => 'Bad Empty Rel',
            'app_version'       => '1.0.0',
            'framework_version' => '1.4.0',
            'clients'           => ['admin-pc'],
            'relations'         => [
                ['from' => 'a', 'to' => 'b', 'type' => 'belongs_to', 'foreign_key' => 'b_id'],
            ],
        ])['valid'], '未声明实体却包含悬空 relations 必须被拒绝');
        $this->assertFalse($schemaService->validate([
            'app_id'            => 'hwadmin-bad-pk',
            'app_name'          => 'Bad PK',
            'app_version'       => '1.0.0',
            'framework_version' => '1.4.0',
            'clients'           => ['admin-pc'],
            'entities'          => [
                'item' => [
                    'table'       => 'item',
                    'title'       => '条目',
                    'primary_key' => 'missing_pk',
                    'fields'      => [
                        ['name' => 'id', 'type' => 'integer', 'primary' => true],
                    ],
                ],
            ],
        ])['valid'], 'primary_key 指向不存在字段时必须被拒绝');

        // 恢复合法项目描述校验状态
        $validRes = $schemaService->validate($schemaService->load());
        $this->assertTrue($validRes['valid'], '默认 hw.project.json 应校验通过');

        // 3. 验证 RouteInspector 对 User 控制器的真实 HTTP 动词与 explicit 来源推导
        $inspector = new RouteInspector($this->app);
        $routesData = $inspector->inspect(['routes'], '', ['module' => 'api', 'controller' => 'User']);
        $apiControllers = $routesData['routes']['api'] ?? [];
        $this->assertNotEmpty($apiControllers);

        $userCtrl = null;
        foreach ($apiControllers as $ctrl) {
            if (($ctrl['class'] ?? '') === 'app\\api\\controller\\User') {
                $userCtrl = $ctrl;
                break;
            }
        }
        $this->assertNotNull($userCtrl, '应扫描到 app\\api\\controller\\User');

        $epMap = [];
        foreach ($userCtrl['endpoints'] as $ep) {
            $epMap[$ep['action']] = $ep;
        }

        foreach (['login', 'register', 'sendResetCode', 'resetPassword', 'wechatLogin', 'upload', 'logout'] as $postAction) {
            $this->assertArrayHasKey($postAction, $epMap, "User 控制器应包含 {$postAction} 端点");
            $this->assertSame('POST', $epMap[$postAction]['method'], "{$postAction} 动词必须为 POST");
            $this->assertSame('explicit', $epMap[$postAction]['source'], "{$postAction} source 必须为 explicit");
            $this->assertSame('explicit', $epMap[$postAction]['method_source'], "{$postAction} method_source 必须为 explicit");
        }
        $this->assertSame('GET', $epMap['info']['method'] ?? '');
        $this->assertSame('explicit', $epMap['info']['source'] ?? '');

        // 4. 验证 OpenAPI 导出器与自省动词、参数 Schema、鉴权要求一致
        $exporter = new OpenApiExporter($this->app);
        $openApi = $exporter->export();
        $paths = $openApi['paths'] ?? [];

        $this->assertArrayHasKey('/api/user/wechatLogin', $paths);
        $this->assertArrayHasKey('post', $paths['/api/user/wechatLogin']);
        $this->assertArrayNotHasKey('get', $paths['/api/user/wechatLogin']);

        $wxPostOp = $paths['/api/user/wechatLogin']['post'];
        $wxProps = $wxPostOp['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertArrayHasKey('code', $wxProps, 'OpenAPI wechatLogin 应导出 code 请求参数');
        $this->assertSame([], $wxPostOp['security'], '免登录接口 security 应为空数组');

        $resetPostOp = $paths['/api/user/resetPassword']['post'] ?? [];
        $resetProps = $resetPostOp['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertArrayHasKey('account', $resetProps);
        $this->assertArrayHasKey('new_password', $resetProps);
        $this->assertArrayHasKey('reset_token', $resetProps);

        $uploadPostOp = $paths['/api/user/upload']['post'] ?? [];
        $this->assertArrayHasKey('multipart/form-data', $uploadPostOp['requestBody']['content'] ?? []);
        $this->assertNotEmpty($uploadPostOp['security'] ?? [], '受保护接口 upload 必须声明 BearerAuth 鉴权');

        $logoutPostOp = $paths['/api/user/logout']['post'] ?? [];
        $this->assertNotEmpty($logoutPostOp['security'] ?? [], '受保护接口 logout 必须声明 BearerAuth 鉴权');
        $this->assertArrayNotHasKey('requestBody', $logoutPostOp, '无请求体的 POST /api/user/logout 不应生成空 requestBody');

        // 5. 验证跨端共享类型文件一致且无漂移
        $root = $schemaService->getProjectRoot();
        $pcTypes = (string) file_get_contents($root . '/admin-ui/src/types/shared-api.d.ts');
        $mobileTypes = (string) file_get_contents($root . '/admin-ui/mobile/src/types/shared-api.d.ts');
        $this->assertSame($pcTypes, $mobileTypes, 'PC 与移动端 shared-api.d.ts 必须保持完全一致');
        $this->assertStringContainsString('UserSendResetCodeParams', $pcTypes);
        $this->assertStringContainsString('UserResetPasswordParams', $pcTypes);
        $this->assertStringContainsString('UserWechatLoginParams', $pcTypes);
    }
}
