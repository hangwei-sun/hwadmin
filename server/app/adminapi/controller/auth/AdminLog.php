<?php
declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\common\model\AdminLog as AdminLogModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;

/**
 * 操作日志管理
 *
 * 节点：auth/admin_log/{index|read|del}
 */
class AdminLog extends AdminController
{
    use CrudTrait;

    protected bool $recordLog = false;

    protected array $searchFields = ['username', 'url', 'title', 'ip'];
    protected array $filterFields = ['id', 'admin_id', 'username', 'ip', 'create_time'];
    protected array $sortableFields = ['id', 'create_time'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new AdminLogModel();
    }

    public function add(): void
    {
        throw new ApiException('操作日志不支持新增或修改', 0, null, 405);
    }

    public function edit(): void
    {
        throw new ApiException('操作日志不支持新增或修改', 0, null, 405);
    }
}
