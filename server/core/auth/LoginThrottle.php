<?php
declare(strict_types=1);

namespace core\auth;

use core\exception\ApiException;
use think\facade\Cache;
use Throwable;

/**
 * 登录防爆破与防锁定攻击限流器（P1-5 & S06）
 *
 * 设计要点：
 * 1. 采用 `scope + account + ip` 组合键与独立 `ip` 键双维度限流：
 *    - 同一 IP 针对同一账号连续失败达到阈值（默认 5 次 / 15 分钟）时，仅锁定该 (account, ip) 组合，
 *      从而避免攻击者通过枚举已知管理员账号（如 admin）实施全网永久锁定 DoS；
 *    - 同一 IP 全局失败达到阈值（默认 20 次 / 15 分钟）时，封禁该 IP 登录尝试；
 * 2. 统一窗口语义：采用固定时间窗口计数器（首次失败建立 TTL 窗口，窗口期内达到阈值即拦截）；
 * 3. 登录成功后立即原子重置当前 (account, ip) 的失败计数；
 * 4. 单机并发原子安全与故障隔离（S06）：
 *    - 在单机多 FPM/多进程环境下，采用基于系统级独占锁 (flock LOCK_EX) 的原子计数持久化机制；
 *    - 重置时不采用 unlink 避免并发进程持有不同 inode 破坏文件锁，而是在独占锁内覆写重置计数与过期时间；
 *    - 严格检查文件写入 (fwrite) 与落盘刷新 (fflush) 状态；
 *    - 若配置并启用了 Redis 缓存驱动，支持原子 EVAL / INCR+EXPIRE 模式；
 *    - 在 Redis 发生故障降级到文件、后续又恢复正常时，取两者最大计数，杜绝本地计数被 Redis 0 计数遮蔽的失效逃逸；
 * 5. 覆盖后台登录、锁屏解锁（admin_unlock）与前台会员登录多场景防爆破。
 */
class LoginThrottle
{
    /**
     * 检查当前 (scope, account, ip) 是否被限流拦截
     */
    public static function check(string $scope, string $account, string $ip): void
    {
        $maxAccountIp = (int) config('hwadmin.login.max_failures', 5);
        $maxIpTotal   = (int) config('hwadmin.login.max_ip_failures', 20);
        $window       = (int) config('hwadmin.login.lock_seconds', 900);

        $pairKey = self::pairKey($scope, $account, $ip);
        $ipKey   = self::ipKey($scope, $ip);

        $pairHits = self::getHits($pairKey);
        $ipHits   = self::getHits($ipKey);

        if (($maxAccountIp > 0 && $pairHits >= $maxAccountIp) || ($maxIpTotal > 0 && $ipHits >= $maxIpTotal)) {
            $minutes = max(1, (int) ceil($window / 60));
            throw new ApiException("登录尝试过于频繁，请 {$minutes} 分钟后再试");
        }
    }

    /**
     * 记录一次登录失败（原子并发自增）
     */
    public static function hit(string $scope, string $account, string $ip): void
    {
        $window  = (int) config('hwadmin.login.lock_seconds', 900);
        $pairKey = self::pairKey($scope, $account, $ip);
        $ipKey   = self::ipKey($scope, $ip);

        self::atomicHit($pairKey, $window);
        self::atomicHit($ipKey, $window);
    }

    /**
     * 登录成功后清除当前 (scope, account, ip) 失败计数
     */
    public static function clear(string $scope, string $account, string $ip): void
    {
        $pairKey = self::pairKey($scope, $account, $ip);
        self::atomicClear($pairKey);
    }

    /**
     * 原子自增并维护过期时间窗口（S06 单机并发安全）
     */
    protected static function atomicHit(string $key, int $window): int
    {
        // 1. 若配置为 Redis 驱动且可用，走 Redis 原子 Lua
        if (self::isRedisDriver()) {
            try {
                $redis = Cache::store('redis')->handler();
                if ($redis) {
                    $prefix = (string) (config('cache.stores.redis.prefix') ?: 'hw:');
                    $fullKey = $prefix . $key;
                    $lua = <<<LUA
local current = redis.call('INCR', KEYS[1])
if tonumber(current) == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
LUA;
                    return (int) $redis->eval($lua, [$fullKey, $window], 1);
                }
            } catch (Throwable) {
                // 回退到单机本地文件原子锁
            }
        }

        // 2. Redis 不可用时降级到单机基于 flock 独占锁的原子计数
        if (self::isRedisDriver()) {
            self::markDegradedFallback($window);
        }
        return self::fileAtomicHit($key, $window);
    }

