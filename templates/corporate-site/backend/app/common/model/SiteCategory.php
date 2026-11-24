<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class SiteCategory extends UnixTimeModel
{
    protected $name = 'site_category';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
