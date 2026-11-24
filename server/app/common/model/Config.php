<?php
declare(strict_types=1);

namespace app\common\model;

use app\common\service\ConfigService;
use core\base\UnixTimeModel;
use think\Model;

/**
 * 系统配置模型
 */
class Config extends UnixTimeModel
{
    protected $name = 'config';

    public static function onAfterWrite(Model $model): void
    {
        ConfigService::clearCache();
    }

    public static function onAfterDelete(Model $model): void
    {
        ConfigService::clearCache();
    }
}
