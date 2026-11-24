<?php
declare(strict_types=1);

namespace plugins\cms\controller\admin;

use core\base\AdminController;
use plugins\cms\model\Article as ArticleModel;
use plugins\cms\model\Category as CategoryModel;
use plugins\cms\model\Banner as BannerModel;
use plugins\cms\model\Inquiry as InquiryModel;
use think\facade\Db;

/**
 * CMS 站点配置与三套模板管理后台控制器
 */
class Setting extends AdminController
{
    /**
     * 获取完整站点配置、模板元数据、横幅与统计看板
     * GET /adminapi/plugin/cms/setting/index
     */
    public function index(): void
    {
        $pluginRow = Db::name('plugin')->where('name', 'cms')->find();
        $storedConfig = [];
        if ($pluginRow && !empty($pluginRow['config'])) {
            $storedConfig = is_array($pluginRow['config']) ? $pluginRow['config'] : (json_decode((string) $pluginRow['config'], true) ?: []);
        }

        $activeTemplate = (string) ($storedConfig['active_template'] ?? 'corporate');

        // 系统当前 home_mode
        $homeModeRow = Db::name('config')->where('name', 'home_mode')->find();
        $homeMode = $homeModeRow ? (string) $homeModeRow['value'] : 'cms';

        // 三套标准模板元数据
        $templates = [
            [
                'code'        => 'corporate',
                'name'        => '企业官方网站',
                'subtitle'    => '全宽科技巨幕、实力指标带、解决方案矩阵与商务洽谈预约，政企商务首选',
                'badge'       => '官方主推',
                'features'    => ['全宽科技巨幕轮播', '四核实力指标看板', '痛点与解决方案', '标杆客户成功案例', '在线商务预约表单'],
                'preview_url' => '/?theme=corporate',
            ],
            [
                'code'        => 'blog',
                'name'        => '个人博客分享站',
                'subtitle'    => '双栏极客排版，沉浸式长文阅读、目录导航、点赞互动、彩色标签云与博主名片',
                'badge'       => '极客首选',
                'features'    => ['极简毛玻璃双栏排版', '博主全景实力名片', '文章阅读目录与点赞', '读者互动留言墙', '日间/夜间阅读模式'],
                'preview_url' => '/?theme=blog',
            ],
            [
                'code'        => 'showcase',
                'name'        => '购物产品展示站',
                'subtitle'    => '现代严选商品橱窗，全息规格参数、品质承诺条、多维筛选与零支付一键采购询价',
                'badge'       => '电商展示',
                'features'    => ['严选商品橱窗网格', '核心参数胶囊徽标', '结构化规格参数抽屉', '意向采购询价单', '不含支付纯展示'],
                'preview_url' => '/?theme=showcase',
            ],
        ];

        // 默认全量配置（含全局通用与三套模板独立定制参数）
        $defaultConfig = [
            // 全局基础与默认兜底配置
            'site_name'             => 'HwAdmin 现代科技',
            'site_slogan'           => '驱动企业下一代数字化创新的全栈智能平台',
            'site_logo'             => '',
            'seo_keywords'          => 'HwAdmin, ThinkPHP 8, Vue 3, 智能后台, 博客, 企业官网, 产品展厅',
            'seo_description'       => '基于 ThinkPHP 8.1 + ThinkORM 4 + Vue 3 极速架构打造的全新一代现代化后台框架。',
            'icp_no'                => '京ICP备20260888号-1',
            'copyright'             => 'Copyright © 2026 HwAdmin Team. All rights reserved.',
            'show_theme_switcher'   => 1,
            'page_size'             => 10,

            // 博客模板独立内容配置
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

            // 企业官网模板独立内容配置
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

            // 产品展厅模板独立内容配置
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

            // 会员体系与互动规则配置
            'allow_member_register'    => 1,
            'comment_require_login'    => 0,
            'interaction_reward_score' => 10,
        ];

        $mergedConfig = array_merge($defaultConfig, $storedConfig);

        // 横幅轮播列表
        $banners = BannerModel::order('weight', 'desc')->order('id', 'desc')->select();

        // 统计数据
        $stats = [
            'article_count'         => ArticleModel::where('content_type', 'article')->count(),
            'product_count'         => ArticleModel::where('content_type', 'product')->count(),
            'solution_count'        => ArticleModel::where('content_type', 'solution')->count(),
            'category_count'        => CategoryModel::count(),
            'inquiry_count'         => InquiryModel::count(),
            'pending_inquiry_count' => InquiryModel::where('status', 0)->count(),
            'member_count'          => Db::name('user')->count(),
            'total_views'           => (int) ArticleModel::sum('views'),
        ];

        $this->success([
            'active_template' => $activeTemplate,
            'home_mode'       => $homeMode,
            'templates'       => $templates,
            'config'          => $mergedConfig,
            'banners'         => $banners,
            'stats'           => $stats,
        ]);
    }

