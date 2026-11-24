<?php
declare(strict_types=1);

namespace plugins\cms\controller\api;

use app\common\model\User as UserModel;
use core\auth\TokenService;
use core\base\ApiController;
use plugins\cms\model\Article as ArticleModel;
use plugins\cms\model\Category as CategoryModel;
use plugins\cms\model\Banner as BannerModel;
use plugins\cms\model\Inquiry as InquiryModel;
use think\facade\Cache;
use think\facade\Db;

/**
 * CMS 内容、展厅与门户公开 API 控制器
 */
class Article extends ApiController
{
    protected array $noNeedLogin = ['index', 'detail', 'categories', 'siteInit', 'siteinit', 'like', 'submitInquiry', 'submitinquiry'];

    /**
     * 整站初始化配置与多模板数据聚合
     * GET /api/plugin/cms/article/siteInit?theme=corporate
     */
    public function siteInit(): void
    {
        $themeParam = trim((string) $this->request->param('theme', ''));
        $allowedThemes = ['corporate', 'blog', 'showcase'];

        // 读取插件后台保存的配置
        $pluginRow = Db::name('plugin')->where('name', 'cms')->find();
        $storedConfig = [];
        if ($pluginRow && !empty($pluginRow['config'])) {
            $storedConfig = is_array($pluginRow['config']) ? $pluginRow['config'] : (json_decode((string) $pluginRow['config'], true) ?: []);
        }

        $activeTemplate = in_array($themeParam, $allowedThemes, true)
            ? $themeParam
            : (string) ($storedConfig['active_template'] ?? 'corporate');

        // 默认全量配置合并（含全局通用与三套模板独立定制）
        $defaultConfig = [
            'site_name'             => 'HwAdmin 现代科技',
            'site_slogan'           => '驱动企业下一代数字化创新的全栈智能平台',
            'site_logo'             => '',
            'seo_keywords'          => 'HwAdmin, ThinkPHP 8, Vue 3, 智能后台, 博客, 企业官网, 产品展厅',
            'seo_description'       => '基于 ThinkPHP 8.1 + ThinkORM 4 + Vue 3 极速架构打造的全新一代现代化后台框架。',
            'icp_no'                => '京ICP备20260888号-1',
            'copyright'             => 'Copyright © 2026 HwAdmin Team. All rights reserved.',
            'show_theme_switcher'   => 1,
            'page_size'             => 10,
            'blog_site_name'            => '极客空间',
            'blog_site_slogan'          => '专注全栈工程、分布式架构与 AI 实践的技术博客',
            'blog_cta_text'             => '留言',
            'blog_hero_badge'           => '极客老王 · 个人技术空间',
            'blog_hero_title'           => '探索架构与工程美学的无垠边界',
            'blog_hero_sub'             => '汇聚微服务架构、Vue 3 现代化交互与大语言模型工程落地实战手记。',
            'blog_author'               => '极客老王',
            'blog_bio'                  => '保持专注，热爱开源，探索 AI 与工程架构的无垠边界。十年全栈工程师与技术博主。',
            'blog_avatar'               => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&q=80',
            'blog_github'               => 'https://github.com/hwadmin',
            'blog_email'                => 'admin@hwadmin.com',
            'blog_rank_title'           => '🔥 热门文章榜',
            'blog_tags_title'           => '🏷️ 热门标签',
            'blog_comments_title'       => '💬 读者留言',
            'blog_footer_service_title' => '关于博主与交流',
            'blog_footer_service_desc'  => '欢迎订阅专栏或留言交流工程架构、全栈开发与开源工具链实践。',
            'blog_seo_keywords'         => '技术博客, 全栈开发, 架构设计, ThinkPHP 8, Vue 3',
            'blog_seo_description'      => '极客老王的个人技术博客，记录全栈工程架构与 AI 落地实践。',
            'corp_site_name'            => 'HwAdmin 科技',
            'corp_site_slogan'          => '新一代企业级数字化高可用智能基座',
            'corp_cta_text'             => '在线预约',
            'corp_hero_badge'           => 'HwAdmin 2.0 旗舰发布',
            'corp_hero_title'           => '新一代企业级数字化高可用智能基座',
            'corp_hero_sub'             => 'ThinkPHP 8 + Vue 3 极速架构，双数据库原生兼容与零侵入插件体系，驱动企业数智化腾飞。',
            'corp_hero_btn1_text'       => '探索解决方案',
            'corp_hero_btn2_text'       => '预约专家演示',
            'corp_solutions_title'      => '核心业务解决方案矩阵',
            'corp_solutions_desc'       => '面向金融科技、智能制造与政企供应链，提供端到端高可靠自主可控数字底座。',
            'corp_cases_title'          => '标杆客户转型成功实践',
            'corp_cases_desc'           => '世界 500 强与行业先锋企业的信赖之选，见证数字化带来的真实跃升。',
            'corp_about_title'          => '引领下一代智能架构革新',
            'corp_contact_title'        => '预约产品演示与方案洽谈',
            'corp_contact_desc'         => '我们的行业数字化顾问将在 15 分钟内与您致电确认需求细节，提供量身定制的技术选型方案。',
            'corp_name'                 => '华网云擎科技有限公司',
            'corp_phone'                => '400-888-2026',
            'corp_email'                => 'business@hwadmin.com',
            'corp_address'              => '北京市海淀区中关村前沿科技软件园 A 座 8 层',
            'corp_stat_1_num'           => '99.999%',
            'corp_stat_1_label'         => '工业级高可用 SLA',
            'corp_stat_2_num'           => '500+',
            'corp_stat_2_label'         => '行业龙头标杆信赖',
            'corp_stat_3_num'           => '35ms',
            'corp_stat_3_label'         => '微中台平均超低延迟',
            'corp_stat_4_num'           => '24/7',
            'corp_stat_4_label'         => '专属运维专家保障',
            'corp_about_html'           => '<p>我们致力于通过新一代云原生、双数据库引擎原生兼容以及零侵入插件体系，为全球成长型企业与大型机构构筑智能、自主可控的现代数字化研发底座。</p>',
            'corp_footer_service_title' => '商务合作与咨询',
            'corp_footer_service_desc'  => '支持私有化部署、信创适配与政企项目定制，欢迎提交商务合作需求。',
            'corp_seo_keywords'         => '企业官网, 数字化底座, 微中台, ThinkPHP 8, Vue 3',
            'corp_seo_description'      => '为成长型企业与政企机构提供高可用、自主可控的数字化研发底座与解决方案。',
            'showcase_site_name'            => '智算严选展厅',
            'showcase_site_slogan'          => '官方原厂直供，支持政企批量阶梯报价与工程打样',
            'showcase_cta_text'             => '询价',
            'showcase_hero_badge'           => '🛍️ 2026 算力硬件与智能装备展厅',
            'showcase_title'                => '2026 智能硬件与算力装备精选展厅',
            'showcase_notice'               => '官方直供正品，现货库存充裕，支持政企客户按批量规模定制专属阶梯报价',
            'showcase_hotline'              => '010-8888-6666',
            'showcase_inquiry_tip'          => '提交采购意向后，专属销售顾问将在 15 分钟内与您致电确认技术选型与阶梯底价',
            'showcase_promise_1_title'      => '官方原厂正品',
            'showcase_promise_1_desc'       => '全系出厂 100% 严苛质检',
            'showcase_promise_2_title'      => '专属批量报价',
            'showcase_promise_2_desc'       => '支持阶梯折扣与政企采购单',
            'showcase_promise_3_title'      => '全国联保售后',
            'showcase_promise_3_desc'       => '3年质保与专属技术顾问',
            'showcase_promise_4_title'      => '软硬方案打样',
            'showcase_promise_4_desc'       => '免费提供工程镜像定制预刷',
            'showcase_footer_service_title' => '采购询价与支持',
            'showcase_footer_service_desc'  => '本展示站为纯产品与方案展示，不涉及在线支付；如需获取阶梯报价单请提交采购意向。',
            'showcase_seo_keywords'         => '产品展厅, 智能硬件, 边缘计算, 企业采购, 询价',
            'showcase_seo_description'      => '2026 智能硬件与算力装备精选展厅，原厂正品直供与政企批量定制报价。',
            'allow_member_register'         => 1,
        ];
        $config = array_merge($defaultConfig, $storedConfig);

        // 对应主题横幅
        $banners = BannerModel::where('status', 1)
            ->where(function ($q) use ($activeTemplate) {
                $q->where('template_code', $activeTemplate)
                  ->whereOr('template_code', 'all');
            })
            ->order('weight', 'desc')
            ->order('id', 'desc')
            ->select();

        // 对应主题分类
        $categories = CategoryModel::where('status', 1)
            ->where(function ($q) use ($activeTemplate) {
                $q->where('template_scope', $activeTemplate)
                  ->whereOr('template_scope', 'all');
            })
            ->order('weight', 'desc')
            ->order('id', 'asc')
            ->select();

        // 精选推荐列表（按当前主题偏好筛选）
        $recQuery = ArticleModel::with(['category'])
            ->where('status', 1)
            ->where('is_recommend', 1)
            ->order('weight', 'desc')
            ->order('id', 'desc');

        if ($activeTemplate === 'showcase') {
            $recQuery->where('content_type', 'product');
        } elseif ($activeTemplate === 'blog') {
            $recQuery->where('content_type', 'article');
        }

        $recommendList = $recQuery->limit(8)->select();
        $recommendList->append(['specs', 'gallery']);

        // 聚合热门标签
        $tagRows = Db::name('cms_article')
            ->where('status', 1)
            ->where('tags', '<>', '')
            ->column('tags');

        $tagCounts = [];
        foreach ($tagRows as $tagStr) {
            $parts = explode(',', str_replace('，', ',', (string) $tagStr));
            foreach ($parts as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
                }
            }
        }
        arsort($tagCounts);
        $hotTags = array_slice(array_keys($tagCounts), 0, 15);

