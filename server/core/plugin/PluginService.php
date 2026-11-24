<?php
declare(strict_types=1);

namespace core\plugin;

use core\console\CrudCommand;
use core\console\DevTokenCommand;
use core\console\DoctorCommand;
use core\console\InspectCommand;
use core\console\InstallCommand;
use core\console\MenuSyncCommand;
use core\console\MigrateFastAdminCommand;
use core\console\PageCommand;
use core\console\PluginCommand;
use core\console\TestCommand;
use think\Service;
use Throwable;

/**
 * 插件系统服务（在 app/service.php 中注册）
 *
 * - register：绑定 PluginManager 单例、注册命令行
 * - boot：加载所有已启用插件，注册事件监听并调用 Plugin::boot()
 *
 * 单个插件启动失败只记录日志，不影响系统其他部分运行。
 */
class PluginService extends Service
{
    public function register(): void
    {
        $this->app->instance(PluginManager::class, new PluginManager($this->app));
    }

    public function boot(): void
    {
        $this->commands([
            PluginCommand::class,
            InstallCommand::class,
            MigrateFastAdminCommand::class,
            CrudCommand::class,
            PageCommand::class,
            MenuSyncCommand::class,
            InspectCommand::class,
            DoctorCommand::class,
            DevTokenCommand::class,
            TestCommand::class,
        ]);

        $manager = $this->app->make(PluginManager::class);
        foreach ($manager->enabledNames() as $name) {
            try {
                $plugin = $manager->instance($name);
                foreach ($plugin->events() as $event => $listeners) {
                    foreach ((array) $listeners as $listener) {
                        $this->app->event->listen($event, $listener);
                    }
                }
                $plugin->boot();
            } catch (Throwable $e) {
                $this->app->log->error("[plugin] {$name} 启动失败：" . $e->getMessage());
            }
        }
    }
}
