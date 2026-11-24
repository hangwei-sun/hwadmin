<?php
declare(strict_types=1);

namespace core\support;

use app\common\model\system\Crontab as CrontabModel;
use app\common\model\system\CrontabLog as CrontabLogModel;
use think\console\Output;
use think\facade\Console;

/**
 * 定时任务安全执行器（抽离自控制器，强制指令白名单、禁用 SQL 类型、接入 OutboundHttpGuard）
 */
class CrontabExecutor
{
    /** 允许定时任务调度的安全控制台指令白名单 */
    public const ALLOWED_COMMANDS = [
        'clear',
        'cache:clear',
        'devops:health-check',
        'hw:doctor',
        'hw:inspect',
        'version',
    ];

    /**
     * 校验任务类型与目标是否符合安全策略
     */
    public static function assertAllowedTask(string $type, string $target): void
    {
        $type = strtolower(trim($type));
        switch ($type) {
            case 'command':
                self::parseAndValidateCommand($target);
                break;

            case 'url':
                OutboundHttpGuard::assertSafeUrl($target);
                break;

            case 'sql':
                throw new \InvalidArgumentException('出于安全策略已禁止创建或执行 SQL 类型定时任务');

            default:
                throw new \InvalidArgumentException("不支持的任务类型: {$type}");
        }
    }

    /**
     * 校验并解析控制台指令
     *
     * @return array{0: string, 1: string[]}
     */
    public static function parseAndValidateCommand(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            throw new \InvalidArgumentException('命令行指令不能为空');
        }
        if (preg_match('/[;&|`$><\r\n\0]/', $command)) {
            throw new \InvalidArgumentException('命令行指令包含非法控制字符');
        }

        $parts = preg_split('/\s+/', $command) ?: [];
        $cmdName = strtolower((string) array_shift($parts));
        if ($cmdName === '' || !in_array($cmdName, self::ALLOWED_COMMANDS, true)) {
            throw new \InvalidArgumentException("安全拦截：指令 [{$cmdName}] 不在允许执行的白名单内");
        }

        foreach ($parts as $arg) {
            if ($arg === '' || !preg_match('/^[a-zA-Z0-9_=.,:\/-]+$/', $arg)) {
                throw new \InvalidArgumentException("安全拦截：指令参数 [{$arg}] 格式非法");
            }
        }

        return [$cmdName, $parts];
    }

    /**
     * 执行具体定时任务的核心逻辑（仅允许白名单 command 与经过 SSRF 校验的 url）
     */
    public static function executeTask(CrontabModel $task): array
    {
        $startTime = microtime(true);
        $isSuccess = true;
        $output = '';

        try {
            $type = strtolower(trim((string) $task->type));
            switch ($type) {
                case 'command':
                    $output = self::executeCommand((string) $task->target);
                    break;

                case 'url':
                    $output = self::executeUrl((string) $task->target);
                    break;

                case 'sql':
                    throw new \InvalidArgumentException('出于安全策略已禁用 SQL 类型定时任务');

                default:
                    throw new \InvalidArgumentException("不支持的任务类型: {$task->type}");
            }
        } catch (\Throwable $e) {
            $isSuccess = false;
            $output = '执行异常: ' . $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $now = time();

        if (mb_strlen($output) > 65000) {
            $output = mb_substr($output, 0, 65000) . "\n...[输出过长已截断]";
        }

        $task->save([
            'last_run_time'   => $now,
            'last_run_status' => $isSuccess ? 1 : 0,
            'last_run_cost'   => $durationMs,
            'update_time'     => $now,
        ]);

        $log = new CrontabLogModel();
        $log->save([
            'crontab_id'  => $task->id,
            'title'       => $task->title,
            'target'      => $task->target,
            'status'      => $isSuccess ? 1 : 0,
            'output'      => $output,
            'duration_ms' => $durationMs,
            'create_time' => $now,
        ]);

        return [
            'status'      => $isSuccess ? 1 : 0,
            'output'      => $output,
            'duration_ms' => $durationMs,
            'run_time'    => date('Y-m-d H:i:s', $now),
        ];
    }

    private static function executeCommand(string $command): string
    {
        [$cmdName, $parts] = self::parseAndValidateCommand($command);
        $outputBuffer = new Output();

        try {
            ob_start();
            Console::call($cmdName, $parts, $outputBuffer);
            $buffered = ob_get_clean();
            return trim($buffered !== false && $buffered !== '' ? $buffered : "指令 [{$command}] 执行完毕");
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private static function executeUrl(string $url): string
    {
        $safeUrl = OutboundHttpGuard::assertSafeUrl($url);
        $resp = OutboundHttpGuard::request(
            $safeUrl,
            'GET',
            '',
            [],
            30,
            10,
            3,
            'HwAdmin-Crontab-Runner/1.0',
            true
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('CURL 请求错误: ' . ($resp['error'] ?: '请求失败'));
        }

        $summary = "HTTP 状态码: {$resp['http_code']}\n返回内容:\n" . $resp['body'];
        if ($resp['http_code'] >= 400) {
            throw new \RuntimeException($summary);
        }

        return $summary;
    }
}
