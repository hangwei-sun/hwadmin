<?php
declare(strict_types=1);

namespace core\plugin;

use think\App;

/**
 * 插件主类基类。每个插件在 plugins/{name}/Plugin.php 中定义：
 *
 *   namespace plugins\demo;
 *   class Plugin extends \core\plugin\Plugin {
 *       public function events(): array {
 *           return ['AdminLoginAfter' => [listener\LoginLog::class]];
 *       }
 *   }
 *
 * 生命周期（由 PluginManager 调用）：
 *   install()   安装：执行迁移/初始化数据之后调用
 *   enable()    启用
 *   disable()   禁用
 *   upgrade()   升级：$from 为旧版本号
 *   uninstall() 卸载：删除菜单、迁移回滚之前调用
 *   boot()      每次请求：插件启用状态下在应用初始化阶段调用，可注册路由/中间件/服务
 *
 * 详见 docs/插件系统规范.md
 */
abstract class Plugin
{
    public function __construct(protected App $app, protected PluginInfo $info)
    {
    }

    public function info(): PluginInfo
    {
        return $this->info;
    }

    /**
     * 事件监听声明：['事件名' => [监听类或闭包, ...]]
     * 详见 docs/插件系统规范.md
     */
    public function events(): array
    {
        return [];
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function upgrade(string $from): void
    {
    }

    /**
     * 读取插件配置（plugin 表 config 字段，后台“插件配置”页维护）
     */
    protected function config(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->app->make(PluginManager::class)->getConfig($this->info->name);
        return $key === null ? $config : ($config[$key] ?? $default);
    }
}
