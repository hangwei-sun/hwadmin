<?php
declare(strict_types=1);

namespace plugins\cms\model;

use core\base\UnixTimeModel;
use think\model\relation\BelongsTo;

/**
 * CMS 内容与商品模型
 */
class Article extends UnixTimeModel
{
    protected $name = 'cms_article';
    protected $autoWriteTimestamp = true;

    /**
     * 关联分类
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    /**
     * 获取解析后的规格数组
     */
    public function getSpecsAttr($value, array $data): array
    {
        $raw = $data['specs_json'] ?? '';
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取解析后的画廊图片数组
     */
    public function getGalleryAttr($value, array $data): array
    {
        $raw = $data['gallery_json'] ?? '';
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
