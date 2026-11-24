<?php
declare(strict_types=1);

namespace app\adminapi\controller\user;

use app\common\model\User as UserModel;
use core\auth\PasswordHasher;
use core\auth\TokenService;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\facade\Db;
use think\Model;

/**
 * 会员管理
 *
 * 节点：user/user/{index|read|add|edit|del}
 */
class User extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['username', 'nickname', 'email', 'mobile'];
    protected array $filterFields = ['id', 'group_id', 'username', 'nickname', 'email', 'mobile', 'gender', 'level', 'status', 'create_time'];
    protected array $sortableFields = ['id', 'money', 'score', 'level', 'login_time', 'create_time', 'update_time'];
    protected array $allowFields = [
        'group_id', 'username', 'nickname', 'password', 'email', 'mobile',
        'avatar', 'gender', 'birthday', 'bio', 'level', 'money', 'score', 'status',
    ];
    protected array $hiddenFields = ['password', 'salt'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new UserModel();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $this->validate($data, [
            'username|用户名' => ($row ? '' : 'require|') . 'alphaDash|length:3,32',
            'email|邮箱'      => 'email',
            'status|状态'     => 'in:0,1',
        ]);

        if (isset($data['username'])) {
            $exists = Db::name('user')->where('username', $data['username'])
                ->when($row, fn ($q) => $q->where('id', '<>', $row->id))->find();
            if ($exists) {
                throw new ApiException('用户名已存在');
            }
        }

        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 6) {
                throw new ApiException('密码至少 6 位');
            }
            $data['password'] = PasswordHasher::hash((string) $data['password']);
            $data['salt'] = '';
        } elseif ($row) {
            unset($data['password']);
        } else {
            throw new ApiException('请设置密码');
        }

        if (!$row && !isset($data['join_ip'])) {
            $data['join_ip'] = (string) $this->request->ip();
        }

        return $data;
    }

    protected function afterWrite(Model $row, array $data, bool $isNew): void
    {
        // 禁用会员或修改密码后清除该会员所有令牌
        if (!$isNew && (isset($data['password']) || (isset($data['status']) && (int) $data['status'] !== 1))) {
            $this->app->make(TokenService::class)->revokeAll('user', (int) $row->id);
        }
    }

    protected function afterDelete(array $ids): void
    {
        Db::name('token')->where('type', 'user')->whereIn('user_id', $ids)->delete();
    }
}
