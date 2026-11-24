<?php
declare(strict_types=1);

namespace core\auth;

/**
 * 密码哈希。新密码统一使用 password_hash（bcrypt/argon2，PHP 默认算法）。
 *
 * 兼容历史旧数据：password = md5(md5(明文) . salt)，salt 非空。
 * 旧账号首次登录验证通过后由调用方调用 hash() 升级并清空 salt。
 */
final class PasswordHasher
{
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash, string $salt = ''): bool
    {
        if ($hash === '') {
            return false;
        }
        if (self::isLegacy($hash, $salt)) {
            return hash_equals($hash, md5(md5($plain) . $salt));
        }
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash, string $salt = ''): bool
    {
        return self::isLegacy($hash, $salt) || password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    protected static function isLegacy(string $hash, string $salt): bool
    {
        return $salt !== '' && strlen($hash) === 32 && ctype_xdigit($hash);
    }
}
