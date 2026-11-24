<?php
// +----------------------------------------------------------------------
// | 核心菜单与权限规则（安装/升级时通过 MenuImporter 同步到 auth_rule，plugin = ''）
// | 新增核心页面：在此登记 → 执行 php think hw:install --force 或后台“同步菜单”。
// | 字段说明见 core\plugin\MenuImporter
// +----------------------------------------------------------------------

$crud = static function (string $node, ?array $actions = null): array {
    $actions ??= ['index' => '查看', 'read' => '详情', 'add' => '添加', 'edit' => '编辑', 'del' => '删除'];
    $buttons = [];
    foreach ($actions as $action => $title) {
        $buttons[] = ['type' => 'button', 'name' => "{$node}/{$action}", 'title' => $title];
    }
    return $buttons;
};

return [
    [
        'type' => 'menu', 'name' => 'dashboard', 'title' => '首页', 'path' => 'index',
        'component' => '@/views/index/index.vue', 'icon' => 'home-2-line', 'weight' => 1000,
    ],
    [
        'type' => 'dir', 'name' => 'auth', 'title' => '权限', 'path' => 'auth', 'icon' => 'shield-user-line', 'weight' => 900,
        'children' => [
            ['type' => 'menu', 'name' => 'auth/admin', 'title' => '管理员', 'path' => 'admin', 'component' => '@/views/auth/admin/index.vue', 'icon' => 'user-settings-line', 'children' => $crud('auth/admin')],
            ['type' => 'menu', 'name' => 'auth/group', 'title' => '角色组', 'path' => 'group', 'component' => '@/views/auth/group/index.vue', 'icon' => 'group-line', 'children' => $crud('auth/group')],
            ['type' => 'menu', 'name' => 'auth/rule', 'title' => '菜单规则', 'path' => 'rule', 'component' => '@/views/auth/rule/index.vue', 'icon' => 'menu-line', 'children' => $crud('auth/rule')],
            ['type' => 'menu', 'name' => 'auth/admin_log', 'title' => '操作日志', 'path' => 'adminLog', 'component' => '@/views/auth/adminLog/index.vue', 'icon' => 'file-list-3-line', 'children' => $crud('auth/admin_log', ['index' => '查看', 'read' => '详情'])],
        ],
    ],
    [
        'type' => 'dir', 'name' => 'user', 'title' => '会员', 'path' => 'user', 'icon' => 'user-3-line', 'weight' => 800,
        'children' => [
            ['type' => 'menu', 'name' => 'user/user', 'title' => '会员列表', 'path' => 'user', 'component' => '@/views/user/user/index.vue', 'icon' => 'user-line', 'children' => $crud('user/user')],
            ['type' => 'menu', 'name' => 'user/group', 'title' => '会员分组', 'path' => 'group', 'component' => '@/views/user/group/index.vue', 'icon' => 'team-line', 'children' => $crud('user/group')],
        ],
    ],
    [
        'type' => 'dir', 'name' => 'system', 'title' => '系统', 'path' => 'system', 'icon' => 'settings-3-line', 'weight' => 700,
        'children' => [
            ['type' => 'menu', 'name' => 'system/config', 'title' => '系统配置', 'path' => 'config', 'component' => '@/views/system/config/index.vue', 'icon' => 'equalizer-line', 'children' => $crud('system/config')],
            ['type' => 'menu', 'name' => 'system/attachment', 'title' => '附件管理', 'path' => 'attachment', 'component' => '@/views/system/attachment/index.vue', 'icon' => 'attachment-line', 'children' => $crud('system/attachment', ['index' => '查看', 'read' => '详情', 'upload' => '上传', 'del' => '删除'])],
            ['type' => 'menu', 'name' => 'system/personal_center', 'title' => '个人中心', 'path' => 'personalCenter', 'component' => '@/views/setting/personalCenter/index.vue', 'icon' => 'user-follow-line', 'weight' => 600, 'children' => $crud('system/personal_center', ['index' => '查看', 'edit' => '修改'])],
            ['type' => 'menu', 'name' => 'system/plugin', 'title' => '插件管理', 'path' => 'plugin', 'component' => '@/views/system/plugin/index.vue', 'icon' => 'apps-2-line', 'children' => [
                ['type' => 'button', 'name' => 'system/plugin/index', 'title' => '查看'],
                ['type' => 'button', 'name' => 'system/plugin/install', 'title' => '安装'],
                ['type' => 'button', 'name' => 'system/plugin/uninstall', 'title' => '卸载'],
                ['type' => 'button', 'name' => 'system/plugin/enable', 'title' => '启用'],
                ['type' => 'button', 'name' => 'system/plugin/disable', 'title' => '禁用'],
                ['type' => 'button', 'name' => 'system/plugin/upgrade', 'title' => '升级'],
                ['type' => 'button', 'name' => 'system/plugin/config', 'title' => '配置'],
            ]],
        ],
    ],
    [
        'type' => 'dir', 'name' => 'plugin', 'title' => '应用开发', 'path' => 'plugin', 'icon' => 'apps-2-line', 'weight' => 650,
        'children' => [
            ['type' => 'menu', 'name' => 'system/dict', 'title' => '数据字典', 'path' => 'dict', 'component' => '@/views/system/dict/index.vue', 'icon' => 'Collection', 'weight' => 95, 'children' => $crud('system/dict')],
            ['type' => 'menu', 'name' => 'system/crontab', 'title' => '定时任务', 'path' => 'crontab', 'component' => '@/views/system/crontab/index.vue', 'icon' => 'AlarmClock', 'weight' => 90, 'children' => array_merge($crud('system/crontab'), [
                ['type' => 'button', 'name' => 'system/crontab/run', 'title' => '立即执行'],
            ])],
            ['type' => 'menu', 'name' => 'system/devops', 'title' => '开发运维中心', 'path' => 'devops', 'component' => '@/views/system/devops/index.vue', 'icon' => 'Cpu', 'weight' => 85, 'children' => [
                ['type' => 'button', 'name' => 'system/devops/index', 'title' => '查看'],
                ['type' => 'button', 'name' => 'system/devops/apiDocs', 'title' => '接口文档'],
                ['type' => 'button', 'name' => 'system/devops/tables', 'title' => '表空间'],
                ['type' => 'button', 'name' => 'system/devops/backup', 'title' => '备份还原'],
            ]],
            ['type' => 'menu', 'name' => 'plugin/crud', 'title' => 'CRUD生成', 'path' => 'crud', 'component' => '@/views/plugin/crud/index.vue', 'icon' => 'MagicStick', 'weight' => 80, 'children' => [
                ['type' => 'button', 'name' => 'plugin/crud/index', 'title' => '查看'],
                ['type' => 'button', 'name' => 'plugin/crud/generator/tables', 'title' => '数据表列表'],
                ['type' => 'button', 'name' => 'plugin/crud/generator/columns', 'title' => '字段探测'],
                ['type' => 'button', 'name' => 'plugin/crud/generator/preview', 'title' => '代码预览'],
                ['type' => 'button', 'name' => 'plugin/crud/generator/generate', 'title' => '一键生成'],
            ]],
        ],
    ],
];
