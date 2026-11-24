<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class SiteInquiry extends UnixTimeModel
{
    protected $name = 'site_inquiry';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
