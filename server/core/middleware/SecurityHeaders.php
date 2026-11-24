<?php
declare(strict_types=1);

namespace core\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 全局 HTTP 安全响应头与请求标识中间件（S09）
 *
 * 核心安全策略：
 * 1. X-Content-Type-Options: nosniff
 * 2. X-Frame-Options: SAMEORIGIN
 * 3. X-XSS-Protection: 1; mode=block
 * 4. Referrer-Policy: strict-origin-when-cross-origin
 * 5. Content-Security-Policy: 兼容 Vue 3 + Vite 单页管理后台的受限 CSP 策略
 * 6. X-Request-Id: 全链路追踪标识
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->header('x-request-id', '');
        if ($requestId === '' || !preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        if (property_exists($request, 'requestId')) {
            $request->requestId = $requestId;
        }

        /** @var Response $response */
        $response = $next($request);

        // 默认 CSP 策略：允许同源及常用本地资源，禁止未授权嵌套与对象执行（放行前台外部图片加载）
        $csp = (string) config('hwadmin.security.csp', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self';");

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'X-XSS-Protection'       => '1; mode=block',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Request-Id'           => $requestId,
        ];

        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        return $response->header($headers);
    }
}
