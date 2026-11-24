<?php
declare(strict_types=1);

namespace plugins\cms\controller\admin;

use core\base\AdminController;
use core\crud\CrudTrait;
use plugins\cms\model\Article as ArticleModel;
use think\Model;

/**
 * CMS 内容与产品管理后台控制器
 */
class Article extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['title', 'summary', 'author', 'tags', 'sku_code'];
    protected array $allowFields = [
        'category_id', 'title', 'summary', 'content', 'cover',
        'content_type', 'author', 'tags', 'is_recommend', 'is_hot',
        'price_label', 'market_price_label', 'sku_code', 'badge_text',
        'specs_json', 'gallery_json', 'external_link', 'likes', 'views',
        'weight', 'status'
    ];
    protected array $with = ['category'];
    protected string|array $defaultSort = ['weight' => 'desc', 'id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new ArticleModel();
    }

    /**
     * 写入前数据清洗与 JSON 自动编码
     */
    protected function beforeWrite(array $data, ?Model $row): array
    {
        if (isset($data['specs_json']) && is_array($data['specs_json'])) {
            $data['specs_json'] = json_encode($data['specs_json'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['gallery_json']) && is_array($data['gallery_json'])) {
            $data['gallery_json'] = json_encode($data['gallery_json'], JSON_UNESCAPED_UNICODE);
        }
        return $data;
    }

    /**
     * 详情接口补充 specs 与 gallery 解析数据
     */
    public function read(): void
    {
        $row = $this->findOrFail();
        $row->append(['specs', 'gallery']);
        $this->success($row);
    }
}
