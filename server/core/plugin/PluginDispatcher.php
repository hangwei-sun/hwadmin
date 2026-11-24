<?php
declare(strict_types=1);

namespace core\plugin;

use core\base\AdminController;
use core\base\ApiController;
use core\exception\ApiException;
use ReflectionMethod;
use think\App;
use think\Response;

/**
 * 插件控制器分发器
 *
 * 路由（已在 app/adminapi/route 与 app/api/route 注册）：
 *   /adminapi/plugin/{plugin}/{controller}/{action}  => plugins\{plugin}\controller\admin\{Controller}::{action}
 *   /api/plugin/{plugin}/{controller}/{action}       => plugins\{plugin}\controller\api\{Controller}::{action}
 *
 * 安全基线（2-P0-4）：
 *   1. 强制校验控制器基类：admin 层必须继承 core\base\AdminController，api 层必须继承 core\base\ApiController，
 *      否则一律返回 404，杜绝第三方插件忘记继承基类导致无需登录的公开后台接口；
 *   2. 严格反射校验方法可见性：仅允许调用控制器自身（含 Trait）声明的 public 非静态且非 _ 前缀方法。
 */
class PluginDispatcher
{
    public function __construct(protected App $app)
    {
    }

    public function admin(string $plugin, string $controller, string $action): Response
    {
        return $this->dispatch('admin', $plugin, $controller, $action);
    }

    public function api(string $plugin, string $controller, string $action): Response
    {
        return $this->dispatch('api', $plugin, $controller, $action);
    }

    protected function dispatch(string $layer, string $plugin, string $controller, string $action): Response
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $plugin)
            || !preg_match('/^[a-zA-Z][\w.]*$/', $controller)
            || !preg_match('/^[a-zA-Z]\w*$/', $action)) {
            throw new ApiException('非法请求', 0, null, 404);
        }
        $manager = $this->app->make(PluginManager::class);
        if (!$manager->isEnabled($plugin)) {
            throw new ApiException("插件 {$plugin} 未启用", 0, null, 404);
        }

        $segments = explode('.', $controller);
        $last = array_pop($segments);
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $last)));
        $class = 'plugins\\' . $plugin . '\\controller\\' . $layer . '\\'
            . ($segments ? strtolower(implode('\\', $segments)) . '\\' : '') . $studly;
        if (!class_exists($class)) {
            throw new ApiException('接口不存在', 0, null, 404);
        }

        // 2-P0-4：强制校验控制器继承链，防止未继承基类的插件控制器绕过后台登录与 RBAC 鉴权
        $expectedBase = $layer === 'admin' ? AdminController::class : ApiController::class;
        if (!is_subclass_of($class, $expectedBase)) {
            throw new ApiException('接口不存在', 0, null, 404);
        }

        // 只允许调用控制器自身（含 Trait）声明的 public 非静态方法
        $action = lcfirst($action);
        if (!method_exists($class, $action)) {
            throw new ApiException('接口不存在', 0, null, 404);
        }
        $ref = new ReflectionMethod($class, $action);
        if (!$ref->isPublic() || $ref->isStatic() || str_starts_with($action, '_')
            || str_starts_with($ref->getDeclaringClass()->getName(), 'core\\')) {
            throw new ApiException('接口不存在', 0, null, 404);
        }

        $request = $this->app->request;
        $request->setController($controller)->setAction($action);

        $instance = $this->app->make($class, [], true);
        $result = $this->app->invokeMethod([$instance, $action], []);
        return $result instanceof Response ? $result : json($result);
    }
}
