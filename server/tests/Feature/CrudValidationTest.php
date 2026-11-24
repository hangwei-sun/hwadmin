<?php
declare(strict_types=1);

namespace tests\Feature;

use core\base\AdminController;
use core\base\UnixTimeModel;
use core\crud\CrudTrait;
use core\http\Result;
use tests\Fixtures\CrudDummyValidate;
use tests\TestCase;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Db;
use think\Request;

final class CrudValidationTest extends TestCase
{
    private string $table = 'phpunit_crud_items';

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute("CREATE TABLE IF NOT EXISTS hw_{$this->table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(50) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            create_time INTEGER NOT NULL DEFAULT 0,
            update_time INTEGER NOT NULL DEFAULT 0
        )");
    }

    protected function tearDown(): void
    {
        Db::execute("DROP TABLE IF EXISTS hw_{$this->table}");
        parent::tearDown();
    }

    public function testCrudTraitThrowsValidateExceptionWhenValidateClassFails(): void
    {
        $validateFqn = CrudDummyValidate::class;

        $model = new class extends UnixTimeModel {
            protected $name = 'phpunit_crud_items';
            protected $autoWriteTimestamp = 'int';
        };

        $makeController = function (string $method, array $post, array $query = []) use ($validateFqn, $model): AdminController {
            $app = $this->app;
            $req = $app->make(Request::class, [], true);
            $req->setMethod($method);
            $req->setAction($method === 'POST' && isset($query['id']) ? 'edit' : 'add');
            $req->withGet($query);
            $req->withPost($post);
            $req->withHeader([
                'authorization' => 'Bearer ' . $this->adminToken,
                'x-token'       => $this->adminToken,
            ]);
            $app->instance('request', $req);

            return new class($app, $validateFqn, $model) extends AdminController {
                use CrudTrait;

                protected array $noNeedRight = ['*'];
                protected array $allowFields = ['title', 'email', 'status'];

                public function __construct($app, string $vClass, $targetModel)
                {
                    $this->validateClass = $vClass;
                    $this->model = $targetModel;
                    parent::__construct($app);
                }
            };
        };

        // 1. 非法邮箱与空标题在 add() 阶段必须抛出 ValidateException（不可静默落库）
        $ctrlInvalidAdd = $makeController('POST', ['title' => '', 'email' => 'not-an-email']);
        $threw = false;
        try {
            $ctrlInvalidAdd->add();
        } catch (ValidateException $e) {
            $threw = true;
            $this->assertNotEmpty($e->getError());
        }
        $this->assertTrue($threw, 'CrudTrait::add() 校验失败必须自动抛出 ValidateException');
        $this->assertSame(0, (int) Db::name($this->table)->count());

        // 2. 合法数据写入成功
        $ctrlValidAdd = $makeController('POST', ['title' => 'Hello', 'email' => 'ok@example.com', 'status' => 1]);
        $createdId = 0;
        try {
            $ctrlValidAdd->add();
        } catch (HttpResponseException $e) {
            $payload = json_decode((string) $e->getResponse()->getContent(), true);
            $this->assertSame(Result::SUCCESS, $payload['code']);
            $createdId = (int) ($payload['data']['id'] ?? 0);
        }
        $this->assertGreaterThan(0, $createdId);

        // 3. 非法数据在 edit() 阶段也必须抛出 ValidateException
        $ctrlInvalidEdit = $makeController('POST', ['title' => 'Updated', 'email' => 'bad-email'], ['id' => $createdId]);
        $editThrew = false;
        try {
            $ctrlInvalidEdit->edit();
        } catch (ValidateException $e) {
            $editThrew = true;
        }
        $this->assertTrue($editThrew, 'CrudTrait::edit() 校验失败必须自动抛出 ValidateException');

        $row = Db::name($this->table)->where('id', $createdId)->find();
        $this->assertSame('Hello', $row['title'], '校验失败后原数据不应被覆盖');
    }
}
