<?php
declare(strict_types=1);

namespace core\support;

use Closure;
use core\auth\PasswordHasher;
use core\auth\TokenService;
use core\auth\UserAuth;
use core\exception\ApiException;
use think\App;
use think\facade\Db;

/**
 * 微信小程序登录与身份绑定服务（R11）
 *
 * 职责：
 * 1. 诊断 WECHAT_MINI_APPID / WECHAT_MINI_SECRET（兼容 WECHAT_MP_APPID / WECHAT_MP_SECRET）配置状态；
 * 2. 通过可注入适配器、WECHAT_MINI_MOCK_CODES 环境变量或真实 cURL 调用微信 jscode2session；
 * 3. 按 openid 查找或自动创建 hw_user 会员并持久化绑定关系，签发真实会员 Opaque Token。
 */
class WechatMiniAuthService
{
    /** @var callable|Closure|null 可注入的离线/测试 jscode2session 解析器 */
    protected static $sessionResolver = null;

    protected static ?string $testAppId = null;
    protected static ?string $testAppSecret = null;

    public function __construct(protected ?App $app = null)
    {
        $this->app = $app ?: app();
    }

    /**
     * 注入自定义 jscode2session 适配器（供单元测试或自定义网关使用）
     * 签名: fn(string $code, string $appId, string $appSecret): array
     */
    public static function setSessionResolver(?callable $resolver): void
    {
        self::$sessionResolver = $resolver;
    }

    /**
     * 设置测试态微信凭据覆盖
     */
    public static function setTestCredentials(?string $appId, ?string $appSecret): void
    {
        self::$testAppId = $appId;
        self::$testAppSecret = $appSecret;
    }

    /**
     * 重置所有测试注入状态
     */
    public static function resetTestState(): void
    {
        self::$sessionResolver = null;
        self::$testAppId = null;
        self::$testAppSecret = null;
    }

    /**
     * 诊断微信小程序 AppID / Secret 凭据配置
     *
     * @return array{ready: bool, app_id: string, app_secret: string, missing_env: string[], required: string[], todo: string, instruction: string}
     */
    public function diagnoseCredentials(): array
    {
        $appId = $this->isTestingEnvironment() && self::$testAppId !== null
            ? self::$testAppId
            : $this->readEnv('WECHAT_MINI_APPID', $this->readEnv('WECHAT_MP_APPID', (string) config('hwadmin.wechat.app_id', '')));

        $appSecret = $this->isTestingEnvironment() && self::$testAppSecret !== null
            ? self::$testAppSecret
            : $this->readEnv('WECHAT_MINI_SECRET', $this->readEnv('WECHAT_MP_SECRET', (string) config('hwadmin.wechat.app_secret', '')));

        $missingEnv = [];
        if ($appId === '' || str_contains($appId, 'your-')) {
            $missingEnv[] = 'WECHAT_MINI_APPID';
        }
        if ($appSecret === '' || str_contains($appSecret, 'your-')) {
            $missingEnv[] = 'WECHAT_MINI_SECRET';
        }

        return [
            'ready'       => empty($missingEnv),
            'app_id'      => $appId,
            'app_secret'  => $appSecret,
            'missing_env' => $missingEnv,
            'required'    => ['WECHAT_MINI_APPID', 'WECHAT_MINI_SECRET'],
            'todo'        => 'CONFIGURE_WECHAT_CREDENTIALS',
            'instruction' => '请在服务器或本地 .env 中补齐 WECHAT_MINI_APPID 与 WECHAT_MINI_SECRET（或 WECHAT_MP_APPID / WECHAT_MP_SECRET）后重试',
        ];
    }

