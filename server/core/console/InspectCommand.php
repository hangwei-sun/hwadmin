<?php
declare(strict_types=1);

namespace core\console;

use core\support\RouteInspector;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 面向 Agent 与开发者的项目自省命令行工具
 *
 * 用法：
 *   php think hw:inspect --json
 *   php think hw:inspect --json --section=routes,plugins
 *   php think hw:inspect --json --section=database --table=user --with-counts
 *   php think hw:inspect --json --module=adminapi --controller=user
 */
class InspectCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:inspect')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出，便于 AI Agent 与脚本消费')
            ->addOption('section', 's', Option::VALUE_OPTIONAL, '指定输出区块，逗号分隔：framework,database,routes,auth_rules,plugins', '')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, '仅查看指定数据表结构', '')
            ->addOption('with-counts', null, Option::VALUE_NONE, '是否统计数据表真实行数（默认不执行 COUNT(*)）')
            ->addOption('module', 'm', Option::VALUE_OPTIONAL, '指定扫描模块（adminapi|api|plugins）', '')
            ->addOption('controller', 'c', Option::VALUE_OPTIONAL, '指定扫描控制器名称过滤', '')
            ->addOption('plugin', 'p', Option::VALUE_OPTIONAL, '指定插件标识过滤', '')
            ->setDescription('自省输出当前框架版本、数据库表结构、已注册路由、权限节点与插件状态');
    }

    protected function execute(Input $input, Output $output): int
    {
        $asJson = (bool) $input->getOption('json');
        $rawSection = trim((string) $input->getOption('section'));
        $table = trim((string) $input->getOption('table'));
        $withCounts = (bool) $input->getOption('with-counts');
        $module = trim((string) $input->getOption('module'));
        $controller = trim((string) $input->getOption('controller'));
        $plugin = trim((string) $input->getOption('plugin'));

        $sections = [];
        if ($rawSection !== '') {
            $sections = array_values(array_filter(array_map('trim', explode(',', $rawSection))));
            $invalidSections = array_diff($sections, RouteInspector::VALID_SECTIONS);
            if (!empty($invalidSections)) {
                $err = sprintf(
                    '不支持的自省区块: %s。支持的区块为: %s',
                    implode(', ', $invalidSections),
                    implode(', ', RouteInspector::VALID_SECTIONS)
                );
                if ($asJson) {
                    $payload = [
                        'schema_version' => RouteInspector::SCHEMA_VERSION,
                        'status'         => 'degraded',
                        'errors'         => [$err],
                        'warnings'       => [],
                        'sources'        => [],
                    ];
                    $output->writeln((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->error($err);
                }
                return 1;
            }
        }

        $filters = [
            'with_counts' => $withCounts,
            'module'      => $module,
            'controller'  => $controller,
            'plugin'      => $plugin,
            'table'       => $table,
        ];

        $inspector = new RouteInspector($this->app);
        $data = $inspector->inspect($sections, $table, $filters);

        if ($asJson) {
            $output->writeln((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $data['status'] === 'degraded' && !empty($data['errors']) ? 1 : 0;
        }

        if (isset($data['framework'])) {
            $fw = $data['framework'];
            $output->info("=== HwAdmin v{$fw['version']} (App: {$fw['app_id']} v{$fw['app_version']}, PHP {$fw['php_version']}) ===");
            $output->writeln(sprintf(
                '环境: env=%s debug=%s installed=%s status=%s',
                $fw['app_env'] ?: '-',
                $fw['app_debug'] ? 'true' : 'false',
                $fw['installed'] ? 'true' : 'false',
                $data['status']
            ));
        }

        if (!empty($data['warnings'])) {
            foreach ($data['warnings'] as $w) {
                $output->warning('[警告] ' . $w);
            }
        }

        if (!empty($data['errors'])) {
            foreach ($data['errors'] as $e) {
                $output->error('[错误] ' . $e);
            }
        }

        if (isset($data['database'])) {
            $db = $data['database'];
            $output->writeln('');
            $output->info(sprintf('=== 数据库 (%s, prefix=%s, connected=%s, status=%s) ===', $db['driver'], $db['prefix'], $db['connected'] ? 'yes' : 'no', $db['status']));
            foreach ($db['tables'] as $t) {
                $rowCountStr = $t['rows'] !== null ? (string) $t['rows'] : '-';
                $output->writeln(sprintf('  - %-28s pk=%-6s cols=%-3d rows=%s', $t['clean_name'], $t['pk'], count($t['columns']), $rowCountStr));
            }
        }

        if (isset($data['routes'])) {
            $output->writeln('');
            $output->info('=== 已扫描接口路由 ===');
            foreach (['adminapi', 'api'] as $group) {
                foreach ($data['routes'][$group] ?? [] as $ctrl) {
                    $output->writeln("  [{$group}] {$ctrl['url_prefix']} ({$ctrl['title']})");
                    foreach ($ctrl['endpoints'] as $ep) {
                        $node = isset($ep['permission_node']) ? " node={$ep['permission_node']}" : '';
                        $src = " src={$ep['method_source']}";
                        $output->writeln(sprintf('      %-4s %-42s%s%s', $ep['method'], $ep['url'], $node, $src));
                    }
                }
            }
            foreach ($data['routes']['plugins'] ?? [] as $pName => $pRoutes) {
                foreach (['adminapi', 'api'] as $group) {
                    foreach ($pRoutes[$group] ?? [] as $ctrl) {
                        $output->writeln("  [plugin:{$pName}:{$group}] {$ctrl['url_prefix']} ({$ctrl['title']})");
                    }
                }
            }
        }

        if (isset($data['plugins'])) {
            $output->writeln('');
            $output->info('=== 插件列表 ===');
            foreach ($data['plugins'] as $p) {
                $state = !empty($p['enabled']) ? 'enabled' : (!empty($p['installed']) ? 'disabled' : 'uninstalled');
                $output->writeln(sprintf('  - %-16s v%-8s %-12s %s', $p['name'], $p['version'], $state, $p['title']));
            }
        }

        if (isset($data['auth_rules'])) {
            $output->writeln('');
            $output->info('=== 权限规则数量：' . count($data['auth_rules']) . ' ===');
        }

        return $data['status'] === 'degraded' && !empty($data['errors']) ? 1 : 0;
    }
}
