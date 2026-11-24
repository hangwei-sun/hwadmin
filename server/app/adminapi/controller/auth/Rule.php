<?php
declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\common\model\AuthRule as AuthRuleModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\crud\QueryParams;
use core\exception\ApiException;
use core\http\Result;
use core\support\Tree;
use think\facade\Db;
use think\Model;

/**
 * 菜单与权限规则管理
 *
 * 节点：auth/rule/{index|read|add|edit|del}
 * 安全基线（2-P0-1）：
 *   - 权限规则的写操作（add / edit / del）仅允许超级管理员执行，防止普通管理员通过篡改自己已拥有的规则节点名（name）实现越权提权。
 */
class Rule extends AdminController
{
    use CrudTrait {
        del as protected crudDel;
    }

    protected array $searchFields = ['name', 'title', 'path'];
    protected array $filterFields = ['id', 'pid', 'type', 'name', 'title', 'status', 'hidden', 'plugin'];
    protected array $sortableFields = ['id', 'weight', 'create_time', 'pid'];
    protected array $allowFields = ['pid', 'type', 'name', 'title', 'icon', 'path', 'component', 'hidden', 'weight', 'status'];
    protected string|array $defaultSort = 'weight';

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new AuthRuleModel();
    }

    public function index(): void
    {
        $flat = (int) $this->request->param('flat', 0);
        if ($flat === 1) {
            $params = QueryParams::fromRequest($this->request);
            $query = $this->buildIndexQuery();
            $params->apply($query, $this->filterableFields(), $this->searchFields, 'weight');
            $total = (clone $query)->removeOption('order')->count();
            $list = $query->order('weight', 'desc')->order('id', 'asc')->page($params->page, $params->limit)->select();
            $this->page($list, $total);
            return;
        }

        $rows = $this->buildIndexQuery()->order('weight', 'desc')->order('id', 'asc')->select()->toArray();
        $tree = Tree::build($rows);
        $this->page($tree, count($rows));
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        if (!$this->auth->isSuperAdmin()) {
            $this->error('越权拦截：权限规则仅限超级管理员新增或修改', Result::FORBIDDEN, null, 403);
        }

        $this->validate($data, [
            'title|标题' => ($row ? '' : 'require|') . 'max:50',
            'type|类型'  => 'in:dir,menu,button',
        ]);

        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $name = strtolower(trim((string) $data['name'], '/'));
            $exists = Db::name('auth_rule')->where('name', $name)
                ->when($row, fn ($q) => $q->where('id', '<>', $row->id))
                ->find();
            if ($exists) {
                throw new ApiException("权限节点标识 {$name} 已存在");
            }
            $data['name'] = $name;
        }

        if (array_key_exists('pid', $data)) {
            $pid = max(0, (int) $data['pid']);
            $data['pid'] = $pid;
            $targetType = (string) ($data['type'] ?? ($row ? $row->type : 'menu'));

            if ($pid > 0) {
                if ($row) {
                    $selfId = (int) $row->id;
                    $allRules = Db::name('auth_rule')->field('id, pid')->select()->toArray();
                    $forbiddenIds = array_map('intval', Tree::descendantIds($allRules, $selfId, true));
                    if (in_array($pid, $forbiddenIds, true)) {
                        throw new ApiException('上级规则不能选择自身或下级子节点，避免菜单树形成环路');
                    }
                }

                $parent = Db::name('auth_rule')->where('id', $pid)->find();
                if (!$parent) {
                    throw new ApiException('所选上级规则不存在');
                }
                $parentType = (string) ($parent['type'] ?? '');
                if ($targetType === 'button') {
                    if ($parentType === 'button') {
                        throw new ApiException('按钮权限不能挂载在另一个按钮下');
                    }
                } elseif ($parentType !== 'dir') {
                    throw new ApiException('上级规则必须是「目录 (Dir)」类型，挂在普通菜单下会导致子菜单无法渲染');
                }
            }
        }

        return $data;
    }

    public function del(): void
    {
        $this->ensurePost();
        if (!$this->auth->isSuperAdmin()) {
            $this->error('越权拦截：权限规则仅限超级管理员删除', Result::FORBIDDEN, null, 403);
        }
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new ApiException('请选择要删除的记录');
        }

        $allRules = Db::name('auth_rule')->field('id, pid')->select()->toArray();
        $allIds = [];
        foreach ($ids as $id) {
            $descendants = Tree::descendantIds($allRules, $id, true);
            foreach ($descendants as $did) {
                $allIds[] = (int) $did;
            }
        }
        $allIds = array_values(array_unique($allIds));

        $this->request->withPost(array_merge($this->request->post(), ['ids' => $allIds]));
        $this->crudDel();
    }
}
