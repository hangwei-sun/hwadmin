<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class BookingOrder extends UnixTimeModel
{
    protected $name = 'booking_order';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
