<?php
declare(strict_types=1);

namespace core\console;

use core\support\DoctorService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 开发态 / 生产交付态统一体检命令
 *
 * 用法：
 *   php think hw:doctor --mode=dev --json
 *   php think hw:doctor --mode=prod --json
 */
class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:doctor')
            ->addOption('mode', 'm', Option::VALUE_OPTIONAL, '体检模式（dev|prod）', 'dev')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出诊断结果与上线清单')
            ->setDescription('执行开发态或生产交付态工程自检，返回可执行体检报告与上线核对清单');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rawMode = strtolower(trim((string) $input->getOption('mode')));
        $asJson = (bool) $input->getOption('json');

        if (!in_array($rawMode, ['dev', 'prod'], true)) {
            $err = "不支持的体检模式 [{$rawMode}]，仅支持 dev 或 prod";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'fail', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $service = new DoctorService($this->app);
        $report = $service->diagnose($rawMode);

        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $report['summary']['fail'] > 0 ? 1 : 0;
        }

        $output->info("=== HwAdmin 交付体检 (mode={$report['mode']}, status={$report['status']}) ===");
        foreach ($report['checks'] as $check) {
            $tag = strtoupper((string) $check['status']);
            $output->writeln("  [{$tag}] {$check['name']}: {$check['message']}");
            if ($check['status'] !== 'pass' && $check['hint'] !== '') {
                $output->writeln("        -> 建议: {$check['hint']}");
            }
        }

        if (!empty($report['deploy_checklist'])) {
            $output->info('--- 待核对/上线清单 ---');
            foreach ($report['deploy_checklist'] as $idx => $item) {
                $output->writeln('  ' . ($idx + 1) . '. ' . $item);
            }
        }

        return $report['summary']['fail'] > 0 ? 1 : 0;
    }
}
