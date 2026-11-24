<?php
declare(strict_types=1);

namespace core\base;

use think\Model;

/** Keep integer timestamp columns numeric, including PostgreSQL bigint fields. */
abstract class UnixTimeModel extends Model
{
    protected function getDateTime(string $field)
    {
        if (in_array($this->getFields($field), ['bigint', 'smallint'], true)) {
            return time();
        }
        return parent::getDateTime($field);
    }
}
