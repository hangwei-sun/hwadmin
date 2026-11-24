<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

class CreateCorporateSiteTables extends Migrator
{
    public function up(): void
    {
        // 栏目分类表
        if (!$this->hasTable('site_category')) {
            $this->table('site_category')
                ->addColumn('name', 'string', ['limit' => 64, 'comment' => '栏目名称'])
                ->addColumn('slug', 'string', ['limit' => 64, 'default' => '', 'comment' => 'URL别名'])
                ->addColumn('sort', 'integer', ['default' => 0, 'comment' => '排序权重'])
                ->addColumn('status', 'integer', ['default' => 1, 'comment' => '状态(1启用,0禁用)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['slug'], ['unique' => false])
                ->create();
        }

        // 官网文章/产品表
        if (!$this->hasTable('site_article')) {
            $this->table('site_article')
                ->addColumn('category_id', 'integer', ['default' => 0, 'comment' => '栏目分类ID'])
                ->addColumn('type', 'string', ['limit' => 32, 'default' => 'article', 'comment' => '类型(article文章|product产品)'])
                ->addColumn('title', 'string', ['limit' => 255, 'comment' => '文章/产品标题'])
                ->addColumn('slug', 'string', ['limit' => 128, 'default' => '', 'comment' => 'URL别名'])
                ->addColumn('summary', 'string', ['limit' => 500, 'default' => '', 'comment' => '文章摘要'])
                ->addColumn('content', 'text', ['null' => true, 'comment' => '详情正文'])
                ->addColumn('cover_image', 'string', ['limit' => 255, 'default' => '', 'comment' => '封面图片'])
                ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00', 'comment' => '产品展示价格'])
                ->addColumn('views', 'integer', ['default' => 0, 'comment' => '浏览量'])
                ->addColumn('seo_title', 'string', ['limit' => 255, 'default' => '', 'comment' => 'SEO标题'])
                ->addColumn('seo_keywords', 'string', ['limit' => 255, 'default' => '', 'comment' => 'SEO关键词'])
                ->addColumn('seo_description', 'string', ['limit' => 500, 'default' => '', 'comment' => 'SEO描述'])
                ->addColumn('canonical_url', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Canonical规范URL'])
                ->addColumn('status', 'integer', ['default' => 1, 'comment' => '发布状态(1发布,0草稿)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['category_id'], ['unique' => false])
                ->addIndex(['type'], ['unique' => false])
                ->create();
        }

        // 客户询盘留言表
        if (!$this->hasTable('site_inquiry')) {
            $this->table('site_inquiry')
                ->addColumn('name', 'string', ['limit' => 64, 'comment' => '客户姓名'])
                ->addColumn('mobile', 'string', ['limit' => 32, 'default' => '', 'comment' => '联系电话'])
                ->addColumn('email', 'string', ['limit' => 128, 'default' => '', 'comment' => '电子邮箱'])
                ->addColumn('company', 'string', ['limit' => 128, 'default' => '', 'comment' => '所属公司'])
                ->addColumn('target_id', 'integer', ['default' => 0, 'comment' => '关联文章/产品ID'])
                ->addColumn('content', 'text', ['null' => true, 'comment' => '询盘具体需求'])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '跟进备注'])
                ->addColumn('status', 'integer', ['default' => 0, 'comment' => '跟进状态(0待处理,1已联系,2已完成)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '提交时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->create();
        }

        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::ensureNode([
                    'type'     => 'dir',
                    'name'     => 'site',
                    'title'    => '企业官网管理',
                    'path'     => 'site',
                    'icon'     => 'global-line',
                    'weight'   => 580,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'site/article',
                            'title'     => '文章与产品管理',
                            'path'      => 'article',
                            'component' => '@/views/site/article/index.vue',
                            'icon'      => 'article-line',
                            'weight'    => 100,
                            'children'  => [
                                ['type' => 'button', 'name' => 'site/article/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'site/article/read', 'title' => '详情'],
                                ['type' => 'button', 'name' => 'site/article/add', 'title' => '新增'],
                                ['type' => 'button', 'name' => 'site/article/edit', 'title' => '编辑'],
                                ['type' => 'button', 'name' => 'site/article/del', 'title' => '删除'],
                                ['type' => 'button', 'name' => 'site/article/categories', 'title' => '栏目列表'],
                                ['type' => 'button', 'name' => 'site/article/saveCategory', 'title' => '保存栏目'],
                            ],
                        ],
                        [
                            'type'      => 'menu',
                            'name'      => 'site/inquiry',
                            'title'     => '客户询盘管理',
                            'path'      => 'inquiry',
                            'component' => '@/views/site/inquiry/index.vue',
                            'icon'      => 'question-answer-line',
                            'weight'    => 90,
                            'children'  => [
                                ['type' => 'button', 'name' => 'site/inquiry/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'site/inquiry/read', 'title' => '详情'],
                                ['type' => 'button', 'name' => 'site/inquiry/edit', 'title' => '跟进状态'],
                                ['type' => 'button', 'name' => 'site/inquiry/followUp', 'title' => '跟进备注'],
                                ['type' => 'button', 'name' => 'site/inquiry/del', 'title' => '删除'],
                            ],
                        ],
                    ],
                ]);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::removeNodes([
                    'site/article/index', 'site/article/read', 'site/article/add',
                    'site/article/edit', 'site/article/del', 'site/article/categories',
                    'site/article/saveCategory', 'site/article',
                    'site/inquiry/index', 'site/inquiry/read', 'site/inquiry/edit',
                    'site/inquiry/del', 'site/inquiry',
                    'site',
                ]);
            });
        }

        if ($this->hasTable('site_inquiry')) {
            $this->table('site_inquiry')->drop()->save();
        }
        if ($this->hasTable('site_article')) {
            $this->table('site_article')->drop()->save();
        }
        if ($this->hasTable('site_category')) {
            $this->table('site_category')->drop()->save();
        }
    }
}
