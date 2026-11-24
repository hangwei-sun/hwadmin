<?php
declare(strict_types=1);

namespace core\console;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 后端自动化测试一键入口（封装 PHPUnit）
 *
 * 用法：
 *   php think hw:test
 *   php think hw:test --filter=CrudValidationTest
 */
class TestCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:test')
            ->addOption('filter', null, Option::VALUE_OPTIONAL, '仅运行匹配名称的测试类或方法', '')
            ->setDescription('运行 HwAdmin 后端 PHPUnit 自动化测试套件（SQLite 隔离夹具）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $issue = self::diagnoseEnvironment($root);
        if ($issue !== null) {
            $output->error($issue);
            return 1;
        }

        $phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
            . ' --configuration ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'phpunit.xml');

        $filter = trim((string) $input->getOption('filter'));
        if ($filter !== '') {
            $cmd .= ' --filter ' . escapeshellarg($filter);
        }

        $descriptorSpec = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        if (!is_resource($proc)) {
            $output->error('无法启动 PHPUnit 测试进程');
            return 1;
        }

        return proc_close($proc);
    }

    /**
     * 动态检测运行目录属于源码仓 server/ 还是生产发布包根目录，并检查 PHPUnit 依赖与测试套件是否就绪
     */
    public static function diagnoseEnvironment(string $root): ?string
    {
        $root = rtrim($root, '/\\');
        $ds = DIRECTORY_SEPARATOR;
        $isSourceRepoServer = basename($root) === 'server'
            && is_dir(dirname($root) . $ds . 'admin-ui');

        $phpunit = $root . $ds . 'vendor' . $ds . 'bin' . $ds . 'phpunit';
        if (!is_file($phpunit)) {
            $dirHint = $isSourceRepoServer ? 'server/ 目录' : '当前项目根目录';
            $releaseTip = $isSourceRepoServer ? '' : '（发布包默认仅含生产依赖）';
            return "未找到 vendor/bin/phpunit{$releaseTip}，请先在{$dirHint}执行 composer install 后重试";
        }

        $phpunitXml = $root . $ds . 'phpunit.xml';
        $testsDir = $root . $ds . 'tests';
        $bootstrap = $testsDir . $ds . 'bootstrap.php';
        if (!is_file($phpunitXml) || !is_dir($testsDir) || !is_file($bootstrap)) {
            return '当前目录缺少 phpunit.xml 或 tests/bootstrap.php 测试引导，无法运行 PHPUnit 套件';
        }

        return null;
    }
}
