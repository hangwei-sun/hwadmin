<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

class CreateEnterpriseAdminTables extends Migrator
{
    public function up(): void
    {
        // 项目主表
        if (!$this->hasTable('enterprise_project')) {
            $this->table('enterprise_project')
                ->addColumn('project_no', 'string', ['limit' => 32, 'comment' => '项目编号'])
                ->addColumn('title', 'string', ['limit' => 128, 'comment' => '项目名称'])
                ->addColumn('admin_id', 'integer', ['default' => 0, 'comment' => '负责人管理员ID'])
                ->addColumn('created_by', 'integer', ['default' => 0, 'comment' => '创建人管理员ID(数据归属隔离)'])
                ->addColumn('priority', 'integer', ['default' => 2, 'comment' => '优先级(1低,2中,3高,4紧急)'])
                ->addColumn('budget', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => '项目预算'])
                ->addColumn('status', 'integer', ['default' => 1, 'comment' => '项目状态(1进行中,2已完成,0已归档)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['project_no'], ['unique' => true])
                ->addIndex(['created_by'], ['unique' => false])
                ->create();
        }

        // 任务从表
        if (!$this->hasTable('enterprise_task')) {
            $this->table('enterprise_task')
                ->addColumn('project_id', 'integer', ['comment' => '关联项目ID'])
                ->addColumn('title', 'string', ['limit' => 128, 'comment' => '任务标题'])
                ->addColumn('assignee', 'string', ['limit' => 64, 'default' => '', 'comment' => '执行人'])
                ->addColumn('deadline', 'string', ['limit' => 16, 'default' => '', 'comment' => '截止日期'])
                ->addColumn('created_by', 'integer', ['default' => 0, 'comment' => '创建人管理员ID'])
                ->addColumn('status', 'integer', ['default' => 0, 'comment' => '任务状态(0待办,1进行中,2已完成)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['project_id'], ['unique' => false])
                ->create();
        }

        // 附件记录表
        if (!$this->hasTable('enterprise_attachment')) {
            $this->table('enterprise_attachment')
                ->addColumn('target_type', 'string', ['limit' => 32, 'comment' => '关联主体类型(project|task)'])
                ->addColumn('target_id', 'integer', ['comment' => '主体ID'])
                ->addColumn('file_name', 'string', ['limit' => 255, 'comment' => '文件原名'])
                ->addColumn('file_url', 'string', ['limit' => 500, 'comment' => '文件存储URL'])
                ->addColumn('file_size', 'integer', ['default' => 0, 'comment' => '文件大小(字节)'])
                ->addColumn('file_ext', 'string', ['limit' => 16, 'default' => '', 'comment' => '后缀名'])
                ->addColumn('admin_id', 'integer', ['default' => 0, 'comment' => '上传者管理员ID'])
                ->addColumn('created_by', 'integer', ['default' => 0, 'comment' => '创建人管理员ID'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '上传时间'])
                ->addIndex(['target_type', 'target_id'], ['unique' => false])
                ->create();
        }

        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::ensureNode([
                    'type'     => 'dir',
                    'name'     => 'enterprise',
                    'title'    => '企业协同管理',
                    'path'     => 'enterprise',
                    'icon'     => 'briefcase-4-line',
                    'weight'   => 560,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'enterprise/project',
                            'title'     => '项目与任务协同',
                            'path'      => 'project',
                            'component' => '@/views/enterprise/project/index.vue',
                            'icon'      => 'projector-line',
                            'weight'    => 100,
                            'children'  => [
                                ['type' => 'button', 'name' => 'enterprise/project/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'enterprise/project/read', 'title' => '详情'],
                                ['type' => 'button', 'name' => 'enterprise/project/detail', 'title' => '主从详情'],
                                ['type' => 'button', 'name' => 'enterprise/project/add', 'title' => '新增'],
                                ['type' => 'button', 'name' => 'enterprise/project/createWithTasks', 'title' => '主从协同新增'],
                                ['type' => 'button', 'name' => 'enterprise/project/edit', 'title' => '编辑'],
                                ['type' => 'button', 'name' => 'enterprise/project/del', 'title' => '删除'],
                                ['type' => 'button', 'name' => 'enterprise/project/addAttachment', 'title' => '上传附件'],
                                ['type' => 'button', 'name' => 'enterprise/project/export', 'title' => '导出数据'],
                            ],
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'enterprise/task',
                            'title'     => '任务明细管理',
                            'path'      => 'task',
                            'component' => '@/views/enterprise/project/index.vue',
                            'icon'      => 'task-line',
                            'weight'    => 90,
                            'children'  => [
                                ['type' => 'button', 'name' => 'enterprise/task/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'enterprise/task/add', 'title' => '新增'],
                                ['type' => 'button', 'name' => 'enterprise/task/edit', 'title' => '编辑'],
                                ['type' => 'button', 'name' => 'enterprise/task/del', 'title' => '删除'],
                            ],
                        ],
                    ],
                ]);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::removeNodes([
                    'enterprise/project/index', 'enterprise/project/read', 'enterprise/project/detail',
                    'enterprise/project/add', 'enterprise/project/createWithTasks', 'enterprise/project/edit',
                    'enterprise/project/del', 'enterprise/project/addAttachment', 'enterprise/project/export',
                    'enterprise/project',
                    'enterprise/task/index', 'enterprise/task/add', 'enterprise/task/edit',
                    'enterprise/task/del', 'enterprise/task',
                    'enterprise',
                ]);
            });
        }

        if ($this->hasTable('enterprise_attachment')) {
            $this->table('enterprise_attachment')->drop()->save();
        }
        if ($this->hasTable('enterprise_task')) {
            $this->table('enterprise_task')->drop()->save();
        }
        if ($this->hasTable('enterprise_project')) {
            $this->table('enterprise_project')->drop()->save();
        }
    }
}
