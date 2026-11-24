<?php
declare(strict_types=1);

namespace app\adminapi\controller;

use core\auth\CaptchaService;
use core\base\AdminController;
use core\exception\ApiException;
use think\facade\Db;

/**
 * 后台入口：登录、退出、当前管理员信息、菜单与图形验证码
 *
 * GET  /adminapi/index/config                          => 站点公共基础配置与安全策略
 * GET  /adminapi/index/captcha                         => { captcha_key, captcha_image, enabled }
 * POST /adminapi/index/login   { username, password, captcha_key?, captcha_code? } => { token, expires_in, admin }
 * POST /adminapi/index/logout
 * GET  /adminapi/index/info                            => { admin, permissions }
 * GET  /adminapi/index/menus                           => { list: 路由树 }
 */
class Index extends AdminController
{
    protected array $noNeedLogin = ['login', 'config', 'captcha'];
    protected array $noNeedRight = ['logout', 'info', 'menus', 'config', 'captcha', 'notices', 'clearCache'];

    /**
     * 获取站点公共基础配置与登录安全策略（免登录公开接口）
     * GET /adminapi/index/config
     */
    public function config(): void
    {
        $configs = Db::name('config')
            ->whereIn('group', ['basic', 'security'])
            ->column('value', 'name');

        $this->success([
            'site_name'      => (string) ($configs['site_name'] ?? 'HwAdmin'),
            'site_logo'      => (string) ($configs['site_logo'] ?? ''),
            'site_favicon'   => (string) ($configs['site_favicon'] ?? ''),
            'login_image'    => (string) ($configs['login_image'] ?? ''),
            'default_avatar' => (string) ($configs['default_avatar'] ?? ''),
            'site_copyright' => (string) ($configs['site_copyright'] ?? 'Copyright © 2026 HwAdmin. All rights reserved.'),
            'beian'          => (string) ($configs['beian'] ?? ''),
            'login_captcha'  => CaptchaService::isEnabled(),
            'home_mode'      => (string) ($configs['home_mode'] ?? 'portal'),
            'admin_entry'    => (string) ($configs['admin_entry'] ?? 'admin'),
        ]);
    }

    /**
     * 获取后台登录图形验证码（免登录公开接口）
     * GET /adminapi/index/captcha
     */
    public function captcha(): void
    {
        $data = CaptchaService::generate();
        $this->success($data);
    }

    /**
     * 管理员登录
     * POST /adminapi/index/login
     */
    public function login(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['username', 'password', 'captcha_key', 'captcha_code']);
        $this->validate($data, [
            'username|用户名' => 'require|max:30',
            'password|密码'   => 'require|max:64',
        ]);

        if (CaptchaService::isEnabled()) {
            $captchaCode = trim((string) ($data['captcha_code'] ?? ''));
            $captchaKey = trim((string) ($data['captcha_key'] ?? ''));
            if ($captchaCode === '') {
                throw new ApiException('请输入图形验证码');
            }
            if (!CaptchaService::verify($captchaKey, $captchaCode)) {
                throw new ApiException('图形验证码错误或已过期，请点击刷新');
            }
        }

        $result = $this->auth->login((string) $data['username'], (string) $data['password'], $this->request->ip());
        event('AdminLoginAfter', $result['admin']);
        $this->success($result, '登录成功');
    }

    public function logout(): void
    {
        $this->auth->logout();
        $this->success(null, '已退出');
    }

    public function info(): void
    {
        $configs = Db::name('config')
            ->whereIn('group', ['basic', 'security'])
            ->column('value', 'name');

        $site = [
            'site_name'      => (string) ($configs['site_name'] ?? 'HwAdmin'),
            'site_logo'      => (string) ($configs['site_logo'] ?? ''),
            'site_favicon'   => (string) ($configs['site_favicon'] ?? ''),
            'login_image'    => (string) ($configs['login_image'] ?? ''),
            'default_avatar' => (string) ($configs['default_avatar'] ?? ''),
            'site_copyright' => (string) ($configs['site_copyright'] ?? 'Copyright © 2026 HwAdmin. All rights reserved.'),
            'beian'          => (string) ($configs['beian'] ?? ''),
            'login_captcha'  => CaptchaService::isEnabled(),
            'home_mode'      => (string) ($configs['home_mode'] ?? 'portal'),
            'admin_entry'    => (string) ($configs['admin_entry'] ?? 'admin'),
        ];

        $this->success([
            'admin'       => $this->auth->profile(),
            'permissions' => $this->auth->permissions(),
            'site'        => $site,
        ]);
    }

    public function menus(): void
    {
        $this->success(['list' => $this->auth->menus()]);
    }

    /**
     * 系统通知与巡检快捷代理
     * GET /adminapi/index/notices
     */
    public function notices(): void
    {
        app(Dashboard::class)->notices();
    }

    /**
     * 全栈缓存清理快捷代理
     * POST /adminapi/index/clearCache
     */
    public function clearCache(): void
    {
        app(Dashboard::class)->clearCache();
    }
}
