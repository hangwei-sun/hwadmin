<?php
// +----------------------------------------------------------------------
// | 前台/会员接口应用路由（URL 前缀 /api）
// +----------------------------------------------------------------------
use think\facade\Route;

// 插件前台接口：/api/plugin/{插件}/{控制器}/{操作}
Route::any('plugin/:plugin/:controller/:action', '\\core\\plugin\\PluginDispatcher@api')
    ->pattern(['plugin' => '[a-z][a-z0-9_]*', 'controller' => '[a-zA-Z][\w.]*', 'action' => '[a-zA-Z]\w*']);
