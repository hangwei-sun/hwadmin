<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\install\controller\Index;
use core\exception\ApiException;
use think\App;
use think\Request;

final class InstallAccessProbe extends Index
{
    public function __construct(App $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
    }

    public function expected(): string { return $this->getExpectedInstallToken(); }
    public function access(): void { $this->ensureInstallAccess(); }
}

$root = sys_get_temp_dir() . '/hwadmin-install-access-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$app = new class($root) extends App {
    public function getConfigPath(): string { return $this->getRootPath(); }
};
$app->env->set('HW_INSTALL_TOKEN', '');
$previous = getenv('HW_INSTALL_TOKEN');
putenv('HW_INSTALL_TOKEN');
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) { throw new RuntimeException($message); }
    $count++;
};
$denied = static function (InstallAccessProbe $probe, int $status) use ($assert): void {
    try { $probe->access(); } catch (ApiException $e) {
        $assert($e->getHttpStatus() === $status, 'Unexpected denial status');
        return;
    }
    throw new RuntimeException('Unauthenticated installation was allowed');
};

try {
    $request = new Request();
    $request->withServer(['REMOTE_ADDR' => '127.0.0.1']);
    $probe = new InstallAccessProbe($app, $request);
    $denied($probe, 403);
    $token = $probe->expected();
    $assert(strlen($token) === 64 && ctype_xdigit($token), 'Token entropy/format');
    $assert($token === $probe->expected(), 'Token changed between requests');
    $assert(trim(file_get_contents($root . '/install.token')) === $token, 'Token not persisted');
    $assert((fileperms($root . '/install.token') & 0777) === 0600, 'Token is not private');
    foreach (['::1', '192.0.2.10'] as $ip) {
        $request = new Request();
        $request->withServer(['REMOTE_ADDR' => $ip]);
        $denied(new InstallAccessProbe($app, $request), 403);
    }
    $request = new Request();
    $request->withHeader(['x-install-token' => 'wrong', 'x-forwarded-for' => '127.0.0.1']);
    $denied(new InstallAccessProbe($app, $request), 403);
    $request = new Request();
    $request->withHeader(['x-install-token' => $token]);
    (new InstallAccessProbe($app, $request))->access();
    $assert(true, 'Header token accepted');
    $request = new Request();
    $request->withPost(['install_token' => $token]);
    (new InstallAccessProbe($app, $request))->access();
    $assert(true, 'POST token accepted');
    $request = new Request();
    $request->withGet(['install_token' => $token]);
    $denied(new InstallAccessProbe($app, $request), 403);
    unlink($root . '/install.token');
    mkdir($root . '/install.token', 0700);
    $denied(new InstallAccessProbe($app, new Request()), 503);
    rmdir($root . '/install.token');
    echo "Installation access: {$count} assertions passed" . PHP_EOL;
} finally {
    if (is_file($root . '/install.token')) { unlink($root . '/install.token'); }
    if (is_dir($root . '/install.token')) { rmdir($root . '/install.token'); }
    rmdir($root);
    putenv($previous === false ? 'HW_INSTALL_TOKEN' : 'HW_INSTALL_TOKEN=' . $previous);
}
