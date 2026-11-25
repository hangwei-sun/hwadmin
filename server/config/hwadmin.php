<?php
// +----------------------------------------------------------------------
// | HwAdmin 框架配置
// +----------------------------------------------------------------------

return [
    // 框架底层基座版本（维护 HwAdmin 框架时变更，插件兼容检查依据此值）
    'version'        => '1.6.5',

    // 业务应用标识与业务版本（业务开发仅需维护业务版本，无需同步十处框架版本）
    'app_id'         => (string) env('APP_ID', 'hwadmin-app'),
    'app_name'       => (string) env('APP_NAME', 'HwAdmin Application'),
    'app_version'    => (string) env('APP_VERSION', '1.0.0'),

    // 令牌
    'token'          => [
        // 后台管理员令牌有效期（秒）
        'admin_ttl' => (int) env('HW_ADMIN_TOKEN_TTL', 86400),
        // 会员令牌有效期（秒）
        'user_ttl'  => (int) env('HW_USER_TOKEN_TTL', 2592000),
        // 读取令牌的请求头，优先 Authorization: Bearer <token>，其次此头
        'header'    => 'X-Token',
    ],

    // 跨域白名单，空数组表示不额外放行（同域部署推荐）
    'cors_origins'   => array_values(array_filter(array_map('trim', explode(',', (string) env('HW_CORS_ORIGINS', ''))))),

    // 登录安全（P1-5：基于 (account + ip) 与 ip 双维度防爆破限流）
    'login'          => [
        // 同一 IP 对同一账号连续失败多少次后锁定该 (account, ip) 组合
        'max_failures'    => (int) env('HW_LOGIN_MAX_FAILURES', 5),
        // 同一 IP 跨账号累计失败多少次后封禁该 IP 登录尝试
        'max_ip_failures' => (int) env('HW_LOGIN_MAX_IP_FAILURES', 20),
        // 锁定窗口时长（秒，默认 15 分钟）
        'lock_seconds'    => (int) env('HW_LOGIN_LOCK_SECONDS', 900),
    ],

    // 插件
    'plugin'         => [
        // 插件源码目录（相对项目根目录）
        'path'       => 'plugins',
        // 插件静态资源发布目录（相对 public）
        'asset_path' => 'plugins',
    ],

    // 上传
    'upload'         => [
        'max_size'   => 10 * 1024 * 1024,
        'allow_ext'  => 'jpg,jpeg,png,gif,webp,bmp,mp4,mp3,zip,rar,7z,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
        'save_path'  => 'uploads/{year}{mon}{day}',
    ],

    // 应用商城渠道配置：
    // default_url 作为所有开源框架安装实例的默认官方商城渠道；
    // 管理员在后台切换自定义商城地址时存入 config.market_server_url，清空即可一键切回官方默认渠道。
    'market'         => [
        'default_url' => rtrim((string) (env('MARKET_SERVER_URL', '') ?: env('HW_MARKET_GATEWAY', '') ?: 'https://hw.baotoutv.cn'), '/'),
    ],
];
