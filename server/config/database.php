<?php
// +----------------------------------------------------------------------
// | 数据库设置
// | 支持 mysql（推荐）/ pgsql / sqlite（仅用于自动化测试）。
// | 所有核心表结构通过 database/migrations 迁移创建，禁止在核心代码中写数据库方言 SQL。
// +----------------------------------------------------------------------

$common = [
    // 数据库表前缀
    'prefix'          => env('DB_PREFIX', 'hw_'),
    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
    'deploy'          => 0,
    // 数据库读写是否分离 主从式有效
    'rw_separate'     => false,
    // 读写分离后 主服务器数量
    'master_num'      => 1,
    // 指定从服务器序号
    'slave_no'        => '',
    // 是否严格检查字段是否存在
    'fields_strict'   => true,
    // 是否需要断线重连
    'break_reconnect' => env('DB_BREAK_RECONNECT', false),
    // 监听SQL
    'trigger_sql'     => env('APP_DEBUG', false),
    // 开启字段缓存（默认生产环境开启、调试模式关闭，可通过 DB_FIELDS_CACHE 显式覆盖）
    'fields_cache'    => env('DB_FIELDS_CACHE', !env('APP_DEBUG', false)),
];

// 为 SQLite 连接自动应用高性能并发 PRAGMA（WAL 日志模式、NORMAL 同步与 5000ms 锁等待超时）
if (class_exists(\think\Container::class)) {
    try {
        $container = \think\Container::getInstance();
        if ($container && $container->has('event')) {
            /** @var \WeakMap<\think\Event, bool>|null $hwSqliteEventBound */
            static $hwSqliteEventBound = null;
            $hwSqliteEventBound ??= new \WeakMap();
            $eventService = $container->make(\think\Event::class);
            if (!isset($hwSqliteEventBound[$eventService])) {
                $hwSqliteEventBound[$eventService] = true;
                $eventService->listen('db.after_connect', static function ($pdo) use ($container): void {
                    if (!$pdo instanceof \PDO || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                        return;
                    }
                    $pragmas = [];
                    if ($container->has('config')) {
                        $pragmas = (array) ($container->make(\think\Config::class)->get('database.connections.sqlite.pragmas') ?? []);
                    }
                    $journalMode = strtoupper((string) ($pragmas['journal_mode'] ?? 'WAL'));
                    if (in_array($journalMode, ['WAL', 'DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'OFF'], true)) {
                        @$pdo->exec("PRAGMA journal_mode = {$journalMode}");
                    }
                    $synchronous = strtoupper((string) ($pragmas['synchronous'] ?? 'NORMAL'));
                    if (in_array($synchronous, ['OFF', 'NORMAL', 'FULL', 'EXTRA'], true)) {
                        @$pdo->exec("PRAGMA synchronous = {$synchronous}");
                    }
                    $busyTimeout = max(0, (int) ($pragmas['busy_timeout'] ?? 5000));
                    @$pdo->exec("PRAGMA busy_timeout = {$busyTimeout}");
                });
            }
        }
    } catch (\Throwable) {
    }
}

return [
    // 默认使用的数据库连接配置
    'default'         => env('DB_DRIVER', 'mysql'),

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳字段（核心表时间字段统一为整型 Unix 时间戳）
    'auto_timestamp'  => true,

    // 时间字段取出后的默认时间格式（false 表示保持时间戳原样输出，由前端格式化）
    'datetime_format' => false,

    // 时间字段配置 配置格式：create_time,update_time
    'datetime_field'  => '',

    // 数据库连接配置信息
    'connections'     => [
        'mysql'  => array_merge($common, [
            'type'     => 'mysql',
            'hostname' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_NAME', 'hwadmin'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'hostport' => env('DB_PORT', '3306'),
            'params'   => [],
            'charset'  => env('DB_CHARSET', 'utf8mb4'),
        ]),
        'pgsql'  => array_merge($common, [
            'type'     => 'pgsql',
            'hostname' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_NAME', 'hwadmin'),
            'username' => env('DB_USER', 'postgres'),
            'password' => env('DB_PASS', ''),
            'hostport' => env('DB_PORT', '5432'),
            'params'   => [],
            'charset'  => 'utf8',
        ]),
        'sqlite' => array_merge($common, [
            'type'     => 'sqlite',
            'database' => env('DB_NAME', root_path() . 'runtime/hwadmin.sqlite'),
            'charset'  => 'utf8',
            'params'   => [
                \PDO::ATTR_TIMEOUT => 30,
            ],
            'pragmas'  => [
                'journal_mode' => env('DB_SQLITE_JOURNAL_MODE', 'WAL'),
                'synchronous'  => env('DB_SQLITE_SYNCHRONOUS', 'NORMAL'),
                'busy_timeout' => (int) env('DB_SQLITE_BUSY_TIMEOUT', 5000),
            ],
            // 以下占位项供 think-migration 读取，SQLite 不使用
            'hostname' => '',
            'username' => '',
            'password' => '',
            'hostport' => '',
        ]),
    ],
];
