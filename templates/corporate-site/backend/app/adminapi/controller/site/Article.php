<?php
declare(strict_types=1);

namespace app\adminapi\controller\site;

use app\common\model\SiteArticle as TargetModel;
use app\common\model\SiteCategory;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;

/**
 * 官网文章/产品与栏目管理
 */
class Article extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['title', 'summary', 'slug'];
    protected array $allowFields = [
        'category_id', 'type', 'title', 'slug', 'summary', 'content', 'cover_image',
        'price', 'seo_title', 'seo_keywords', 'seo_description', 'canonical_url', 'status',
    ];
    protected string|array $defaultSort = ['id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new TargetModel();
    }

    /**
     * 栏目分类列表
     */
    public function categories(): void
    {
        $list = SiteCategory::order('sort', 'desc')->order('id', 'asc')->select()->toArray();
        $this->success(['list' => $list, 'total' => count($list)]);
    }

    /**
     * 新增或更新栏目分类
     */
    public function saveCategory(): void
    {
        if (!$this->request->isPost()) {
            throw new ApiException('请求方式错误', 0, null, 405);
        }
        $id = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            throw new ApiException('栏目名称不能为空');
        }
        $payload = [
            'name'   => $name,
            'slug'   => trim((string) $this->request->post('slug', '')),
            'sort'   => (int) $this->request->post('sort', 0),
            'status' => (int) $this->request->post('status', 1),
        ];
        if ($id > 0) {
            $cat = SiteCategory::where('id', $id)->find();
            if (!$cat) {
                throw new ApiException('栏目不存在');
            }
            $cat->save($payload);
        } else {
            $cat = new SiteCategory();
            $cat->save($payload);
        }
        $this->success($cat->toArray(), '栏目保存成功');
    }
}
