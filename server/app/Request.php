<?php
declare(strict_types=1);

namespace app;

// 应用请求对象类
class Request extends \think\Request
{
    public string $requestId = '';

    public function __construct()
    {
        parent::__construct();
        $trustedProxies = (string) (\think\facade\Env::get('TRUSTED_PROXIES', '') ?: '');
        if ($trustedProxies !== '') {
            $this->proxyServerIp = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
        }
    }
}
