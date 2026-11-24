<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 角色组模型
 */
class AuthGroup extends UnixTimeModel
{
    protected $name = 'auth_group';
}
