<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class EnterpriseProject extends UnixTimeModel
{
    protected $name = 'enterprise_project';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
