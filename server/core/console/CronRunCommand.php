<?php
declare(strict_types=1);

namespace core\console;

use app\common\model\system\Crontab as CrontabModel;
use core\support\CrontabExecutor;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 定时任务调度轮询指令
 *
 * 用法：
 *   php think cron:run
 *   php think cron:run --id=1 --force
 */
class CronRunCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('cron:run')
            ->addOption('id', null, Option::VALUE_OPTIONAL, '指定运行的任务ID')
            ->addOption('force', 'f', Option::VALUE_NONE, '是否强制运行（忽略Cron时间表达式）')
            ->setDescription('运行到期的定时任务');
    }

    protected function execute(Input $input, Output $output): int
    {
        $id = $input->getOption('id');
        $force = (bool) $input->getOption('force');
        $now = time();

        $query = CrontabModel::where('status', 1);
        if ($id) {
            $query->where('id', (int) $id);
        }

        $tasks = $query->select();
        if ($tasks->isEmpty()) {
            $output->writeln('<info>[Cron]</info> 没有处于启用状态的定时任务。');
            return 0;
        }

        $executedCount = 0;
        foreach ($tasks as $task) {
            $shouldRun = $force;
            if (!$shouldRun) {
                // 判断当前分钟是否已执行过，避免同一分钟重复触发
                $lastMinute = $task->last_run_time > 0 ? date('YmdHi', (int) $task->last_run_time) : '';
                $currentMinute = date('YmdHi', $now);
                if ($lastMinute !== $currentMinute && self::isDue((string) $task->cron, $now)) {
                    $shouldRun = true;
                }
            }

            if ($shouldRun) {
                $output->writeln(sprintf('<info>[Cron Trigger]</info> 正在执行任务 #%d: %s (%s)', $task->id, $task->title, $task->type));
                $result = CrontabExecutor::executeTask($task);
                $statusText = $result['status'] === 1 ? '<info>成功</info>' : '<error>失败</error>';
                $output->writeln(sprintf('       结果: %s | 耗时: %d ms', $statusText, $result['duration_ms']));
                $executedCount++;
            }
        }

        $output->writeln(sprintf('<info>[Cron]</info> 调度完毕，本次共执行 %d 个任务。', $executedCount));
        return 0;
    }

    /**
     * 判断 Cron 表达式是否在指定时间戳触发
     * 支持 5 段格式：分 时 日 月 周
     */
    public static function isDue(string $expression, int $timestamp): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if (count($fields) < 5) {
            return false;
        }

        $minute = (int) date('i', $timestamp);
        $hour = (int) date('G', $timestamp);
        $day = (int) date('j', $timestamp);
        $month = (int) date('n', $timestamp);
        $dayOfWeek = (int) date('w', $timestamp); // 0 (周日) - 6 (周六)

        return self::matchField($fields[0], $minute, 0, 59)
            && self::matchField($fields[1], $hour, 0, 23)
            && self::matchField($fields[2], $day, 1, 31)
            && self::matchField($fields[3], $month, 1, 12)
            && self::matchField($fields[4], $dayOfWeek, 0, 6);
    }

    /**
     * 字段匹配器
     */
    private static function matchField(string $field, int $current, int $min, int $max): bool
    {
        if ($field === '*' || $field === '?') {
            return true;
        }

        // 步长表达式: */5
        if (str_starts_with($field, '*/')) {
            $step = (int) substr($field, 2);
            return $step > 0 && ($current % $step === 0);
        }

        // 列表表达式: 1,3,5
        if (str_contains($field, ',')) {
            $items = explode(',', $field);
            foreach ($items as $item) {
                if ((int) trim($item) === $current) {
                    return true;
                }
            }
            return false;
        }

        // 范围表达式: 1-5
        if (str_contains($field, '-')) {
            $range = explode('-', $field);
            if (count($range) === 2) {
                $start = (int) $range[0];
                $end = (int) $range[1];
                return $current >= $start && $current <= $end;
            }
        }

        // 单一数字匹配
        return (int) $field === $current;
    }
}
