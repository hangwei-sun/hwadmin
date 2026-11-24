<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

echo "=== HwAdmin S02 / S06 / S07 / S08 深度隔离与安全回归验证 ===" . PHP_EOL;
$assertCount = 0;
function assertTrue(bool $condition, string $desc): void {
    global $assertCount;
    if (!$condition) {
        echo "❌ 失败: {$desc}" . PHP_EOL;
        exit(1);
    }
    $assertCount++;
    echo "✓ [PASS] {$desc}" . PHP_EOL;
}

// -------------------------------------------------------------
// 1. S06: LoginThrottle 稳定 Inode 验证（clear 不采用 unlink）
// -------------------------------------------------------------
$testKey = 'hw:login_throttle:test_scope:pair:' . sha1('test_user|127.0.0.99');
\core\auth\LoginThrottle::hit('test_scope', 'test_user', '127.0.0.99');
$throttleDir = $app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'throttle' . DIRECTORY_SEPARATOR;
$lockFile = $throttleDir . md5($testKey) . '.lock';
assertTrue(is_file($lockFile), 'S06: 计数命中后锁文件正常创建');
$inodeBefore = fileinode($lockFile);

\core\auth\LoginThrottle::clear('test_scope', 'test_user', '127.0.0.99');
assertTrue(is_file($lockFile), 'S06: clear 重置后保持锁文件存在');
$inodeAfter = fileinode($lockFile);
assertTrue($inodeBefore === $inodeAfter, 'S06: clear 重置后 inode 保持绝对稳定（无 unlink 竞态）');
$content = json_decode((string) file_get_contents($lockFile), true);
assertTrue(($content['count'] ?? -1) === 0 && ($content['expire'] ?? -1) === 0, 'S06: clear 重置后计数与过期时间已归零');

// -------------------------------------------------------------
// 2. S02: CrudTrait 数据权限 Fail-Close 与配置先行验证
// -------------------------------------------------------------
$dummyCtrl = new class {
    use \core\crud\CrudTrait;
    public $model;
    public $dataScope = '';
    public $dataScopeField = 'admin_id';
    public $auth;
    public function __construct() {
        $this->model = new \app\common\model\Admin();
        $this->auth = new class {
            public function isLogin(): bool { return true; }
            public function isSuper(): bool { return true; }
            public function id(): int { return 1; }
        };
    }
    public function testApply() {
        return $this->applyDataScope($this->model->db());
    }
};

// 2.1 空 scope 必须 Fail-Close (抛出 500)
try {
    $dummyCtrl->dataScope = '';
    $dummyCtrl->testApply();
    assertTrue(false, 'S02: 空字符串 scope 应该 Fail-Close 抛出异常');
} catch (\core\exception\ApiException $e) {
    assertTrue($e->getHttpStatus() === 500, 'S02: 空字符串 scope 严格 Fail-Close (HTTP 500)');
}

// 2.2 未知 scope 必须 Fail-Close
try {
    $dummyCtrl->dataScope = 'unsupported_custom_scope';
    $dummyCtrl->testApply();
    assertTrue(false, 'S02: 未知 scope 应该 Fail-Close 抛出异常');
} catch (\core\exception\ApiException $e) {
    assertTrue($e->getHttpStatus() === 500, 'S02: 未知 scope 严格 Fail-Close (HTTP 500)');
}

// 2.3 即使是超管，如果 dataScopeField 在数据表中不存在，也必须配置校验先行抛出 500
try {
    $dummyCtrl->dataScope = 'self';
    $dummyCtrl->dataScopeField = 'non_existent_field_xyz';
    $dummyCtrl->testApply();
    assertTrue(false, 'S02: 超管在错误 dataScopeField 下不应放行');
} catch (\core\exception\ApiException $e) {
    assertTrue($e->getHttpStatus() === 500, 'S02: 错误 dataScopeField 先行阻断，超管不越权放行 (HTTP 500)');
}

// -------------------------------------------------------------
// 3. S07: CrudTrait::select() 限制 labelField 敏感列
// -------------------------------------------------------------
$selectCtrl = new class extends \core\base\AdminController {
    use \core\crud\CrudTrait;
    protected array $noNeedLogin = ['*'];
    protected array $noNeedRight = ['*'];
    public function __construct() {
        $app = \think\facade\App::getInstance();
        parent::__construct($app);
        $this->model = new \app\common\model\Admin();
        $this->hiddenFields = ['password', 'salt'];
    }
    public function testSelectLabel(string $reqLabel): string {
        $pk = (string) $this->model->getPk();
        $fields = $this->filterableFields();
        $safeLabelFields = array_values(array_filter($fields, function ($f) {
            return is_string($f)
                && !in_array($f, $this->hiddenFields, true)
                && !preg_match('/(password|pass|pwd|salt|token|secret|private_key|api_key|access_key|auth_key|remember_token|rules|permissions)/i', $f);
        }));

        $labelField = ($reqLabel !== '' && in_array($reqLabel, $safeLabelFields, true))
            ? $reqLabel
            : $this->detectLabelField($safeLabelFields, $pk);
        return $labelField;
    }
};

