<?php
declare(strict_types=1);

namespace plugins\cms\controller\admin;

use core\base\AdminController;
use core\crud\CrudTrait;
use plugins\cms\model\Category as CategoryModel;

/**
 * CMS 栏目分类管理后台控制器
 */
class Category extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['name', 'slug', 'description'];
    protected array $allowFields = [
        'pid', 'name', 'slug', 'type', 'template_scope',
        'icon', 'description', 'weight', 'status'
    ];
    protected string|array $defaultSort = ['weight' => 'desc', 'id' => 'asc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new CategoryModel();
    }
}
