<?php
declare(strict_types=1);

namespace core\console;

use core\support\OpenApiExporter;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 导出 OpenAPI 3.0 规范契约命令行工具
 *
 * 用法：
 *   php think hw:openapi --json
 *   php think hw:openapi --output=openapi.json
 */
class OpenApiCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:openapi')
            ->addOption('output', 'o', Option::VALUE_OPTIONAL, '输出文件路径（默认 stdout）', '')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出到控制台')
            ->setDescription('导出 OpenAPI 3.0.0 规范契约（供管理端与移动端强类型消费）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $outputPath = trim((string) $input->getOption('output'));
        $asJson = (bool) $input->getOption('json');

        $exporter = new OpenApiExporter($this->app);
        $spec = $exporter->export();

        if ($outputPath !== '') {
            $realPath = $exporter->exportToFile($outputPath);
            $output->info("[✔] OpenAPI 3.0 规范已导出到：{$realPath}");
            return 0;
        }

        $output->writeln((string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return 0;
    }
}
