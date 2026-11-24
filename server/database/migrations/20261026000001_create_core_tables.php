<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * HwAdmin 核心表结构（v0.1.0）
 *
 * 规范（见 docs/数据库开发规范.md）：
 * - 表前缀由 .env DB_PREFIX 决定（默认 hw_），此处只写不带前缀的表名；
 * - 时间字段统一 BIGINT Unix 时间戳：create_time / update_time / delete_time；
 * - 只使用 Phinx 通用类型，保证 MySQL / PostgreSQL 兼容，禁止写方言 SQL；
 * - 已发布的迁移禁止修改，结构变更一律新增迁移文件。
 */
class CreateCoreTables extends Migrator
{
    public function change(): void
    {
        // 管理员
        $this->table('admin', ['comment' => '管理员'])
            ->addColumn('username', 'string', ['limit' => 30, 'default' => '', 'comment' => '用户名'])
            ->addColumn('nickname', 'string', ['limit' => 50, 'default' => '', 'comment' => '昵称'])
            ->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => '密码哈希'])
            ->addColumn('salt', 'string', ['limit' => 30, 'default' => '', 'comment' => '旧版密码盐（历史兼容，升级后清空）'])
            ->addColumn('avatar', 'string', ['limit' => 255, 'default' => '', 'comment' => '头像'])
            ->addColumn('email', 'string', ['limit' => 100, 'default' => '', 'comment' => '邮箱'])
            ->addColumn('mobile', 'string', ['limit' => 20, 'default' => '', 'comment' => '手机号'])
            ->addColumn('login_failure', 'integer', ['default' => 0, 'comment' => '连续登录失败次数'])
            ->addColumn('last_login_time', 'biginteger', ['default' => 0, 'comment' => '最后登录时间'])
            ->addColumn('last_login_ip', 'string', ['limit' => 50, 'default' => '', 'comment' => '最后登录IP'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 1=正常 0=禁用'])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->addIndex(['username'], ['unique' => true])
            ->create();

        // 管理员角色组
        $this->table('auth_group', ['comment' => '管理员角色组'])
            ->addColumn('pid', 'integer', ['default' => 0, 'comment' => '父组ID'])
            ->addColumn('name', 'string', ['limit' => 100, 'default' => '', 'comment' => '组名'])
            ->addColumn('rules', 'text', ['null' => true, 'comment' => "规则ID，逗号分隔；'*' 表示全部权限"])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->create();

        // 管理员-角色组关联
        $this->table('auth_group_access', ['comment' => '管理员角色组关联', 'id' => false, 'primary_key' => ['admin_id', 'group_id']])
            ->addColumn('admin_id', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('group_id', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['group_id'])
            ->create();

        // 菜单与权限规则
        $this->table('auth_rule', ['comment' => '菜单与权限规则'])
            ->addColumn('pid', 'integer', ['default' => 0, 'comment' => '父ID'])
            ->addColumn('type', 'string', ['limit' => 10, 'default' => 'menu', 'comment' => 'dir=目录 menu=菜单 button=按钮/接口'])
            ->addColumn('name', 'string', ['limit' => 150, 'default' => '', 'comment' => '权限节点，如 auth/admin/index'])
            ->addColumn('title', 'string', ['limit' => 50, 'default' => '', 'comment' => '标题'])
            ->addColumn('icon', 'string', ['limit' => 50, 'default' => '', 'comment' => '图标'])
            ->addColumn('path', 'string', ['limit' => 150, 'default' => '', 'comment' => '前端路由路径'])
            ->addColumn('component', 'string', ['limit' => 255, 'default' => '', 'comment' => '前端组件'])
            ->addColumn('hidden', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '是否在菜单中隐藏'])
            ->addColumn('plugin', 'string', ['limit' => 32, 'default' => '', 'comment' => '所属插件，空为核心'])
            ->addColumn('weight', 'integer', ['default' => 0, 'comment' => '排序权重，越大越靠前'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->addIndex(['pid'])
            ->addIndex(['plugin', 'name'])
            ->create();

        // 管理员操作日志
        $this->table('admin_log', ['comment' => '管理员操作日志'])
            ->addColumn('admin_id', 'integer', ['default' => 0])
            ->addColumn('username', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('url', 'string', ['limit' => 1500, 'default' => ''])
            ->addColumn('title', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '请求参数（已脱敏）'])
            ->addColumn('ip', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('useragent', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addIndex(['admin_id'])
            ->addIndex(['create_time'])
            ->create();

        // 会员
        $this->table('user', ['comment' => '会员'])
            ->addColumn('group_id', 'integer', ['default' => 0])
            ->addColumn('username', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('nickname', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('password', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('salt', 'string', ['limit' => 30, 'default' => '', 'comment' => '旧版密码盐（迁移兼容）'])
            ->addColumn('email', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('mobile', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('avatar', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('gender', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '0=未知 1=男 2=女'])
            ->addColumn('birthday', 'date', ['null' => true])
            ->addColumn('bio', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('level', 'integer', ['default' => 0])
            ->addColumn('money', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('score', 'integer', ['default' => 0])
            ->addColumn('last_login_time', 'biginteger', ['default' => 0])
            ->addColumn('last_login_ip', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('join_ip', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->addIndex(['username'])
            ->addIndex(['email'])
            ->addIndex(['mobile'])
            ->create();

        // 会员分组
        $this->table('user_group', ['comment' => '会员分组'])
            ->addColumn('name', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('rules', 'text', ['null' => true])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->create();

        // 登录令牌（仅存哈希）
        $this->table('token', ['comment' => '登录令牌'])
            ->addColumn('token', 'string', ['limit' => 64, 'default' => '', 'comment' => 'sha256(明文令牌)'])
            ->addColumn('type', 'string', ['limit' => 20, 'default' => '', 'comment' => 'admin|user|插件自定义'])
            ->addColumn('user_id', 'integer', ['default' => 0])
            ->addColumn('expire_time', 'biginteger', ['default' => 0, 'comment' => '0=永不过期'])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['type', 'user_id'])
            ->create();

        // 系统配置
        $this->table('config', ['comment' => '系统配置'])
            ->addColumn('group', 'string', ['limit' => 30, 'default' => '', 'comment' => '分组'])
            ->addColumn('name', 'string', ['limit' => 50, 'default' => '', 'comment' => '配置键'])
            ->addColumn('title', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('type', 'string', ['limit' => 20, 'default' => 'string', 'comment' => '表单类型'])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('options', 'text', ['null' => true, 'comment' => '选项 JSON'])
            ->addColumn('tip', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('rule', 'string', ['limit' => 100, 'default' => '', 'comment' => '校验规则'])
            ->addColumn('weight', 'integer', ['default' => 0])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->addIndex(['name'], ['unique' => true])
            ->create();

        // 附件
        $this->table('attachment', ['comment' => '附件'])
            ->addColumn('admin_id', 'integer', ['default' => 0])
            ->addColumn('user_id', 'integer', ['default' => 0])
            ->addColumn('storage', 'string', ['limit' => 30, 'default' => 'local'])
            ->addColumn('url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('name', 'string', ['limit' => 255, 'default' => '', 'comment' => '原始文件名'])
            ->addColumn('mime', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('ext', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('size', 'biginteger', ['default' => 0])
            ->addColumn('width', 'integer', ['default' => 0])
            ->addColumn('height', 'integer', ['default' => 0])
            ->addColumn('sha1', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addIndex(['sha1'])
            ->create();

        // 插件状态
        $this->table('plugin', ['comment' => '插件'])
            ->addColumn('name', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('title', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('version', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '1=启用 0=禁用'])
            ->addColumn('config', 'text', ['null' => true, 'comment' => '插件配置 JSON'])
            ->addColumn('create_time', 'biginteger', ['default' => 0])
            ->addColumn('update_time', 'biginteger', ['default' => 0])
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }
}