    /**
     * 保存站点主题与全部配置
     * POST /adminapi/plugin/cms/setting/save
     */
    public function save(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $activeTemplate = trim((string) $this->request->param('active_template', 'corporate'));
        $setAsHome = (int) $this->request->param('set_as_home', 0);
        $inputConfig = (array) $this->request->param('config', []);

        $inputConfig['active_template'] = $activeTemplate;

        // 更新 plugin 表的 config 字段
        $pluginRow = Db::name('plugin')->where('name', 'cms')->find();
        if ($pluginRow) {
            $oldConfig = !empty($pluginRow['config']) ? (is_array($pluginRow['config']) ? $pluginRow['config'] : (json_decode((string) $pluginRow['config'], true) ?: [])) : [];
            $finalConfig = array_merge($oldConfig, $inputConfig);

            Db::name('plugin')->where('name', 'cms')->update([
                'config'      => json_encode($finalConfig, JSON_UNESCAPED_UNICODE),
                'update_time' => time(),
            ]);
        }

        // 若选择将 CMS 设为首页
        if ($setAsHome === 1) {
            $hasHomeMode = Db::name('config')->where('name', 'home_mode')->find();
            if ($hasHomeMode) {
                Db::name('config')->where('name', 'home_mode')->update([
                    'value'       => 'cms',
                    'update_time' => time(),
                ]);
            } else {
                Db::name('config')->insert([
                    'name'        => 'home_mode',
                    'group'       => 'basic',
                    'title'       => '首页展示模式',
                    'type'        => 'string',
                    'value'       => 'cms',
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
        }

        $this->success(null, '站点配置保存成功');
    }

    /**
     * 新增或编辑横幅
     * POST /adminapi/plugin/cms/setting/saveBanner
     */
    public function saveBanner(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $id = (int) $this->request->param('id', 0);
        $templateCode = trim((string) ($this->request->param('template_code', '') ?: $this->request->param('template_scope', 'all')));
        $badge = trim((string) ($this->request->param('badge', '') ?: $this->request->param('badge_text', '')));
        $btnText = trim((string) ($this->request->param('btn_text', '') ?: $this->request->param('button_text', '')));
        $btnLink = trim((string) ($this->request->param('btn_link', '') ?: $this->request->param('button_url', '')));
        $weight = $this->request->param('weight', null);
        if ($weight === null) {
            $weight = $this->request->param('weigh', 100);
        }

        $data = [
            'template_code' => $templateCode ?: 'all',
            'title'         => trim((string) $this->request->param('title', '')),
            'subtitle'      => trim((string) $this->request->param('subtitle', '')),
            'badge'         => $badge,
            'image'         => trim((string) $this->request->param('image', '')),
            'btn_text'      => $btnText,
            'btn_link'      => $btnLink,
            'weight'        => (int) $weight,
            'status'        => (int) $this->request->param('status', 1),
        ];

        if ($data['title'] === '') {
            $this->error('横幅标题不能为空');
        }

        if ($id > 0) {
            $banner = BannerModel::find($id);
            if (!$banner) {
                $this->error('横幅不存在');
            }
            $banner->save($data);
        } else {
            BannerModel::create($data);
        }

        $this->success(null, '横幅保存成功');
    }

    /**
     * 删除横幅
     * DELETE /adminapi/plugin/cms/setting/delBanner
     */
    public function delBanner(): void
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            $this->error('参数错误');
        }

        BannerModel::where('id', $id)->delete();
        $this->success(null, '横幅已删除');
    }
}
