<?php
declare(strict_types=1);

namespace core\auth;

use core\exception\ApiException;
use think\facade\Db;

/**
 * 会员认证（user 表）。
 *
 * 安全基线（P1-5）：
 * - 与后台认证共享 LoginThrottle 双维度防爆破限流（account+ip 与 ip），防止撞库与枚举。
 */
class UserAuth
{
    public const TOKEN_TYPE = 'user';

    protected const SAFE_FIELDS = ['id', 'group_id', 'username', 'nickname', 'avatar', 'email', 'mobile', 'gender', 'level', 'score', 'money', 'status'];

    protected ?array $user = null;
    protected string $token = '';

    public function __construct(protected TokenService $tokens)
    {
    }

    public function init(string $token): bool
    {
        $this->user = null;
        $this->token = '';
        $row = $this->tokens->verify(self::TOKEN_TYPE, $token);
        if (!$row) {
            return false;
        }
        $user = Db::name('user')->where('id', $row['user_id'])->find();
        if (!$user || (int) $user['status'] !== 1) {
            return false;
        }
        $this->user = $user;
        $this->token = $token;
        return true;
    }

    /**
     * 账号（用户名/邮箱/手机号）+ 密码登录
     */
    public function login(string $account, string $password, string $ip = ''): array
    {
        LoginThrottle::check(self::TOKEN_TYPE, $account, $ip);

        $field = filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email'
            : (preg_match('/^1\d{10}$/', $account) ? 'mobile' : 'username');
        $user = Db::name('user')->where($field, $account)->find();
        if (!$user || !PasswordHasher::verify($password, (string) $user['password'], (string) ($user['salt'] ?? ''))) {
            LoginThrottle::hit(self::TOKEN_TYPE, $account, $ip);
            throw new ApiException('账号或密码错误');
        }
        if ((int) $user['status'] !== 1) {
            throw new ApiException('账号已被禁用');
        }

        LoginThrottle::clear(self::TOKEN_TYPE, $account, $ip);

        $update = ['last_login_time' => time(), 'last_login_ip' => $ip, 'update_time' => time()];
        if (PasswordHasher::needsRehash((string) $user['password'], (string) ($user['salt'] ?? ''))) {
            $update['password'] = PasswordHasher::hash($password);
            $update['salt'] = '';
        }
        Db::name('user')->where('id', $user['id'])->update($update);

        $ttl = (int) config('hwadmin.token.user_ttl', 2592000);
        $token = $this->tokens->issue(self::TOKEN_TYPE, (int) $user['id'], $ttl);
        $this->init($token);
        return ['token' => $token, 'expires_in' => $ttl, 'user' => $this->profile()];
    }

    public function logout(): void
    {
        if ($this->token !== '') {
            $this->tokens->revoke($this->token);
        }
        $this->user = null;
        $this->token = '';
    }

    public function isLogin(): bool
    {
        return $this->user !== null;
    }

    public function isSuper(): bool
    {
        return false;
    }

    public function id(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function profile(): array
    {
        return $this->user ? array_intersect_key($this->user, array_flip(self::SAFE_FIELDS)) : [];
    }
}