    /**
     * 调用微信 jscode2session 换取 openid / session_key / unionid
     *
     * @return array{openid: string, session_key: string, unionid: string}
     */
    public function code2Session(string $code): array
    {
        $diag = $this->diagnoseCredentials();
        if (!$diag['ready']) {
            throw new ApiException(
                '微信小程序配置未就绪，缺少 ' . implode(' / ', $diag['missing_env']) . ' 凭据',
                0,
                [
                    'todo'        => $diag['todo'],
                    'missing_env' => $diag['missing_env'],
                    'required'    => $diag['required'],
                    'instruction' => $diag['instruction'],
                ],
                400
            );
        }

        $code = trim($code);
        if ($code === '') {
            throw new ApiException('缺少微信 js_code 授权凭据', 0, null, 400);
        }

        $appId = $diag['app_id'];
        $appSecret = $diag['app_secret'];

        if (!$this->isTestingEnvironment()
            && (self::$sessionResolver !== null || $this->readEnv('WECHAT_MINI_MOCK_CODES', '') !== '')) {
            throw new ApiException('微信模拟授权仅允许在 APP_ENV=testing 中使用', 0, null, 503);
        }

        if (self::$sessionResolver !== null) {
            $raw = (self::$sessionResolver)($code, $appId, $appSecret);
            $sessionData = is_array($raw) ? $raw : [];
        } else {
            $mockCodesRaw = $this->readEnv('WECHAT_MINI_MOCK_CODES', '');
            if ($mockCodesRaw !== '') {
                $sessionData = $this->resolveFromMockEnv($code, $mockCodesRaw);
            } else {
                $sessionData = $this->requestWechatApi($code, $appId, $appSecret);
            }
        }

        $errCode = (int) ($sessionData['errcode'] ?? 0);
        if ($errCode !== 0) {
            $errMsg = (string) ($sessionData['errmsg'] ?? '微信授权校验失败');
            throw new ApiException(
                sprintf('微信授权失败：%s (errcode=%d)', $errMsg, $errCode),
                0,
                [
                    'errcode' => $errCode,
                    'errmsg'  => $errMsg,
                ],
                400
            );
        }

        $openid = trim((string) ($sessionData['openid'] ?? ''));
        if ($openid === '' || !preg_match('/^[A-Za-z0-9_-]{4,128}$/', $openid)) {
            throw new ApiException('微信授权响应缺少合法的 openid', 0, null, 400);
        }

        return [
            'openid'      => $openid,
            'session_key' => (string) ($sessionData['session_key'] ?? ''),
            'unionid'     => (string) ($sessionData['unionid'] ?? ''),
        ];
    }

    /**
     * 完整执行微信小程序 code 登录：换取 openid -> 绑定或创建 hw_user -> 签发真实会员 Token
     *
     * @param array<string, mixed> $profileHint 可选昵称、头像或已登录绑定用户 ID
     * @return array<string, mixed>
     */
    public function loginByCode(string $code, string $ip = '', array $profileHint = []): array
    {
        $session = $this->code2Session($code);
        $openid = $session['openid'];
        $unionid = $session['unionid'];

        $bindResult = $this->bindOrCreateUser($openid, $unionid, $ip, $profileHint);
        $user = $bindResult['user'];
        $userId = (int) $user['id'];

        if ((int) ($user['status'] ?? 1) !== 1) {
            throw new ApiException('该会员账号已被禁用');
        }

        $ttl = (int) config('hwadmin.token.user_ttl', 2592000);
        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $token = $tokenService->issue(UserAuth::TOKEN_TYPE, $userId, $ttl);

        /** @var UserAuth $userAuth */
        $userAuth = $this->app->make(UserAuth::class);
        $userAuth->init($token);

        return [
            'token'      => $token,
            'expires_in' => $ttl,
            'openid'     => $openid,
            'unionid'    => $unionid,
            'is_new'     => $bindResult['is_new'],
            'user'       => $userAuth->profile(),
        ];
    }

