<?php
declare(strict_types=1);

namespace app\common\service;

use core\support\AdminEntry;
use think\facade\Cache;
use think\facade\Db;

/**
 * 系统配置读取与缓存服务（60s 缓存 + 写失效）
 */
class ConfigService
{
    public const CACHE_TTL = 60;
    public const CACHE_KEY = 'hw:config:snapshot';

    /**
     * 获取单个配置项值
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    /**
     * 获取全部配置键值对 [name => value]
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $snapshot = self::loadSnapshot();
        return $snapshot['all'];
    }

    /**
     * 按配置分组批量获取键值对 [name => value]
     *
     * @param string|string[] $groups
     * @return array<string, string>
     */
    public static function getByGroup(string|array $groups): array
    {
        $groupList = is_array($groups) ? $groups : [$groups];
        $snapshot = self::loadSnapshot();
        $result = [];
        foreach ($groupList as $group) {
            $g = (string) $group;
            if (isset($snapshot['groups'][$g]) && is_array($snapshot['groups'][$g])) {
                foreach ($snapshot['groups'][$g] as $k => $v) {
                    $result[$k] = $v;
                }
            }
        }
        return $result;
    }

    /**
     * 按配置键名列表批量获取 [name => value]
     *
     * @param string[] $names
     * @return array<string, string>
     */
    public static function getMany(array $names): array
    {
        $all = self::all();
        $result = [];
        foreach ($names as $name) {
            $k = (string) $name;
            if (array_key_exists($k, $all)) {
                $result[$k] = $all[$k];
            }
        }
        return $result;
    }

    /**
     * 写操作后主动失效配置缓存
     */
    public static function clearCache(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
            Cache::delete('hw:dashboard:summary');
        } catch (\Throwable) {
        }
        if (class_exists(AdminEntry::class)) {
            AdminEntry::clearCache();
        }
    }

    /**
     * @return array{all: array<string, string>, groups: array<string, array<string, string>>}
     */
    protected static function loadSnapshot(): array
    {
        $empty = ['all' => [], 'groups' => []];
        try {
            if (function_exists('is_installed') && !is_installed()) {
                return $empty;
            }
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['all'], $cached['groups']) && is_array($cached['all']) && is_array($cached['groups'])) {
                return $cached;
            }

            $rows = Db::name('config')->field('name, group, value')->select()->toArray();
            $all = [];
            $groups = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $val = (string) ($row['value'] ?? '');
                $grp = (string) ($row['group'] ?? '');
                $all[$name] = $val;
                if ($grp !== '') {
                    $groups[$grp][$name] = $val;
                }
            }
            $snapshot = ['all' => $all, 'groups' => $groups];
            Cache::set(self::CACHE_KEY, $snapshot, self::CACHE_TTL);
            return $snapshot;
        } catch (\Throwable) {
            return $empty;
        }
    }
}
