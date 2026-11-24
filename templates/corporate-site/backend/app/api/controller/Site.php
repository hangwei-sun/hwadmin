<?php
declare(strict_types=1);

namespace app\api\controller;

use app\common\service\SiteArticleService;
use core\base\ApiController;

/**
 * 官网前台公开接口（免登录：文章/产品列表与独立详情、页面级 SEO、Sitemap、Robots 与在线询盘）
 */
class Site extends ApiController
{
    protected array $noNeedLogin = [
        'articles',
        'products',
        'detail',
        'productDetail',
        'categories',
        'submitInquiry',
        'seo',
        'sitemap',
        'robots',
    ];

    public function articles(): void
    {
        $categoryId = (int) $this->request->get('category_id', 0);
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 10);
        $type = trim((string) $this->request->get('type', ''));

        $service = new SiteArticleService();
        $this->success($service->getPublishedArticles($categoryId, $page, $limit, $type));
    }

    public function products(): void
    {
        $categoryId = (int) $this->request->get('category_id', 0);
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 10);

        $service = new SiteArticleService();
        $this->success($service->getPublishedProducts($categoryId, $page, $limit));
    }

    public function detail(): void
    {
        $id = (int) $this->request->get('id', 0);
        $slug = trim((string) $this->request->get('slug', ''));
        $baseUrl = (string) $this->request->domain();
        $service = new SiteArticleService();
        $this->success($service->getArticleDetail($id, $baseUrl, '', $slug));
    }

    public function productDetail(): void
    {
        $id = (int) $this->request->get('id', 0);
        $slug = trim((string) $this->request->get('slug', ''));
        $baseUrl = (string) $this->request->domain();
        $service = new SiteArticleService();
        $this->success($service->getProductDetail($id, $baseUrl, $slug));
    }

    public function categories(): void
    {
        $service = new SiteArticleService();
        $this->success($service->getCategories());
    }

    public function seo(): void
    {
        $id = (int) $this->request->get('id', 0);
        $slug = trim((string) $this->request->get('slug', ''));
        $baseUrl = (string) $this->request->domain();
        $service = new SiteArticleService();
        $detail = $service->getArticleDetail($id, $baseUrl, '', $slug);
        $this->success($detail['seo']);
    }

    public function sitemap(): void
    {
        $baseUrl = (string) $this->request->domain();
        $service = new SiteArticleService();
        $this->success($service->buildSitemap($baseUrl));
    }

    public function robots(): void
    {
        $baseUrl = (string) $this->request->domain();
        $service = new SiteArticleService();
        $this->success($service->buildRobots($baseUrl));
    }

    public function submitInquiry(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $data = $this->request->only(['name', 'mobile', 'email', 'company', 'target_id', 'content']);
        $service = new SiteArticleService();
        $inquiry = $service->submitInquiry($data);
        $this->success($inquiry, '询盘提交成功，我们会在 24 小时内与您联系');
    }
}
