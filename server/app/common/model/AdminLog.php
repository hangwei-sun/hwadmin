<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 管理员操作日志模型
 */
class AdminLog extends UnixTimeModel
{
    protected $name = 'admin_log';

    protected $updateTime = false;
}
