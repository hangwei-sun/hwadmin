<?php
declare(strict_types=1);

namespace app\common\model\system;

use core\base\UnixTimeModel;
use think\model\relation\HasMany;

/**
 * 定时任务模型
 */
class Crontab extends UnixTimeModel
{
    protected $name = 'crontab';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联执行日志
     */
    public function logs(): HasMany
    {
        return $this->hasMany(CrontabLog::class, 'crontab_id', 'id')->order('create_time', 'desc');
    }
}
