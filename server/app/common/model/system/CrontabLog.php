<?php
declare(strict_types=1);

namespace app\common\model\system;

use core\base\UnixTimeModel;
use think\model\relation\BelongsTo;

/**
 * 定时任务执行日志模型
 */
class CrontabLog extends UnixTimeModel
{
    protected $name = 'crontab_log';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    /**
     * 所属定时任务
     */
    public function crontab(): BelongsTo
    {
        return $this->belongsTo(Crontab::class, 'crontab_id', 'id');
    }
}
