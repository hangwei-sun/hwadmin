<?php
declare(strict_types=1);

namespace tests\Unit;

use core\console\CrudCommand;
use core\support\CrudScaffoldBuilder;
use tests\TestCase;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class V1515EfficiencyAndScaffoldTest extends TestCase
{
    public function testScaffoldEmitsTypeCastsTimestampAllowFieldsHookHintsAndMobileFiles(): void
    {
        $sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hwadmin-v1515-' . bin2hex(random_bytes(4));
        $serverRoot = $sandbox . DIRECTORY_SEPARATOR . 'server';
        @mkdir($serverRoot . '/app/common/model', 0755, true);
        @mkdir($serverRoot . '/app/common/validate', 0755, true);
        @mkdir($serverRoot . '/app/adminapi/controller', 0755, true);
        @mkdir($serverRoot . '/app/api/controller', 0755, true);
        @mkdir($serverRoot . '/app/index/controller', 0755, true);
        @mkdir($serverRoot . '/app/index/route', 0755, true);
        @mkdir($serverRoot . '/database/migrations', 0755, true);
        @mkdir($sandbox . '/admin-ui/src/api', 0755, true);
        @mkdir($sandbox . '/admin-ui/src/views', 0755, true);
        @mkdir($sandbox . '/admin-ui/mobile/src/api', 0755, true);
        @mkdir($sandbox . '/admin-ui/mobile/src/pages', 0755, true);

        try {
            $cmd = new CrudCommand();
            $cmd->setApp($this->app);
            $cmd->setCustomRootPath($serverRoot);

            $input = new Input([
                '--table=quick_feedback',
                '--fields=nickname:string(64):昵称,score:int=5:评分,price:decimal(8,2):费用,content:text:内容,status:int=1:状态',
                '--title=反馈单',
                '--with-ui',
                '--with-api',
                '--with-mobile',
                '--json',
            ]);
            $buf = new Output('buffer');
            $rc = $cmd->run($input, $buf);
            $raw = $buf->fetch();
            $this->assertSame(0, $rc, $raw);

            $result = json_decode($raw, true);
            $this->assertIsArray($result, $raw);
            $this->assertSame('success', $result['status'] ?? null);

            $modelCode = (string) file_get_contents($serverRoot . '/app/common/model/QuickFeedback.php');
            $this->assertStringContainsString('protected $type =', $modelCode);
            $this->assertStringContainsString("'id' => 'integer'", $modelCode);
            $this->assertStringContainsString("'score' => 'integer'", $modelCode);
            $this->assertStringContainsString("'price' => 'float'", $modelCode);
            $this->assertStringContainsString("'status' => 'integer'", $modelCode);
            $this->assertStringContainsString("'create_time' => 'integer'", $modelCode);
            $this->assertStringContainsString("'update_time' => 'integer'", $modelCode);

            $adminCtrlCode = (string) file_get_contents($serverRoot . '/app/adminapi/controller/QuickFeedback.php');
            $this->assertStringContainsString('beforeWrite(array $data, ?\think\Model $row = null): array', $adminCtrlCode);
            $this->assertStringContainsString('afterWrite(\think\Model $row, array $data, bool $isAdd): void', $adminCtrlCode);

            $apiCtrlCode = (string) file_get_contents($serverRoot . '/app/api/controller/QuickFeedback.php');
            $this->assertStringContainsString("'create_time', 'update_time'", $apiCtrlCode);
            $this->assertStringContainsString('$model->allowField($allowWriteFields)->save($payload);', $apiCtrlCode);

            $pcViewCode = (string) file_get_contents($sandbox . '/admin-ui/src/views/quick_feedback/index.vue');
            $this->assertStringContainsString('if (!num || num <= 0) return \'-\'', $pcViewCode);

            $mobileApiPath = $sandbox . '/admin-ui/mobile/src/api/quick_feedback.ts';
            $mobilePagePath = $sandbox . '/admin-ui/mobile/src/pages/quick_feedback/index.vue';
            $this->assertFileExists($mobileApiPath);
            $this->assertFileExists($mobilePagePath);

            $mobileApiCode = (string) file_get_contents($mobileApiPath);
            $this->assertStringContainsString("path: '/quick_feedback/index'", $mobileApiCode);
            $this->assertStringContainsString("path: '/quick_feedback/submit'", $mobileApiCode);
            $this->assertStringNotContainsString("path: '/api/", $mobileApiCode);

            $mobilePageCode = (string) file_get_contents($mobilePagePath);
            $this->assertStringContainsString("definePage({ layout: 'default', style: { navigationBarTitleText: '反馈单' } })", $mobilePageCode);
            $this->assertStringContainsString(':maxlength="64"', $mobilePageCode);
            $this->assertStringContainsString("if (!n || n <= 0) return '-'", $mobilePageCode);
        } finally {
            $this->deleteTree($sandbox);
        }
    }

    public function testUnixTimeModelWithTypeCastsAndAllowFieldPersistsIntegerTimestampsOnSqlite(): void
    {
        $table = 'tmp_ts_cast_' . bin2hex(random_bytes(3));
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        $fullTable = $prefix . $table;
        Db::execute("CREATE TABLE IF NOT EXISTS \"{$fullTable}\" (
            \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
            \"nickname\" VARCHAR(64) NOT NULL DEFAULT '',
            \"status\" INTEGER NOT NULL DEFAULT 1,
            \"create_time\" INTEGER NOT NULL DEFAULT 0,
            \"update_time\" INTEGER NOT NULL DEFAULT 0
        )");

        try {
            $className = 'TmpTsCastModel_' . bin2hex(random_bytes(4));
            eval(<<<PHP
class {$className} extends \\core\\base\\UnixTimeModel
{
    protected \$name = '{$table}';
    protected \$autoWriteTimestamp = 'int';
    protected \$createTime = 'create_time';
    protected \$updateTime = 'update_time';
    protected \$type = [
        'id'          => 'integer',
        'status'      => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}
PHP
            );

            /** @var \core\base\UnixTimeModel $model */
            $model = new $className();

            $payload = ['nickname' => 'Alice', 'status' => 1];
            $allowWriteFields = array_values(array_unique(array_merge(
                array_keys($payload),
                ['create_time', 'update_time'],
                array_values(array_filter($model->getAutoTimeFields(), static fn ($f) => is_string($f) && $f !== ''))
            )));
            $model->allowField($allowWriteFields)->save($payload);

            $savedId = (int) $model->getAttr('id');
            $this->assertGreaterThan(0, $savedId);

            /** @var \core\base\UnixTimeModel $reader */
            $reader = new $className();
            $found = $reader->where('id', $savedId)->find();
            $this->assertNotNull($found);
            $rowArr = $found->toArray();
            $this->assertIsInt($rowArr['id']);
            $this->assertIsInt($rowArr['status']);
            $this->assertIsInt($rowArr['create_time']);
            $this->assertIsInt($rowArr['update_time']);
            $this->assertGreaterThan(0, $rowArr['create_time']);
            $this->assertGreaterThan(0, $rowArr['update_time']);
        } finally {
            Db::execute("DROP TABLE IF EXISTS \"{$fullTable}\"");
        }
    }

    public function testMobileScaffoldBuilderAvoidsHardcodedApiPrefix(): void
    {
        $builder = new CrudScaffoldBuilder();
        $api = $builder->renderMobileApi('Guestbook', 'guestbook', 'id', ['id', 'nickname', 'create_time'], ['nickname'], [
            'id'          => ['type' => 'int', 'comment' => 'ID'],
            'nickname'    => ['type' => 'varchar(64)', 'length' => 64, 'comment' => '昵称'],
            'create_time' => ['type' => 'bigint', 'comment' => '创建时间'],
        ]);

        $this->assertStringContainsString("path: '/guestbook/index'", $api);
        $this->assertStringContainsString("path: '/guestbook/read'", $api);
        $this->assertStringContainsString("path: '/guestbook/submit'", $api);
        $this->assertStringNotContainsString('/api/guestbook', $api);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