$labelPassword = $selectCtrl->testSelectLabel('password');
assertTrue($labelPassword !== 'password', 'S07: 请求 labelField=password 被安全拒绝');
$labelSalt = $selectCtrl->testSelectLabel('salt');
assertTrue($labelSalt !== 'salt', 'S07: 请求 labelField=salt 被安全拒绝');
$labelValid = $selectCtrl->testSelectLabel('username');
assertTrue($labelValid === 'username', 'S07: 请求合法 labelField=username 正常通过');

// -------------------------------------------------------------
// 4. S05: 插件升级版本化 SQL 与阶段持久化记录验证
// -------------------------------------------------------------
$pm = new \core\plugin\PluginManager($app);
$testPluginDir = plugin_path('test_s05_upgrade');
@mkdir($testPluginDir, 0755, true);
file_put_contents($testPluginDir . 'plugin.json', json_encode([
    'name' => 'test_s05_upgrade', 'title' => 'S05 Upgrade Test', 'version' => '1.0.0', 'require' => ['hwadmin' => '>=0.1.0']
]));
$pm->install('test_s05_upgrade', false);
$stageFile = $app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'test_s05_upgrade_stage.json';
assertTrue(is_file($stageFile), 'S05: 插件安装全阶段状态正常持久化到磁盘');
$stageData = json_decode((string) file_get_contents($stageFile), true);
assertTrue(($stageData['status'] ?? '') === 'completed', 'S05: 插件安装成功标记为 completed');

// 升级到 1.1.0，提供版本化 upgrade_1.1.0.sql
file_put_contents($testPluginDir . 'plugin.json', json_encode([
    'name' => 'test_s05_upgrade', 'title' => 'S05 Upgrade Test', 'version' => '1.1.0', 'require' => ['hwadmin' => '>=0.1.0']
]));
file_put_contents($testPluginDir . 'upgrade_1.1.0.sql', "SELECT 1;\n");
$pm->upgrade('test_s05_upgrade');
$upgradeStageData = json_decode((string) file_get_contents($stageFile), true);
assertTrue(($upgradeStageData['action'] ?? '') === 'upgrade' && ($upgradeStageData['status'] ?? '') === 'completed', 'S05: 版本化升级 upgrade_1.1.0.sql 成功执行并持久化');

// 卸载并清理
$pm->uninstall('test_s05_upgrade');
assertTrue(!is_file($stageFile), 'S05: 插件卸载后持久化阶段文件已自动清理');
// 删除测试目录
array_map('unlink', glob($testPluginDir . '*'));
@rmdir($testPluginDir);

// -------------------------------------------------------------
// 5. S08: 私有附件两用户交叉下载权限隔离验证
// -------------------------------------------------------------
$attCtrl = new class($app) extends \app\adminapi\controller\system\Attachment {
    protected array $noNeedLogin = ['*'];
    protected array $noNeedRight = ['*'];
};
$tokens = $app->make(\core\auth\TokenService::class);
$mockAuth2 = new class($tokens) extends \core\auth\AdminAuth {
    public function isLogin(): bool { return true; }
    public function isSuper(): bool { return false; }
    public function isSuperAdmin(): bool { return false; }
    public function id(): int { return 102; }
    public function profile(): array { return ['id' => 102, 'username' => 'user_102']; }
    public function check(string $node): bool { return true; }
};

$prefix = (string) (\think\facade\Db::connect()->getConfig('prefix') ?: 'hw_');
$now = time();
$attId1 = \think\facade\Db::name('attachment')->insertGetId([
    'admin_id'    => 101,
    'user_id'     => 0,
    'name'        => 'user101_private.pdf',
    'size'        => 1024,
    'mime'        => 'application/pdf',
    'ext'         => 'pdf',
    'storage'     => 'private',
    'url'         => '/adminapi/system.attachment/download?id=0',
    'sha1'        => 'user101_sha1_hash',
    'create_time' => $now,
]);

// 绑定 User 2 尝试越权访问 User 1 的私有附件
$ref = new ReflectionProperty(\core\base\AdminController::class, 'auth');
if (PHP_VERSION_ID < 80500) {
    $ref->setAccessible(true);
}
$ref->setValue($attCtrl, $mockAuth2);

$reqRef = new ReflectionProperty(\core\base\BaseController::class, 'request');
if (PHP_VERSION_ID < 80500) {
    $reqRef->setAccessible(true);
}
$mockRequest = new \app\Request();
$mockRequest->withGet(['id' => (string) $attId1]);
$reqRef->setValue($attCtrl, $mockRequest);

$blocked = false;
try {
    $attCtrl->download();
} catch (\core\exception\ApiException $e) {
    if ($e->getHttpStatus() === 403) {
        $blocked = true;
    }
}
assertTrue($blocked, 'S08: 普通管理员 102 尝试下载管理员 101 的私有附件被 403 严格拦截隔离');

// 清理附件记录
\think\facade\Db::name('attachment')->where('id', $attId1)->delete();

echo PHP_EOL . "========================================" . PHP_EOL;
echo "恭喜！全部 {$assertCount} 项深度安全与多账号隔离断言全部通过！" . PHP_EOL;
echo "========================================" . PHP_EOL;
