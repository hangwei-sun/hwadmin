<?php
declare(strict_types=1);

namespace plugins\cms\model;

use core\base\UnixTimeModel;

/**
 * CMS 网站横幅模型
 */
class Banner extends UnixTimeModel
{
    protected $name = 'cms_banner';
    protected $autoWriteTimestamp = true;
}
