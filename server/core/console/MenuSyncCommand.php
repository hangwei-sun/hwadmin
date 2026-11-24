<?php
declare(strict_types=1);

namespace core\console;

use core\plugin\MenuImporter;
use core\plugin\MenuSync;
use core\plugin\PluginManager;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/**
 * 菜单与权限规则单一数据源同步命令
 *
 * 用法：
 *   php think hw:menu:sync                 # 将 core/install/data/menus.php 幂等同步到 auth_rule（保留自定义菜单）
 *   php think hw:menu:sync --prune         # 严格对齐 menus.php，清理未登记的残留核心节点
 *   php think hw:menu:sync --with-plugins  # 同时同步所有已安装插件的 plugin.json menus
 */
class MenuSyncCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:menu:sync')
            ->addOption('prune', null, Option::VALUE_NONE, '清理未在 core/install/data/menus.php 中声明的残留核心节点')
            ->addOption('with-plugins', null, Option::VALUE_NONE, '同时同步已安装插件的 menus 配置')
            ->addOption('force-layout', null, Option::VALUE_NONE, '强制以 menus.php / plugin.json 重置已有菜单层级与标题排序（覆盖手动调整）')
            ->setDescription('从单一数据源（menus.php / plugin.json）幂等同步后台菜单与权限规则');
    }

    protected function execute(Input $input, Output $output): int
    {
        $prune = (bool) $input->getOption('prune');
        $withPlugins = (bool) $input->getOption('with-plugins');
        $forceLayout = (bool) $input->getOption('force-layout');

        try {
            $coreStats = MenuSync::syncCore($prune, $forceLayout);
            $output->info(sprintf(
                '✔ 核心菜单同步完成：总计 %d 条（新增 %d，更新 %d，清理 %d）',
                $coreStats['total'],
                $coreStats['inserted'],
                $coreStats['updated'],
                $coreStats['pruned']
            ));

            if ($withPlugins) {
                /** @var PluginManager $manager */
                $manager = $this->app->make(PluginManager::class);
                foreach ($manager->all() as $plugin) {
                    if (empty($plugin['installed'])) {
                        continue;
                    }
                    $info = $manager->info((string) $plugin['name']);
                    MenuImporter::import((string) $plugin['name'], $info->menus, !empty($plugin['enabled']), $forceLayout);
                    $output->writeln(sprintf('  - 插件 [%s] 菜单已同步 (%d 顶级项)', $plugin['name'], count($info->menus)));
                }
            }

            return 0;
        } catch (Throwable $e) {
            $output->error('菜单同步失败：' . $e->getMessage());
            return 1;
        }
    }
}
