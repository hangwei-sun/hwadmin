<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class EnterpriseTask extends UnixTimeModel
{
    protected $name = 'enterprise_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
