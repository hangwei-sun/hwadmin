<?php
declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\common\model\Admin as AdminModel;
use core\auth\LoginThrottle;
use core\auth\PasswordHasher;
use core\auth\TokenService;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\crud\QueryParams;
use core\exception\ApiException;
use core\http\Result;
use think\facade\Db;
use think\Model;

/**
 * 管理员管理 —— CrudTrait 的参考实现（新建 CRUD 控制器时照此编写）
 *
 * 节点：auth/admin/{index|read|add|edit|del}
 * 安全基线（2-P0-1 & 2-P1-3）：
 *   1. 下级隔离：非超管仅能查看/新增/编辑/删除归属于自己下级子角色组的管理员，严禁操作超管（含 id=1）或同级管理员；
 *   2. 角色组分配子集约束：非超管分配的 groups 必须全部位于自身 `childrenGroupIds(false)` 范围内，严禁将自己或他人加入超管组；
 *   3. 个人资料改密保护：`profile` 修改密码强制校验 `old_password`，改密后自动吊销该账号全部历史令牌并签发新令牌。
 */
class Admin extends AdminController
{
    use CrudTrait {
        del as protected crudDel;
        buildIndexQuery as protected crudBuildIndexQuery;
    }

    protected array $noNeedRight = ['profile', 'unlock'];

    protected array $searchFields = ['username', 'nickname', 'email', 'mobile'];
    protected array $filterFields = ['id', 'username', 'nickname', 'email', 'mobile', 'status', 'create_time'];
    protected array $sortableFields = ['id', 'create_time', 'update_time', 'last_login_time'];
    protected array $allowFields = ['username', 'nickname', 'password', 'avatar', 'email', 'mobile', 'status'];
    protected array $hiddenFields = ['password', 'salt', 'lock_password'];

