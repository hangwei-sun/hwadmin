<?php
declare(strict_types=1);

namespace core\console;

use core\install\Installer;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/**
 * 命令行安装（开发/运维使用；面向小白的网页安装向导见 /install）
 *
 *   php think hw:install --username=admin --password=YourStrongPass
 *   php think hw:install --db-driver=mysql --db-host=127.0.0.1 --db-port=3306 --db-name=hwadmin --db-user=root --db-pass=root
 */
class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:install')
            ->addOption('username', null, Option::VALUE_REQUIRED, '超级管理员用户名', 'admin')
            ->addOption('password', null, Option::VALUE_REQUIRED, '超级管理员密码（至少 8 位）')
            ->addOption('email', null, Option::VALUE_REQUIRED, '超级管理员邮箱', '')
            ->addOption('force', 'f', Option::VALUE_NONE, '已安装时强制重新执行（不会删除数据）')
            ->addOption('skip-if-installed', null, Option::VALUE_NONE, '若已安装则跳过安装并成功返回（用于幂等部署与流水线）')
            ->addOption('db-type', null, Option::VALUE_REQUIRED, '数据库类型 (mysql|pgsql|sqlite)')
            ->addOption('db-driver', null, Option::VALUE_REQUIRED, '数据库驱动类型 (别名，等同于 --db-type)')
            ->addOption('db-host', null, Option::VALUE_REQUIRED, '数据库主机地址')
            ->addOption('db-port', null, Option::VALUE_REQUIRED, '数据库端口')
            ->addOption('db-name', null, Option::VALUE_REQUIRED, '数据库库名或 SQLite 文件路径')
            ->addOption('db-user', null, Option::VALUE_REQUIRED, '数据库用户名')
            ->addOption('db-pass', null, Option::VALUE_REQUIRED, '数据库密码')
            ->addOption('db-prefix', null, Option::VALUE_REQUIRED, '数据库表前缀', 'hw_')
            ->setDescription('HwAdmin 命令行安装：配置数据库、执行迁移、初始化数据、创建超级管理员');
    }

    protected function execute(Input $input, Output $output): int
    {
        $installer = new Installer($this->app);

        if (is_installed() && !$input->getOption('force')) {
            if ($input->getOption('skip-if-installed')) {
                $output->info('系统已安装（存在 config/install.lock），跳过安装任务。');
                return 0;
            }
            $output->error('系统已安装（存在 config/install.lock）。如需重新执行请加 --force 或 --skip-if-installed');
            return 1;
        }

        $dbType = (string) ($input->getOption('db-type') ?: $input->getOption('db-driver') ?: '');
        $dbHost = $input->getOption('db-host');
        $dbPort = $input->getOption('db-port');
        $dbName = $input->getOption('db-name');
        $dbUser = $input->getOption('db-user');
        $dbPass = $input->getOption('db-pass');
        $dbPrefix = (string) ($input->getOption('db-prefix') ?: 'hw_');

        // 如果传入了数据库配置选项，先行写入或更新 .env
        if ($dbType !== '' || $dbHost !== null || $dbName !== null) {
            $dbConfig = [
                'type'   => $dbType !== '' ? $dbType : (string) env('DB_TYPE', 'mysql'),
                'host'   => $dbHost !== null ? (string) $dbHost : (string) env('DB_HOST', '127.0.0.1'),
                'port'   => $dbPort !== null ? (int) $dbPort : (int) env('DB_PORT', 3306),
                'name'   => $dbName !== null ? (string) $dbName : (string) env('DB_NAME', 'hwadmin'),
                'user'   => $dbUser !== null ? (string) $dbUser : (string) env('DB_USER', 'root'),
                'pass'   => $dbPass !== null ? (string) $dbPass : (string) env('DB_PASS', ''),
                'prefix' => $dbPrefix,
            ];
            $output->writeln("更新环境配置 .env (驱动: {$dbConfig['type']})...");
            $installer->writeEnv($dbConfig);
        }

        $effectiveDbType = $dbType !== '' ? $dbType : (string) (env('DB_DRIVER') ?: env('DB_TYPE') ?: 'sqlite');

        $password = (string) $input->getOption('password');
        if ($password === '') {
            if (is_installed()) {
                $password = ''; // 已安装时保留原密码
            } else {
                $password = self::generateStrongPassword(16);
                $output->writeln("<comment>未指定管理员密码，已随机生成初始强密码: {$password}</comment>");
                $output->writeln('<comment>请妥善保存该密码并在首次登录后及时修改！</comment>');
            }
        }

        try {
            foreach ($installer->checkEnvironment($effectiveDbType) as $item) {
                if (!$item['ok'] && $item['required']) {
                    throw new \RuntimeException("环境检查未通过：{$item['name']}（{$item['message']}）");
                }
            }

            $output->writeln('执行数据库迁移...');
            $installer->migrate();

            $output->writeln('初始化基础数据...');
            $installer->seed();

            $output->writeln('创建超级管理员...');
            $installer->createSuperAdmin((string) $input->getOption('username'), $password, (string) $input->getOption('email'));

            $installer->lock();
        } catch (Throwable $e) {
            $output->error($e->getMessage());
            return 1;
        }

        $output->info('安装完成！后台地址：/admin/');
        return 0;
    }

    /**
     * 生成包含大小写字母与数字的密码学安全随机强密码
     */
    public static function generateStrongPassword(int $length = 16): string
    {
        $length = max(12, $length);
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%_-';
        $all = $upper . $lower . $digits . $symbols;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
