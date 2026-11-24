<?php
declare(strict_types=1);

namespace core\auth;

/**
 * 密码哈希别名兼容类，转发至 PasswordHasher
 */
class PasswordHash
{
    public static function hash(string $plain): string
    {
        return PasswordHasher::hash($plain);
    }

    public static function verify(string $plain, string $hash, string $salt = ''): bool
    {
        return PasswordHasher::verify($plain, $hash, $salt);
    }

    public static function needsRehash(string $hash, string $salt = ''): bool
    {
        return PasswordHasher::needsRehash($hash, $salt);
    }
}