    /** 待保存的下级合法角色组 ID 列表 */
    protected ?array $validatedGroupIds = null;

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new AdminModel();
    }

    protected function buildIndexQuery()
    {
        $query = $this->crudBuildIndexQuery();
        if (!$this->auth->isSuperAdmin()) {
            $visibleAdminIds = $this->auth->childrenAdminIds(true);
            $query->whereIn('id', $visibleAdminIds ?: [0]);
        }
        return $query;
    }

    public function index(): void
    {
        $params = QueryParams::fromRequest($this->request);
        $query = $this->buildIndexQuery();
        $params->apply($query, $this->filterableFields(), $this->searchFields, $this->defaultSort);
        $total = (clone $query)->removeOption('order')->count();
        $list = $query->page($params->page, $params->limit)->select();
        if ($this->hiddenFields) {
            $list->hidden($this->hiddenFields);
        }
        $rows = $list->toArray();
        if ($rows) {
            $adminIds = array_column($rows, 'id');
            $groupAccess = Db::name('auth_group_access')
                ->alias('aga')
                ->join('auth_group ag', 'aga.group_id = ag.id')
                ->whereIn('aga.admin_id', $adminIds)
                ->field('aga.admin_id, aga.group_id, ag.name as group_name')
                ->select()
                ->toArray();
            $adminGroups = [];
            $adminGroupNames = [];
            foreach ($groupAccess as $item) {
                $aid = $item['admin_id'];
                $adminGroups[$aid][] = (int) $item['group_id'];
                $adminGroupNames[$aid][] = (string) $item['group_name'];
            }
            foreach ($rows as &$row) {
                $aid = $row['id'];
                $row['groups'] = $adminGroups[$aid] ?? [];
                $row['group_names'] = $adminGroupNames[$aid] ?? [];
            }
            unset($row);
        }
        $this->page($rows, $total);
    }

    public function profile(): void
    {
        $adminId = $this->auth->id();
        if ($this->request->isGet()) {
            $profile = $this->auth->profile();
            $rawAdmin = Db::name('admin')->where('id', $adminId)->find();
            $profile['has_custom_lock_password'] = !empty($rawAdmin['lock_password']);
            $this->success($profile);
        }
        $this->ensurePost();
        $data = $this->request->post();
        $update = [];
        foreach (['nickname', 'email', 'mobile', 'avatar'] as $field) {
            if (isset($data[$field])) {
                $update[$field] = (string) $data[$field];
            }
        }
        if (isset($update['email']) && $update['email'] !== '') {
            $this->validate(['email' => $update['email']], ['email' => 'email']);
        }
        if (isset($update['avatar']) && $update['avatar'] !== '') {
            $this->assertSafeAvatarUrl($update['avatar']);
        }
        $newToken = null;
        if (!empty($data['password'])) {
            $oldPassword = (string) ($data['old_password'] ?? '');
            if ($oldPassword === '') {
                throw new ApiException('修改密码必须提供当前旧密码 (old_password)');
            }
            $rawAdmin = $this->auth->rawAdmin();
            if (!$rawAdmin || !PasswordHasher::verify($oldPassword, (string) $rawAdmin['password'], (string) ($rawAdmin['salt'] ?? ''))) {
                throw new ApiException('当前旧密码不正确');
            }
            if (strlen((string) $data['password']) < 8) {
                throw new ApiException('新密码至少 8 位');
            }
            $update['password'] = PasswordHasher::hash((string) $data['password']);
            $update['salt'] = '';
        }

        // 锁屏密码设置与重置逻辑
        $hasLockPasswordParam = array_key_exists('lock_password', $data);
        $hasResetLockParam = !empty($data['reset_lock_password']);
        if ($hasLockPasswordParam || $hasResetLockParam) {
            $currentPassword = (string) ($data['current_password'] ?? $data['old_password'] ?? '');
            if ($currentPassword === '') {
                throw new ApiException('设置锁屏密码必须提供当前后台登录密码以验证身份');
            }
            $rawAdmin = Db::name('admin')->where('id', $adminId)->find();
            if (!$rawAdmin || !PasswordHasher::verify($currentPassword, (string) $rawAdmin['password'], (string) ($rawAdmin['salt'] ?? ''))) {
                throw new ApiException('当前后台登录密码不正确');
            }

            if ($hasResetLockParam) {
                $update['lock_password'] = '';
            } elseif ($hasLockPasswordParam) {
                $lockPassword = (string) $data['lock_password'];
                if ($lockPassword === '') {
                    $update['lock_password'] = '';
                } else {
                    if (strlen($lockPassword) < 4) {
                        throw new ApiException('个性锁屏密码至少 4 位');
                    }
                    $update['lock_password'] = PasswordHasher::hash($lockPassword);
                }
            }
        }

        if ($update) {
            $update['update_time'] = time();
            Db::name('admin')->where('id', $adminId)->update($update);
        }
        if (isset($update['password'])) {
            // 改密成功后吊销所有旧会话，并为当前会话签发新 Token
            $tokens = $this->app->make(TokenService::class);
            $tokens->revokeAll('admin', $adminId);
            $ttl = (int) config('hwadmin.token.admin_ttl', 86400);
            $newToken = $tokens->issue('admin', $adminId, $ttl);
        }
        $this->success($newToken ? ['token' => $newToken] : null, '个人资料已更新');
    }

    /**
     * 锁屏密码校验与解锁接口
     * POST /adminapi/auth.admin/unlock
     */
    public function unlock(): void
    {
        $this->ensurePost();
        $password = (string) $this->request->post('password', '');
        if ($password === '') {
            throw new ApiException('请输入解锁密码');
        }

        $adminId = $this->auth->id();
        $admin = Db::name('admin')->where('id', $adminId)->find();
        if (!$admin || (int) ($admin['status'] ?? 0) !== 1) {
            throw new ApiException('管理员账号状态异常');
        }

        $username = (string) $admin['username'];
        $ip = (string) $this->request->ip();

        // S06: 锁屏防爆破限流校验
        LoginThrottle::check('admin_unlock', $username, $ip);

        $unlocked = false;
        // 1. 若已设置个性锁屏密码，优先校验
        if (!empty($admin['lock_password'])) {
            if (PasswordHasher::verify($password, (string) $admin['lock_password'], '')) {
                $unlocked = true;
            }
        }

        // 2. 若未设置个性密码或不匹配，则校验后台登录密码
        if (!$unlocked) {
            if (PasswordHasher::verify($password, (string) $admin['password'], (string) ($admin['salt'] ?? ''))) {
                $unlocked = true;
            }
        }

        if (!$unlocked) {
            LoginThrottle::hit('admin_unlock', $username, $ip);
            throw new ApiException('解锁密码错误，请输入正确的密码（默认为登录密码）');
        }

        LoginThrottle::clear('admin_unlock', $username, $ip);
        $this->success(null, '解锁成功');
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $isSuper = $this->auth->isSuperAdmin();

        // 1. 编辑目标权限校验：非超管严禁编辑初始超管 (id=1)、自己（改角色/状态）或非下级管理员
        if ($row) {
            $targetId = (int) $row->id;
            if ($targetId === 1 && $this->auth->id() !== 1) {
                $this->error('越权拦截：只有初始超级管理员本人可以修改初始超管账号', Result::FORBIDDEN, null, 403);
            }
            if (!$isSuper) {
                $manageableAdminIds = $this->auth->childrenAdminIds(false);
                if (!in_array($targetId, $manageableAdminIds, true)) {
                    $this->error('越权拦截：您只能编辑下级角色组的管理员', Result::FORBIDDEN, null, 403);
                }
            }
        }

        // 2. 校验分配的角色组 groups
        $groups = $this->request->post('groups');
        $this->validatedGroupIds = null;
        if ($groups !== null || !$row) {
            if (is_string($groups) && $groups !== '') {
                $groups = explode(',', $groups);
            }
            $groupIds = is_array($groups)
                ? array_values(array_unique(array_filter(array_map('intval', $groups), static fn ($g) => $g > 0)))
                : [];
            if (!$isSuper) {
                if (!$groupIds) {
                    throw new ApiException('请为管理员分配所属的下级角色组');
                }
                $allowedGroupIds = $this->auth->childrenGroupIds(false);
                if (array_diff($groupIds, $allowedGroupIds)) {
                    $this->error('越权拦截：只能为管理员分配您所属角色组的下级子组', Result::FORBIDDEN, null, 403);
                }
            } elseif ($groupIds) {
                // 超管也需校验组 ID 真实存在
                $existGroupIds = array_map('intval', Db::name('auth_group')->whereIn('id', $groupIds)->column('id'));
                if (array_diff($groupIds, $existGroupIds)) {
                    throw new ApiException('所选角色组不存在');
                }
            }
            if ($groups !== null) {
                $this->validatedGroupIds = $groupIds;
            }
        }

        $this->validate($data, [
            'username|用户名' => ($row ? '' : 'require|') . 'alphaDash|length:3,30',
            'email|邮箱'      => 'email',
            'status|状态'     => 'in:0,1',
        ]);
        if (!empty($data['avatar'])) {
            $this->assertSafeAvatarUrl((string) $data['avatar']);
        }
        if (isset($data['username'])) {
            $exists = Db::name('admin')->where('username', $data['username'])
                ->when($row, fn ($q) => $q->where('id', '<>', $row->id))->find();
            if ($exists) {
                throw new ApiException('用户名已存在');
            }
        }
        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 8) {
                throw new ApiException('密码至少 8 位');
            }
            $data['password'] = PasswordHasher::hash((string) $data['password']);
            $data['salt'] = '';
        } elseif ($row) {
            unset($data['password']);
        } else {
            throw new ApiException('请设置密码');
        }
        if ($row && (int) $row->id === $this->auth->id() && isset($data['status']) && (int) $data['status'] !== 1) {
            throw new ApiException('不能禁用自己');
        }
        if ($row && (int) $row->id === 1 && isset($data['status']) && (int) $data['status'] !== 1) {
            throw new ApiException('不能禁用初始超级管理员');
        }
        return $data;
    }

    protected function afterWrite(Model $row, array $data, bool $isNew): void
    {
        $groupChanged = false;
        if ($this->validatedGroupIds !== null) {
            // 保护初始超管 id=1 始终保留超管组 id=1
            if ((int) $row->id === 1 && !in_array(1, $this->validatedGroupIds, true)) {
                $this->validatedGroupIds[] = 1;
            }
            Db::name('auth_group_access')->where('admin_id', $row->id)->delete();
            foreach ($this->validatedGroupIds as $gid) {
                Db::name('auth_group_access')->insert(['admin_id' => $row->id, 'group_id' => $gid]);
            }
            $groupChanged = true;
        }
        // 修改密码、变更角色组或禁用后强制吊销目标管理员全部令牌
        if (!$isNew && ($groupChanged || isset($data['password']) || (isset($data['status']) && (int) $data['status'] !== 1))) {
            $this->app->make(TokenService::class)->revokeAll('admin', (int) $row->id);
        }
    }

    public function multi(): void
    {
        $this->ensurePost();
        $rawIds = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if (!$ids) {
            throw new ApiException('请选择要操作的管理员');
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

        if (in_array($this->auth->id(), $ids, true)) {
            throw new ApiException('不能批量修改当前登录管理员自身状态');
        }
        if (in_array(1, $ids, true)) {
            throw new ApiException('不能批量修改初始超级管理员状态');
        }
        if (!$this->auth->isSuperAdmin()) {
            $manageableAdminIds = $this->auth->childrenAdminIds(false);
            if (array_diff($ids, $manageableAdminIds)) {
                $this->error('越权拦截：只能操作下级角色组的管理员', Result::FORBIDDEN, null, 403);
            }
        }

        $updatedIds = [];
        $now = time();
        $this->model->transaction(function () use ($ids, $statusVal, $now, &$updatedIds) {
            foreach ($this->buildIndexQuery()->whereIn('id', $ids)->select() as $row) {
                $row->save(['status' => $statusVal, 'update_time' => $now]);
                $updatedIds[] = (int) $row->id;
            }
        });

        if ($statusVal !== 1 && $updatedIds) {
            $tokens = $this->app->make(TokenService::class);
            foreach ($updatedIds as $aid) {
                $tokens->revokeAll('admin', $aid);
            }
        }

        $this->success(['count' => count($updatedIds)], '批量更新成功');
    }

    protected function assertSafeAvatarUrl(string $url): void
    {
        if (preg_match('/[\r\n\0\s]/', $url)) {
            throw new ApiException('头像地址格式非法');
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return;
        }
        if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }
        throw new ApiException('头像地址仅允许站内路径 (/...) 或 http(s):// 链接');
    }

    public function del(): void
    {
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string) $ids))));
        if (in_array($this->auth->id(), $ids, true)) {
            throw new ApiException('不能删除自己');
        }
        if (in_array(1, $ids, true)) {
            throw new ApiException('不能删除初始超级管理员');
        }
        if (!$this->auth->isSuperAdmin()) {
            $manageableAdminIds = $this->auth->childrenAdminIds(false);
            if (array_diff($ids, $manageableAdminIds)) {
                $this->error('越权拦截：只能删除下级角色组的管理员', Result::FORBIDDEN, null, 403);
            }
        }
        $this->crudDel();
    }

    protected function afterDelete(array $ids): void
    {
        Db::name('auth_group_access')->whereIn('admin_id', $ids)->delete();
        Db::name('token')->where('type', 'admin')->whereIn('user_id', $ids)->delete();
    }
}
