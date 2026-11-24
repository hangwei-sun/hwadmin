<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 系统配置模型
 */
class Config extends UnixTimeModel
{
    protected $name = 'config';
}
