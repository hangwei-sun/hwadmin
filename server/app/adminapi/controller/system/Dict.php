<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\common\model\system\Dict as DictModel;
use app\common\model\system\DictItem as DictItemModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\facade\Db;

/**
 * 通用数据字典控制器
 *
 * 节点：system/dict/{index|read|add|edit|del}
 */
class Dict extends AdminController
{
    use CrudTrait {
        del as protected crudDel;
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
        $this->ensureSeedDicts();
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

        $list = DictItemModel::where('dict_id', $dictId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();

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

        $dicts = DictModel::whereIn('code', $codes)
            ->where('status', 1)
            ->select();

        $result = [];
        foreach ($codes as $code) {
            $result[$code] = [];
        }

        if (!$dicts->isEmpty()) {
            $dictMap = [];
            $dictIds = [];
            foreach ($dicts as $dict) {
                $dictMap[$dict->id] = $dict->code;
                $dictIds[] = $dict->id;
            }

            $items = DictItemModel::whereIn('dict_id', $dictIds)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select();

            foreach ($items as $item) {
                $code = $dictMap[$item->dict_id] ?? '';
                if ($code !== '') {
                    $result[$code][] = [
                        'label'    => $item->label,
                        'value'    => $item->value,
                        'tag_type' => $item->tag_type,
                        'sort'     => $item->sort,
                    ];
                }
            }
        }

        $this->success($result);
    }

    /**
     * 兜底自动补齐初始默认字典
     */
    protected function ensureSeedDicts(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $count = DictModel::count();
            if ($count === 0) {
                $now = time();
                $defaultDicts = [
                    [
                        'name' => '通用状态',
                        'code' => 'common_status',
                        'remark' => '通用数据状态枚举',
                        'sort' => 1,
                        'items' => [
                            ['label' => '正常', 'value' => '1', 'tag_type' => 'success', 'sort' => 1],
                            ['label' => '禁用', 'value' => '0', 'tag_type' => 'danger', 'sort' => 2],
                        ],
                    ],
                    [
                        'name' => '系统是否',
                        'code' => 'sys_yes_no',
                        'remark' => '通用是否枚举标识',
                        'sort' => 2,
                        'items' => [
                            ['label' => '是', 'value' => '1', 'tag_type' => 'primary', 'sort' => 1],
                            ['label' => '否', 'value' => '0', 'tag_type' => 'info', 'sort' => 2],
                        ],
                    ],
                    [
                        'name' => '文章分类',
                        'code' => 'article_type',
                        'remark' => '官方资讯分类枚举',
                        'sort' => 3,
                        'items' => [
                            ['label' => '官方公告', 'value' => 'notice', 'tag_type' => 'warning', 'sort' => 1],
                            ['label' => '技术专栏', 'value' => 'tech', 'tag_type' => 'primary', 'sort' => 2],
                            ['label' => '版本发布', 'value' => 'release', 'tag_type' => 'success', 'sort' => 3],
                        ],
                    ],
                ];

                foreach ($defaultDicts as $d) {
                    $dict = new DictModel();
                    $dict->save([
                        'name' => $d['name'],
                        'code' => $d['code'],
                        'remark' => $d['remark'],
                        'status' => 1,
                        'sort' => $d['sort'],
                    ]);

                    foreach ($d['items'] as $it) {
                        $item = new DictItemModel();
                        $item->save([
                            'dict_id' => $dict->id,
                            'label' => $it['label'],
                            'value' => $it['value'],
                            'tag_type' => $it['tag_type'],
                            'status' => 1,
                            'sort' => $it['sort'],
                            'remark' => '',
                        ]);
                    }
                }
            }
        } catch (\Throwable) {
            // 忽略迁移前尚未建表时的异常
        }
    }
}