    /**
     * 根据 openid 查找已绑定会员或自动创建新会员
     *
     * @param array<string, mixed> $profileHint
     * @return array{user: array<string, mixed>, is_new: bool}
     */
    public function bindOrCreateUser(string $openid, string $unionid = '', string $ip = '', array $profileHint = []): array
    {
        $bindingFile = $this->getBindingFilePath($openid);
        $legacyFile = dirname($bindingFile) . DIRECTORY_SEPARATOR . 'openid_' . hash('sha256', $openid) . '.json';
        if (!is_file($bindingFile) && is_file($legacyFile)) {
            $legacy = json_decode((string) file_get_contents($legacyFile), true);
            $legacyDbKey = is_array($legacy) ? (string) ($legacy['db_key'] ?? '') : '';
            if (is_array($legacy) && ($legacyDbKey === '' || $legacyDbKey === $this->getDbScopeKey())) {
                throw new ApiException('旧版微信绑定记录需要确认 AppID 后迁移', 0,
                    ['todo' => 'MIGRATE_WECHAT_BINDINGS'], 409);
            }
        }
        $directory = dirname($bindingFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ApiException('微信身份绑定目录不可写');
        }
        $lock = fopen($bindingFile . '.lock', 'c');
        if ($lock === false) {
            throw new ApiException('微信身份绑定不可用');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new ApiException('无法锁定微信身份绑定');
            }
            return $this->resolveBoundUser($openid, $unionid, $ip, $profileHint);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function resolveBoundUser(string $openid, string $unionid, string $ip, array $profileHint): array
    {
        $now = time();
        $wxUsername = 'wx_' . substr(hash('sha256', $openid), 0, 16) . '_' . bin2hex(random_bytes(4));
        $bindingFile = $this->getBindingFilePath($openid);

        $user = null;
        $isNew = false;

        // 1. 若调用方指定了已登录会员 ID 进行主动绑定
        $bindUserId = (int) ($profileHint['bind_user_id'] ?? 0);
        $explicitBind = false;

        // 2. 通过持久化绑定记录查找（严格校验当前数据库作用域与自动建号身份一致性）
        $dbKey = $this->getDbScopeKey();
        if (is_file($bindingFile)) {
            $bindData = json_decode((string) file_get_contents($bindingFile), true);
            $sameDb = is_array($bindData) && (($bindData['db_key'] ?? '') === $dbKey);
            $mappedUserId = $sameDb ? (int) ($bindData['user_id'] ?? 0) : 0;
            if ($mappedUserId <= 0) {
                throw new ApiException('微信身份绑定记录损坏，请联系管理员');
            }
            if ($mappedUserId > 0) {
                $found = Db::name('user')->where('id', $mappedUserId)->find();
                if (!$found || ($found['username'] ?? '') !== ($bindData['username'] ?? '')) {
                    throw new ApiException('微信身份绑定的会员已变更，请联系管理员');
                }
                if ($found) {
                    if ($bindUserId > 0 && $bindUserId !== $mappedUserId) {
                        throw new ApiException('该微信身份已绑定其他会员', 0, null, 409);
                    }
                    $user = $found;
                    $explicitBind = !empty($bindData['explicit_bind']);
                }
            }
        }

        // Publicly editable bio and usernames are never identity evidence.
        if (!$user && $bindUserId > 0) {
            $user = Db::name('user')->where('id', $bindUserId)->where('status', 1)->find();
            if (!$user) {
                throw new ApiException('待绑定的会员不存在或已禁用');
            }
            $explicitBind = true;
        }

        if ($user) {
            $update = [
                'last_login_time' => $now,
                'last_login_ip'   => $ip,
                'update_time'     => $now,
            ];
            if (!empty($profileHint['nickname']) && empty($user['nickname'])) {
                $update['nickname'] = mb_substr((string) $profileHint['nickname'], 0, 50);
            }
            if (!empty($profileHint['avatar']) && empty($user['avatar'])) {
                $update['avatar'] = mb_substr((string) $profileHint['avatar'], 0, 255);
            }
            Db::name('user')->where('id', $user['id'])->update($update);
            $user = array_merge($user, $update);
        } else {
            $isNew = true;
            $nickname = trim((string) ($profileHint['nickname'] ?? ''));
            if ($nickname === '') {
                $nickname = '微信会员_' . substr($wxUsername, 3, 6);
            }
            $avatar = trim((string) ($profileHint['avatar'] ?? ''));

            $insertData = [
                'group_id'        => 0,
                'username'        => $wxUsername,
                'nickname'        => mb_substr($nickname, 0, 50),
                'password'        => PasswordHasher::hash(bin2hex(random_bytes(16))),
                'salt'            => '',
                'email'           => '',
                'mobile'          => '',
                'avatar'          => mb_substr($avatar, 0, 255),
                'gender'          => 0,
                'bio'             => 'wechat:' . $openid,
                'level'           => 0,
                'money'           => 0,
                'score'           => 0,
                'last_login_time' => $now,
                'last_login_ip'   => $ip,
                'join_ip'         => $ip,
                'status'          => 1,
                'create_time'     => $now,
                'update_time'     => $now,
            ];

            $userId = (int) Db::name('user')->insertGetId($insertData);
            $user = Db::name('user')->where('id', $userId)->find() ?: array_merge($insertData, ['id' => $userId]);
        }

        // 保存绑定元数据
        $dir = dirname($bindingFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $binding = json_encode([
            'user_id'       => (int) $user['id'],
            'username'      => (string) $user['username'],
            'app_id'        => $this->diagnoseCredentials()['app_id'],
            'openid'        => $openid,
            'unionid'       => $unionid,
            'db_key'        => $dbKey,
            'explicit_bind' => $explicitBind,
            'updated_at'    => $now,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $temporary = tempnam($dir, '.binding-');
        if ($temporary === false) {
            throw new ApiException('无法持久化微信身份绑定');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $binding) === false
                || !rename($temporary, $bindingFile)) {
                throw new ApiException('无法持久化微信身份绑定');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return [
            'user'   => $user,
            'is_new' => $isNew,
        ];
    }

    /**
     * 解析 WECHAT_MINI_MOCK_CODES 模拟映射
     *
     * 支持 JSON 格式：{"valid_code":{"openid":"oWx_123","session_key":"sk"},"bad_code":{"errcode":40029,"errmsg":"invalid code"}}
     * 或简写键值对：valid_code=oWx_123,valid_code2=oWx_456
     *
     * @return array<string, mixed>
     */
    protected function resolveFromMockEnv(string $code, string $mockCodesRaw): array
    {
        $trimmed = trim($mockCodesRaw);
        $map = [];
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        } else {
            foreach (explode(',', $trimmed) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $pair, 2);
                $map[trim($k)] = trim($v);
            }
        }

        if (!array_key_exists($code, $map)) {
            return [
                'errcode' => 40029,
                'errmsg'  => 'invalid code',
            ];
        }

        $target = $map[$code];
        if (is_string($target)) {
            if (str_starts_with($target, 'err:')) {
                $errCode = (int) substr($target, 4) ?: -1;
                return [
                    'errcode' => $errCode,
                    'errmsg'  => 'wechat platform error',
                ];
            }
            return [
                'errcode'     => 0,
                'openid'      => $target,
                'session_key' => 'mock_session_key_' . substr(hash('sha256', $code), 0, 8),
                'unionid'     => '',
            ];
        }

        return is_array($target) ? $target : ['errcode' => 40029, 'errmsg' => 'invalid mock target'];
    }

    /**
     * 调用微信官方 sns/jscode2session 接口
     *
     * @return array<string, mixed>
     */
    protected function requestWechatApi(string $code, string $appId, string $appSecret): array
    {
        $url = 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query([
            'appid'      => $appId,
            'secret'     => $appSecret,
            'js_code'    => $code,
            'grant_type' => 'authorization_code',
        ]);

        if (!function_exists('curl_init')) {
            throw new ApiException('服务器未启用 cURL 扩展，无法请求微信接口', 0, null, 500);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('初始化微信请求失败', 0, null, 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $responseBody = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            throw new ApiException('请求微信 jscode2session 网络异常: ' . ($curlError ?: 'unknown error'), 0, [
                'curl_errno' => $curlErrno,
            ], 502);
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            throw new ApiException('解析微信 jscode2session 响应失败 (HTTP ' . $httpCode . ')', 0, null, 502);
        }

        return $decoded;
    }

    protected function getDbScopeKey(): string
    {
        try {
            $conn = Db::connect();
            $type = (string) ($conn->getConfig('type') ?: '');
            $host = (string) ($conn->getConfig('hostname') ?: '');
            $db = (string) ($conn->getConfig('database') ?: '');
            $prefix = (string) ($conn->getConfig('prefix') ?: '');
            return hash('sha256', $type . '|' . $host . '|' . $db . '|' . $prefix);
        } catch (\Throwable) {
            return 'default';
        }
    }

    public function getBindingsDirectory(): string
    {
        $runtimePath = $this->app
            ? ($this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR)
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        return $runtimePath . 'wechat_bindings';
    }

    public function getScopedBindingFilePath(string $openid, string $appId, ?string $dbKey = null): string
    {
        $scope = $appId . '|' . ($dbKey ?? $this->getDbScopeKey());
        return $this->getBindingsDirectory() . DIRECTORY_SEPARATOR . 'openid_' . hash('sha256', $scope . '|' . $openid) . '.json';
    }

    /**
     * 审查旧版微信绑定记录：列出待迁移记录、校验目标 user_id 存在性与冲突状态
     *
     * @return array<string, mixed>
     */
    public function inspectLegacyBindings(?string $targetAppId = null): array
    {
        $dir = $this->getBindingsDirectory();
        $currentDbKey = $this->getDbScopeKey();
        $diagAppId = $this->diagnoseCredentials()['app_id'];
        $requestedAppId = trim((string) ($targetAppId ?? ''));

        $items = [];
        $migratable = 0;
        $conflicts = 0;

        if (!is_dir($dir)) {
            return [
                'db_key'        => $currentDbKey,
                'target_app_id' => $requestedAppId !== '' ? $requestedAppId : $diagAppId,
                'total_legacy'  => 0,
                'migratable'    => 0,
                'conflicts'     => 0,
                'items'         => [],
            ];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'openid_*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $basename = basename($file);
            $raw = (string) file_get_contents($file);
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                $conflicts++;
                $items[] = [
                    'file'             => $basename,
                    'openid'           => '',
                    'openid_masked'    => '',
                    'user_id'          => 0,
                    'username'         => '',
                    'legacy_app_id'    => '',
                    'legacy_db_key'    => '',
                    'db_scope_match'   => false,
                    'user_exists'      => false,
                    'user_username'    => '',
                    'user_status'      => null,
                    'username_matches' => false,
                    'has_conflict'     => true,
                    'conflict_reasons' => ['invalid_binding_json'],
                    'status'           => 'invalid',
                ];
                continue;
            }

            $openid = trim((string) ($data['openid'] ?? ''));
            $legacyAppId = trim((string) ($data['app_id'] ?? ''));
            $legacyDbKey = trim((string) ($data['db_key'] ?? ''));

            // 若文件名严格符合已隔离的 app_id|db_key|openid 哈希，则属于正式结构而非旧格式
            if ($openid !== '' && $legacyAppId !== '' && $legacyDbKey !== '') {
                $expectedScopedName = 'openid_' . hash('sha256', $legacyAppId . '|' . $legacyDbKey . '|' . $openid) . '.json';
                if ($basename === $expectedScopedName) {
                    continue;
                }
            }

            $conflictReasons = [];
            if ($openid === '') {
                $conflictReasons[] = 'missing_openid';
            }

            $userId = (int) ($data['user_id'] ?? 0);
            $legacyUsername = trim((string) ($data['username'] ?? ''));
            $dbScopeMatch = ($legacyDbKey === '' || $legacyDbKey === $currentDbKey);
            if (!$dbScopeMatch) {
                $conflictReasons[] = 'db_scope_mismatch';
            }

            $userRow = $userId > 0 ? Db::name('user')->where('id', $userId)->find() : null;
            $userExists = is_array($userRow);
            $userUsername = $userExists ? (string) ($userRow['username'] ?? '') : '';
            $userStatus = $userExists ? (int) ($userRow['status'] ?? 1) : null;
            $usernameMatches = $userExists && ($legacyUsername === '' || $legacyUsername === $userUsername);

            if (!$userExists) {
                $conflictReasons[] = 'target_user_missing';
            } elseif (!$usernameMatches) {
                $conflictReasons[] = 'username_mismatch';
            }

            $effectiveAppId = $requestedAppId !== '' ? $requestedAppId : ($legacyAppId !== '' ? $legacyAppId : $diagAppId);
            if ($legacyAppId !== '' && $effectiveAppId !== '' && $legacyAppId !== $effectiveAppId) {
                $conflictReasons[] = 'app_id_mismatch';
            }

            $targetFile = '';
            $targetUserId = null;
            if ($openid !== '' && $effectiveAppId !== '') {
                $targetPath = $this->getScopedBindingFilePath($openid, $effectiveAppId, $currentDbKey);
                $targetFile = basename($targetPath);
                if (is_file($targetPath)) {
                    $targetData = json_decode((string) file_get_contents($targetPath), true);
                    if (!is_array($targetData)) {
                        $conflictReasons[] = 'target_binding_corrupted';
                    } else {
                        $targetUserId = (int) ($targetData['user_id'] ?? 0);
                        $targetUsername = (string) ($targetData['username'] ?? '');
                        if ($targetUserId !== $userId || ($userUsername !== '' && $targetUsername !== $userUsername)) {
                            $conflictReasons[] = 'target_binding_conflict';
                        } else {
                            $conflictReasons[] = 'target_binding_already_exists';
                        }
                    }
                }
            }

            $hasConflict = !empty($conflictReasons);
            if ($hasConflict) {
                $conflicts++;
            } else {
                $migratable++;
            }

            $status = 'ready';
            if (in_array('missing_openid', $conflictReasons, true)) {
                $status = 'invalid';
            } elseif (in_array('target_user_missing', $conflictReasons, true)) {
                $status = 'missing_user';
            } elseif ($hasConflict) {
                $status = 'conflict';
            }

            $items[] = [
                'file'             => $basename,
                'target_file'      => $targetFile,
                'target_user_id'   => $targetUserId,
                'openid'           => $openid,
                'openid_masked'    => $this->maskOpenId($openid),
                'user_id'          => $userId,
                'username'         => $legacyUsername,
                'legacy_app_id'    => $legacyAppId,
                'legacy_db_key'    => $legacyDbKey,
                'db_scope_match'   => $dbScopeMatch,
                'user_exists'      => $userExists,
                'user_username'    => $userUsername,
                'user_status'      => $userStatus,
                'username_matches' => $usernameMatches,
                'has_conflict'     => $hasConflict,
                'conflict_reasons' => $conflictReasons,
                'status'           => $status,
            ];
        }

        return [
            'db_key'        => $currentDbKey,
            'target_app_id' => $requestedAppId !== '' ? $requestedAppId : $diagAppId,
            'total_legacy'  => count($items),
            'migratable'    => $migratable,
            'conflicts'     => $conflicts,
            'items'         => $items,
        ];
    }

    /**
     * 管理员确认 AppID 后将旧版微信绑定安全迁移至按 app_id + db_key 隔离的正式绑定结构（严禁覆盖冲突绑定）
     *
     * @return array<string, mixed>
     */
    public function migrateLegacyBindings(string $appId, bool $confirm = false, ?string $onlyOpenId = null): array
    {
        $appId = trim($appId);
        if ($appId === '' || str_contains(strtolower($appId), 'your-') || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $appId)) {
            throw new ApiException('请提供合法的微信小程序 AppID（--app-id）', 0, ['todo' => 'PROVIDE_VALID_APP_ID'], 400);
        }
        if (!$confirm) {
            throw new ApiException('执行旧版微信绑定迁移必须显式传入 --confirm 确认归属', 0, ['todo' => 'CONFIRM_WECHAT_MIGRATION'], 400);
        }

        $inspection = $this->inspectLegacyBindings($appId);
        $dir = $this->getBindingsDirectory();
        $archiveDir = $dir . DIRECTORY_SEPARATOR . 'migrated';
        $currentDbKey = $this->getDbScopeKey();
        $only = $onlyOpenId !== null ? trim($onlyOpenId) : '';

        $migrated = [];
        $conflicts = [];
        $now = time();

        foreach ($inspection['items'] as $item) {
            if ($only !== '' && (string) ($item['openid'] ?? '') !== $only) {
                continue;
            }
            if (!empty($item['has_conflict'])) {
                $conflicts[] = [
                    'file'             => $item['file'],
                    'openid_masked'    => $item['openid_masked'],
                    'user_id'          => $item['user_id'],
                    'conflict_reasons' => $item['conflict_reasons'],
                    'status'           => $item['status'],
                ];
                continue;
            }

            $openid = (string) $item['openid'];
            $legacyPath = $dir . DIRECTORY_SEPARATOR . $item['file'];
            $targetPath = $this->getScopedBindingFilePath($openid, $appId, $currentDbKey);

            if (!is_file($legacyPath)) {
                continue;
            }

            $lockHandle = fopen($targetPath . '.lock', 'c');
            if ($lockHandle === false) {
                $conflicts[] = [
                    'file'             => $item['file'],
                    'openid_masked'    => $item['openid_masked'],
                    'user_id'          => $item['user_id'],
                    'conflict_reasons' => ['lock_open_failed'],
                    'status'           => 'conflict',
                ];
                continue;
            }

            try {
                if (!flock($lockHandle, LOCK_EX)) {
                    $conflicts[] = [
                        'file'             => $item['file'],
                        'openid_masked'    => $item['openid_masked'],
                        'user_id'          => $item['user_id'],
                        'conflict_reasons' => ['lock_acquire_failed'],
                        'status'           => 'conflict',
                    ];
                    continue;
                }

                // 加锁后再次确认目标文件不存在，严禁覆盖已有正式绑定
                if (is_file($targetPath)) {
                    $conflicts[] = [
                        'file'             => $item['file'],
                        'openid_masked'    => $item['openid_masked'],
                        'user_id'          => $item['user_id'],
                        'conflict_reasons' => ['target_binding_conflict'],
                        'status'           => 'conflict',
                    ];
                    continue;
                }

                $legacyRaw = json_decode((string) file_get_contents($legacyPath), true);
                if (!is_array($legacyRaw)) {
                    $conflicts[] = [
                        'file'             => $item['file'],
                        'openid_masked'    => $item['openid_masked'],
                        'user_id'          => $item['user_id'],
                        'conflict_reasons' => ['invalid_binding_json'],
                        'status'           => 'invalid',
                    ];
                    continue;
                }

                $userId = (int) ($legacyRaw['user_id'] ?? 0);
                $userRow = $userId > 0 ? Db::name('user')->where('id', $userId)->find() : null;
                if (!is_array($userRow)) {
                    $conflicts[] = [
                        'file'             => $item['file'],
                        'openid_masked'    => $item['openid_masked'],
                        'user_id'          => $userId,
                        'conflict_reasons' => ['target_user_missing'],
                        'status'           => 'missing_user',
                    ];
                    continue;
                }

                $scopedPayload = json_encode([
                    'user_id'       => $userId,
                    'username'      => (string) $userRow['username'],
                    'app_id'        => $appId,
                    'openid'        => $openid,
                    'unionid'       => (string) ($legacyRaw['unionid'] ?? ''),
                    'db_key'        => $currentDbKey,
                    'explicit_bind' => !empty($legacyRaw['explicit_bind']),
                    'updated_at'    => $now,
                    'migrated_from' => $item['file'],
                    'migrated_at'   => $now,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                $temporary = tempnam($dir, '.binding-mig-');
                if ($temporary === false) {
                    throw new ApiException('无法创建迁移临时绑定文件');
                }
                try {
                    if (!chmod($temporary, 0600)
                        || file_put_contents($temporary, $scopedPayload) === false
                        || !rename($temporary, $targetPath)) {
                        throw new ApiException('无法写入隔离微信绑定文件');
                    }
                } finally {
                    if (is_file($temporary)) {
                        @unlink($temporary);
                    }
                }

                if (!is_dir($archiveDir)) {
                    @mkdir($archiveDir, 0700, true);
                }
                $archivePath = $archiveDir . DIRECTORY_SEPARATOR . $now . '_' . $item['file'];
                @chmod($legacyPath, 0600);
                @rename($legacyPath, $archivePath);

                $migrated[] = [
                    'legacy_file'   => $item['file'],
                    'target_file'   => basename($targetPath),
                    'archived_to'   => 'migrated/' . basename($archivePath),
                    'openid_masked' => $item['openid_masked'],
                    'user_id'       => $userId,
                    'username'      => (string) $userRow['username'],
                    'app_id'        => $appId,
                ];
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        $status = !empty($conflicts) ? (empty($migrated) ? 'conflict' : 'partial') : 'success';

        return [
            'status'         => $status,
            'app_id'         => $appId,
            'db_key'         => $currentDbKey,
            'migrated_count' => count($migrated),
            'conflict_count' => count($conflicts),
            'migrated'       => $migrated,
            'conflicts'      => $conflicts,
        ];
    }

    protected function maskOpenId(string $openid): string
    {
        $len = strlen($openid);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return substr($openid, 0, 2) . str_repeat('*', max(2, $len - 2));
        }
        return substr($openid, 0, 4) . str_repeat('*', min(8, $len - 8)) . substr($openid, -4);
    }

    protected function getBindingFilePath(string $openid): string
    {
        return $this->getScopedBindingFilePath($openid, $this->diagnoseCredentials()['app_id']);
    }

    protected function isTestingEnvironment(): bool
    {
        return (string) $this->app->env->get('APP_ENV', '') === 'testing';
    }

    protected function readEnv(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && $fromGetenv !== '') {
            return (string) $fromGetenv;
        }
        if ($this->app !== null) {
            $fromApp = $this->app->env->get($key, '');
            if ($fromApp !== '' && $fromApp !== null) {
                return (string) $fromApp;
            }
        }
        $val = env($key, $default);
        return (string) ($val ?? $default);
    }
}
