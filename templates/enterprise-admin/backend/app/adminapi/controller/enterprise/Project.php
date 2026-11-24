<?php
declare(strict_types=1);

namespace app\adminapi\controller\enterprise;

use app\common\model\EnterpriseProject as TargetModel;
use app\common\service\EnterpriseProjectService;
use core\base\AdminController;
use core\crud\CrudTrait;
use think\Model;

/**
 * 企业项目管理（主从表协同 + 附件安全校验 + 数据导出 + created_by 归属隔离）
 */
class Project extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['project_no', 'title'];
    protected array $allowFields = ['title', 'priority', 'budget', 'status'];
    protected string $dataScope = 'self';
    protected string $dataScopeField = 'created_by';
    protected string|array $defaultSort = ['id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new TargetModel();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        if ($row === null) {
            $adminId = (int) $this->auth->id();
            $data['created_by'] = $adminId;
            $data['admin_id'] = $adminId;
            if (empty($data['project_no'])) {
                $data['project_no'] = 'PRJ' . date('Ymd') . random_int(1000, 9999);
            }
        }
        if (array_key_exists('budget', $data)) {
            $data['budget'] = EnterpriseProjectService::normalizeBudget($data['budget']);
        }
        return $data;
    }

    /**
     * 主从详情（自动执行 Service 层 created_by 数据归属校验）
     */
    public function detail(): void
    {
        $id = (int) $this->request->get('id', 0);
        $service = new EnterpriseProjectService();
        $isSuper = method_exists($this->auth, 'isSuper') && $this->auth->isSuper();
        $this->success($service->getProjectWithTasks($id, (int) $this->auth->id(), $isSuper));
    }

    /**
     * 主从协同新增
     */
    public function createWithTasks(): void
    {
        $this->ensurePost();
        $projectData = (array) $this->request->post('project', []);
        $tasks = (array) $this->request->post('tasks', []);
        $attachments = (array) $this->request->post('attachments', []);
        $service = new EnterpriseProjectService();
        $res = $service->createProjectWithTasks($projectData, $tasks, (int) $this->auth->id(), $attachments);
        $this->success($res, '主从项目创建成功');
    }

    /**
     * 绑定上传附件（含扩展名白名单与大小校验 + 归属隔离）
     */
    public function addAttachment(): void
    {
        $this->ensurePost();
        $payload = $this->request->post();
        $service = new EnterpriseProjectService();
        $isSuper = method_exists($this->auth, 'isSuper') && $this->auth->isSuper();
        $res = $service->addAttachment($payload, (int) $this->auth->id(), $isSuper);
        $this->success($res, '附件上传并绑定成功');
    }

    /**
     * 数据导出（自动应用 created_by 归属隔离）
     */
    public function export(): void
    {
        $service = new EnterpriseProjectService();
        $isSuper = method_exists($this->auth, 'isSuper') && $this->auth->isSuper();
        $data = $service->exportProjects($this->request->get(), (int) $this->auth->id(), $isSuper);
        $this->success($data, '导出成功');
    }

    /* @hw-custom-start: enterprise-project-controller-extensions */
    /* @hw-custom-end: enterprise-project-controller-extensions */
}
