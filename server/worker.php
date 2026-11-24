<?php
declare(strict_types=1);

/**
 * [EXPERIMENTAL / 实验性脚本] 仅供本地开发调试与基准测试使用，生产环境请使用 Nginx + PHP-FPM
 *
 * HwAdmin 常驻内存 HTTP Worker 服务启动入口
 *
 * 当安装了 Workerman 扩展包时，可实现高并发、常驻内存的 HTTP 服务运行：
 *   composer require workerman/workerman
 *   php worker.php start
 *   php worker.php restart
 *   php worker.php stop
 */

define('APP_PATH', __DIR__ . '/app');
require __DIR__ . '/vendor/autoload.php';

// 初始化基础辅助函数
if (is_file(__DIR__ . '/vendor/topthink/framework/src/helper.php')) {
    require_once __DIR__ . '/vendor/topthink/framework/src/helper.php';
}

// 实验性环境变量门禁检查：非调试模式且未显式开启实验性标志时拦截启动
$isDebug = filter_var(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : (function_exists('env') ? env('APP_DEBUG', false) : false), FILTER_VALIDATE_BOOLEAN);
$isExperimentalAllowed = getenv('HW_EXPERIMENTAL_WORKER') === '1';

if (!$isDebug && !$isExperimentalAllowed) {
    echo "\033[1;31m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "  [安全拦截 / SECURITY WARNING] 实验性常驻内存服务启动受阻                     " . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "\033[0m";
    echo "[EXPERIMENTAL / 实验性脚本] 仅供本地开发调试与基准测试使用，生产环境请使用 Nginx + PHP-FPM。" . PHP_EOL;
    echo "如确需在生产或无调试环境下启动测试，请显式设置环境变量：HW_EXPERIMENTAL_WORKER=1" . PHP_EOL . PHP_EOL;
    echo "启动示例：" . PHP_EOL;
    echo "    \033[36mHW_EXPERIMENTAL_WORKER=1 php worker.php start\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL . PHP_EOL;
    exit(1);
}

// 检查是否安装 Workerman 核心包
if (!class_exists(\Workerman\Worker::class)) {
    echo "\033[1;33m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "  [EXPERIMENTAL / 实验性脚本] 仅供本地开发调试与基准测试使用，生产环境请使用 Nginx + PHP-FPM " . PHP_EOL;
    echo "           HwAdmin 常驻内存 HTTP 引擎 (Workerman) 状态说明                        " . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "\033[0m";
    echo "提示：当前项目未检测到 workerman/workerman 依赖包。" . PHP_EOL;
    echo "如需启用极速常驻内存模式（吞吐性能提升 5~10 倍），请执行以下命令安装：" . PHP_EOL . PHP_EOL;
    echo "    \033[32mcomposer require workerman/workerman\033[0m" . PHP_EOL . PHP_EOL;
    echo "安装后再次运行此脚本即可体验原生常驻内存运行模式：" . PHP_EOL;
    echo "    \033[36mphp worker.php start\033[0m" . PHP_EOL . PHP_EOL;
    echo "在常规本地开发场景下，推荐使用以下内置服务启动：" . PHP_EOL;
    echo "    \033[36mbash scripts/wsl-dev.sh serve\033[0m  或  \033[36mphp think run -p 8000\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL . PHP_EOL;
    exit(0);
}

// 启动 Banner
echo "\033[1;36m" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  [EXPERIMENTAL / 实验性脚本] 仅供本地开发调试与基准测试使用，生产环境请使用 Nginx + PHP-FPM " . PHP_EOL;
echo "                  HwAdmin Workerman HTTP Service Starting                       " . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "\033[0m" . PHP_EOL;

$worker = new \Workerman\Worker('http://0.0.0.0:8000');
$worker->count = (int) (shell_exec('nproc') ?: 4);
$worker->name = 'HwAdminWorker';

$worker->onWorkerStart = static function () {
    // 常驻内存模式下强制开启数据库断线重连，防止长连接空闲超时后报错
    putenv('DB_BREAK_RECONNECT=true');
    $_ENV['DB_BREAK_RECONNECT'] = 'true';

    // 确保应用基座已加载
    if (is_file(__DIR__ . '/vendor/topthink/framework/src/helper.php')) {
        require_once __DIR__ . '/vendor/topthink/framework/src/helper.php';
    }
};

$worker->onMessage = static function ($connection, $request) {
    // 清理与重置全局超全局变量，防止常驻内存请求上下文污染
    $_GET = (array) ($request->get() ?? []);
    $_POST = (array) ($request->post() ?? []);
    $_COOKIE = (array) ($request->cookie() ?? []);
    $_FILES = (array) ($request->file() ?? []);
    $_REQUEST = array_merge($_GET, $_POST);

    $rawHeaders = (array) ($request->header() ?? []);
    $_SERVER = [
        'REQUEST_METHOD' => (string) $request->method(),
        'REQUEST_URI'    => (string) $request->uri(),
        'PATH_INFO'      => (string) ltrim($request->path(), '/'),
        'QUERY_STRING'   => (string) $request->queryString(),
        'SERVER_PROTOCOL'=> 'HTTP/' . ($request->protocolVersion() ?? '1.1'),
        'SERVER_NAME'    => 'localhost',
        'SERVER_PORT'    => '8000',
        'REMOTE_ADDR'    => (string) ($connection->getRemoteIp() ?: '127.0.0.1'),
        'CONTENT_TYPE'   => (string) ($rawHeaders['content-type'] ?? ''),
        'CONTENT_LENGTH' => (string) ($rawHeaders['content-length'] ?? ''),
    ];

    foreach ($rawHeaders as $key => $val) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $key));
        $_SERVER[$serverKey] = is_array($val) ? implode(', ', $val) : (string) $val;
    }

    $app = null;
    $http = null;
    $response = null;

    try {
        $app = new \think\App();
        $app->initialize();
        // 确保当前 Worker 进程内所有数据库连接均开启 break_reconnect
        $dbConfig = (array) $app->config->get('database', []);
        if (!empty($dbConfig['connections']) && is_array($dbConfig['connections'])) {
            foreach ($dbConfig['connections'] as $connName => $connCfg) {
                if (is_array($connCfg)) {
                    $dbConfig['connections'][$connName]['break_reconnect'] = true;
                }
            }
            $app->config->set($dbConfig, 'database');
        }
        $http = $app->http;

        // 构造 ThinkPHP Request 并填充 Workerman 传入的上下文
        $thinkRequest = $app->request;
        $thinkRequest->setMethod($_SERVER['REQUEST_METHOD'])
            ->setUrl($_SERVER['REQUEST_URI'])
            ->setPathinfo($_SERVER['PATH_INFO']);

        // 执行请求并获取响应
        $response = $http->run($thinkRequest);
        $content = (string) $response->getContent();

        // 封装 Workerman HTTP 响应并发送
        $workermanResponse = new \Workerman\Protocols\Http\Response(
            $response->getCode(),
            $response->getHeader(),
            $content
        );

        $connection->send($workermanResponse);
    } catch (\Throwable $e) {
        // 异常日志记录
        if ($app && isset($app->log)) {
            $app->log->error('[Worker 500] ' . $e->getMessage(), ['exception' => $e]);
        } else {
            error_log('[Worker 500] ' . $e->getMessage());
        }

        $isDebugMode = filter_var(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : (function_exists('env') ? env('APP_DEBUG', false) : false), FILTER_VALIDATE_BOOLEAN);
        $errorMsg = $isDebugMode ? ('Internal Server Error: ' . $e->getMessage()) : 'Internal Server Error';

        $errResponse = new \Workerman\Protocols\Http\Response(
            500,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode([
                'code' => 0,
                'msg'  => $errorMsg,
                'data' => null,
            ], JSON_UNESCAPED_UNICODE)
        );
        $connection->send($errResponse);
    } finally {
        if ($http !== null && $response !== null) {
            $http->end($response);
        }
        // 禁用跨请求静态污染：每次请求结束后清理进程内静态状态（如 AdminEntry::$cachedEntry）与超全局变量
        if (class_exists(\core\support\AdminEntry::class)) {
            \core\support\AdminEntry::clearCache();
        }
        $_GET = $_POST = $_COOKIE = $_FILES = $_REQUEST = [];
    }
};

\Workerman\Worker::runAll();
