<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

/**
 * 将数据字典、定时任务、开发运维中心与可视化 CRUD 统一归集至内置「应用开发」插件目录
 */
class RegroupAppDevMenus extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), function (): void {
                $crud = static function (string $node, ?array $actions = null): array {
                    $actions ??= ['index' => '查看', 'read' => '详情', 'add' => '添加', 'edit' => '编辑', 'del' => '删除'];
                    $buttons = [];
                    foreach ($actions as $action => $title) {
                        $buttons[] = ['type' => 'button', 'name' => "{$node}/{$action}", 'title' => $title];
                    }
                    return $buttons;
                };

                $appDevTree = [
                    'type'     => 'dir',
                    'name'     => 'plugin',
                    'title'    => '应用开发',
                    'path'     => 'plugin',
                    'icon'     => 'apps-2-line',
                    'weight'   => 650,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'system/dict',
                            'title'     => '数据字典',
                            'path'      => 'dict',
                            'component' => '@/views/system/dict/index.vue',
                            'icon'      => 'Collection',
                            'weight'    => 95,
                            'children'  => $crud('system/dict'),
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'system/crontab',
                            'title'     => '定时任务',
                            'path'      => 'crontab',
                            'component' => '@/views/system/crontab/index.vue',
                            'icon'      => 'AlarmClock',
                            'weight'    => 90,
                            'children'  => array_merge($crud('system/crontab'), [
                                ['type' => 'button', 'name' => 'system/crontab/run', 'title' => '立即执行'],
                            ]),
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'system/devops',
                            'title'     => '开发运维中心',
                            'path'      => 'devops',
                            'component' => '@/views/system/devops/index.vue',
                            'icon'      => 'Cpu',
                            'weight'    => 85,
                            'children'  => [
                                ['type' => 'button', 'name' => 'system/devops/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'system/devops/apiDocs', 'title' => '接口文档'],
                                ['type' => 'button', 'name' => 'system/devops/tables', 'title' => '表空间'],
                                ['type' => 'button', 'name' => 'system/devops/backup', 'title' => '备份还原'],
                            ],
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'plugin/crud',
                            'title'     => 'CRUD生成',
                            'path'      => 'crud',
                            'component' => '@/views/plugin/crud/index.vue',
                            'icon'      => 'MagicStick',
                            'weight'    => 80,
                            'children'  => [
                                ['type' => 'button', 'name' => 'plugin/crud/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'plugin/crud/generator/tables', 'title' => '数据表列表'],
                                ['type' => 'button', 'name' => 'plugin/crud/generator/columns', 'title' => '字段探测'],
                                ['type' => 'button', 'name' => 'plugin/crud/generator/preview', 'title' => '代码预览'],
                                ['type' => 'button', 'name' => 'plugin/crud/generator/generate', 'title' => '一键生成'],
                            ],
                        ],
                    ],
                ];

                MenuSync::syncTree('', [$appDevTree], true, false, true);
            });
        }

        if ($this->hasTable('plugin')) {
            $adapter = $this->getAdapter();
            $target = (!method_exists($adapter, 'getConnection') && method_exists($adapter, 'getAdapter'))
                ? $adapter->getAdapter()
                : $adapter;
            if (method_exists($target, 'getConnection')) {
                $pdo = $target->getConnection();
                $prefix = (string) ($target->getOption('table_prefix') ?? '');
                if ($pdo instanceof \PDO) {
                    $stmt = $pdo->prepare(sprintf(
                        'UPDATE %s SET title = ?, update_time = ? WHERE name = ?',
                        $prefix . 'plugin'
                    ));
                    $stmt->execute(['应用开发', time(), 'crud']);
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), function (): void {
                $sysTree = [
                    'type'     => 'dir',
                    'name'     => 'system',
                    'title'    => '系统',
                    'path'     => 'system',
                    'icon'     => 'settings-3-line',
                    'weight'   => 700,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'system/dict',
                            'title'     => '数据字典',
                            'path'      => 'dict',
                            'component' => '@/views/system/dict/index.vue',
                            'icon'      => 'Collection',
                            'weight'    => 690,
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'system/crontab',
                            'title'     => '定时任务',
                            'path'      => 'crontab',
                            'component' => '@/views/system/crontab/index.vue',
                            'icon'      => 'AlarmClock',
                            'weight'    => 680,
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'system/devops',
                            'title'     => '开发与运维中心',
                            'path'      => 'devops',
                            'component' => '@/views/system/devops/index.vue',
                            'icon'      => 'Cpu',
                            'weight'    => 670,
                        ],
                    ],
                ];
                $pluginTree = [
                    'type'     => 'dir',
                    'name'     => 'plugin',
                    'title'    => '插件应用',
                    'path'     => 'plugin',
                    'icon'     => 'apps-2-line',
                    'weight'   => 650,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'plugin/crud',
                            'title'     => '可视化CRUD生成',
                            'path'      => 'crud',
                            'component' => '@/views/plugin/crud/index.vue',
                            'icon'      => 'MagicStick',
                            'weight'    => 100,
                        ],
                    ],
                ];
                MenuSync::syncTree('', [$sysTree, $pluginTree], true, false, true);
            });
        }
    }
}
