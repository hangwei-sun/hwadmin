<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SiteArticle;
use app\common\model\SiteCategory;
use app\common\model\SiteInquiry;
use core\exception\ApiException;

/**
 * 官网业务服务层（负责文章/产品独立详情检索、阅读计数、询盘提交、页面级 SEO 及 Sitemap/Robots 生成）
 */
class SiteArticleService
{
    /**
     * 获取已发布文章或产品分页列表
     */
    public function getPublishedArticles(int $categoryId = 0, int $page = 1, int $limit = 10, string $type = ''): array
    {
        $query = SiteArticle::where('status', 1);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['seo'] = $this->formatSeoMetadata($item);
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取已发布产品列表
     */
    public function getPublishedProducts(int $categoryId = 0, int $page = 1, int $limit = 10): array
    {
        return $this->getPublishedArticles($categoryId, $page, $limit, 'product');
    }

    /**
     * 获取独立文章或产品详情（支持 id 或 slug，自动递增浏览量并附带完整页面级 SEO 元信息）
     */
    public function getArticleDetail(int $id, string $baseUrl = 'https://localhost', string $expectedType = '', string $slug = ''): array
    {
        $query = SiteArticle::where('status', 1);
        if ($id > 0) {
            $query->where('id', $id);
        } elseif ($slug !== '') {
            $query->where('slug', $slug);
        } else {
            throw new ApiException($expectedType === 'product' ? '产品不存在或已下架' : '文章不存在或已下架');
        }
        if ($expectedType !== '') {
            $query->where('type', $expectedType);
        }
        $article = $query->find();
        if (!$article) {
            throw new ApiException($expectedType === 'product' ? '产品不存在或已下架' : '文章不存在或已下架');
        }

        // 递增浏览量
        SiteArticle::where('id', (int) $article['id'])->inc('views')->update();
        $data = $article->toArray();
        $data['views'] = (int) ($data['views'] ?? 0) + 1;
        $data['seo'] = $this->formatSeoMetadata($data, $baseUrl);

        return $data;
    }

    /**
     * 获取独立产品详情
     */
    public function getProductDetail(int $id, string $baseUrl = 'https://localhost', string $slug = ''): array
    {
        return $this->getArticleDetail($id, $baseUrl, 'product', $slug);
    }

    /**
     * 组装页面级 SEO 结构（title / keywords / description / canonical）
     */
    public function formatSeoMetadata(array $item, string $baseUrl = 'https://localhost'): array
    {
        $id = (int) ($item['id'] ?? 0);
        $type = (string) ($item['type'] ?? 'article');
        $slug = trim((string) ($item['slug'] ?? ''));
        $domain = rtrim($baseUrl ?: 'https://localhost', '/');

        $defaultPath = $type === 'product'
            ? ($slug !== '' ? "/product/{$slug}" : "/product/{$id}")
            : ($slug !== '' ? "/article/{$slug}" : "/article/{$id}");
        $canonical = trim((string) ($item['canonical_url'] ?? ''));
        if ($canonical === '') {
            $canonical = $domain . $defaultPath;
        }

        return [
            'title'       => trim((string) ($item['seo_title'] ?? '')) ?: (string) ($item['title'] ?? ''),
            'keywords'    => trim((string) ($item['seo_keywords'] ?? '')),
            'description' => trim((string) ($item['seo_description'] ?? '')) ?: (string) ($item['summary'] ?? ''),
            'canonical'   => $canonical,
        ];
    }

    /**
     * 提交客户在线询盘
     */
    public function submitInquiry(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $mobile = trim((string) ($data['mobile'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $targetId = (int) ($data['target_id'] ?? 0);

        if ($name === '') {
            throw new ApiException('请输入联系人姓名');
        }
        if ($mobile === '' && $email === '') {
            throw new ApiException('请至少填写手机号或邮箱之一');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('电子邮箱格式不正确');
        }
        if ($content === '') {
            throw new ApiException('请输入您的询盘需求内容');
        }

        $inquiry = new SiteInquiry();
        $inquiry->save([
            'name'      => $name,
            'mobile'    => $mobile,
            'email'     => $email,
            'company'   => trim((string) ($data['company'] ?? '')),
            'target_id' => $targetId,
            'content'   => $content,
            'remark'    => '',
            'status'    => 0,
        ]);

        return $inquiry->toArray();
    }

    /**
     * 获取启用栏目分类列表
     */
    public function getCategories(): array
    {
        return SiteCategory::where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 构建 Sitemap 条目与标准 XML
     */
    public function buildSitemap(string $baseUrl = 'https://localhost'): array
    {
        $domain = rtrim($baseUrl ?: 'https://localhost', '/');
        $entries = [
            [
                'loc'        => $domain . '/',
                'changefreq' => 'daily',
                'priority'   => '1.0',
                'lastmod'    => date('Y-m-d'),
            ],
        ];

        $categories = $this->getCategories();
        foreach ($categories as $cat) {
            $slug = trim((string) ($cat['slug'] ?? '')) ?: (string) $cat['id'];
            $entries[] = [
                'loc'        => $domain . '/category/' . rawurlencode($slug),
                'changefreq' => 'weekly',
                'priority'   => '0.8',
                'lastmod'    => !empty($cat['update_time']) ? date('Y-m-d', (int) $cat['update_time']) : date('Y-m-d'),
            ];
        }

        $items = SiteArticle::where('status', 1)->order('id', 'desc')->select()->toArray();
        foreach ($items as $item) {
            $seo = $this->formatSeoMetadata($item, $domain);
            $entries[] = [
                'loc'        => $seo['canonical'],
                'changefreq' => ($item['type'] ?? 'article') === 'product' ? 'weekly' : 'monthly',
                'priority'   => ($item['type'] ?? 'article') === 'product' ? '0.9' : '0.7',
                'lastmod'    => !empty($item['update_time']) ? date('Y-m-d', (int) $item['update_time']) : date('Y-m-d'),
            ];
        }

        $xmlLines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($entries as $entry) {
            $loc = htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xmlLines[] = '  <url>';
            $xmlLines[] = "    <loc>{$loc}</loc>";
            $xmlLines[] = "    <lastmod>{$entry['lastmod']}</lastmod>";
            $xmlLines[] = "    <changefreq>{$entry['changefreq']}</changefreq>";
            $xmlLines[] = "    <priority>{$entry['priority']}</priority>";
            $xmlLines[] = '  </url>';
        }
        $xmlLines[] = '</urlset>';

        return [
            'entries' => $entries,
            'total'   => count($entries),
            'xml'     => implode("\n", $xmlLines),
        ];
    }

    /**
     * 构建 Robots.txt 规则
     */
    public function buildRobots(string $baseUrl = 'https://localhost'): array
    {
        $domain = rtrim($baseUrl ?: 'https://localhost', '/');
        $sitemapUrl = $domain . '/api/site/sitemap';
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin/',
            'Disallow: /adminapi/',
            "Sitemap: {$sitemapUrl}",
            '',
        ]);

        return [
            'sitemap_url' => $sitemapUrl,
            'content'     => $content,
        ];
    }

    /**
     * 管理员更新询盘跟进状态与备注（0待处理, 1已联系, 2已完成）
     */
    public function followUpInquiry(int $inquiryId, int $status, string $remark = ''): array
    {
        if (!in_array($status, [0, 1, 2], true)) {
            throw new ApiException('无效的询盘跟进状态');
        }
        $remark = trim($remark);
        if (mb_strlen($remark) > 255) {
            throw new ApiException('跟进备注不能超过 255 个字符');
        }
        $inquiry = SiteInquiry::where('id', $inquiryId)->find();
        if (!$inquiry) {
            throw new ApiException('询盘记录不存在');
        }
        $inquiry->save([
            'status' => $status,
            'remark' => $remark,
        ]);
        return $inquiry->toArray();
    }

    /* @hw-custom-start: site-service-extensions */
    // 企业官网扩展业务方法写在此处，模板增量升级时自动保留
    /* @hw-custom-end: site-service-extensions */
}
