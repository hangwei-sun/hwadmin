<?php
declare(strict_types=1);

namespace core\base;

use core\auth\AdminAuth;
use core\auth\TokenService;
use core\http\Result;

/**
 * 后台接口控制器基类（app/adminapi 与插件 controller/admin 下的控制器继承此类）
 *
 * 子类可声明：
 *   protected array $noNeedLogin = ['login'];   // 无需登录的操作，'*' 表示全部
 *   protected array $noNeedRight = ['menus'];   // 需登录但无需权限校验的操作
 *
 * 权限节点自动由类名 + 操作名推导，见 node()。
 */
abstract class AdminController extends BaseController
{
    protected array $noNeedLogin = [];
    protected array $noNeedRight = [];

    /** 是否记录写操作到 admin_log（子类如操作日志控制器可设为 false） */
    protected bool $recordLog = true;

    protected AdminAuth $auth;

    /** 敏感键名匹配正则（支持任意层级嵌套脱敏，P1-4） */
    private const SENSITIVE_KEY_PATTERN = '/(pass|pwd|secret|token|key|salt|authorization|credential|private)/i';

    protected function initialize(): void
    {
        parent::initialize();
        $this->auth = $this->app->make(AdminAuth::class);
        $this->auth->init(TokenService::fromRequest($this->request));

        if ($this->matchAction($this->noNeedLogin)) {
            return;
        }
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', Result::UNAUTHORIZED, null, 401);
        }
        if (!$this->matchAction($this->noNeedRight) && !$this->auth->check($this->node())) {
            $this->error('没有权限：' . $this->node(), Result::FORBIDDEN, null, 403);
        }
        if ($this->recordLog && !$this->request->isGet() && $this->request->method(true) !== 'OPTIONS') {
            $this->writeAdminLog();
        }
        if (!$this->request->isGet() && $this->request->method(true) !== 'OPTIONS') {
            $node = $this->node();
            if (str_starts_with($node, 'auth/') || str_starts_with($node, 'system/plugin/')) {
                $this->app->event->listen(\think\event\HttpEnd::class, static function (): void {
                    AdminAuth::clearCache();
                });
            }
        }
    }

    /**
     * 自动记录后台写操作日志（递归深层脱敏敏感字段 + 剥离 URL 敏感查询串 + 关联 RequestId 与异常追踪告警）
     */
    protected function writeAdminLog(string $title = ''): void
    {
        try {
            $params = $this->sanitizeLogData($this->request->param());
            $requestId = (string) ((property_exists($this->request, 'requestId') && $this->request->requestId !== '')
                ? $this->request->requestId
                : ($this->request->header('x-request-id', '') ?: ($this->request->param('_request_id') ?? '')));
            if ($requestId !== '') {
                $params['_request_id'] = $requestId;
            }
            $node = $this->node();
            if ($title === '') {
                $title = $this->auth->ruleTitle($node);
            }
            // 使用 baseUrl() 避免将 URL query 中的敏感参数原样写入 url 列
            $safeUrl = substr((string) $this->request->baseUrl(), 0, 1500);
            $contentStr = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($contentStr) > 60000) {
                $contentStr = substr($contentStr, 0, 60000) . '...[truncated]';
            }
            \think\facade\Db::name('admin_log')->insert([
                'admin_id'    => $this->auth->id(),
                'username'    => (string) ($this->auth->profile()['username'] ?? ''),
                'url'         => $safeUrl,
                'title'       => $title !== '' ? $title : $node,
                'content'     => $contentStr,
                'ip'          => (string) $this->request->ip(),
                'useragent'   => substr((string) $this->request->header('user-agent', ''), 0, 255),
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // S09 审计故障可发现：记录错误日志以供运维告警排查，不阻断正常业务
            $this->app->log->error('[audit] 记录管理员操作日志失败：' . $e->getMessage());
        }
    }

    /**
     * 递归脱敏请求参数（任意嵌套层级的密码、密钥、Token 均替换为 ***，超长文本自动截断）
     */
    protected function sanitizeLogData(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['_truncated' => true];
        }
        $cleaned = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (preg_match(self::SENSITIVE_KEY_PATTERN, $keyStr)) {
                $cleaned[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $cleaned[$key] = $this->sanitizeLogData($value, $depth + 1);
            } elseif (is_string($value)) {
                $cleaned[$key] = mb_strlen($value) > 1000 ? (mb_substr($value, 0, 1000) . '...(truncated)') : $value;
            } else {
                $cleaned[$key] = $value;
            }
        }
        return $cleaned;
    }

    /**
     * 当前请求的权限节点
     *
     * app\adminapi\controller\auth\Admin::index        => auth/admin/index
     * plugins\cms\controller\admin\Article::index      => plugin/cms/article/index
     * plugins\cms\controller\admin\article\Tag::index  => plugin/cms/article/tag/index
     */
    protected function node(): string
    {
        return static::controllerNode() . '/' . $this->actionName();
    }

    protected static function controllerNode(): string
    {
        $class = static::class;
        if (preg_match('/^plugins\\\\(\w+)\\\\controller\\\\admin\\\\(.+)$/', $class, $m)) {
            return 'plugin/' . strtolower($m[1]) . '/' . self::classToPath($m[2]);
        }
        if (preg_match('/^app\\\\adminapi\\\\controller\\\\(.+)$/', $class, $m)) {
            return self::classToPath($m[1]);
        }
        return self::classToPath(str_replace('\\', '/', $class));
    }

    private static function classToPath(string $relative): string
    {
        $parts = explode('\\', str_replace('/', '\\', $relative));
        return strtolower(implode('/', array_map(
            static fn ($p) => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $p) ?? $p),
            $parts
        )));
    }
}
