<?php
declare (strict_types = 1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // The application has loaded APP_DEBUG before service registration.
        // Abort before plugin boot and request dispatch without changing multi-app initialization.
        if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) && $this->app->isDebug()) {
            $this->app->debug(false);
            error_log('[HwAdmin] Refusing production HTTP requests while APP_DEBUG is enabled');
            throw new \RuntimeException('Production debug is enabled');
        }
    }

    public function boot()
    {
        // 服务启动
    }
}
