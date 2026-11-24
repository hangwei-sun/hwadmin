<?php
declare(strict_types=1);

namespace core\auth;

use core\exception\ApiException;
use core\support\Tree;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

/**
 * 后台管理员认证与 RBAC 权限（强化子集授权安全边界）
 *
 * 数据表：admin / auth_group / auth_group_access / auth_rule
 * - auth_group.rules = '*' 表示超级管理员组；否则为逗号分隔的 auth_rule.id；
 * - 权限节点（auth_rule.name）格式：{控制器路径}/{操作}，全小写，例如 auth/admin/index；
 *   插件节点：plugin/{插件标识}/{控制器}/{操作}；
 * - auth_rule.type：dir=菜单目录，menu=菜单页面，button=按钮/接口权限点。
 *
 * 安全基线（P0-1 & P1-5）：
 * - 防时序枚举：不存在账号时执行一次 DUMMY_BCRYPT_HASH 校验；禁用状态在密码验证通过后检查；
 * - 防锁定 DoS：使用 LoginThrottle (account+ip) 与 ip 双维度限流；
 * - 下级与子集约束：提供 childrenGroupIds() / childrenAdminIds() / ruleIds() 供 Group/Admin/Rule 控制器强制校验。
 */
class AdminAuth
{
    public const TOKEN_TYPE = 'admin';

    /** 防计时攻击的虚拟 bcrypt 哈希（对应随机不可猜字符串） */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ZTMG';

    /** 返回给前端的管理员安全字段 */
    protected const SAFE_FIELDS = ['id', 'username', 'nickname', 'avatar', 'email', 'mobile', 'status', 'last_login_time'];

    protected const CACHE_VERSION_KEY = 'hw:auth:cache_ver';
    protected const CACHE_TTL = 60;

    protected ?array $admin = null;
    protected string $token = '';
    protected ?array $groups = null;
    protected ?array $rules = null;
    /** @var array<string, true>|null */
    protected ?array $ruleSet = null;
    protected ?array $allGroups = null;

    public function __construct(protected TokenService $tokens)
    {
    }

    /**
     * 使用令牌初始化登录态
     */
    public function init(string $token): bool
    {
        $this->reset();
        $row = $this->tokens->verify(self::TOKEN_TYPE, $token);
        if (!$row) {
            return false;
        }
        $admin = Db::name('admin')->where('id', $row['user_id'])->find();
        if (!$admin || (int) $admin['status'] !== 1) {
            return false;
        }
        $this->admin = $admin;
        $this->token = $token;
        return true;
    }

    /**
     * 账号密码登录，返回 { token, expires_in, admin }
     */
    public function login(string $username, string $password, string $ip = ''): array
    {
        LoginThrottle::check(self::TOKEN_TYPE, $username, $ip);

        $admin = Db::name('admin')->where('username', $username)->find();
        if (!$admin) {
            // 执行一次虚拟哈希验证以消除存在/不存在账号的响应耗时差异（防计时枚举）
            PasswordHasher::verify($password, self::DUMMY_HASH, '');
            LoginThrottle::hit(self::TOKEN_TYPE, $username, $ip);
            throw new ApiException('用户名或密码错误');
        }

        if (!PasswordHasher::verify($password, (string) $admin['password'], (string) ($admin['salt'] ?? ''))) {
            LoginThrottle::hit(self::TOKEN_TYPE, $username, $ip);
            Db::name('admin')->where('id', $admin['id'])->update([
                'login_failure' => Db::raw('login_failure + 1'),
            ]);
            throw new ApiException('用户名或密码错误');
        }

        if ((int) $admin['status'] !== 1) {
            throw new ApiException('账号已被禁用');
        }

        LoginThrottle::clear(self::TOKEN_TYPE, $username, $ip);

        $update = [
            'login_failure'   => 0,
            'last_login_time' => time(),
            'last_login_ip'   => $ip,
            'update_time'     => time(),
        ];
        // 兼容历史旧密码：验证通过后自动升级为 password_hash
        if (PasswordHasher::needsRehash((string) $admin['password'], (string) ($admin['salt'] ?? ''))) {
            $update['password'] = PasswordHasher::hash($password);
            $update['salt'] = '';
        }
        Db::name('admin')->where('id', $admin['id'])->update($update);

        $ttl = (int) config('hwadmin.token.admin_ttl', 86400);
        $token = $this->tokens->issue(self::TOKEN_TYPE, (int) $admin['id'], $ttl);
        $this->init($token);

        return ['token' => $token, 'expires_in' => $ttl, 'admin' => $this->profile()];
    }

    public function logout(): void
    {
        if ($this->token !== '') {
            $this->tokens->revoke($this->token);
        }
        $this->reset();
    }

    public function isLogin(): bool
    {
        return $this->admin !== null;
    }

