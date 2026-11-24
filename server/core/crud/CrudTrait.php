<?php
declare(strict_types=1);

namespace core\crud;

use core\exception\ApiException;
use think\Model;

/**
 * 通用 CRUD 能力（内置 index/read/add/edit/del/multi/select）
 *
 * 路由（以后台为例）：
 *   GET  index         列表，data = { list, total }
 *   GET  read?id=1     详情
 *   POST add           新增
 *   POST edit?id=1     修改（body 只需包含要改的字段）
 *   POST del           删除，body: { ids: [1,2] } 或 "1,2"
 *   POST multi         批量更新状态/权重，body: { ids: [1,2], field: "status", value: 1 }
 *   GET  select        下拉远程选择器，支持 keyword、keyValue 主键回显与分页
 *
 * 安全基线（P1-1 & 2-P0-2）：
 *   1. 默认最小授权：子类必须显式配置 $allowFields（或 $addAllowFields / $editAllowFields），
 *      未配置时拒绝写入，严禁默认放开全表字段导致 Mass Assignment 越权；
 *   2. 筛选防盲注：filterableFields() 自动从表字段中剥离 $hiddenFields 与全局敏感列（password/salt/token）；
 *   3. 数据级权限隔离：支持通过 $dataScope = 'self' 及 $dataScopeField = 'admin_id' 开启行级归属隔离，
 *      自动在列表、详情、编辑、删除、批量更新中追加归属过滤，并在新增时自动注入当前管理员 ID。
 */
trait CrudTrait
{
    public function index(): void
    {
        $params = QueryParams::fromRequest($this->request);
        $query = $this->buildIndexQuery();
        $params->apply($query, $this->filterableFields(), $this->searchFields, $this->defaultSort, $this->sortableFields());
        $total = (clone $query)->removeOption('order')->count();
        $list = $query->page($params->page, $params->limit)->select();
        if ($this->hiddenFields) {
            $list->hidden($this->hiddenFields);
        }
        $this->page($list, $total);
    }

    public function read(): void
    {
        $row = $this->findOrFail();
        $this->success($row->hidden($this->hiddenFields));
    }

    public function add(): void
    {
        $this->ensurePost();
        $data = $this->filterWritable($this->request->post(), true);
        $data = $this->injectDataScopeOnAdd($data);
        $this->validateCrudData($data, 'add');
        $data = $this->beforeWrite($data, null);
        event('CrudBeforeInsert', ['controller' => static::class, 'data' => &$data]);
        $row = $this->model->transaction(function () use ($data) {
            $row = $this->model->newInstance();
            $row->save($data);
            $this->afterWrite($row, $data, true);
            return $row;
        });
        event('CrudAfterInsert', ['controller' => static::class, 'row' => $row]);
        $this->success(['id' => $row->getKey()], '新增成功');
    }

    public function edit(): void
    {
        $this->ensurePost();
        $row = $this->findOrFail();
        $data = $this->filterWritable($this->request->post(), false);
        // 数据归属保护：启用 self 时非超管严禁篡改归属字段
        if ($this->dataScope === 'self' && property_exists($this, 'auth') && isset($this->auth) && !$this->auth->isSuper() && $this->dataScopeField !== '') {
            unset($data[$this->dataScopeField]);
        }
        $this->validateCrudData($data, 'edit');
        $data = $this->beforeWrite($data, $row);
        event('CrudBeforeUpdate', ['controller' => static::class, 'row' => $row, 'data' => &$data]);
        $this->model->transaction(function () use ($row, $data) {
            $row->save($data);
            $this->afterWrite($row, $data, false);
        });
        event('CrudAfterUpdate', ['controller' => static::class, 'row' => $row]);
        $this->success(null, '保存成功');
    }

