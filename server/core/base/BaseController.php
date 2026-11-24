<?php
declare(strict_types=1);

namespace core\base;

use core\http\Result;
use think\App;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Request;
use think\Validate;

/**
 * 所有 HwAdmin 控制器的基类。
 *
 * 约定（重要，AI/开发者必读）：
 * - 基类及 Trait 中除构造函数外不得声明 public 方法，避免被 URL 直接访问；
 * - success()/error() 会直接抛出响应并终止执行，返回类型为 never；
 * - 子类初始化逻辑写在 initialize()，务必先调用 parent::initialize()。
 */
abstract class BaseController
{
    protected Request $request;

    /** @var \think\Model|null 当前模型（CrudTrait 使用，子类在 initialize() 中赋值） */
    protected ?\think\Model $model = null;

    /** 关键词搜索字段（CrudTrait 使用） */
    protected array $searchFields = [];

    /**
     * 允许列表筛选 (filter) 与排序 (sort) 的字段白名单（CrudTrait 使用，2-P0-2 防盲注提取）：
     * - 未声明时自动由表真实字段减去 $hiddenFields 及全局敏感字段模式生成。
     */
    protected array $filterFields = [];

    /** 允许列表排序 (sort) 的字段白名单（CrudTrait 使用，S07 显式收敛） */
    protected array $sortableFields = [];

    /**
     * 允许新增/修改的字段白名单（CrudTrait 使用，P1-1 默认最小授权）：
     * - 必须由子类显式声明允许写入的字段名数组；
     * - 空数组表示拒绝任何字段写入（防止忘记配置导致批量赋值越权）。
     */
    protected array $allowFields = [];

    /** 新增时允许写入的字段白名单（可选，优先于 $allowFields） */
    protected array $addAllowFields = [];

    /** 修改时允许写入的字段白名单（可选，优先于 $allowFields） */
    protected array $editAllowFields = [];

    /** 关联的通用验证器类（CrudTrait 使用，自动在 add/edit 写入前执行场景校验） */
    protected ?string $validateClass = null;

    /** 允许通过 multi 批量快速更新的字段白名单（默认仅允许 status, weight） */
    protected array $multiFields = ['status', 'weight'];

    /** 列表与详情中隐藏的字段（CrudTrait 使用） */
    protected array $hiddenFields = [];

    /** 列表预加载的关联（CrudTrait 使用） */
    protected array $with = [];

    /** 默认排序字段（CrudTrait 使用） */
    protected string|array $defaultSort = 'id';

    /**
     * 数据权限隔离范围（CrudTrait 使用）：
     * - 'all'  : 不限制（默认）
     * - 'self' : 仅限当前登录管理员本人创建的数据（超管自动豁免）
     */
    protected string $dataScope = 'all';

    /** 数据归属管理员字段名（当 $dataScope !== 'all' 时生效） */
    protected string $dataScopeField = 'admin_id';

    public function __construct(protected App $app)
    {
        $this->request = $this->app->request;
        $this->initialize();
    }

    protected function initialize(): void
    {
    }

    /**
     * 当前操作名（小写）
     */
    protected function actionName(): string
    {
        return strtolower($this->request->action());
    }

    /**
     * 判断当前操作是否命中列表（支持 '*' 通配）
     */
    protected function matchAction(array|string $actions): bool
    {
        $actions = is_string($actions) ? [$actions] : $actions;
        if (in_array('*', $actions, true)) {
            return true;
        }
        return in_array($this->actionName(), array_map('strtolower', $actions), true);
    }

    /**
     * 验证数据，失败抛出 ValidateException（由异常处理器转为 code=0 响应）
     * @param array                $data     待验证数据
     * @param string|array|Validate $validate 验证器类名（可带 .场景）或规则数组
     */
    protected function validate(array $data, string|array|Validate $validate, array $message = []): void
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } elseif ($validate instanceof Validate) {
            $v = $validate;
        } else {
            $scene = null;
            if (str_contains($validate, '.')) {
                [$validate, $scene] = explode('.', $validate, 2);
            }
            $v = new $validate();
            if ($scene) {
                $v->scene($scene);
            }
        }
        $v->message($message);
        if (!$v->failException(true)->check($data)) {
            throw new ValidateException($v->getError());
        }
    }

    protected function success(mixed $data = null, string $msg = 'ok'): never
    {
        throw new HttpResponseException(Result::success($data, $msg));
    }

    protected function error(string $msg = '操作失败', int $code = Result::FAIL, mixed $data = null, int $httpStatus = 200): never
    {
        throw new HttpResponseException(Result::error($msg, $code, $data, $httpStatus));
    }

    /**
     * 列表响应：data = { list, total }
     */
    protected function page(iterable $list, int $total, array $extra = []): never
    {
        throw new HttpResponseException(Result::page($list, $total, $extra));
    }
}
