<?php
declare(strict_types=1);

namespace core\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 跨域中间件：仅放行 config('hwadmin.cors_origins') 白名单中的来源。
 * 生产环境推荐前后端同域部署（后台静态文件放 public/admin），无需跨域。
 */
class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->header('origin', '');
        $allowed = (array) config('hwadmin.cors_origins', []);
        $headers = [];
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $headers = [
                'Access-Control-Allow-Origin'  => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Token, X-Requested-With, Accept-Language',
                'Access-Control-Max-Age'       => '86400',
                'Vary'                         => 'Origin',
            ];
        }
        if ($request->method(true) === 'OPTIONS') {
            return Response::create('', 'html', 204)->header($headers);
        }
        return $next($request)->header($headers);
    }
}
