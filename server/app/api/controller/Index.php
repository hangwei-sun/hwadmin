<?php
declare(strict_types=1);

namespace app\api\controller;

use core\base\ApiController;

/**
 * 前台公共接口
 *
 * GET /api/index/ping  健康检查
 */
class Index extends ApiController
{
    protected array $noNeedLogin = ['*'];

    public function ping(): void
    {
        $this->success(['version' => hw_version(), 'installed' => is_installed()]);
    }
}
