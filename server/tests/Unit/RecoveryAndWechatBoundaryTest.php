<?php
declare(strict_types=1);

namespace tests\Unit;

use core\exception\ApiException;
use core\support\WechatMiniAuthService;
use tests\TestCase;
use think\App;
use think\facade\Db;

final class RecoveryAndWechatBoundaryTest extends TestCase
{
    private bool $production = false;
    private bool $deliveryEnabled = false;
    private array $delivered = [];

    protected function createIsolatedApp(): App
    {
        $app = parent::createIsolatedApp();
        if ($this->production) {
            // Accidentally enabling debug on a server must not expose credentials.
            $app->env->set(['APP_ENV' => 'production']);
        }
        if ($this->deliveryEnabled) {
            $app->event->listen('UserPasswordResetRequested', function (array $data): bool {
                $this->delivered = $data;
                return true;
            });
        }
        return $app;
    }

    protected function tearDown(): void
    {
        WechatMiniAuthService::resetTestState();
        unset($_ENV['WECHAT_MINI_APPID'], $_ENV['WECHAT_MINI_SECRET'], $_ENV['WECHAT_MINI_MOCK_CODES']);
        parent::tearDown();
    }

    private function member(): array
    {
        $name = 'boundary' . bin2hex(random_bytes(5));
        $result = $this->guestPost('/api/user/register', ['username' => $name, 'password' => 'OriginalPass123!']);
        $this->assertApiSuccess($result);
        return [$name, $result['data']['token'], (int) $result['data']['user']['id']];
    }

    public function testProductionDebugDoesNotEchoRecoveryCredentialAndRequiresDelivery(): void
    {
        [$name] = $this->member();
        $this->production = true;
        $result = $this->guestPost('/api/user/sendResetCode', ['account' => $name]);
        $this->assertApiFail($result);
        $this->assertSame('CONFIGURE_PASSWORD_RECOVERY_DELIVERY', $result['data']['todo']);
        $this->assertArrayNotHasKey('reset_token', $result['data']);

        $this->deliveryEnabled = true;
        $sent = $this->guestPost('/api/user/sendResetCode', ['account' => $name]);
        $this->assertApiSuccess($sent);
        $this->assertArrayNotHasKey('reset_token', $sent['data']);
        $this->assertNotEmpty($this->delivered['reset_token']);
        $changed = $this->guestPost('/api/user/resetPassword', [
            'account' => $name, 'reset_token' => $this->delivered['reset_token'], 'new_password' => 'RecoveredPass456!',
        ]);
        $this->assertApiSuccess($changed);
        $replay = $this->guestPost('/api/user/resetPassword', [
            'account' => $name, 'reset_token' => $this->delivered['reset_token'], 'new_password' => 'ReplayPass789!',
        ]);
        $this->assertApiFail($replay);
    }

    public function testPasswordChangeAlsoInvalidatesOutstandingRecoveryCredentials(): void
    {
        [$name, $token] = $this->member();
        $sent = $this->guestPost('/api/user/sendResetCode', ['account' => $name]);
        $this->assertApiSuccess($sent);
        $changed = $this->dispatchHttp('POST', '/api/user/resetPassword', [], [
            'old_password' => 'OriginalPass123!', 'new_password' => 'UpdatedPass456!',
        ], $token);
        $this->assertApiSuccess($changed);
        $this->assertApiFail($this->guestPost('/api/user/resetPassword', [
            'account' => $name, 'reset_token' => $sent['data']['reset_token'], 'new_password' => 'StaleToken789!',
        ]));
    }

    public function testEditableProfileCannotClaimWechatIdentityOrTransferExistingBinding(): void
    {
        [, , $attacker] = $this->member();
        $openid = 'openid_boundary_' . bin2hex(random_bytes(8));
        Db::name('user')->where('id', $attacker)->update([
            'username' => 'wx_' . substr(hash('sha256', $openid), 0, 24),
            'bio' => 'wechat:' . $openid,
        ]);
        WechatMiniAuthService::setTestCredentials('wx_boundary_app', 'boundary_secret');
        $service = new WechatMiniAuthService($this->app);
        $first = $service->bindOrCreateUser($openid);
        $this->assertNotSame($attacker, (int) $first['user']['id']);
        $second = $service->bindOrCreateUser($openid);
        $this->assertSame((int) $first['user']['id'], (int) $second['user']['id']);
        $this->expectException(ApiException::class);
        $service->bindOrCreateUser($openid, '', '', ['bind_user_id' => $attacker]);
    }

    public function testWechatMockMappingIsRejectedOutsideTesting(): void
    {
        $this->app->env->set(['APP_ENV' => 'production']);
        $_ENV['WECHAT_MINI_APPID'] = 'wx_production_app';
        $_ENV['WECHAT_MINI_SECRET'] = 'production_secret_fixture';
        $_ENV['WECHAT_MINI_MOCK_CODES'] = '{"fake":"openid_fake_123"}';
        $this->expectException(ApiException::class);
        (new WechatMiniAuthService($this->app))->code2Session('fake');
    }
}
