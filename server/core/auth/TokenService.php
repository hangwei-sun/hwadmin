<?php
declare(strict_types=1);

namespace core\auth;

use think\facade\Db;
use think\Request;

/**
 * 不透明令牌服务（Opaque Token）
 *
 * - 明文令牌只在签发时返回给客户端一次；数据库仅保存 sha256 哈希，泄库不可直接冒用；
 * - 支持服务端吊销（退出登录、修改密码、禁用账号时调用 revokeAll）；
 * - type 区分令牌归属：admin=后台管理员，user=会员，插件可自定义类型。
 *
 * 安全基线（P1-4）：
 * - 默认严禁从 URL Query 或 POST Body 中读取 token（防止网关日志、浏览器历史或 Referer 泄露管理员令牌）；
 * - 仅接受 `Authorization: Bearer <token>` 或 `X-Token` 请求头。
 */
class TokenService
{
    protected const TABLE = 'token';

    /**
     * 从请求头中读取令牌：优先 Authorization: Bearer，其次 X-Token 头（默认不从 query/body 读取）
     */
    public static function fromRequest(Request $request, bool $allowQueryToken = false): string
    {
        $auth = (string) $request->header('authorization', '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $header = (string) config('hwadmin.token.header', 'X-Token');
        $token = (string) $request->header($header, '');
        if ($token === '' && $allowQueryToken) {
            $token = (string) $request->param('token', '');
        }
        return trim($token);
    }

    /**
     * 签发令牌，返回明文
     */
    public function issue(string $type, int $userId, int $ttl): string
    {
        $plain = bin2hex(random_bytes(32));
        $now = time();
        Db::name(self::TABLE)->insert([
            'token'       => self::hash($plain),
            'type'        => $type,
            'user_id'     => $userId,
            'expire_time' => $ttl > 0 ? $now + $ttl : 0,
            'create_time' => $now,
        ]);
        // 顺带清理过期令牌（低频，1% 概率）
        if (random_int(1, 100) === 1) {
            $this->gc();
        }
        return $plain;
    }

    /**
     * 校验令牌，成功返回令牌记录（含 user_id、expire_time），失败返回 null
     */
    public function verify(string $type, string $plain): ?array
    {
        if ($plain === '' || strlen($plain) > 128) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('token', self::hash($plain))
            ->where('type', $type)
            ->find();
        if (!$row) {
            return null;
        }
        if ((int) $row['expire_time'] > 0 && (int) $row['expire_time'] < time()) {
            $this->revoke($plain);
            return null;
        }
        return $row;
    }

    public function revoke(string $plain): void
    {
        Db::name(self::TABLE)->where('token', self::hash($plain))->delete();
    }

    /**
     * 吊销某用户全部令牌（改密、禁用时使用）
     */
    public function revokeAll(string $type, int $userId): void
    {
        Db::name(self::TABLE)->where('type', $type)->where('user_id', $userId)->delete();
        if ($type === AdminAuth::TOKEN_TYPE) {
            AdminAuth::clearCache($userId);
        }
    }

    public function gc(): void
    {
        Db::name(self::TABLE)->where('expire_time', '>', 0)->where('expire_time', '<', time())->delete();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
