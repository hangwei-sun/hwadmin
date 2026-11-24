<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\common\model\system\Crontab as CrontabModel;
use app\common\model\system\CrontabLog as CrontabLogModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use core\http\Result;
use core\support\CrontabExecutor;
use think\facade\Db;
use think\Model;

/**
 * 可视化定时任务控制器
 *
 * 节点：system/crontab/{index|read|add|edit|del|run}
 */
class Crontab extends AdminController
{
    use CrudTrait {
        add as protected crudAdd;
        edit as protected crudEdit;
        del as protected crudDel;
        multi as protected crudMulti;
    }

    protected array $searchFields = ['title', 'target', 'remark'];
    protected array $filterFields = ['id', 'title', 'type', 'status', 'last_run_status', 'create_time'];
    protected array $sortableFields = ['id', 'sort', 'last_run_time', 'create_time'];
    protected array $allowFields = ['title', 'type', 'target', 'cron', 'status', 'remark', 'sort'];
    protected string|array $defaultSort = ['sort' => 'asc', 'id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new CrontabModel();
    }

    protected function requireSuperAdmin(): void
    {
        if (!$this->auth->isSuperAdmin()) {
            $this->error('仅超级管理员可管理或执行定时任务', Result::FORBIDDEN, null, 403);
        }
    }

    public function add(): void
    {
        $this->requireSuperAdmin();
        $this->crudAdd();
    }

    public function edit(): void
    {
        $this->requireSuperAdmin();
        $this->crudEdit();
    }

    public function multi(): void
    {
        $this->requireSuperAdmin();
        $this->crudMulti();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $type = (string) ($data['type'] ?? ($row ? $row->type : ''));
        $target = (string) ($data['target'] ?? ($row ? $row->target : ''));
        try {
            CrontabExecutor::assertAllowedTask($type, $target);
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage());
        }
        return $data;
    }

    /**
     * 删除任务时级联删除对应的历史日志
     */
    public function del(): void
    {
        $this->requireSuperAdmin();
        $this->ensurePost();
        $ids = $this->request->post('ids', $this->request->post('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (empty($ids)) {
            throw new ApiException('请选择要删除的任务');
        }

        Db::transaction(function () use ($ids) {
            CrontabLogModel::whereIn('crontab_id', $ids)->delete();
            CrontabModel::whereIn('id', $ids)->delete();
        });

        $this->success(null, '删除成功');
    }

    /**
     * 立即执行一次定时任务
     * POST /adminapi/system.crontab/runOnce
     */
    public function runOnce(): void
    {
        $this->requireSuperAdmin();
        $this->ensurePost();
        $id = (int) ($this->request->post('id') ?? $this->request->get('id', 0));
        if ($id <= 0) {
            throw new ApiException('缺少任务ID');
        }

        $task = CrontabModel::find($id);
        if (!$task) {
            throw new ApiException('定时任务不存在');
        }

        $result = CrontabExecutor::executeTask($task);
        $this->success($result, $result['status'] === 1 ? '任务执行成功' : '任务执行失败');
    }

    /**
     * 查询执行日志列表
     * GET /adminapi/system.crontab/logs?crontab_id=1&page=1&limit=20
     */
    public function logs(): void
    {
        $crontabId = (int) $this->request->get('crontab_id', 0);
        $page = max(1, (int) $this->request->get('page', 1));
        $limit = max(1, min(100, (int) $this->request->get('limit', 20)));

        $query = CrontabLogModel::order('id', 'desc');
        if ($crontabId > 0) {
            $query->where('crontab_id', $crontabId);
        }

        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->select();

        $this->page($list, $total);
    }

    /**
     * 清理执行日志
     * POST /adminapi/system.crontab/clearLogs
     */
    public function clearLogs(): void
    {
        $this->requireSuperAdmin();
        $this->ensurePost();
        $crontabId = (int) $this->request->post('crontab_id', 0);
        $days = (int) $this->request->post('days', 0);

        $query = CrontabLogModel::where('id', '>', 0);
        if ($crontabId > 0) {
            $query->where('crontab_id', $crontabId);
        }
        if ($days > 0) {
            $expireTime = time() - ($days * 86400);
            $query->where('create_time', '<=', $expireTime);
        }

        $deleted = $query->delete();
        $this->success(['deleted' => $deleted], '日志清理成功');
    }
}
