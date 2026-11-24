<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

/**
 * 企业级公共中台与开发者运维工具箱表结构与初始种子（v0.2.0）
 *
 * 涵盖：
 * 1. 数据字典：dict / dict_item
 * 2. 可视化定时任务：crontab / crontab_log
 * 3. 菜单权限规则同步：数据字典、定时任务、开发运维中心、可视化CRUD生成
 */
class CreateEnterpriseMidplatformTables extends Migrator
{
    public function up(): void
    {
        $now = time();
        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');

        // 1. 创建数据字典主表 dict
        if (!$this->hasTable('dict')) {
            $this->table('dict', ['comment' => '数据字典主表'])
                ->addColumn('name', 'string', ['limit' => 50, 'default' => '', 'comment' => '字典名称'])
                ->addColumn('code', 'string', ['limit' => 50, 'default' => '', 'comment' => '字典唯一编码'])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '备注'])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1=正常 0=禁用'])
                ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序权重'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['code'], ['unique' => true])
                ->create();
        }

        // 2. 创建数据字典项表 dict_item
        if (!$this->hasTable('dict_item')) {
            $this->table('dict_item', ['comment' => '数据字典明细项'])
                ->addColumn('dict_id', 'integer', ['default' => 0, 'comment' => '所属字典ID'])
                ->addColumn('label', 'string', ['limit' => 100, 'default' => '', 'comment' => '显示文本'])
                ->addColumn('value', 'string', ['limit' => 100, 'default' => '', 'comment' => '字典键值'])
                ->addColumn('tag_type', 'string', ['limit' => 30, 'default' => 'primary', 'comment' => '标签样式 primary/success/warning/danger/info'])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1=启用 0=禁用'])
                ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序权重'])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '备注'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['dict_id'])
                ->addIndex(['status'])
                ->create();
        }

        // 3. 填充默认数据字典种子
        $dictTable = $prefix . 'dict';
        $dictItemTable = $prefix . 'dict_item';
        $defaultDicts = [
            [
                'name' => '通用状态',
                'code' => 'common_status',
                'remark' => '通用数据状态枚举',
                'sort' => 1,
                'items' => [
                    ['label' => '正常', 'value' => '1', 'tag_type' => 'success', 'sort' => 1],
                    ['label' => '禁用', 'value' => '0', 'tag_type' => 'danger', 'sort' => 2],
                ],
            ],
            [
                'name' => '系统是否',
                'code' => 'sys_yes_no',
                'remark' => '通用是否枚举标识',
                'sort' => 2,
                'items' => [
                    ['label' => '是', 'value' => '1', 'tag_type' => 'primary', 'sort' => 1],
                    ['label' => '否', 'value' => '0', 'tag_type' => 'info', 'sort' => 2],
                ],
            ],
            [
                'name' => '文章分类',
                'code' => 'article_type',
                'remark' => '官方资讯分类枚举',
                'sort' => 3,
                'items' => [
                    ['label' => '官方公告', 'value' => 'notice', 'tag_type' => 'warning', 'sort' => 1],
                    ['label' => '技术专栏', 'value' => 'tech', 'tag_type' => 'primary', 'sort' => 2],
                    ['label' => '版本发布', 'value' => 'release', 'tag_type' => 'success', 'sort' => 3],
                ],
            ],
        ];

        foreach ($defaultDicts as $d) {
            $exist = $this->fetchRow(sprintf("SELECT id FROM %s WHERE code = '%s' LIMIT 1", $dictTable, $d['code']));
            if (!$exist) {
                $this->execute(sprintf(
                    "INSERT INTO %s (name, code, remark, status, sort, create_time, update_time) VALUES ('%s', '%s', '%s', 1, %d, %d, %d)",
                    $dictTable,
                    addslashes($d['name']),
                    addslashes($d['code']),
                    addslashes($d['remark']),
                    $d['sort'],
                    $now,
                    $now
                ));
                $newDict = $this->fetchRow(sprintf("SELECT id FROM %s WHERE code = '%s' LIMIT 1", $dictTable, $d['code']));
                $dictId = $newDict ? (int) $newDict['id'] : 0;
                if ($dictId > 0 && !empty($d['items'])) {
                    foreach ($d['items'] as $item) {
                        $this->execute(sprintf(
                            "INSERT INTO %s (dict_id, label, value, tag_type, status, sort, remark, create_time, update_time) VALUES (%d, '%s', '%s', '%s', 1, %d, '', %d, %d)",
                            $dictItemTable,
                            $dictId,
                            addslashes($item['label']),
                            addslashes($item['value']),
                            addslashes($item['tag_type']),
                            $item['sort'],
                            $now,
                            $now
                        ));
                    }
                }
            }
        }

        // 4. 创建定时任务表 crontab
        if (!$this->hasTable('crontab')) {
            $this->table('crontab', ['comment' => '定时任务表'])
                ->addColumn('title', 'string', ['limit' => 100, 'default' => '', 'comment' => '任务名称'])
                ->addColumn('type', 'string', ['limit' => 20, 'default' => 'command', 'comment' => '任务类型 command/url/sql'])
                ->addColumn('target', 'string', ['limit' => 500, 'default' => '', 'comment' => '执行目标'])
                ->addColumn('cron', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Cron表达式'])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1=正常 0=停用'])
                ->addColumn('last_run_time', 'biginteger', ['default' => 0, 'comment' => '最后运行时间'])
                ->addColumn('last_run_status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '最后运行状态 1=成功 0=失败'])
                ->addColumn('last_run_cost', 'integer', ['default' => 0, 'comment' => '最后运行耗时毫秒'])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '任务说明'])
                ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序权重'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['status'])
                ->create();
        }

        // 5. 创建定时任务执行日志表 crontab_log
        if (!$this->hasTable('crontab_log')) {
            $this->table('crontab_log', ['comment' => '定时任务执行日志'])
                ->addColumn('crontab_id', 'integer', ['default' => 0, 'comment' => '任务ID'])
                ->addColumn('title', 'string', ['limit' => 100, 'default' => '', 'comment' => '任务名称'])
                ->addColumn('target', 'string', ['limit' => 500, 'default' => '', 'comment' => '执行目标'])
                ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1=成功 0=失败'])
                ->addColumn('output', 'text', ['null' => true, 'comment' => '输出日志'])
                ->addColumn('duration_ms', 'integer', ['default' => 0, 'comment' => '耗时毫秒'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '执行时间'])
                ->addIndex(['crontab_id'])
                ->addIndex(['create_time'])
                ->create();
        }

        // 6. 填充默认定时任务示例
        $crontabTable = $prefix . 'crontab';
        $defaultTasks = [
            [
                'title' => '清理过期验证码与临时缓存',
                'type' => 'command',
                'target' => 'cache:clear',
                'cron' => '0 2 * * *',
                'status' => 1,
                'remark' => '每天凌晨2点清理临时缓存',
                'sort' => 1,
            ],
            [
                'title' => '数据库健康状态日常巡检',
                'type' => 'command',
                'target' => 'devops:health-check',
                'cron' => '*/30 * * * *',
                'status' => 1,
                'remark' => '每30分钟检查一次数据库连接与表空间',
                'sort' => 2,
            ],
        ];
        foreach ($defaultTasks as $task) {
            $exist = $this->fetchRow(sprintf("SELECT id FROM %s WHERE title = '%s' LIMIT 1", $crontabTable, $task['title']));
            if (!$exist) {
                $this->execute(sprintf(
                    "INSERT INTO %s (title, type, target, cron, status, last_run_time, last_run_status, last_run_cost, remark, sort, create_time, update_time) " .
                    "VALUES ('%s', '%s', '%s', '%s', %d, 0, 1, 0, '%s', %d, %d, %d)",
                    $crontabTable,
                    addslashes($task['title']),
                    addslashes($task['type']),
                    addslashes($task['target']),
                    addslashes($task['cron']),
                    $task['status'],
                    addslashes($task['remark']),
                    $task['sort'],
                    $now,
                    $now
                ));
            }
        }

        // 6.1 预装插件 crud 状态初始化到 plugin 表
        if ($this->hasTable('plugin')) {
            $pluginTable = $prefix . 'plugin';
            $exist = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = 'crud' LIMIT 1", $pluginTable));
            if (!$exist) {
                $this->execute(sprintf(
                    "INSERT INTO %s (name, title, version, status, config, create_time, update_time) VALUES ('crud', '可视化全栈 CRUD 生成器', '1.0.0', 1, '{}', %d, %d)",
                    $pluginTable,
                    $now,
                    $now
                ));
            } else {
                $this->execute(sprintf(
                    "UPDATE %s SET status = 1, update_time = %d WHERE name = 'crud'",
                    $pluginTable,
                    $now
                ));
            }
        }

        // 7. 幂等同步菜单与权限规则到 auth_rule 表（复用迁移 PDO 连接走 MenuSync 参数绑定）
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), function (): void {
                // 确保顶级 system / plugin 目录存在
                $sysPid = MenuSync::ensureNode([
                    'type' => 'dir',
                    'name' => 'system',
                    'title' => '系统',
                    'path' => 'system',
                    'icon' => 'settings-3-line',
                    'weight' => 700,
                ]);

                // 7.1 数据字典管理
                MenuSync::ensureNode([
                    'pid' => $sysPid,
                    'type' => 'menu',
                    'name' => 'system/dict',
                    'title' => '数据字典',
                    'path' => 'dict',
                    'component' => '@/views/system/dict/index.vue',
                    'icon' => 'Collection',
                    'weight' => 690,
                    'children' => [
                        ['type' => 'button', 'name' => 'system/dict/index', 'title' => '查看'],
                        ['type' => 'button', 'name' => 'system/dict/read', 'title' => '详情'],
                        ['type' => 'button', 'name' => 'system/dict/add', 'title' => '添加'],
                        ['type' => 'button', 'name' => 'system/dict/edit', 'title' => '编辑'],
                        ['type' => 'button', 'name' => 'system/dict/del', 'title' => '删除'],
                    ],
                ], $sysPid);

                // 7.2 定时任务调度
                MenuSync::ensureNode([
                    'pid' => $sysPid,
                    'type' => 'menu',
                    'name' => 'system/crontab',
                    'title' => '定时任务',
                    'path' => 'crontab',
                    'component' => '@/views/system/crontab/index.vue',
                    'icon' => 'AlarmClock',
                    'weight' => 680,
                    'children' => [
                        ['type' => 'button', 'name' => 'system/crontab/index', 'title' => '查看'],
                        ['type' => 'button', 'name' => 'system/crontab/read', 'title' => '详情'],
                        ['type' => 'button', 'name' => 'system/crontab/add', 'title' => '添加'],
                        ['type' => 'button', 'name' => 'system/crontab/edit', 'title' => '编辑'],
                        ['type' => 'button', 'name' => 'system/crontab/del', 'title' => '删除'],
                        ['type' => 'button', 'name' => 'system/crontab/run', 'title' => '立即执行'],
                    ],
                ], $sysPid);

                // 7.3 开发与运维中心
                MenuSync::ensureNode([
                    'pid' => $sysPid,
                    'type' => 'menu',
                    'name' => 'system/devops',
                    'title' => '开发与运维中心',
                    'path' => 'devops',
                    'component' => '@/views/system/devops/index.vue',
                    'icon' => 'Cpu',
                    'weight' => 670,
                    'children' => [
                        ['type' => 'button', 'name' => 'system/devops/index', 'title' => '查看'],
                        ['type' => 'button', 'name' => 'system/devops/apiDocs', 'title' => '接口文档'],
                        ['type' => 'button', 'name' => 'system/devops/tables', 'title' => '表空间'],
                        ['type' => 'button', 'name' => 'system/devops/backup', 'title' => '备份还原'],
                    ],
                ], $sysPid);

                // 7.4 预装插件菜单：plugin/crud
                $pluginPid = MenuSync::ensureNode([
                    'type' => 'dir',
                    'name' => 'plugin',
                    'title' => '插件应用',
                    'path' => 'plugin',
                    'icon' => 'apps-2-line',
                    'weight' => 650,
                ]);

                MenuSync::ensureNode([
                    'pid' => $pluginPid,
                    'type' => 'menu',
                    'name' => 'plugin/crud',
                    'title' => '可视化CRUD生成',
                    'path' => 'crud',
                    'component' => '@/views/plugin/crud/index.vue',
                    'icon' => 'MagicStick',
                    'weight' => 100,
                    'children' => [
                        ['type' => 'button', 'name' => 'plugin/crud/index', 'title' => '查看'],
                        ['type' => 'button', 'name' => 'plugin/crud/generator/tables', 'title' => '数据表列表'],
                        ['type' => 'button', 'name' => 'plugin/crud/generator/columns', 'title' => '字段探测'],
                        ['type' => 'button', 'name' => 'plugin/crud/generator/preview', 'title' => '代码预览'],
                        ['type' => 'button', 'name' => 'plugin/crud/generator/generate', 'title' => '一键生成'],
                    ],
                ], $pluginPid);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), function (): void {
                MenuSync::removeNodes([
                    'system/dict', 'system/dict/index', 'system/dict/read', 'system/dict/add', 'system/dict/edit', 'system/dict/del',
                    'system/crontab', 'system/crontab/index', 'system/crontab/read', 'system/crontab/add', 'system/crontab/edit', 'system/crontab/del', 'system/crontab/run',
                    'system/devops', 'system/devops/index', 'system/devops/apiDocs', 'system/devops/tables', 'system/devops/backup',
                    'plugin/crud', 'plugin/crud/index', 'plugin/crud/generator/tables', 'plugin/crud/generator/columns', 'plugin/crud/generator/preview', 'plugin/crud/generator/generate',
                ]);
            });
        }

        if ($this->hasTable('crontab_log')) {
            $this->table('crontab_log')->drop()->save();
        }
        if ($this->hasTable('crontab')) {
            $this->table('crontab')->drop()->save();
        }
        if ($this->hasTable('dict_item')) {
            $this->table('dict_item')->drop()->save();
        }
        if ($this->hasTable('dict')) {
            $this->table('dict')->drop()->save();
        }
    }
}
