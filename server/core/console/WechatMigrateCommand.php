<?php
declare(strict_types=1);

namespace core\console;

use core\exception\ApiException;
use core\support\WechatMiniAuthService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/**
 * 旧版微信绑定可审查迁移命令（T07）
 *
 * 用法：
 *   php think hw:wechat:migrate-bindings --list --json
 *   php think hw:wechat:migrate-bindings --list --app-id=wx123456 --json
 *   php think hw:wechat:migrate-bindings --confirm --app-id=wx123456 --json
 */
class WechatMigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:wechat:migrate-bindings')
            ->addOption('list', 'l', Option::VALUE_NONE, '列出待审查旧版微信绑定记录、目标 user_id 存在性与冲突检测')
            ->addOption('confirm', null, Option::VALUE_NONE, '管理员确认归属后执行安全迁移（拒绝冲突绑定覆盖）')
            ->addOption('app-id', null, Option::VALUE_OPTIONAL, '目标微信小程序 AppID', '')
            ->addOption('openid', null, Option::VALUE_OPTIONAL, '仅迁移指定 OpenID（可选）', '')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出')
            ->setDescription('可审查地迁移旧版微信 OpenID 绑定至 app_id + db_key 隔离结构');
    }

    protected function execute(Input $input, Output $output): int
    {
        $listMode = (bool) $input->getOption('list');
        $confirmMode = (bool) $input->getOption('confirm');
        $appId = trim((string) $input->getOption('app-id'));
        $openid = trim((string) $input->getOption('openid'));
        $asJson = (bool) $input->getOption('json');

        $service = new WechatMiniAuthService($this->app);

        try {
            if ($listMode || !$confirmMode) {
                if (!$listMode && !$confirmMode) {
                    $err = '请指定 --list 审查待迁移记录，或指定 --confirm --app-id=<appId> 执行迁移';
                    if ($asJson) {
                        $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                    } else {
                        $output->error($err);
                    }
                    return 1;
                }

                $report = $service->inspectLegacyBindings($appId !== '' ? $appId : null);
                $payload = array_merge(['status' => 'ok'], $report);

                if ($asJson) {
                    $output->writeln((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->info(sprintf(
                        '=== 旧版微信绑定审查报告 (总计 %d，可迁移 %d，冲突/异常 %d) ===',
                        $report['total_legacy'],
                        $report['migratable'],
                        $report['conflicts']
                    ));
                    foreach ($report['items'] as $item) {
                        $output->writeln(sprintf(
                            '  - %s | user_id=%d (%s) | status=%s | reasons=%s',
                            $item['file'],
                            $item['user_id'],
                            $item['user_exists'] ? ('存在:' . $item['user_username']) : '不存在',
                            $item['status'],
                            empty($item['conflict_reasons']) ? 'none' : implode(',', $item['conflict_reasons'])
                        ));
                    }
                }
                return 0;
            }

            if ($appId === '') {
                $err = '执行 --confirm 迁移时必须通过 --app-id=<appId> 指定目标微信小程序 AppID';
                if ($asJson) {
                    $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->error($err);
                }
                return 1;
            }

            $res = $service->migrateLegacyBindings($appId, true, $openid !== '' ? $openid : null);
            if ($asJson) {
                $output->writeln((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info(sprintf(
                    '[✔] 微信旧绑定迁移执行完成：AppID=%s，成功迁移 %d 条，拒绝冲突 %d 条',
                    $res['app_id'],
                    $res['migrated_count'],
                    $res['conflict_count']
                ));
            }

            return $res['conflict_count'] > 0 ? 1 : 0;
        } catch (ApiException $e) {
            if ($asJson) {
                $output->writeln((string) json_encode([
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                    'data'   => $e->getData(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->error($e->getMessage());
            }
            return 1;
        } catch (Throwable $e) {
            if ($asJson) {
                $output->writeln((string) json_encode([
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->error('迁移失败: ' . $e->getMessage());
            }
            return 1;
        }
    }
}
