<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\App;

// [ 应用入口文件 ]

require __DIR__ . '/../vendor/autoload.php';

// 安全入口前置拦截与承载
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$cleanUri = rtrim($uriPath, '/');
if ($cleanUri === '') {
    $cleanUri = '/';
}

if ($cleanUri !== '/' && !str_starts_with($cleanUri, '/api') && !str_starts_with($cleanUri, '/adminapi') && !str_starts_with($cleanUri, '/install') && !str_starts_with($cleanUri, '/uploads') && !str_starts_with($cleanUri, '/plugins') && !str_starts_with($cleanUri, '/static')) {
    $adminEntry = \core\support\AdminEntry::getEntry();
    $adminHtml = __DIR__ . '/admin/index.html';
    $sendAdmin = function () use ($adminHtml) {
        $html = \core\support\AdminEntry::renderAdminHtml($adminHtml);
        if ($html !== '') {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Cache-Control: no-cache');
            echo $html;
            exit;
        }
    };
    $send404 = function () {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1 style="text-align:center;margin-top:100px;font-family:sans-serif;color:#64748b;">404 Not Found</h1></body></html>';
        exit;
    };

    // 1. 访问默认 /admin
    if ($cleanUri === '/admin' || $cleanUri === '/admin/index.html') {
        if ($adminEntry !== 'admin') {
            $send404();
        }
        $sendAdmin();
    }

    // 2. 访问自定义安全入口
    if ($adminEntry !== 'admin' && ($cleanUri === '/' . $adminEntry || $cleanUri === '/' . $adminEntry . '/index.html')) {
        $sendAdmin();
    }
}

// Disable PHP error display before initialization; production startup failures
// are handled without exposing paths or traces.
$isWebSapi = !in_array(PHP_SAPI, ['cli', 'cli-server'], true);
if ($isWebSapi) {
    ini_set('display_errors', '0');
}
$app = new App();
$http = $app->http;
try {
    $response = $http->run();
} catch (\Throwable $e) {
    if (!$isWebSapi) {
        throw $e;
    }
    $app->debug(false);
    error_log('[HwAdmin] Application initialization failed');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"code":0,"msg":"服务暂不可用","data":null}';
    return;
}
$response->send();
$http->end($response);
