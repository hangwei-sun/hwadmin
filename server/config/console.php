<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'cron:run'                   => \core\console\CronRunCommand::class,
        'hw:project'                 => \core\console\ProjectCommand::class,
        'hw:openapi'                 => \core\console\OpenApiCommand::class,
        'hw:wechat:migrate-bindings' => \core\console\WechatMigrateCommand::class,
    ],
];
