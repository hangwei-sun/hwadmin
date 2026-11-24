<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 管理员模型
 */
class Admin extends UnixTimeModel
{
    protected $name = 'admin';

    protected $hidden = ['password', 'salt', 'lock_password'];
}
