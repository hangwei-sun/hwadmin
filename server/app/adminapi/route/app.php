<?php
// +----------------------------------------------------------------------
// | 后台接口应用路由（URL 前缀 /adminapi）
// | 未在此定义的地址按 ThinkPHP 默认规则访问：/adminapi/{控制器}/{操作}
// | 多级控制器使用点号：/adminapi/auth.admin/index
// +----------------------------------------------------------------------
use think\facade\Route;

// 插件后台接口：/adminapi/plugin/{插件}/{控制器}/{操作}
Route::any('plugin/:plugin/:controller/:action', '\\core\\plugin\\PluginDispatcher@admin')
    ->pattern(['plugin' => '[a-z][a-z0-9_]*', 'controller' => '[a-zA-Z][\w.]*', 'action' => '[a-zA-Z]\w*']);
