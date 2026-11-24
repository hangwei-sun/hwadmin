<?php
declare(strict_types=1);

namespace plugins\crud;

use core\plugin\Plugin as BasePlugin;
use core\plugin\MenuImporter;
use core\plugin\PluginInfo;
use think\App;
use Throwable;

/**
 * HwAdmin 官方可视化全栈 CRUD 生成器插件主类
 */
class Plugin extends BasePlugin
{
    public function __construct(App $app, ?PluginInfo $info = null)
    {
        if ($info === null) {
            $info = PluginInfo::fromDirectory(__DIR__);
        }
        parent::__construct($app, $info);
    }

    /**
     * 声明监听的系统事件与钩子
     */
    public function events(): array
    {
        return [];
    }

    /**
     * 插件安装：同步菜单并创建生成器运行时目录
     */
    public function install(): void
    {
        $this->ensureRuntimeDir();
        $this->syncMenus();
    }

    /**
     * 插件卸载：清理菜单
     */
    public function uninstall(): void
    {
        try {
            MenuImporter::remove('crud');
        } catch (Throwable $e) {
            $this->app->log->warning("[CRUD Plugin] 卸载清理菜单异常: " . $e->getMessage());
        }
    }

    /**
     * 启用插件
     */
    public function enable(): void
    {
        $this->ensureRuntimeDir();
        $this->setSuiteMenuStatus(1);
        MenuImporter::setStatus('crud', 1);
    }

    /**
     * 禁用插件
     */
    public function disable(): void
    {
        $this->setSuiteMenuStatus(0);
        MenuImporter::setStatus('crud', 0);
    }

    /**
     * 同步 plugin.json 中的菜单到 auth_rule（若核心菜单已托管则仅确保状态同步）
     */
    public function syncMenus(): void
    {
        $existing = \think\facade\Db::name('auth_rule')->where('name', 'plugin/crud')->find();
        if ($existing && (string) ($existing['plugin'] ?? '') === '') {
            $this->setSuiteMenuStatus(1);
            return;
        }

        $jsonPath = __DIR__ . '/plugin.json';
        if (!is_file($jsonPath)) {
            return;
        }

        $info = json_decode((string) file_get_contents($jsonPath), true);
        if (!empty($info['menus']) && is_array($info['menus'])) {
            MenuImporter::import('crud', $info['menus'], true);
        }
    }

    /**
     * 统一控制内置「应用开发」套件目录及 4 个子模块规则状态
     */
    protected function setSuiteMenuStatus(int $status): void
    {
        try {
            \think\facade\Db::name('auth_rule')
                ->whereIn('name', ['plugin', 'system/dict', 'system/crontab', 'system/devops', 'plugin/crud'])
                ->update(['status' => $status, 'update_time' => time()]);
        } catch (Throwable $e) {
            $this->app->log->warning('[应用开发插件] 切换套件菜单状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 初始化运行时目录
     */
    protected function ensureRuntimeDir(): void
    {
        $runtimeDir = runtime_path() . 'crud';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
    }
}
