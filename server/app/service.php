<?php

use app\AppService;
use core\plugin\PluginService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
return [
    AppService::class,
    // HwAdmin 插件系统（加载已启用插件的事件与启动逻辑）
    PluginService::class,
];
