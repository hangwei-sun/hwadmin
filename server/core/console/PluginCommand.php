<?php
declare(strict_types=1);

namespace core\console;

use core\plugin\PluginInfo;
use core\plugin\PluginManager;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use Throwable;

/**
 * 插件命令行
 *
 *   php think hw:plugin list
 *   php think hw:plugin make demo          # 生成插件骨架
 *   php think hw:plugin install demo
 *   php think hw:plugin enable|disable|upgrade|uninstall demo
 */
class PluginCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:plugin')
            ->addArgument('action', Argument::REQUIRED, 'list|make|install|uninstall|enable|disable|upgrade')
            ->addArgument('name', Argument::OPTIONAL, '插件标识')
            ->setDescription('HwAdmin 插件管理');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = (string) $input->getArgument('action');
        $name = (string) $input->getArgument('name');
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);

        try {
            switch ($action) {
                case 'list':
                    foreach ($manager->all() as $p) {
                        $state = $p['enabled'] ? '已启用' : ($p['installed'] ? '已禁用' : '未安装');
                        $output->writeln(sprintf('%-20s %-10s %-8s %s', $p['name'], $p['version'], $state, $p['title']));
                    }
                    return 0;
                case 'make':
                    $this->make($name, $output);
                    return 0;
                case 'install':
                case 'uninstall':
                case 'enable':
                case 'disable':
                case 'upgrade':
                    if ($name === '') {
                        $output->error('请指定插件标识');
                        return 1;
                    }
                    $manager->{$action}($name);
                    $output->info("插件 {$name} {$action} 完成");
                    return 0;
                default:
                    $output->error("未知操作：{$action}");
                    return 1;
            }
        } catch (Throwable $e) {
            $output->error($e->getMessage());
            return 1;
        }
    }

    /**
     * 由 core/stubs/plugin 生成插件骨架
     */
    protected function make(string $name, Output $output): void
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('插件标识须以小写字母开头，仅含小写字母/数字/下划线，长度 2-32');
        }
        $target = plugin_path($name);
        if (is_dir($target)) {
            throw new \RuntimeException("目录已存在：{$target}");
        }
        $stub = root_path() . 'core' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'plugin';
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        $this->copyStub($stub, rtrim($target, DIRECTORY_SEPARATOR), ['{{name}}' => $name, '{{Name}}' => $studly]);
        $output->info("插件骨架已生成：{$target}");
        $output->writeln("下一步：php think hw:plugin install {$name}");
    }

    private function copyStub(string $src, string $dst, array $vars): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dst . DIRECTORY_SEPARATOR . preg_replace('/\.stub$/', '', strtr($item, $vars));
            if (is_dir($from)) {
                $this->copyStub($from, $to, $vars);
            } else {
                file_put_contents($to, strtr((string) file_get_contents($from), $vars));
            }
        }
    }
}
