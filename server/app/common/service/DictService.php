<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\system\Dict as DictModel;
use app\common\model\system\DictItem as DictItemModel;
use think\facade\Cache;

/**
 * 数据字典读取与缓存服务（60s 缓存 + 写失效）
 */
class DictService
{
    public const CACHE_TTL = 60;
    public const MAP_CACHE_KEY = 'hw:dict:map_all';
    public const VERSION_CACHE_KEY = 'hw:dict:version';

    /**
     * 按字典编码批量获取启用状态的字典项映射
     *
     * @param string[] $codes
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getMapByCodes(array $codes): array
    {
        $cleanCodes = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $codes
        ))));
        if (empty($cleanCodes)) {
            return [];
        }

        $allMap = self::loadAllMap();
        $result = [];
        foreach ($cleanCodes as $code) {
            $result[$code] = $allMap[$code] ?? [];
        }
        return $result;
    }

    /**
     * 按单个字典编码获取启用状态的字典项列表
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getByCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }
        $allMap = self::loadAllMap();
        return $allMap[$code] ?? [];
    }

    /**
     * 按字典 ID 获取明细项列表（含禁用项，供管理端使用，带 60s 缓存）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getItemsByDictId(int $dictId): array
    {
        if ($dictId <= 0) {
            return [];
        }

        try {
            $ver = self::getVersion();
            $cacheKey = "hw:dict:items:{$dictId}:v{$ver}";
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $list = DictItemModel::where('dict_id', $dictId)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            Cache::set($cacheKey, $list, self::CACHE_TTL);
            return $list;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 写操作后主动失效字典缓存
     */
    public static function clearCache(): void
    {
        try {
            Cache::delete(self::MAP_CACHE_KEY);
            $nextVer = self::getVersion() + 1;
            Cache::set(self::VERSION_CACHE_KEY, $nextVer, 86400);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected static function loadAllMap(): array
    {
        try {
            $cached = Cache::get(self::MAP_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }

            $dicts = DictModel::where('status', 1)->select();
            $allMap = [];
            if (!$dicts->isEmpty()) {
                $dictMap = [];
                $dictIds = [];
                foreach ($dicts as $dict) {
                    $dictMap[(int) $dict->id] = (string) $dict->code;
                    $dictIds[] = (int) $dict->id;
                    $allMap[(string) $dict->code] = [];
                }

                $items = DictItemModel::whereIn('dict_id', $dictIds)
                    ->where('status', 1)
                    ->order('sort', 'asc')
                    ->order('id', 'asc')
                    ->select();

                foreach ($items as $item) {
                    $code = $dictMap[(int) $item->dict_id] ?? '';
                    if ($code !== '') {
                        $allMap[$code][] = [
                            'label'    => $item->label,
                            'value'    => $item->value,
                            'tag_type' => $item->tag_type,
                            'sort'     => $item->sort,
                        ];
                    }
                }
            }

            Cache::set(self::MAP_CACHE_KEY, $allMap, self::CACHE_TTL);
            return $allMap;
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function getVersion(): int
    {
        try {
            $ver = Cache::get(self::VERSION_CACHE_KEY);
            return is_numeric($ver) ? (int) $ver : 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
