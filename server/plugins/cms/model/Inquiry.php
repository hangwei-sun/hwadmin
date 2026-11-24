<?php
declare(strict_types=1);

namespace plugins\cms\model;

use core\base\UnixTimeModel;

/**
 * CMS 留言与询单模型
 */
class Inquiry extends UnixTimeModel
{
    protected $name = 'cms_inquiry';
    protected $autoWriteTimestamp = true;
}
