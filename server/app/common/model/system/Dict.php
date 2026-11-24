<?php
declare(strict_types=1);

namespace app\common\model\system;

use core\base\UnixTimeModel;
use think\model\relation\HasMany;

/**
 * 数据字典主表模型
 */
class Dict extends UnixTimeModel
{
    protected $name = 'dict';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联字典明细项
     */
    public function items(): HasMany
    {
        return $this->hasMany(DictItem::class, 'dict_id', 'id')->order('sort', 'asc')->order('id', 'asc');
    }
}
