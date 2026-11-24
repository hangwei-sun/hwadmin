<?php
declare(strict_types=1);

namespace core\base;

use core\auth\TokenService;
use core\auth\UserAuth;
use core\http\Result;

/**
 * 前台/会员接口控制器基类（app/api 与插件 controller/api 下的控制器继承此类）
 *
 *   protected array $noNeedLogin = ['*'];  // 全部公开
 *   protected array $noNeedLogin = ['index', 'detail'];
 */
abstract class ApiController extends BaseController
{
    protected array $noNeedLogin = [];

    protected UserAuth $auth;

    protected function initialize(): void
    {
        parent::initialize();
        $requiresLogin = !$this->matchAction($this->noNeedLogin);
        $token = TokenService::fromRequest($this->request);

        if ($requiresLogin) {
            $this->auth = $this->app->make(UserAuth::class);
            $this->auth->init($token);
            if (!$this->auth->isLogin()) {
                $this->error('请先登录', Result::UNAUTHORIZED, null, 401);
            }
            return;
        }

        // 免登录接口（如 $noNeedLogin = ['*']）：跳过或延迟 Token 查库，仅在业务实际访问 $this->auth 时按需校验
        if ($token === '') {
            $this->auth = $this->app->make(UserAuth::class);
            return;
        }

        $tokens = $this->app->make(TokenService::class);
        $this->auth = new class($tokens, $token) extends UserAuth {
            private bool $lazyInitialized = false;

            public function __construct(TokenService $tokens, private string $pendingToken)
            {
                parent::__construct($tokens);
            }

            private function ensureLazyInit(): void
            {
                if (!$this->lazyInitialized) {
                    $this->lazyInitialized = true;
                    parent::init($this->pendingToken);
                }
            }

            public function init(string $token): bool
            {
                $this->lazyInitialized = true;
                return parent::init($token);
            }

            public function login(string $account, string $password, string $ip = ''): array
            {
                $this->lazyInitialized = true;
                return parent::login($account, $password, $ip);
            }

            public function logout(): void
            {
                $this->ensureLazyInit();
                parent::logout();
            }

            public function isLogin(): bool
            {
                $this->ensureLazyInit();
                return parent::isLogin();
            }

            public function id(): int
            {
                $this->ensureLazyInit();
                return parent::id();
            }

            public function profile(): array
            {
                $this->ensureLazyInit();
                return parent::profile();
            }
        };
    }
}
