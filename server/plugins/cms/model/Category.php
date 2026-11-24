<?php
declare(strict_types=1);

namespace plugins\cms\model;

use core\base\UnixTimeModel;
use think\model\relation\HasMany;

/**
 * CMS 分类模型
 */
class Category extends UnixTimeModel
{
    protected $name = 'cms_category';
    protected $autoWriteTimestamp = true;

    /**
     * 分类关联内容与商品
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id', 'id');
    }
}
