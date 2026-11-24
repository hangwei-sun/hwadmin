<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 权限规则/菜单模型
 */
class AuthRule extends UnixTimeModel
{
    protected $name = 'auth_rule';
}
