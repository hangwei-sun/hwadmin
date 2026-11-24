<?php
declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\common\model\AuthGroup as AuthGroupModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use core\http\Result;
use core\support\Tree;
use think\facade\Db;
use think\Model;

/**
 * 角色组管理
 *
 * 节点：auth/group/{index|read|add|edit|del}
 * 安全基线（2-P0-1）：
 *   1. 非超管仅能查看含自身组在内的角色树，仅能新增/编辑/删除自身所属组的下级子孙角色组；
 *   2. 非超管严禁将 rules 设为 '*'，且所分配的规则 ID 必须完全处于操作者自身拥有的权限规则子集内；
 *   3. 超级管理员组 (id=1) 严禁禁用、缩权或删除。
 */
class Group extends AdminController
{
    use CrudTrait {
        index as protected crudIndex;
        del as protected crudDel;
        buildIndexQuery as protected crudBuildIndexQuery;
    }

    protected array $searchFields = ['name'];
    protected array $filterFields = ['id', 'pid', 'name', 'status', 'create_time'];
    protected array $sortableFields = ['id', 'pid', 'create_time'];
    protected array $allowFields = ['pid', 'name', 'rules', 'status'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new AuthGroupModel();
    }

    protected function buildIndexQuery()
    {
        $query = $this->crudBuildIndexQuery();
        if (!$this->auth->isSuperAdmin()) {
            // 列表与详情可读范围：自身组 + 下级子孙组
            $visibleIds = $this->auth->childrenGroupIds(true);
            $query->whereIn('id', $visibleIds ?: [0]);
        }
        return $query;
    }

    public function index(): void
    {
        $tree = (int) $this->request->param('tree', 1);
        if ($tree !== 1) {
            $this->crudIndex();
            return;
        }

        $rows = $this->buildIndexQuery()->order('id', 'asc')->select()->toArray();
        $rootPid = 0;
        if (!$this->auth->isSuperAdmin() && $rows) {
            $idSet = array_column($rows, 'id', 'id');
            foreach ($rows as &$r) {
                if (!isset($idSet[$r['pid']])) {
                    $r['pid'] = 0;
                }
            }
            unset($r);
        }
        $treeList = Tree::build($rows, $rootPid);
        $this->page($treeList, count($rows));
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $this->validate($data, [
            'name|组名'   => ($row ? '' : 'require|') . 'max:100',
            'status|状态' => 'in:0,1',
        ]);

        $isSuper = $this->auth->isSuperAdmin();

        // 1. 编辑时：非超管只能编辑自己的严格下级子孙组（不能编辑自己直接所属的组或上级组）
        if ($row) {
            $targetId = (int) $row->id;
            if ($targetId === 1) {
                if (isset($data['status']) && (int) $data['status'] !== 1) {
                    throw new ApiException('超级管理员组不可禁用或缩减权限');
                }
                if (isset($data['rules']) && (string) $data['rules'] !== '*' && $data['rules'] !== ['*']) {
                    throw new ApiException('超级管理员组不可禁用或缩减权限');
                }
            }
            if (!$isSuper) {
                $manageableIds = $this->auth->childrenGroupIds(false);
                if (!in_array($targetId, $manageableIds, true)) {
                    $this->error('越权拦截：仅允许修改您所属角色组的下级子组', Result::FORBIDDEN, null, 403);
                }
            }
        }

        // 2. 校验父级组 pid：非超管新建或移动角色组时，pid 必须属于自己所在组或下级子组
        if (!$isSuper || isset($data['pid'])) {
            $pid = (int) ($data['pid'] ?? ($row ? $row->pid : 0));
            if (!$isSuper) {
                $allowedPids = $this->auth->childrenGroupIds(true);
                if ($pid <= 0 || !in_array($pid, $allowedPids, true)) {
                    $this->error('越权拦截：父级角色组必须为您所属的角色组或其下级组', Result::FORBIDDEN, null, 403);
                }
            }
            if ($row && $pid === (int) $row->id) {
                throw new ApiException('父级角色组不能设为自己');
            }
        }

        // 3. 校验并归一化 rules：非超管严禁写入 '*'，且所有规则 ID 必须是当前操作者权限的子集
        if (isset($data['rules'])) {
            if (is_array($data['rules'])) {
                $data['rules'] = implode(',', array_filter($data['rules'], static fn ($v) => $v !== ''));
            }
            $rulesStr = trim((string) $data['rules']);
            if ($rulesStr === '*' || str_contains($rulesStr, '*')) {
                if (!$isSuper || !$row || (int) $row->id !== 1) {
                    $this->error('越权拦截：禁止将普通角色组的权限规则设为通配符 *', Result::FORBIDDEN, null, 403);
                }
                $data['rules'] = '*';
            } elseif ($rulesStr !== '') {
                $reqRuleIds = array_values(array_unique(array_filter(
                    array_map('intval', explode(',', $rulesStr)),
                    static fn ($id) => $id > 0
                )));
                if (!$isSuper) {
                    $myRuleIds = $this->auth->ruleIds();
                    $exceed = array_diff($reqRuleIds, $myRuleIds);
                    if ($exceed) {
                        $this->error('越权拦截：分配的权限规则超出您自身拥有的权限范围', Result::FORBIDDEN, null, 403);
                    }
                }
                $data['rules'] = implode(',', $reqRuleIds);
            } else {
                $data['rules'] = '';
            }
        }

        return $data;
    }

    public function multi(): void
    {
        $this->ensurePost();
        $rawIds = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if (!$ids) {
            throw new ApiException('请选择要操作的角色组');
        }

        $field = trim((string) $this->request->post('field', 'status'));
        $value = $this->request->post('value');
        if ($field !== 'status') {
            throw new ApiException('不允许批量更新字段：' . $field);
        }
        if (is_bool($value) || !in_array($value, [0, 1, '0', '1'], true)) {
            throw new ApiException('状态值不合法，仅支持 0 或 1');
        }
        $statusVal = (int) $value;

        if (in_array(1, $ids, true)) {
            throw new ApiException('超级管理员组不可批量修改状态');
        }
        if (array_intersect($ids, $this->auth->groupIds())) {
            $this->error('越权拦截：不能修改自身所属角色组状态', Result::FORBIDDEN, null, 403);
        }
        if (!$this->auth->isSuperAdmin()) {
            $manageableIds = $this->auth->childrenGroupIds(false);
            if (array_diff($ids, $manageableIds)) {
                $this->error('越权拦截：只能操作下级角色组', Result::FORBIDDEN, null, 403);
            }
        }

        $count = 0;
        $now = time();
        $this->model->transaction(function () use ($ids, $statusVal, $now, &$count) {
            foreach ($this->buildIndexQuery()->whereIn('id', $ids)->select() as $row) {
                $row->save(['status' => $statusVal, 'update_time' => $now]);
                $count++;
            }
        });

        $this->success(['count' => $count], '批量更新成功');
    }

    public function del(): void
    {
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (in_array(1, $ids, true)) {
            throw new ApiException('超级管理员组不可删除');
        }

        if (!$this->auth->isSuperAdmin()) {
            $manageableIds = $this->auth->childrenGroupIds(false);
            if (array_diff($ids, $manageableIds)) {
                $this->error('越权拦截：不能删除非下级角色组', Result::FORBIDDEN, null, 403);
            }
        }

        $this->crudDel();
    }

    protected function afterDelete(array $ids): void
    {
        Db::name('auth_group_access')->whereIn('group_id', $ids)->delete();
    }
}
