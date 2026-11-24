<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class EnterpriseAttachment extends UnixTimeModel
{
    protected $name = 'enterprise_attachment';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = false;
}
