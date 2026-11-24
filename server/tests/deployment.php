<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\common\service\DeploymentService;
use core\exception\ApiException;
use think\App;

$root = sys_get_temp_dir() . '/hwadmin-deployment-' . bin2hex(random_bytes(8));
mkdir($root . '/public/uploads', 0755, true);
mkdir($root . '/public/plugins', 0755, true);
copy(dirname(__DIR__) . '/public/nginx.htaccess', $root . '/public/nginx.htaccess');
$service = new DeploymentService(new App($root));
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) { throw new RuntimeException($message); }
    $count++;
};
$reject = static function (callable $action) use ($assert): void {
    try { $action(); } catch (ApiException $e) { $assert(true, 'Rejected'); return; }
    throw new RuntimeException('Unsafe value accepted');
};
try {
    $config = $service->configuration();
    $assert(!isset($config['php']), 'Legacy dual-block php snippet should be removed');
    $assert(str_contains($config['rewrite'], '$hw_block_php'), 'Missing server-level PHP block in rewrite');
    $assert(str_contains($config['rewrite'], 'location ^~ /uploads/'), 'Missing upload prefix protection');
    $assert(str_contains($config['rewrite'], 'location = /nginx.htaccess'), 'Missing nginx.htaccess direct block');
    $assert(str_contains($config['command'] ?? '', 'nginx'), 'Missing terminal one-liner command');
    foreach ([
        ['https://evil.com:8888', 'valid_api_key_123456'],
        ['http://169.254.169.254:8888', 'valid_api_key_123456'],
        ['ftp://127.0.0.1:8888', 'valid_api_key_123456'],
        ['http://127.0.0.1:8888', 'short'],
    ] as [$badUrl, $badKey]) {
        $reject(fn () => $service->applyBaotaRewrite($badUrl, $badKey, static fn () => []));
    }
    $calls = [];
    $applyRes = $service->applyBaotaRewrite(
        'http://127.0.0.1:8888',
        'valid_baota_api_key_1234567890',
        static function (string $url, array $post) use (&$calls, $root): array {
            $calls[] = [$url, $post];
            if (str_contains($url, 'action=getData')) {
                return ['data' => [['id' => 7, 'name' => 'demo.example.com', 'path' => str_replace('\\', '/', $root)]]];
            }
            if (str_contains($url, 'action=GetFileBody')) {
                return ['status' => true, 'data' => ''];
            }
            return ['status' => true, 'msg' => 'ok'];
        }
    );
    $assert($applyRes['site'] === 'demo.example.com', 'Baota site match failed');
    $assert($applyRes['run_path_updated'] === true, 'Baota runPath update failed');
    $assert(count($calls) >= 4, 'Missing expected Baota API calls');
    $assert(!$service->acceptsGrant(''), 'Empty grant accepted');
    $token = $service->issueGrant();
    $assert($service->acceptsGrant($token), 'Issued grant rejected');
    $assert(!$service->acceptsGrant(str_repeat('0', 64)), 'Wrong grant accepted');
    $assert(!str_contains(file_get_contents($root . '/runtime/deployment/grant.json'), $token), 'Plaintext grant stored');
    $assert((fileperms($root . '/runtime/deployment/grant.json') & 0777) === 0600, 'Grant permissions');
    $grant = json_decode(file_get_contents($root . '/runtime/deployment/grant.json'), true);
    $grant['expires'] = time() - 1;
    file_put_contents($root . '/runtime/deployment/grant.json', json_encode($grant));
    $assert(!$service->acceptsGrant($token), 'Expired grant accepted');
    $task = $service->prepare();
    foreach ($task['urls'] as $key => $url) {
        $contents = file_get_contents($root . '/public' . $url);
        $assert(str_contains($contents, $task['marker']), 'Fixture missing marker');
        $assert(!str_contains($contents, '$_'), 'Fixture can read request inputs');
    }
    $reject(fn () => $service->cleanup('../../outside'));
    $assert(is_file($root . '/public' . $task['urls']['rootPhp']), 'Invalid cleanup removed task');
    $service->cleanup($task['id']);
    foreach ($task['urls'] as $url) { $assert(!file_exists($root . '/public' . $url), 'Fixture leaked'); }
    $service->cleanup($task['id']); // idempotent after retries
    $expired = $service->prepare();
    file_put_contents($root . '/runtime/deployment/probe-' . $expired['id'] . '.json', '{"expires":1}');
    $next = $service->prepare();
    $assert(!file_exists($root . '/public' . $expired['urls']['rootPhp']), 'Expired probe not removed');
    $service->cleanup($next['id']);
    echo "Deployment safety: {$count} assertions passed" . PHP_EOL;
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
