<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use core\install\Installer;
use think\App;
use think\facade\Db;

$testDbFile = sys_get_temp_dir() . '/hwadmin-phpunit-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
define('HWADMIN_TEST_DB', $testDbFile);

$lockFile = dirname(__DIR__) . '/config/install.lock';
$hadLockBefore = is_file($lockFile);

putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('DB_DRIVER=sqlite');
putenv('DB_TYPE=sqlite');
putenv('DB_NAME=' . HWADMIN_TEST_DB);
putenv('DB_PREFIX=hw_');

$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';
$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['DB_TYPE'] = 'sqlite';
$_ENV['DB_NAME'] = HWADMIN_TEST_DB;
$_ENV['DB_PREFIX'] = 'hw_';

register_shutdown_function(static function () use ($lockFile, $hadLockBefore): void {
    foreach ([HWADMIN_TEST_DB, HWADMIN_TEST_DB . '-wal', HWADMIN_TEST_DB . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    if (!$hadLockBefore && is_file($lockFile)) {
        @unlink($lockFile);
    }
});

// 初始化一次性隔离 SQLite 测试数据库（迁移 + 核心种子 + 超级管理员 admin/Admin12345）
(static function () use ($lockFile, $hadLockBefore): void {
    $bootApp = new App(dirname(__DIR__));
    $bootApp->debug(true);
    $bootApp->initialize();
    restore_error_handler();
    restore_exception_handler();

    try {
        Db::connect()->close();
    } catch (\Throwable) {
    }

    $dbConfig = $bootApp->config->get('database');
    $dbConfig['default'] = 'sqlite';
    $dbConfig['connections']['sqlite']['database'] = HWADMIN_TEST_DB;
    $dbConfig['connections']['sqlite']['prefix'] = 'hw_';
    $bootApp->config->set($dbConfig, 'database');
    Db::setConfig($bootApp->config);
    try {
        Db::connect()->close();
    } catch (\Throwable) {
    }

    $bootApp->env->set([
        'APP_ENV'   => 'testing',
        'APP_DEBUG' => 'true',
        'DB_DRIVER' => 'sqlite',
        'DB_TYPE'   => 'sqlite',
        'DB_NAME'   => HWADMIN_TEST_DB,
        'DB_PREFIX' => 'hw_',
    ]);

    $installer = new Installer($bootApp);
    $installer->migrate();
    $installer->seed();
    $installer->createSuperAdmin('admin', 'Admin12345');
    if (!$hadLockBefore && !is_file($lockFile)) {
        @file_put_contents($lockFile, (string) time());
    }

    try {
        Db::connect()->close();
    } catch (\Throwable) {
    }
})();
gc_collect_cycles();
