<?php
declare(strict_types=1);

namespace app\adminapi\controller\enterprise;

use app\common\model\EnterpriseTask as TargetModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use think\Model;

/**
 * 企业任务管理（从表控制器，遵循 created_by 数据归属隔离）
 */
class Task extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['title', 'assignee'];
    protected array $allowFields = ['project_id', 'title', 'assignee', 'deadline', 'status'];
    protected string $dataScope = 'self';
    protected string $dataScopeField = 'created_by';
    protected string|array $defaultSort = ['id' => 'asc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new TargetModel();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        if ($row === null && empty($data['created_by'])) {
            $data['created_by'] = (int) $this->auth->id();
        }
        return $data;
    }
}
