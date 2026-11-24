<?php
declare(strict_types=1);

namespace app\common\model\system;

use app\common\service\DictService;
use core\base\UnixTimeModel;
use think\Model;
use think\model\relation\BelongsTo;

/**
 * 数据字典明细项模型
 */
class DictItem extends UnixTimeModel
{
    protected $name = 'dict_item';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public static function onAfterWrite(Model $model): void
    {
        DictService::clearCache();
    }

    public static function onAfterDelete(Model $model): void
    {
        DictService::clearCache();
    }

    /**
     * 所属主字典
     */
    public function dict(): BelongsTo
    {
        return $this->belongsTo(Dict::class, 'dict_id', 'id');
    }
}
