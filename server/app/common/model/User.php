<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 会员模型
 */
class User extends UnixTimeModel
{
    protected $name = 'user';

    protected $hidden = ['password', 'salt'];
}
