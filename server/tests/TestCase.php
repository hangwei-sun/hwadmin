<?php
declare(strict_types=1);

namespace tests;

use core\auth\AdminAuth;
use core\auth\TokenService;
use PHPUnit\Framework\TestCase as BaseTestCase;
use think\App;
use think\facade\Db;
use think\Request;

/**
 * HwAdmin PHPUnit 测试基类（基于隔离 SQLite 夹具，自动提供管理员令牌与 HTTP 分发辅助）
 */
abstract class TestCase extends BaseTestCase
{
    protected ?App $app = null;
    protected string $adminToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createIsolatedApp();
        $this->adminToken = $this->issueAdminToken('admin');
    }

    protected function tearDown(): void
    {
        $this->closeDbConnections();
        $this->app = null;
        gc_collect_cycles();
        parent::tearDown();
    }

    protected function closeDbConnections(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
    }

    protected function createIsolatedApp(): App
    {
        $root = dirname(__DIR__);
        $app = new App($root);
        $app->debug(true);
        $app->initialize();
        restore_error_handler();
        restore_exception_handler();

        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }

        $dbConfig = $app->config->get('database');
        $dbConfig['default'] = 'sqlite';
        $dbConfig['connections']['sqlite']['database'] = HWADMIN_TEST_DB;
        $dbConfig['connections']['sqlite']['prefix'] = 'hw_';
        $app->config->set($dbConfig, 'database');
        Db::setConfig($app->config);
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        $app->make(\core\plugin\PluginManager::class)->clearCache();

        $app->env->set([
            'APP_ENV'   => 'testing',
            'APP_DEBUG' => 'true',
            'DB_DRIVER' => 'sqlite',
            'DB_TYPE'   => 'sqlite',
            'DB_NAME'   => HWADMIN_TEST_DB,
            'DB_PREFIX' => 'hw_',
        ]);

        return $app;
    }

    protected function issueAdminToken(string $username = 'admin', int $ttl = 3600): string
    {
        $admin = Db::name('admin')->where('username', $username)->find();
        $this->assertNotEmpty($admin, "测试管理员 [{$username}] 不存在");
        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        return $tokens->issue(AdminAuth::TOKEN_TYPE, (int) $admin['id'], $ttl);
    }

    protected function adminGet(string $uri, array $query = []): array
    {
        return $this->dispatchHttp('GET', $uri, $query, [], $this->adminToken);
    }

    protected function adminPost(string $uri, array $body = [], array $query = []): array
    {
        return $this->dispatchHttp('POST', $uri, $query, $body, $this->adminToken);
    }

    protected function guestGet(string $uri, array $query = []): array
    {
        return $this->dispatchHttp('GET', $uri, $query, [], '');
    }

    protected function guestPost(string $uri, array $body = [], array $query = []): array
    {
        return $this->dispatchHttp('POST', $uri, $query, $body, '');
    }

    /**
     * 通过 ThinkPHP Http 内核分发完整请求并返回解码后的 JSON 结构
     */
    protected function dispatchHttp(string $method, string $uri, array $query = [], array $body = [], string $token = ''): array
    {
        $this->closeDbConnections();
        $app = $this->createIsolatedApp();

        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        $uriQuery = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $uriQuery);
        }
        $mergedQuery = array_merge($uriQuery, $query);
        $fullUri = $path . ($mergedQuery ? ('?' . http_build_query($mergedQuery)) : '');

        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $fullUri;
        $_SERVER['QUERY_STRING'] = $mergedQuery ? http_build_query($mergedQuery) : '';
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/public/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_GET = $mergedQuery;
        $_POST = strtoupper($method) === 'POST' ? $body : [];

        $request = $app->make(Request::class, [], true);
        $request->setMethod(strtoupper($method));
        $request->setUrl($fullUri);
        $request->setBaseUrl('');
        $request->setPathinfo(ltrim($path, '/'));
        $request->withGet($mergedQuery);
        if (strtoupper($method) === 'POST') {
            $request->withPost($body);
        }

        $headers = ['accept' => 'application/json'];
        if ($token !== '') {
            $headers['authorization'] = 'Bearer ' . $token;
            $headers['x-token'] = $token;
        }
        $request->withHeader($headers);

        $app->instance('request', $request);
        $response = $app->http->run($request);
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        $this->closeDbConnections();
        if ($this->app !== null) {
            \think\Container::setInstance($this->app);
            Db::setConfig($this->app->config);
        }

        return is_array($decoded)
            ? $decoded
            : ['code' => -1, 'msg' => $content, 'data' => null, '_http_status' => $response->getCode()];
    }

    protected function assertApiSuccess(array $resp, string $message = ''): void
    {
        $this->assertSame(
            1,
            $resp['code'] ?? null,
            ($message !== '' ? $message . ' - ' : '') . 'API 预期成功但返回: ' . json_encode($resp, JSON_UNESCAPED_UNICODE)
        );
    }

    protected function assertApiFail(array $resp, int $expectedCode = 0, string $message = ''): void
    {
        $this->assertSame(
            $expectedCode,
            $resp['code'] ?? null,
            ($message !== '' ? $message . ' - ' : '') . 'API 预期失败码不匹配: ' . json_encode($resp, JSON_UNESCAPED_UNICODE)
        );
    }
}