        // 最新读者公开留言（仅返回已审核通过 status=1 的记录，并脱敏展示）
        $comments = InquiryModel::whereIn('scene', ['blog_comment', 'general'])
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        $recentComments = array_map(function ($c) {
            $contact = (string) ($c['contact'] ?? '');
            $masked = mb_strlen($contact) > 4
                ? mb_substr($contact, 0, 2) . '****' . mb_substr($contact, -2)
                : '***';
            return [
                'id'            => $c['id'],
                'target_title'  => $c['target_title'],
                'name'          => $c['name'],
                'masked_contact'=> $masked,
                'content'       => $c['content'],
                'reply_content' => $c['reply_content'],
                'status'        => $c['status'],
                'create_time'   => $c['create_time'],
            ];
        }, $comments);

        $this->success([
            'active_template' => $activeTemplate,
            'config'          => $config,
            'banners'         => $banners,
            'categories'      => $categories,
            'recommend_list'  => $recommendList,
            'hot_tags'        => $hotTags,
            'recent_comments' => $recentComments,
        ]);
    }

    /**
     * 前台文章与展示商品分页列表
     * GET /api/plugin/cms/article/index?category_id=1&content_type=product&keyword=测试&page=1&limit=10
     */
    public function index(): void
    {
        $categoryId = (int) $this->request->param('category_id', 0);
        $contentType = trim((string) $this->request->param('content_type', ''));
        $templateScope = trim((string) $this->request->param('template_scope', ''));
        $tag = trim((string) $this->request->param('tag', ''));
        $isRecommend = $this->request->param('is_recommend');
        $isHot = $this->request->param('is_hot');
        $keyword = trim((string) $this->request->param('keyword', ''));
        $page = max(1, (int) $this->request->param('page', 1));
        $limit = min(50, max(1, (int) $this->request->param('limit', 10)));

        $query = ArticleModel::with(['category'])
            ->where('status', 1)
            ->order('weight', 'desc')
            ->order('id', 'desc');

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($contentType !== '') {
            $query->where('content_type', $contentType);
        }

        if ($templateScope !== '' && $templateScope !== 'all') {
            $catIds = CategoryModel::where(function ($q) use ($templateScope) {
                $q->where('template_scope', $templateScope)
                  ->whereOr('template_scope', 'all');
            })->column('id');
            if (!empty($catIds)) {
                $query->whereIn('category_id', $catIds);
            }
        }

        if ($tag !== '') {
            $tag = mb_substr($tag, 0, 100);
            $escapedTag = addcslashes($tag, '%_\\');
            $query->whereLike('tags', "%{$escapedTag}%");
        }

        if ($isRecommend !== null && $isRecommend !== '') {
            $query->where('is_recommend', (int) $isRecommend);
        }

        if ($isHot !== null && $isHot !== '') {
            $query->where('is_hot', (int) $isHot);
        }

        if ($keyword !== '') {
            $keyword = mb_substr($keyword, 0, 100);
            $escapedKeyword = addcslashes($keyword, '%_\\');
            $query->where(function ($q) use ($escapedKeyword) {
                $q->whereLike('title', "%{$escapedKeyword}%")
                  ->whereOr('summary', 'like', "%{$escapedKeyword}%")
                  ->whereOr('tags', 'like', "%{$escapedKeyword}%")
                  ->whereOr('sku_code', 'like', "%{$escapedKeyword}%");
            });
        }

        $total = (clone $query)->removeOption('order')->count();
        $list = $query->page($page, $limit)->select();
        $list->append(['specs', 'gallery']);

        $this->page($list, $total);
    }

    /**
     * 前台启用的栏目分类列表
     * GET /api/plugin/cms/article/categories?template_scope=blog
     */
    public function categories(): void
    {
        $scope = trim((string) $this->request->param('template_scope', ''));
        $query = CategoryModel::where('status', 1)
            ->order('weight', 'desc')
            ->order('id', 'asc');

        if ($scope !== '' && $scope !== 'all') {
            $query->where(function ($q) use ($scope) {
                $q->where('template_scope', $scope)
                  ->whereOr('template_scope', 'all');
            });
        }

        $list = $query->select();
        $this->success($list);
    }

    /**
     * 前台内容与商品详情
     * GET /api/plugin/cms/article/detail?id=1
     */
    public function detail(): void
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            $this->error('参数错误', 0, null, 400);
        }

        /** @var ArticleModel|null $article */
        $article = ArticleModel::with(['category'])
            ->where('id', $id)
            ->where('status', 1)
            ->find();

        if (!$article) {
            $this->error('内容或商品不存在或已下架', 0, null, 404);
        }

        // 访问量自增
        $article->inc('views')->save();
        $article->append(['specs', 'gallery']);

        // 上一篇与下一篇
        $prev = ArticleModel::where('status', 1)
            ->where('category_id', $article->category_id)
            ->where('id', '<', $id)
            ->order('id', 'desc')
            ->field('id, title, cover')
            ->find();

        $next = ArticleModel::where('status', 1)
            ->where('category_id', $article->category_id)
            ->where('id', '>', $id)
            ->order('id', 'asc')
            ->field('id, title, cover')
            ->find();

        $data = $article->toArray();
        $data['prev'] = $prev;
        $data['next'] = $next;

        $this->success($data);
    }

    /**
     * 点赞文章或商品
     * POST /api/plugin/cms/article/like
     */
    public function like(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            $this->error('参数错误');
        }

        $article = ArticleModel::where('id', $id)->where('status', 1)->find();
        if (!$article) {
            $this->error('内容不存在');
        }

        $article->inc('likes')->save();

        $this->success([
            'id'    => $id,
            'likes' => (int) $article->likes,
        ], '感谢您的认可与点赞！');
    }

    /**
     * 提交留言或产品采购询单（支持会员 Token 自动识别与积分奖励）
     * POST /api/plugin/cms/article/submitInquiry
     */
    public function submitInquiry(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $scene = trim((string) $this->request->param('scene', 'general'));
        $targetId = (int) $this->request->param('target_id', 0);
        $targetTitle = trim((string) $this->request->param('target_title', ''));
        $name = trim((string) $this->request->param('name', ''));
        $contact = trim((string) $this->request->param('contact', ''));
        $company = trim((string) $this->request->param('company', ''));
        $content = trim((string) $this->request->param('content', ''));

        // 识别会员 Token（优先 X-Cms-User-Token，其次 Authorization: Bearer，再次 X-Token）
        $user = null;
        $token = (string) $this->request->header('x-cms-user-token', '');
        if ($token === '') {
            $auth = (string) $this->request->header('authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        if ($token === '') {
            $token = (string) $this->request->header('x-token', '');
        }

        if ($token !== '') {
            $tokenService = new TokenService();
            $row = $tokenService->verify('user', $token);
            if ($row && !empty($row['user_id'])) {
                $user = UserModel::find((int) $row['user_id']);
                if ($user && (int) $user->status !== 1) {
                    $user = null;
                }
            }
        }

        // 读取 CMS 插件设置中的互动规则
        $pluginRow = Db::name('plugin')->where('name', 'cms')->find();
        $config = [];
        if ($pluginRow && !empty($pluginRow['config'])) {
            $config = is_array($pluginRow['config']) ? $pluginRow['config'] : (json_decode((string) $pluginRow['config'], true) ?: []);
        }
        $commentRequireLogin = (int) ($config['comment_require_login'] ?? 0);
        $rewardScore = (int) ($config['interaction_reward_score'] ?? 10);

        if ($commentRequireLogin === 1 && !$user) {
            $this->error('站点已开启实名认证互动，请先登录会员后再提交', 401, null, 401);
        }

        $userId = $user ? (int) $user->id : 0;
        $userNickname = $user ? (string) ($user->nickname ?: $user->username) : '';

        // 已登录会员缺省回填
        if ($user) {
            if ($name === '') {
                $name = $userNickname;
            }
            if ($contact === '') {
                $contact = (string) ($user->mobile ?: $user->email);
            }
        }

        if ($name === '') {
            $this->error('请输入您的称呼或姓名');
        }
        if (mb_strlen($name) > 50) {
            $this->error('称呼或姓名不能超过 50 个字符');
        }
        if ($contact === '') {
            $this->error('请输入您的联系方式（手机/微信/邮箱）');
        }
        if (mb_strlen($contact) > 100) {
            $this->error('联系方式不能超过 100 个字符');
        }
        if (mb_strlen($company) > 100) {
            $this->error('公司名称不能超过 100 个字符');
        }
        if (mb_strlen($targetTitle) > 150) {
            $this->error('关联主题标题不能超过 150 个字符');
        }
        if ($content === '') {
            $this->error('请输入具体的留言或意向采购需求');
        }
        if (mb_strlen($content) > 2000) {
            $this->error('留言或需求内容不能超过 2000 个字符');
        }
        if (!in_array($scene, ['general', 'blog_comment', 'corp_contact', 'product_inquiry'], true)) {
            $scene = 'general';
        }

        // IP 与用户提交频控限流（60 秒窗口最多 10 次）
        $clientIp = (string) $this->request->ip();
        $ipRateKey = 'cms_inquiry_rate_ip_' . sha1($clientIp);
        $ipCount = (int) Cache::get($ipRateKey, 0);
        if ($ipCount >= 10) {
            $this->error('提交过于频繁，请 1 分钟后再试', 0, null, 429);
        }
        if ($userId > 0) {
            $userRateKey = 'cms_inquiry_rate_user_' . $userId;
            $userCount = (int) Cache::get($userRateKey, 0);
            if ($userCount >= 10) {
                $this->error('您的提交过于频繁，请 1 分钟后再试', 0, null, 429);
            }
            Cache::set($userRateKey, $userCount + 1, 60);
        }
        Cache::set($ipRateKey, $ipCount + 1, 60);

        $inquiry = InquiryModel::create([
            'scene'         => $scene,
            'target_id'     => $targetId,
            'target_title'  => $targetTitle,
            'user_id'       => $userId,
            'user_nickname' => $userNickname,
            'name'          => $name,
            'contact'       => $contact,
            'company'       => $company,
            'content'       => $content,
            'status'        => 0,
            'ip'            => $clientIp,
        ]);

        $responseData = [
            'id' => (int) $inquiry->id,
        ];

        // 若是注册会员且配置了奖励积分，发放互动积分并限制每日上限
        $actualReward = 0;
        if ($user && $rewardScore > 0) {
            $dailyCap = max(0, (int) ($config['daily_reward_score_limit'] ?? 50));
            $dailyKey = 'cms_inquiry_daily_score_' . $userId . '_' . date('Ymd');
            $awardedToday = (int) Cache::get($dailyKey, 0);
            $remaining = max(0, $dailyCap - $awardedToday);
            $actualReward = min($rewardScore, $remaining);

            if ($actualReward > 0) {
                $user->inc('score', $actualReward)->save();
                Cache::set($dailyKey, $awardedToday + $actualReward, 86400);
                $responseData['reward_score'] = $actualReward;
                $responseData['new_score'] = (int) $user->score;
            }
        }

        $msg = $actualReward > 0
            ? "提交成功！感谢您的互动，已获赠 +{$actualReward} 会员积分！"
            : '提交成功！我们的专属顾问将在第一时间与您联系。';

        $this->success($responseData, $msg);
    }
}