    public function id(): int
    {
        return (int) ($this->admin['id'] ?? 0);
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * 当前管理员原始记录（仅供安全校验如旧密码比对使用）
     */
    public function rawAdmin(): ?array
    {
        return $this->admin;
    }

    /**
     * 当前管理员安全信息（不含密码等敏感字段）
     */
    public function profile(): array
    {
        if (!$this->admin) {
            return [];
        }
        return array_intersect_key($this->admin, array_flip(self::SAFE_FIELDS));
    }

    /**
     * 当前管理员所属的启用中的角色组
     */
    public function groups(): array
    {
        if ($this->groups === null) {
            if (!$this->isLogin()) {
                return $this->groups = [];
            }
            $adminId = $this->id();
            $this->groups = $this->rememberAuthCache('ag:' . $adminId, static function () use ($adminId): array {
                return Db::name('auth_group_access')->alias('a')
                    ->join('auth_group g', 'a.group_id = g.id')
                    ->where('a.admin_id', $adminId)
                    ->where('g.status', 1)
                    ->field('g.id,g.pid,g.name,g.rules')
                    ->select()->toArray();
            });
        }
        return $this->groups;
    }

    /**
     * 获取全量角色组树基础列表（请求内 + 短期缓存复用）
     */
    protected function allGroups(): array
    {
        if ($this->allGroups === null) {
            $this->allGroups = $this->rememberAuthCache('groups', static function (): array {
                return Db::name('auth_group')->field('id, pid')->select()->toArray();
            });
        }
        return $this->allGroups;
    }

    /**
     * 当前管理员直接归属的角色组 ID 列表
     * @return int[]
     */
    public function groupIds(): array
    {
        return array_values(array_unique(array_map(static fn ($g) => (int) $g['id'], $this->groups())));
    }

    /**
     * 获取当前管理员可管理的角色组 ID 集合（递归子集授权，P0-1 防提权）
     * @param bool $withSelf 是否包含自己直接所在的角色组（默认 false：非超管只能修改下级子孙角色组，不能篡改自身所在组）
     * @return int[]
     */
    public function childrenGroupIds(bool $withSelf = false): array
    {
        $allGroups = $this->allGroups();
        if ($this->isSuperAdmin()) {
            return array_values(array_map(static fn ($g) => (int) $g['id'], $allGroups));
        }
        $myGroupIds = $this->groupIds();
        if (!$myGroupIds) {
            return [];
        }
        $result = [];
        foreach ($myGroupIds as $gid) {
            foreach (Tree::descendantIds($allGroups, $gid, $withSelf) as $cid) {
                $result[] = (int) $cid;
            }
        }
        // 非超管即使开启 $withSelf=false，也要剔除自己直接所属的组 ID，防止通过父子交叉提权
        if (!$withSelf) {
            $result = array_diff($result, $myGroupIds);
        }
        return array_values(array_unique($result));
    }

    /**
     * 获取当前管理员可管理的下级管理员 ID 集合（P0-1）
     * @param bool $withSelf 是否包含当前管理员自己
     * @return int[]
     */
    public function childrenAdminIds(bool $withSelf = false): array
    {
        if ($this->isSuperAdmin()) {
            $ids = array_map('intval', Db::name('admin')->column('id'));
            return $withSelf ? $ids : array_values(array_diff($ids, [$this->id()]));
        }
        $childGroupIds = $this->childrenGroupIds(false);
        $adminIds = [];
        if ($childGroupIds) {
            $adminIds = array_map(
                'intval',
                Db::name('auth_group_access')->whereIn('group_id', $childGroupIds)->column('admin_id')
            );
            // 排除同时属于超级管理员组或同级/上级组的管理员
            $forbiddenGroupIds = array_diff(
                array_map('intval', array_column($this->allGroups(), 'id')),
                $childGroupIds
            );
            if ($forbiddenGroupIds && $adminIds) {
                $protectedAdmins = array_map(
                    'intval',
                    Db::name('auth_group_access')
                        ->whereIn('admin_id', $adminIds)
                        ->whereIn('group_id', $forbiddenGroupIds)
                        ->column('admin_id')
                );
                $adminIds = array_diff($adminIds, $protectedAdmins);
            }
        }
        if ($withSelf && $this->isLogin()) {
            $adminIds[] = $this->id();
        }
        // 永远禁止非超管操作 id=1 初始超管
        $adminIds = array_diff($adminIds, [1]);
        return array_values(array_unique($adminIds));
    }

    /**
     * 当前管理员拥有的权限规则 ID 集合（用于子集校验）
     * @return int[]
     */
    public function ruleIds(): array
    {
        return array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $this->rules())));
    }

    public function isSuperAdmin(): bool
    {
        foreach ($this->groups() as $group) {
            if (trim((string) $group['rules']) === '*') {
                return true;
            }
        }
        return false;
    }

    /**
     * isSuperAdmin 的简写别名（供 CrudTrait 数据权限等统一调用）
     */
    public function isSuper(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * 获取全量启用中的权限规则（带短期缓存）
     */
    protected function allEnabledRules(): array
    {
        return $this->rememberAuthCache('rules', static function (): array {
            return Db::name('auth_rule')
                ->where('status', 1)
                ->order('weight', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        });
    }

    /**
     * 当前管理员可用的权限规则（启用状态）
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        if (!$this->isLogin()) {
            return $this->rules = [];
        }
        $allRules = $this->allEnabledRules();
        if ($this->isSuperAdmin()) {
            return $this->rules = $allRules;
        }
        $ids = [];
        foreach ($this->groups() as $group) {
            foreach (explode(',', (string) $group['rules']) as $id) {
                if (ctype_digit(trim($id))) {
                    $ids[(int) $id] = true;
                }
            }
        }
        if (!$ids) {
            return $this->rules = [];
        }
        return $this->rules = array_values(array_filter(
            $allRules,
            static fn ($r) => isset($ids[(int) ($r['id'] ?? 0)])
        ));
    }

    /**
     * 根据权限节点名获取标题（优先复用请求内或规则缓存，冷缓存超管走 name 索引单查）
     */
    public function ruleTitle(string $node): string
    {
        $node = strtolower(trim($node, '/'));
        if ($node === '') {
            return '';
        }

        if ($this->rules !== null) {
            foreach ($this->rules as $r) {
                if (strtolower((string) ($r['name'] ?? '')) === $node) {
                    return (string) ($r['title'] ?? '');
                }
            }
            return '';
        }

        try {
            $cached = Cache::get(self::buildCacheKey('rules'));
            if (is_array($cached)) {
                foreach ($cached as $r) {
                    if (strtolower((string) ($r['name'] ?? '')) === $node) {
                        return (string) ($r['title'] ?? '');
                    }
                }
                return '';
            }
        } catch (Throwable) {
        }

        try {
            $title = Db::name('auth_rule')->where('name', $node)->where('status', 1)->value('title');
            return is_string($title) ? $title : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * 校验权限节点
     */
    public function check(string $node): bool
    {
        if (!$this->isLogin()) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        $node = strtolower(trim($node, '/'));
        if ($this->ruleSet === null) {
            $set = [];
            foreach ($this->rules() as $rule) {
                $name = strtolower((string) ($rule['name'] ?? ''));
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
            $this->ruleSet = $set;
        }
        return isset($this->ruleSet[$node]);
    }

    /**
     * 清理权限规则、角色组树与管理员组缓存（权限/角色/管理员变更后调用）
     */
    public static function clearCache(?int $adminId = null): void
    {
        try {
            if ($adminId !== null && $adminId > 0) {
                Cache::delete(self::buildCacheKey('ag:' . $adminId));
                return;
            }
            $ver = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
            Cache::set(self::CACHE_VERSION_KEY, $ver + 1, 86400);
            Cache::delete(self::buildCacheKey('rules'));
            Cache::delete(self::buildCacheKey('groups'));
        } catch (Throwable) {
        }
    }

    protected static function buildCacheKey(string $suffix): string
    {
        $ver = 1;
        try {
            $ver = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
            if ($ver < 1) {
                $ver = 1;
            }
        } catch (Throwable) {
        }
        return "hw:auth:v{$ver}:{$suffix}";
    }

    /**
     * @param callable():array $loader
     */
    protected function rememberAuthCache(string $suffix, callable $loader): array
    {
        $ttl = (int) config('hwadmin.auth.cache_ttl', self::CACHE_TTL);
        if ($ttl <= 0) {
            return $loader();
        }
        try {
            $key = self::buildCacheKey($suffix);
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            $data = $loader();
            Cache::set($key, $data, $ttl);
            return $data;
        } catch (Throwable) {
            return $loader();
        }
    }

    /**
     * 当前管理员拥有的权限节点列表（前端按钮级权限使用）
     */
    public function permissions(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }
        return array_values(array_filter(array_unique(array_map(
            static fn ($r) => strtolower((string) $r['name']),
            $this->rules()
        ))));
    }

    /**
     * 生成前端菜单路由树（兼容 Vab 模板 authentication=all 模式的路由格式）
     */
    public function menus(): array
    {
        $rows = array_filter($this->rules(), static fn ($r) => in_array($r['type'], ['dir', 'menu'], true));
        $tree = Tree::build(array_values($rows));
        return MenuFormatter::format($tree);
    }

    protected function reset(): void
    {
        $this->admin = null;
        $this->token = '';
        $this->groups = null;
        $this->rules = null;
        $this->ruleSet = null;
        $this->allGroups = null;
    }
}
