<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * UserGroup 模型（由 php think hw:crud 自动生成）
 */
class UserGroup extends UnixTimeModel
{
    protected $name = 'user_group';

    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
