<?php
declare(strict_types=1);

namespace plugins\cms\controller\api;

use app\common\model\User as UserModel;
use core\auth\LoginThrottle;
use core\auth\PasswordHash;
use core\auth\TokenService;
use core\base\ApiController;
use think\facade\Db;

/**
 * CMS 前台会员认证与个人中心接口控制器
 */
class Member extends ApiController
{
    protected array $noNeedLogin = ['*'];

    /** 防时序枚举虚拟哈希 */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ZTMG';

    /**
     * 获取当前登录会员模型
     */
    protected function getCurrentUser(bool $require = true): ?UserModel
    {
        $token = (string) $this->request->header('x-cms-user-token', '');
        if ($token === '') {
            $auth = (string) $this->request->header('authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        if ($token === '') {
            $token = (string) $this->request->header('x-token', '');
        }

        if ($token === '') {
            if ($require) {
                $this->error('请先登录会员账号', 401, null, 401);
            }
            return null;
        }

        $tokenService = new TokenService();
        $row = $tokenService->verify('user', $token);
        if (!$row || empty($row['user_id'])) {
            if ($require) {
                $this->error('登录状态已失效，请重新登录', 401, null, 401);
            }
            return null;
        }

        $user = UserModel::find((int) $row['user_id']);
        if (!$user || (int) $user->status !== 1) {
            if ($require) {
                $this->error('会员账号不存在或已被禁用', 403, null, 403);
            }
            return null;
        }

        return $user;
    }

    /**
     * 格式化会员展示数据
     */
    protected function formatUser(UserModel $user): array
    {
        $groupId = (int) ($user->group_id ?? 1);
        $groupName = '普通会员';
        if ($groupId > 0) {
            $group = Db::name('user_group')->where('id', $groupId)->find();
            if ($group && !empty($group['name'])) {
                $groupName = (string) $group['name'];
            }
        }

        $avatar = (string) ($user->avatar ?? '');
        if ($avatar === '') {
            $avatar = 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=200&q=80';
        }

        return [
            'id'         => (int) $user->id,
            'username'   => (string) $user->username,
            'nickname'   => (string) ($user->nickname ?: $user->username),
            'avatar'     => $avatar,
            'email'      => (string) ($user->email ?? ''),
            'mobile'     => (string) ($user->mobile ?? ''),
            'gender'     => (int) ($user->gender ?? 0),
            'birthday'   => (string) ($user->birthday ?? ''),
            'bio'        => (string) ($user->bio ?? ''),
            'level'      => (int) ($user->level ?? 1),
            'score'      => (int) ($user->score ?? 0),
            'money'      => (float) ($user->money ?? 0.00),
            'group_id'   => $groupId,
            'group_name' => $groupName,
        ];
    }

    /**
     * 会员登录
     * POST /api/plugin/cms/member/login
     */
    public function login(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $account = trim((string) $this->request->param('account', ''));
        $password = trim((string) $this->request->param('password', ''));

        if ($account === '' || $password === '') {
            $this->error('请输入登录账号与密码');
        }

        $ip = (string) $this->request->ip();
        LoginThrottle::check('user', $account, $ip);

        $field = filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email'
            : (preg_match('/^1\d{10}$/', $account) ? 'mobile' : 'username');

        $user = UserModel::where($field, $account)->find();
        if (!$user) {
            $user = UserModel::where('username', $account)
                ->whereOr('mobile', $account)
                ->whereOr('email', $account)
                ->find();
        }

        if (!$user) {
            PasswordHash::verify($password, self::DUMMY_HASH, '');
            LoginThrottle::hit('user', $account, $ip);
            $this->error('账号或密码错误');
        }

        if (!PasswordHash::verify($password, (string) $user->password, (string) ($user->salt ?? ''))) {
            LoginThrottle::hit('user', $account, $ip);
            $this->error('账号或密码错误');
        }

        if ((int) $user->status !== 1) {
            $this->error('账号已被锁定或禁用，请联系管理员');
        }

        LoginThrottle::clear('user', $account, $ip);

        $update = [
            'last_login_time' => time(),
            'last_login_ip'   => $ip,
        ];
        if (PasswordHash::needsRehash((string) $user->password, (string) ($user->salt ?? ''))) {
            $update['password'] = PasswordHash::hash($password);
            $update['salt'] = '';
        }
        $user->save($update);

        $tokenService = new TokenService();
        $ttl = (int) config('hwadmin.token.user_ttl', 2592000);
        $token = $tokenService->issue('user', (int) $user->id, $ttl);

        $this->success([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ], '登录成功');
    }

    /**
     * 会员注册
     * POST /api/plugin/cms/member/register
     */
    public function register(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        // 读取 CMS 插件配置，检查是否开放会员注册
        $pluginRow = Db::name('plugin')->where('name', 'cms')->find();
        $config = [];
        if ($pluginRow && !empty($pluginRow['config'])) {
            $config = is_array($pluginRow['config']) ? $pluginRow['config'] : (json_decode((string) $pluginRow['config'], true) ?: []);
        }
        $allowRegister = (int) ($config['allow_member_register'] ?? 1);
        if ($allowRegister !== 1) {
            $this->error('站点前台会员注册功能已暂闭');
        }

        $username = trim((string) $this->request->param('username', ''));
        $password = trim((string) $this->request->param('password', ''));
        $nickname = trim((string) $this->request->param('nickname', ''));
        $mobile = trim((string) $this->request->param('mobile', ''));
        $email = trim((string) $this->request->param('email', ''));

        if ($username === '' || strlen($username) < 3 || strlen($username) > 32) {
            $this->error('用户名长度应在 3 到 32 个字符之间');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $this->error('用户名仅支持英文字母、数字和下划线');
        }
        if ($password === '' || strlen($password) < 6) {
            $this->error('登录密码长度不能少于 6 位');
        }

        // 校验唯一性
        if (UserModel::where('username', $username)->find()) {
            $this->error('用户名已被注册，请更换其他用户名');
        }
        if ($mobile !== '' && UserModel::where('mobile', $mobile)->find()) {
            $this->error('手机号已绑定其他会员账号');
        }
        if ($email !== '' && UserModel::where('email', $email)->find()) {
            $this->error('电子邮箱已绑定其他会员账号');
        }

        $now = time();
        $user = UserModel::create([
            'group_id'        => 1,
            'username'        => $username,
            'nickname'        => $nickname !== '' ? $nickname : $username,
            'password'        => PasswordHash::hash($password),
            'mobile'          => $mobile,
            'email'           => $email,
            'level'           => 1,
            'score'           => 100, // 新注册赠送 100 积分
            'money'           => 0.00,
            'status'          => 1,
            'join_ip'         => (string) $this->request->ip(),
            'last_login_time' => $now,
            'last_login_ip'   => (string) $this->request->ip(),
            'create_time'     => $now,
            'update_time'     => $now,
        ]);

        $tokenService = new TokenService();
        $ttl = (int) config('hwadmin.token.user_ttl', 2592000);
        $token = $tokenService->issue('user', (int) $user->id, $ttl);

        $this->success([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ], '注册成功，已为您获赠 100 欢迎积分！');
    }

    /**
     * 退出登录
     * POST /api/plugin/cms/member/logout
     */
    public function logout(): void
    {
        $token = (string) $this->request->header('x-cms-user-token', '');
        if ($token === '') {
            $auth = (string) $this->request->header('authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        if ($token === '') {
            $token = (string) $this->request->header('x-token', '');
        }

        if ($token !== '') {
            (new TokenService())->revoke($token);
        }

        $this->success(null, '已安全退出登录');
    }

    /**
     * 获取当前会员个人中心资料与我的询单/留言/评论记录
     * GET /api/plugin/cms/member/profile
     */
    public function profile(): void
    {
        $user = $this->getCurrentUser(true);

        $myInquiries = Db::name('cms_inquiry')
            ->where('user_id', $user->id)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $stats = [
            'comment_count' => Db::name('cms_inquiry')->where('user_id', $user->id)->where('scene', 'blog_comment')->count(),
            'contact_count' => Db::name('cms_inquiry')->where('user_id', $user->id)->where('scene', 'corp_contact')->count(),
            'inquiry_count' => Db::name('cms_inquiry')->where('user_id', $user->id)->where('scene', 'product_inquiry')->count(),
            'total_count'   => count($myInquiries),
        ];

        $this->success([
            'user'         => $this->formatUser($user),
            'stats'        => $stats,
            'my_inquiries' => $myInquiries,
        ]);
    }

    /**
     * 更新会员个人资料或修改密码
     * POST /api/plugin/cms/member/updateProfile
     */
    public function updateProfile(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $user = $this->getCurrentUser(true);

        $nickname = trim((string) $this->request->param('nickname', ''));
        $email = trim((string) $this->request->param('email', ''));
        $mobile = trim((string) $this->request->param('mobile', ''));
        $bio = trim((string) $this->request->param('bio', ''));
        $gender = $this->request->param('gender');
        $birthday = trim((string) $this->request->param('birthday', ''));
        $avatar = trim((string) $this->request->param('avatar', ''));

        $oldPassword = (string) $this->request->param('old_password', '');
        $newPassword = (string) $this->request->param('new_password', '');

        $updateData = [];

        if ($nickname !== '') {
            $updateData['nickname'] = $nickname;
        }
        if ($email !== '') {
            $existing = UserModel::where('email', $email)->where('id', '<>', $user->id)->find();
            if ($existing) {
                $this->error('电子邮箱已绑定其他会员账号');
            }
            $updateData['email'] = $email;
        }
        if ($mobile !== '') {
            $existing = UserModel::where('mobile', $mobile)->where('id', '<>', $user->id)->find();
            if ($existing) {
                $this->error('手机号码已绑定其他会员账号');
            }
            $updateData['mobile'] = $mobile;
        }
        if ($bio !== '') {
            $updateData['bio'] = $bio;
        }
        if ($gender !== null && $gender !== '') {
            $updateData['gender'] = (int) $gender;
        }
        if ($birthday !== '') {
            $updateData['birthday'] = $birthday;
        }
        if ($avatar !== '') {
            if (!$this->isSafeAvatarUrl($avatar)) {
                $this->error('头像地址格式非法，仅允许 / 站内路径或 http(s):// 链接');
            }
            $updateData['avatar'] = $avatar;
        }

        // 修改密码校验
        $passwordChanged = false;
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) {
                $this->error('新登录密码不能少于 6 位');
            }
            if ($oldPassword === '') {
                $this->error('修改密码时必须输入当前原密码');
            }
            if (!PasswordHash::verify($oldPassword, (string) $user->password, (string) ($user->salt ?? ''))) {
                $this->error('原密码输入不正确');
            }
            $updateData['password'] = PasswordHash::hash($newPassword);
            $updateData['salt'] = '';
            $passwordChanged = true;
        }

        if (!empty($updateData)) {
            $updateData['update_time'] = time();
            $user->save($updateData);
        }

        if ($passwordChanged) {
            (new TokenService())->revokeAll('user', (int) $user->id);
        }

        $this->success([
            'user' => $this->formatUser($user),
        ], '个人资料更新成功');
    }

    protected function isSafeAvatarUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (preg_match('/[\r\n\0\s]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        return (bool) (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL));
    }
}
