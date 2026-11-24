<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class BookingSlot extends UnixTimeModel
{
    protected $name = 'booking_slot';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
