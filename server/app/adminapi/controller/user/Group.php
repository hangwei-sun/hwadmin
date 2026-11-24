<?php
declare(strict_types=1);

namespace app\adminapi\controller\user;

use app\common\model\UserGroup as UserGroupModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\Model;

/**
 * 会员分组管理
 *
 * 节点：user/group/{index|read|add|edit|del}
 */
class Group extends AdminController
{
    use CrudTrait {
        del as protected crudDel;
    }

    protected array $searchFields = ['name'];
    protected array $filterFields = ['id', 'name', 'status', 'create_time'];
    protected array $sortableFields = ['id', 'create_time'];
    protected array $allowFields = ['name', 'rules', 'status'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new UserGroupModel();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $this->validate($data, [
            'name|组名' => ($row ? '' : 'require|') . 'max:50',
        ]);

        if (isset($data['rules']) && is_array($data['rules'])) {
            $data['rules'] = implode(',', array_filter($data['rules'], fn ($v) => $v !== ''));
        }

        return $data;
    }

    public function del(): void
    {
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (in_array(1, $ids, true)) {
            throw new ApiException('默认会员组不可删除');
        }

        $this->crudDel();
    }
}