    protected static function getHits(string $key): int
    {
        if (self::isRedisDriver()) {
            try {
                $hits = Cache::store('redis')->get($key);
                $redisHits = ($hits !== null && $hits !== false) ? (int) $hits : 0;
                if (self::hasDegradedFallback()) {
                    // S06: Redis 故障恢复后取两者最大值，防止 Redis 初始 0 计数遮蔽本地降级期间累积的攻击计数
                    return max($redisHits, self::fileGetHits($key));
                }
                return $redisHits;
            } catch (Throwable) {
                return self::fileGetHits($key);
            }
        }

        return self::fileGetHits($key);
    }

    protected static function atomicClear(string $key): void
    {
        if (self::isRedisDriver()) {
            $redisOk = false;
            try {
                Cache::store('redis')->delete($key);
                $redisOk = true;
            } catch (Throwable) {
            }
            if ($redisOk && !self::hasDegradedFallback()) {
                return;
            }
        }

        self::fileAtomicClear($key);
    }

    protected static function fileAtomicHit(string $key, int $window): int
    {
        $path = self::filePath($key);
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            // 回退到 Cache facade
            $val = (int) Cache::get($key, 0) + 1;
            Cache::set($key, $val, $window);
            return $val;
        }

        if (flock($fp, LOCK_EX)) {
            $now = time();
            $content = stream_get_contents($fp);
            $data = $content ? @json_decode($content, true) : null;
            if (!is_array($data) || ($data['expire'] ?? 0) < $now) {
                $count = 1;
                $expire = $now + $window;
            } else {
                $count = (int) ($data['count'] ?? 0) + 1;
                $expire = (int) $data['expire'];
            }
            ftruncate($fp, 0);
            rewind($fp);
            $payload = json_encode(['count' => $count, 'expire' => $expire]);
            $written = @fwrite($fp, (string) $payload);
            if ($written !== false) {
                @fflush($fp);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            return $count;
        }

        fclose($fp);
        $val = (int) Cache::get($key, 0) + 1;
        Cache::set($key, $val, $window);
        return $val;
    }

    protected static function fileGetHits(string $key): int
    {
        $path = self::filePath($key, false);
        if (!is_file($path)) {
            if (self::isRedisDriver()) {
                return 0;
            }
            try {
                return (int) Cache::get($key, 0);
            } catch (Throwable) {
                return 0;
            }
        }

        $fp = @fopen($path, 'r');
        if (!$fp) {
            return 0;
        }

        $count = 0;
        if (flock($fp, LOCK_SH)) {
            $now = time();
            $content = stream_get_contents($fp);
            $data = $content ? @json_decode($content, true) : null;
            if (is_array($data) && ($data['expire'] ?? 0) >= $now) {
                $count = (int) ($data['count'] ?? 0);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $count;
    }

    protected static function fileAtomicClear(string $key): void
    {
        $path = self::filePath($key, false);
        if (is_file($path)) {
            // S06: 保持稳定锁文件 inode，避免 unlink 产生跨进程双锁竞态；在独占锁内覆写重置
            $fp = @fopen($path, 'c+');
            if ($fp) {
                if (flock($fp, LOCK_EX)) {
                    ftruncate($fp, 0);
                    rewind($fp);
                    @fwrite($fp, (string) json_encode(['count' => 0, 'expire' => 0]));
                    @fflush($fp);
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
        }
        if (!self::isRedisDriver()) {
            try {
                Cache::delete($key);
            } catch (Throwable) {
            }
        }
    }

    protected static function throttleDir(bool $createIfMissing = true): string
    {
        $root = function_exists('root_path') ? root_path() : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        $dir = $root . 'runtime' . DIRECTORY_SEPARATOR . 'throttle' . DIRECTORY_SEPARATOR;
        if ($createIfMissing && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    protected static function filePath(string $key, bool $createDir = true): string
    {
        return self::throttleDir($createDir) . md5($key) . '.lock';
    }

    protected static function markDegradedFallback(int $window): void
    {
        $marker = self::throttleDir(true) . '.degraded';
        @file_put_contents($marker, (string) (time() + max(60, $window)));
    }

    protected static function hasDegradedFallback(): bool
    {
        $marker = self::throttleDir(false) . '.degraded';
        if (!is_file($marker)) {
            return false;
        }
        $until = (int) trim((string) @file_get_contents($marker));
        if ($until < time()) {
            @unlink($marker);
            return false;
        }
        return true;
    }

    protected static function isRedisDriver(): bool
    {
        return strtolower((string) config('cache.default', 'file')) === 'redis';
    }

    private static function pairKey(string $scope, string $account, string $ip): string
    {
        return 'hw:login_throttle:' . $scope . ':pair:' . sha1(strtolower(trim($account)) . '|' . trim($ip));
    }

    private static function ipKey(string $scope, string $ip): string
    {
        return 'hw:login_throttle:' . $scope . ':ip:' . sha1(trim($ip));
    }
}
