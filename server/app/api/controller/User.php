<?php
declare(strict_types=1);

namespace app\api\controller;

use core\auth\PasswordHasher;
use core\auth\TokenService;
use core\auth\UserAuth;
use core\base\ApiController;
use core\exception\ApiException;
use core\support\WechatMiniAuthService;
use think\facade\Db;

/**
 * 会员接口（登录/退出/资料/注册/找回与重置密码/头像上传/微信小程序登录）
 *
 * POST /api/user/login          { account, password }
 * POST /api/user/register       { username, password, nickname, mobile }
 * POST /api/user/sendResetCode  { account }
 * POST /api/user/resetPassword  { account, new_password, reset_token, old_password }
 * POST /api/user/wechatLogin    { code, nickname, avatar }
 * POST /api/user/upload         (multipart file)
 * POST /api/user/logout
 * GET  /api/user/info
 */
class User extends ApiController
{
    protected array $noNeedLogin = ['login', 'register', 'sendResetCode', 'resetPassword', 'wechatLogin'];

    /** 允许上传的真实图片扩展名与 MIME 映射表 */
    private const ALLOWED_IMAGE_MIMES = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /**
     * 会员账号密码登录
     * @method POST
     */
    public function login(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['account', 'password']);
        $this->validate($data, [
            'account|账号'  => 'require|max:100',
            'password|密码' => 'require|max:64',
        ]);
        $result = $this->auth->login((string) $data['account'], (string) $data['password'], $this->request->ip());
        event('UserLoginAfter', $result['user']);
        $this->success($result, '登录成功');
    }

    /**
     * 会员快速注册
     * @method POST
     */
    public function register(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['username', 'password', 'nickname', 'mobile']);
        $this->validate($data, [
            'username|用户名' => 'require|alphaNum|length:3,30',
            'password|密码'   => 'require|length:6,32',
            'nickname|昵称'   => 'max:50',
            'mobile|手机号'   => 'mobile',
        ]);

        $username = (string) $data['username'];
        $exists = Db::name('user')->where('username', $username)->find();
        if ($exists) {
            throw new ApiException('该用户名已被注册');
        }

        $now = time();
        Db::name('user')->insertGetId([
            'username'        => $username,
            'nickname'        => (string) (($data['nickname'] ?? '') ?: $username),
            'password'        => PasswordHasher::hash((string) $data['password']),
            'salt'            => '',
            'mobile'          => (string) ($data['mobile'] ?? ''),
            'status'          => 1,
            'create_time'     => $now,
            'update_time'     => $now,
            'last_login_time' => $now,
            'last_login_ip'   => $this->request->ip(),
        ]);

        $result = $this->auth->login($username, (string) $data['password'], $this->request->ip());
        $this->success($result, '注册成功');
    }

    /**
     * 申请单次限时密码恢复凭证（600秒有效、单次消费、60秒发送冷却、5次错误锁定）
     * @method POST
     */
    public function sendResetCode(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['account']);
        $this->validate($data, [
            'account|账号' => 'require|max:100',
        ]);

        $account = trim((string) $data['account']);
        $user = $this->findUserByAccount($account);
        if (!$user || (int) ($user['status'] ?? 0) !== 1) {
            throw new ApiException('账号不存在或已被禁用');
        }

        $userId = (int) $user['id'];
        $this->withResetStateLock($userId, function () use ($userId, $user, $account): void {
            $now = time();
            $state = $this->loadResetState($userId);
            if (!empty($state['username']) && $state['username'] !== (string) ($user['username'] ?? '')) {
                $state = [];
            }

            if (((int) ($state['locked_until'] ?? 0)) > $now) {
                $waitLock = (int) $state['locked_until'] - $now;
                throw new ApiException("密码重置尝试次数过多，账号恢复已锁定，请在 {$waitLock} 秒后重试");
            }

            $lastSentAt = (int) ($state['last_sent_at'] ?? 0);
            $isConsumed = !empty($state['consumed']);
            if (!$isConsumed && $lastSentAt > 0 && ($now - $lastSentAt) < 60) {
                $waitCooldown = 60 - ($now - $lastSentAt);
                throw new ApiException("发送过于频繁，请在 {$waitCooldown} 秒后重试");
            }

            $plainToken = bin2hex(random_bytes(16));
            $newState = [
                'user_id'      => $userId,
                'username'     => (string) ($user['username'] ?? ''),
                'account'      => $account,
                'token_hash'   => hash('sha256', $plainToken),
                'created_at'   => $now,
                'last_sent_at' => $now,
                'expires_at'   => $now + 600,
                'attempts'     => 0,
                'max_attempts' => 5,
                'locked_until' => 0,
                'consumed'     => false,
                'request_ip'   => $this->request->ip(),
            ];

            $respData = [
                'expires_in' => 600,
                'cooldown'   => 60,
            ];
            // Debug mode alone must never turn this public endpoint into an account
            // takeover endpoint. Only the isolated automated test environment echoes it.
            if ($this->app->isDebug() && (string) env('APP_ENV', '') === 'testing') {
                $respData['reset_token'] = $plainToken;
            } else {
                $delivered = event('UserPasswordResetRequested', [
                    'user_id' => $userId,
                    'account' => $account,
                    'email' => (string) ($user['email'] ?? ''),
                    'mobile' => (string) ($user['mobile'] ?? ''),
                    'reset_token' => $plainToken,
                    'expires_at' => $newState['expires_at'],
                ]);
                if (!in_array(true, (array) $delivered, true)) {
                    $this->error('尚未配置身份验证后的密码恢复发送渠道', 0,
                        ['todo' => 'CONFIGURE_PASSWORD_RECOVERY_DELIVERY'], 503);
                }
            }

            $this->saveResetState($userId, $newState);
            $this->success($respData, '密码恢复凭证已发送');
        });
    }

    /**
     * 安全重置密码（R01：阻断匿名无凭证改密，要求有效单次 reset_token 或已登录 + old_password，改密后吊销全部旧 Token）
     * @method POST
     */
    public function resetPassword(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['account', 'new_password', 'reset_token', 'old_password']);
        $this->validate($data, [
            'new_password|新密码' => 'require|length:6,32',
        ]);

        $account = trim((string) ($data['account'] ?? ''));
        $newPassword = (string) $data['new_password'];
        $resetToken = trim((string) ($data['reset_token'] ?? ''));
        $oldPassword = (string) ($data['old_password'] ?? '');

        // 路径一：已登录会员凭原密码改密
        if ($this->auth->isLogin() && $oldPassword !== '' && $resetToken === '') {
            $userId = $this->auth->id();
            $this->withResetStateLock($userId, function () use ($userId, $account, $oldPassword, $newPassword): void {
                $user = Db::name('user')->where('id', $userId)->find();
                if (!$user) {
                    throw new ApiException('会员不存在');
                }
                if ($account !== '') {
                    $matchedAccount = in_array($account, [
                        (string) ($user['username'] ?? ''),
                        (string) ($user['email'] ?? ''),
                        (string) ($user['mobile'] ?? ''),
                    ], true);
                    if (!$matchedAccount) {
                        throw new ApiException('操作账号与当前登录身份不匹配');
                    }
                }
                if (!PasswordHasher::verify($oldPassword, (string) $user['password'], (string) ($user['salt'] ?? ''))) {
                    throw new ApiException('原密码不正确');
                }

                $this->applyNewPasswordAndRevokeTokens((int) $user['id'], $newPassword);
                $this->success(null, '密码修改成功，请使用新密码重新登录');
            });
        }

        // 路径二：未登录或使用单次恢复凭证重置密码（严禁仅凭 account + new_password 改密）
        if ($account === '') {
            throw new ApiException('账号不能为空');
        }
        if ($resetToken === '') {
            throw new ApiException('安全拦截：重置密码必须提供有效的单次恢复凭证(reset_token)或已登录态下的原密码(old_password)');
        }

        $user = $this->findUserByAccount($account);
        if (!$user) {
            throw new ApiException('账号不存在');
        }

        $userId = (int) $user['id'];
        $this->withResetStateLock($userId, function () use ($userId, $user, $resetToken, $newPassword): void {
            $now = time();
            $state = $this->loadResetState($userId);
            if (!empty($state['username']) && $state['username'] !== (string) ($user['username'] ?? '')) {
                $state = [];
            }

            if (empty($state) || !empty($state['consumed']) || empty($state['token_hash'])) {
                throw new ApiException('恢复凭证无效或已失效');
            }

            if (((int) ($state['locked_until'] ?? 0)) > $now) {
                throw new ApiException('恢复凭证错误次数过多已被锁定，请稍后再试');
            }

            if (((int) ($state['expires_at'] ?? 0)) < $now) {
                $state['consumed'] = true;
                $state['token_hash'] = '';
                $this->saveResetState($userId, $state);
                throw new ApiException('恢复凭证已过期，请重新获取');
            }

            $inputHash = hash('sha256', $resetToken);
            if (!hash_equals((string) $state['token_hash'], $inputHash)) {
                $attempts = ((int) ($state['attempts'] ?? 0)) + 1;
                $state['attempts'] = $attempts;
                if ($attempts >= (int) ($state['max_attempts'] ?? 5)) {
                    $state['locked_until'] = $now + 600;
                    $state['consumed'] = true;
                    $state['token_hash'] = '';
                }
                $this->saveResetState($userId, $state);
                throw new ApiException('恢复凭证不正确');
            }

            // 校验通过：立即销毁单次恢复凭证（防止二次重放）
            $state['consumed'] = true;
            $state['token_hash'] = '';
            $state['consumed_at'] = $now;
            $state['last_sent_at'] = 0;
            $state['attempts'] = 0;
            $this->saveResetState($userId, $state);

            // 更新密码并吊销该用户所有已签发的会员 Token
            $this->applyNewPasswordAndRevokeTokens($userId, $newPassword);

            $this->success(null, '密码重置成功，请使用新密码登录');
        });
    }

    /**
     * 微信小程序授权登录（R11：诊断凭据 + 可控 jscode2session 适配器 + 会员绑定与真实 Token 签发）
     * @method POST
     */
    public function wechatLogin(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }

        $code = trim((string) $this->request->post('code', ''));
        $service = new WechatMiniAuthService($this->app);
        $diag = $service->diagnoseCredentials();

        // 诊断微信凭据是否齐全：绝不伪造成功！
        if (!$diag['ready']) {
            $this->error(
                '微信小程序配置未就绪，缺少 ' . implode(' / ', $diag['missing_env']) . ' 凭据',
                10001,
                [
                    'todo'        => $diag['todo'],
                    'missing_env' => $diag['missing_env'],
                    'required'    => $diag['required'],
                    'instruction' => $diag['instruction'],
                ],
                400
            );
        }

        if ($code === '') {
            $this->error('缺少微信 js_code 授权凭据', 0, null, 400);
        }

        $profileHint = [
            'nickname'     => (string) $this->request->post('nickname', ''),
            'avatar'       => (string) $this->request->post('avatar', ''),
            'bind_user_id' => $this->auth->isLogin() ? $this->auth->id() : 0,
        ];

        $result = $service->loginByCode($code, $this->request->ip(), $profileHint);
        event('UserLoginAfter', $result['user']);
        $this->success($result, '微信登录成功');
    }

    /**
     * 会员头像上传（强制校验扩展名、大小、finfo_file 真实 MIME 与 getimagesize 图像结构）
     * @method POST
     */
    public function upload(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }

        $file = null;
        if (isset($_FILES['file']) && $_FILES['file'] instanceof \think\File) {
            $file = $_FILES['file'];
        } else {
            $file = $this->request->file('file');
            if (!$file && !empty($_FILES['file']) && is_array($_FILES['file'])) {
                $this->request->withFiles($_FILES);
                $file = $this->request->file('file');
            }
        }
        if (!$file) {
            throw new ApiException('未找到上传的文件');
        }

        $origName = (string) $file->getOriginalName();
        if ($origName === '' || str_contains($origName, "\0")) {
            throw new ApiException('非法的文件名');
        }

        // 拦截双扩展名与脚本伪装（如 evil.php.png, payload.svg.png）
        if (preg_match('/\.(php\d*|phtml|phar|sh|bash|bat|cmd|exe|dll|jsp|asp|aspx|cgi|pl|py|rb|js|mjs|html?|htm|xhtml|svg|xml|htaccess|user\.ini)\b/i', $origName)) {
            throw new ApiException('安全拦截：文件名包含可执行脚本或危险扩展名片段');
        }

        $allowedExts = array_keys(self::ALLOWED_IMAGE_MIMES);
        $ext = strtolower((string) $file->extension());
        if (!in_array($ext, $allowedExts, true)) {
            throw new ApiException('不支持的文件格式，仅允许：' . implode(', ', $allowedExts));
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new ApiException('上传文件内容为空');
        }
        if ($size > 2 * 1024 * 1024) {
            throw new ApiException('上传文件不能超过 2MB');
        }

        $tmpPath = $file->getPathname();
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new ApiException('上传临时文件不可读');
        }

        // 1. 使用 finfo_file 校验真实二进制图片 MIME 类型
        $realMime = $this->detectRealMime($tmpPath);
        $allowedMimesForExt = self::ALLOWED_IMAGE_MIMES[$ext] ?? [];
        if (!in_array($realMime, $allowedMimesForExt, true)) {
            throw new ApiException(sprintf('安全拦截：文件真实内容类型 (%s) 与图片扩展名 (.%s) 不匹配', $realMime, $ext));
        }

        // 2. 使用 getimagesize 校验真实图像宽高与结构头，拒绝伪装为图片的非图像/脚本内容
        $imgInfo = @getimagesize($tmpPath);
        $validImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (
            $imgInfo === false
            || (int) ($imgInfo[0] ?? 0) <= 0
            || (int) ($imgInfo[1] ?? 0) <= 0
            || !in_array((int) ($imgInfo[2] ?? 0), $validImageTypes, true)
        ) {
            throw new ApiException('安全拦截：文件内容非合法的图像结构或图片已损坏');
        }

        $dateDir = date('Ymd');
        $targetDir = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatar' . DIRECTORY_SEPARATOR . $dateDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $file->move($targetDir, $saveName);
        $url = '/uploads/avatar/' . $dateDir . '/' . $saveName;

        $userId = $this->auth->id();
        if ($userId > 0) {
            Db::name('user')->where('id', $userId)->update([
                'avatar'      => $url,
                'update_time' => time(),
            ]);
        }

        $this->success([
            'url'       => $url,
            'file_name' => $origName,
            'mime'      => $realMime,
            'width'     => (int) $imgInfo[0],
            'height'    => (int) $imgInfo[1],
        ], '上传成功');
    }

    /**
     * 退出当前会员登录会话
     * @method POST
     */
    public function logout(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $this->auth->logout();
        $this->success(null, '已退出');
    }

    /**
     * 获取当前登录会员个人资料
     * @method GET
     */
    public function info(): void
    {
        $this->success($this->auth->profile());
    }

    protected function findUserByAccount(string $account): ?array
    {
        $field = filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email'
            : (preg_match('/^1\d{10}$/', $account) ? 'mobile' : 'username');

        $row = Db::name('user')->where($field, $account)->find();
        return is_array($row) ? $row : null;
    }

    protected function applyNewPasswordAndRevokeTokens(int $userId, string $newPassword): void
    {
        Db::transaction(function () use ($userId, $newPassword): void {
            Db::name('user')->where('id', $userId)->update([
                'password'    => PasswordHasher::hash($newPassword),
                'salt'        => '',
                'update_time' => time(),
            ]);

            /** @var TokenService $tokenService */
            $tokenService = $this->app->make(TokenService::class);
            $tokenService->revokeAll(UserAuth::TOKEN_TYPE, $userId);
        });
        // Changing the password with the old password invalidates recovery
        // credentials issued before that change as well.
        $state = $this->loadResetState($userId);
        if ($state !== []) {
            $state['consumed'] = true;
            $state['token_hash'] = '';
            $this->saveResetState($userId, $state);
        }
    }

    protected function withResetStateLock(int $userId, callable $operation): void
    {
        $handle = fopen($this->getResetStorePath($userId) . '.lock', 'c');
        if ($handle === false) {
            throw new ApiException('密码恢复状态暂不可用');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ApiException('无法锁定密码恢复状态');
            }
            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function getResetStorePath(int $userId): string
    {
        $dir = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'password_resets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'user_' . $userId . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadResetState(int $userId): array
    {
        $file = $this->getResetStorePath($userId);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function saveResetState(int $userId, array $state): void
    {
        $file = $this->getResetStorePath($userId);
        $temporary = tempnam(dirname($file), '.reset-');
        if ($temporary === false) {
            throw new ApiException('无法保存密码恢复状态');
        }
        try {
            if (!chmod($temporary, 0600)
                || file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false
                || !rename($temporary, $file)) {
                throw new ApiException('无法保存密码恢复状态');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function detectRealMime(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                if (PHP_VERSION_ID < 80500 && function_exists('finfo_close')) {
                    @finfo_close($finfo);
                }
                if (is_string($mime) && $mime !== '') {
                    return strtolower(trim($mime));
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim($mime));
            }
        }
        return 'application/octet-stream';
    }
}