    public function del(): void
    {
        $this->ensurePost();
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            throw new ApiException('请选择要删除的记录');
        }
        $pk = $this->model->getPk();
        $deleted = [];
        $this->model->transaction(function () use ($pk, $ids, &$deleted) {
            foreach ($this->buildIndexQuery()->whereIn($pk, $ids)->select() as $row) {
                $rowId = (int) $row->getData($pk);
                if ($row->delete()) {
                    $deleted[] = $rowId;
                }
            }
            if ($deleted) {
                $this->afterDelete($deleted);
            }
        });
        event('CrudAfterDelete', ['controller' => static::class, 'ids' => $deleted]);
        $this->success(['count' => count($deleted)], '删除成功');
    }

    /**
     * 批量字段快速更新（如列表开关切换 status 或修改排序 weight）
     * 字段必须位于 $this->multiFields 与 $this->allowFields 交集中，且受数据范围约束
     */
    public function multi(): void
    {
        $this->ensurePost();
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            throw new ApiException('请选择要操作的记录');
        }
        $field = (string) $this->request->post('field', 'status');
        $value = $this->request->post('value');
        if (!is_scalar($value)) {
            throw new ApiException('更新值不合法');
        }
        $allowedMulti = array_intersect($this->multiFields, $this->editAllowFields ?: $this->allowFields, $this->tableFields());
        if (!in_array($field, $allowedMulti, true)) {
            throw new ApiException('不允许批量更新字段：' . $field);
        }
        $pk = $this->model->getPk();
        $count = 0;
        $this->model->transaction(function () use ($pk, $ids, $field, $value, &$count) {
            foreach ($this->buildIndexQuery()->whereIn($pk, $ids)->select() as $row) {
                $row->save([$field => $value]);
                $count++;
            }
        });
        $this->success(['count' => $count], '批量更新成功');
    }

    /**
     * 远程下拉选择器
     * 支持参数：keyword（搜索词）、keyValue（按主键值回显，逗号分隔）、labelField（显示字段，默认自动探测 name/title/username）
     */
    public function select(): void
    {
        $pk = (string) $this->model->getPk();
        $fields = $this->filterableFields();

        // S07 安全收口：labelField 必须在 filterableFields 内，且绝不能处于 hiddenFields 或敏感模式中
        $safeLabelFields = array_values(array_filter($fields, function ($f) {
            return is_string($f)
                && !in_array($f, $this->hiddenFields, true)
                && !preg_match('/(password|pass|pwd|salt|token|secret|private_key|api_key|access_key|auth_key|remember_token|rules|permissions)/i', $f);
        }));

        $reqLabel = (string) $this->request->param('labelField', '');
        $labelField = ($reqLabel !== '' && in_array($reqLabel, $safeLabelFields, true))
            ? $reqLabel
            : $this->detectLabelField($safeLabelFields, $pk);

        $query = $this->buildIndexQuery();
        $keyValue = trim((string) $this->request->param('keyValue', ''));
        if ($keyValue !== '') {
            $kvList = array_values(array_filter(array_map('trim', explode(',', $keyValue)), static fn ($v) => $v !== ''));
            if ($kvList) {
                $query->whereIn($pk, array_slice($kvList, 0, 200));
            }
        } else {
            $params = QueryParams::fromRequest($this->request);
            $searchFields = $this->searchFields ?: [$labelField];
            $params->apply($query, $fields, $searchFields, $this->defaultSort);
            $query->page($params->page, min(100, $params->limit));
        }

        $selectCols = array_values(array_unique(array_filter([$pk, $labelField, in_array('pid', $fields, true) ? 'pid' : null])));
        $total = (clone $query)->removeOption('order')->removeOption('page')->count();
        $rows = $query->field($selectCols)->select()->toArray();
        $this->page($rows, $total, ['keyField' => $pk, 'labelField' => $labelField]);
    }

    /**
     * 列表/详情/修改/删除共用的基础查询，自动应用 $dataScope 数据权限隔离
     */
    protected function buildIndexQuery()
    {
        $query = $this->model->db();
        if ($this->with) {
            $query->with($this->with);
        }
        return $this->applyDataScope($query);
    }

    /**
     * 应用数据范围隔离（支持子类重写扩展部门级/自定义级过滤）
     * S02 安全基线：空范围或未知范围一律默认拒绝 (Fail-Close)；必须在校验配置有效性后再进行权限分流。
     */
    protected function applyDataScope($query)
    {
        if ($this->dataScope === 'all') {
            return $query;
        }

        if ($this->dataScope !== 'self') {
            throw new ApiException("未定义或不支持的数据权限范围：{$this->dataScope}", 0, null, 500);
        }

        // 配置合法性校验先行（即使超管调用，配置错误也必须即刻暴露阻断）
        if ($this->dataScopeField === '') {
            throw new ApiException('数据权限配置错误：启用 self 范围必须配置 dataScopeField', 0, null, 500);
        }
        if (!in_array($this->dataScopeField, $this->tableFields(), true)) {
            throw new ApiException("数据权限配置错误：表 [{$this->model->getName()}] 不存在归属字段 [{$this->dataScopeField}]", 0, null, 500);
        }

        // 严格模式：启用非 all 数据权限时，必须具备合法认证上下文
        $isLoggedIn = false;
        if (property_exists($this, 'auth') && isset($this->auth)) {
            if (method_exists($this->auth, 'isLogin')) {
                $isLoggedIn = (bool) $this->auth->isLogin();
            } elseif (method_exists($this->auth, 'check')) {
                $isLoggedIn = (bool) $this->auth->check();
            }
        }
        if (!$isLoggedIn) {
            throw new ApiException('数据权限校验失败：缺少已认证的用户上下文', 0, null, 401);
        }

        if (method_exists($this->auth, 'isSuper') && $this->auth->isSuper()) {
            return $query;
        }

        $userId = (int) $this->auth->id();
        if ($userId <= 0) {
            throw new ApiException('数据权限校验失败：登录用户身份凭据无效', 0, null, 403);
        }
        return $query->where($this->dataScopeField, $userId);
    }

    /**
     * 新增记录时自动注入数据归属人字段（防止非超管伪造他人归属）
     */
    protected function injectDataScopeOnAdd(array $data): array
    {
        if ($this->dataScope === 'all') {
            return $data;
        }

        if ($this->dataScope !== 'self') {
            throw new ApiException("未定义或不支持的数据权限范围：{$this->dataScope}", 0, null, 500);
        }

        if ($this->dataScopeField === '') {
            throw new ApiException('数据权限配置错误：启用 self 范围必须配置 dataScopeField', 0, null, 500);
        }
        if (!in_array($this->dataScopeField, $this->tableFields(), true)) {
            throw new ApiException("数据权限配置错误：表 [{$this->model->getName()}] 不存在归属字段 [{$this->dataScopeField}]", 0, null, 500);
        }

        $isLoggedIn = false;
        if (property_exists($this, 'auth') && isset($this->auth)) {
            if (method_exists($this->auth, 'isLogin')) {
                $isLoggedIn = (bool) $this->auth->isLogin();
            } elseif (method_exists($this->auth, 'check')) {
                $isLoggedIn = (bool) $this->auth->check();
            }
        }
        if (!$isLoggedIn) {
            throw new ApiException('数据权限校验失败：缺少已认证的用户上下文', 0, null, 401);
        }

        $userId = (int) $this->auth->id();
        if ($userId <= 0) {
            throw new ApiException('数据权限校验失败：登录用户身份凭据无效', 0, null, 403);
        }

        $isSuper = method_exists($this->auth, 'isSuper') && $this->auth->isSuper();
        // 非超管强制注入本人 ID，超管若未指定则默认本人 ID
        if (!$isSuper || !isset($data[$this->dataScopeField])) {
            $data[$this->dataScopeField] = $userId;
        }
        return $data;
    }

    protected function validateCrudData(array $data, string $scene): void
    {
        if (empty($this->validateClass) || !class_exists($this->validateClass)) {
            return;
        }
        $v = validate($this->validateClass);
        if ($v->hasScene($scene)) {
            $v->scene($scene);
        }
        if (!$v->failException(true)->check($data)) {
            $error = $v->getError();
            throw new \think\exception\ValidateException(is_array($error) ? (string) reset($error) : (string) $error);
        }
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        return $data;
    }

    protected function afterWrite(Model $row, array $data, bool $isNew): void
    {
    }

    /**
     * 删除后处理（事务内），如清理关联数据与物理文件
     */
    protected function afterDelete(array $ids): void
    {
    }

    protected function findOrFail(): Model
    {
        $id = $this->request->param('id');
        $row = $id === null || $id === '' ? null : $this->buildIndexQuery()->where($this->model->getPk(), $id)->find();
        if (!$row) {
            throw new ApiException('记录不存在或无权访问');
        }
        return $row;
    }

    /**
     * 过滤可写字段（P1-1 默认拒绝：未显式声明白名单时直接抛错拦截）
     */
    protected function filterWritable(array $data, bool $isNew = true): array
    {
        $fields = $isNew
            ? ($this->addAllowFields ?: $this->allowFields)
            : ($this->editAllowFields ?: $this->allowFields);

        if (empty($fields)) {
            throw new ApiException('安全拦截：当前控制器未配置允许写入的字段白名单 (allowFields)');
        }

        // 双重保险：强制剔除主键与时间戳字段
        $protected = [$this->model->getPk(), 'create_time', 'update_time', 'delete_time'];
        $safeFields = array_diff($fields, $protected);

        return array_intersect_key($data, array_flip($safeFields));
    }

    /**
     * 获取允许列表筛选 (filter) 和排序 (sort) 的安全字段集合（2-P0-2）
     * 默认 = 表字段 − $hiddenFields − 全局敏感字段
     */
    protected function filterableFields(): array
    {
        $base = $this->filterFields ?: $this->tableFields();
        $candidates = array_diff($base, $this->hiddenFields);
        return array_values(array_filter($candidates, static function ($field): bool {
            return is_string($field)
                && !preg_match('/(password|pass|pwd|salt|token|secret|private_key|api_key|access_key|auth_key|remember_token|lock_password)/i', $field);
        }));
    }

    protected function sortableFields(): array
    {
        return !empty($this->sortableFields) ? $this->sortableFields : $this->filterableFields();
    }

    protected function tableFields(): array
    {
        return $this->model->db()->getTableFields();
    }

    protected function ensurePost(): void
    {
        if (!$this->request->isPost()) {
            throw new ApiException('请求方式错误', 0, null, 405);
        }
    }

    private function detectLabelField(array $fields, string $pk): string
    {
        foreach (['title', 'name', 'nickname', 'username', 'label'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }
        return in_array($pk, $fields, true) ? $pk : ($fields[0] ?? $pk);
    }
}
