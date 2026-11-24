<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\common\model\system\Dict as DictModel;
use app\common\model\system\DictItem as DictItemModel;
use app\common\service\DictService;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\facade\Db;
use think\Model;

/**
 * 通用数据字典控制器
 *
 * 节点：system/dict/{index|read|add|edit|del}
 */
class Dict extends AdminController
{
    use CrudTrait {
        del as protected crudDel;
        multi as protected crudMulti;
    }

    protected array $searchFields = ['name', 'code', 'remark'];
    protected array $filterFields = ['id', 'name', 'code', 'status', 'create_time'];
    protected array $sortableFields = ['id', 'sort', 'create_time'];
    protected array $allowFields = ['name', 'code', 'remark', 'status', 'sort'];
    protected string|array $defaultSort = ['sort' => 'asc', 'id' => 'asc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new DictModel();
    }

    public function multi(): void
    {
        try {
            $this->crudMulti();
        } finally {
            DictService::clearCache();
        }
    }

    protected function afterWrite(Model $row, array $data, bool $isNew): void
    {
        DictService::clearCache();
    }

    /**
     * 删除字典分类时级联删除明细项
     */
    public function del(): void
    {
        $this->ensurePost();
        $ids = $this->parseIds($this->request->post('ids', $this->request->post('id')));
        if (empty($ids)) {
            throw new ApiException('请选择要删除的字典分类');
        }

        Db::transaction(function () use ($ids) {
            DictItemModel::whereIn('dict_id', $ids)->delete();
            DictModel::whereIn('id', $ids)->delete();
        });
        DictService::clearCache();

        $this->success(null, '删除成功');
    }

    /**
     * 获取指定字典分类的字典项列表
     * GET /adminapi/system.dict/items?dict_id=1
     */
    public function items(): void
    {
        $dictId = (int) $this->request->get('dict_id', 0);
        if ($dictId <= 0) {
            throw new ApiException('缺少字典ID参数');
        }

        $list = DictService::getItemsByDictId($dictId);

        $this->success(['list' => $list, 'total' => count($list)]);
    }

    /**
     * 新增字典项
     * POST /adminapi/system.dict/itemAdd
     */
    public function itemAdd(): void
    {
        $this->ensurePost();
        $data = $this->request->post();
        $dictId = (int) ($data['dict_id'] ?? 0);
        if ($dictId <= 0) {
            throw new ApiException('请选择所属字典分类');
        }

        $label = trim((string) ($data['label'] ?? ''));
        $value = trim((string) ($data['value'] ?? ''));
        if ($label === '' || $value === '') {
            throw new ApiException('字典项文本与键值不能为空');
        }

        // 校验同一个字典下 value 不重复
        $exist = DictItemModel::where('dict_id', $dictId)->where('value', $value)->find();
        if ($exist) {
            throw new ApiException("字典键值 [{$value}] 在该分类下已存在");
        }

        $item = new DictItemModel();
        $item->save([
            'dict_id'   => $dictId,
            'label'     => $label,
            'value'     => $value,
            'tag_type'  => trim((string) ($data['tag_type'] ?? 'primary')),
            'status'    => isset($data['status']) ? (int) $data['status'] : 1,
            'sort'      => (int) ($data['sort'] ?? 0),
            'remark'    => trim((string) ($data['remark'] ?? '')),
        ]);

        $this->success(['id' => $item->id], '添加字典项成功');
    }

    /**
     * 编辑字典项
     * POST /adminapi/system.dict/itemEdit
     */
    public function itemEdit(): void
    {
        $this->ensurePost();
        $id = (int) ($this->request->post('id') ?? $this->request->get('id', 0));
        if ($id <= 0) {
            throw new ApiException('缺少字典项ID');
        }

        $item = DictItemModel::find($id);
        if (!$item) {
            throw new ApiException('字典项不存在');
        }

        $data = $this->request->post();
        $value = isset($data['value']) ? trim((string) $data['value']) : (string) $item->value;
        if (isset($data['value']) && $value === '') {
            throw new ApiException('字典键值不能为空');
        }

        // 检查同字典下其他项的 value 是否冲突
        if (isset($data['value'])) {
            $exist = DictItemModel::where('dict_id', $item->dict_id)
                ->where('value', $value)
                ->where('id', '<>', $id)
                ->find();
            if ($exist) {
                throw new ApiException("字典键值 [{$value}] 在该分类下已存在");
            }
        }

        $allowed = ['label', 'value', 'tag_type', 'status', 'sort', 'remark'];
        $saveData = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $saveData[$f] = $data[$f];
            }
        }

        if (!empty($saveData)) {
            $item->save($saveData);
        }

        $this->success($item, '保存成功');
    }

    /**
     * 删除字典项
     * POST /adminapi/system.dict/itemDel
     */
    public function itemDel(): void
    {
        $this->ensurePost();
        $ids = $this->parseIds($this->request->post('ids', $this->request->post('id')));
        if (empty($ids)) {
            throw new ApiException('请选择要删除的字典项');
        }

        DictItemModel::whereIn('id', $ids)->delete();
        DictService::clearCache();
        $this->success(null, '删除成功');
    }

    /**
     * 根据字典编码批量获取字典映射数据
     * GET /adminapi/system.dict/map?codes=common_status,sys_yes_no,article_type
     */
    public function map(): void
    {
        $codesStr = (string) $this->request->get('codes', '');
        $codes = array_filter(array_map('trim', explode(',', $codesStr)));

        if (empty($codes)) {
            $this->success([]);
            return;
        }

        $this->success(DictService::getMapByCodes($codes));
    }
}
