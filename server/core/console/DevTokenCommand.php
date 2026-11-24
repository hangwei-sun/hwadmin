<?php
declare(strict_types=1);

namespace core\console;

use core\auth\AdminAuth;
use core\auth\TokenService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 开发与测试阶段快捷签发管理员调试令牌（严格受 APP_ENV/APP_DEBUG 门禁保护）
 *
 * 用法：
 *   php think hw:dev:token --username=admin --json
 *
 * 安全红线：
 *   仅当 APP_DEBUG=true 或 APP_ENV 属于 local / testing / dev 时允许执行，
 *   生产环境默认直接拒绝签发，防止绕过登录防爆破与图形验证码机制。
 */
class DevTokenCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:dev:token')
            ->addOption('username', 'u', Option::VALUE_OPTIONAL, '目标管理员用户名', 'admin')
            ->addOption('ttl', null, Option::VALUE_OPTIONAL, '令牌有效期（秒）', '3600')
            ->addOption('json', 'j', Option::VALUE_NONE, '以 JSON 格式输出')
            ->setDescription('仅在 local/testing/debug 环境下签发开发调试用管理员 Token');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!$this->isDevOrTestingEnvironment()) {
            $output->error('安全拦截：hw:dev:token 仅允许在 APP_ENV=local/testing 或 APP_DEBUG=true 环境下执行');
            return 1;
        }

        $username = trim((string) $input->getOption('username')) ?: 'admin';
        $ttl = max(60, (int) $input->getOption('ttl'));
        $asJson = (bool) $input->getOption('json');

        $admin = Db::name('admin')->where('username', $username)->find();
        if (!$admin) {
            $output->error("管理员账号 [{$username}] 不存在");
            return 1;
        }
        if ((int) ($admin['status'] ?? 0) !== 1) {
            $output->error("管理员账号 [{$username}] 已被禁用");
            return 1;
        }

        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $token = $tokens->issue(AdminAuth::TOKEN_TYPE, (int) $admin['id'], $ttl);

        $payload = [
            'token'      => $token,
            'expires_in' => $ttl,
            'admin'      => [
                'id'       => (int) $admin['id'],
                'username' => (string) $admin['username'],
                'nickname' => (string) ($admin['nickname'] ?? ''),
            ],
        ];

        if ($asJson) {
            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->info("✔ 已签发开发调试 Token（有效期 {$ttl}s）：");
            $output->writeln($token);
        }

        return 0;
    }

    private function isDevOrTestingEnvironment(): bool
    {
        if ($this->app->isDebug()) {
            return true;
        }
        $appEnv = strtolower(trim((string) env('APP_ENV', '')));
        return in_array($appEnv, ['local', 'testing', 'dev', 'development'], true);
    }
}
