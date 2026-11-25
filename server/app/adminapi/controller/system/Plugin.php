<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use core\base\AdminController;
use core\exception\ApiException;
use core\http\Result;
use core\plugin\PluginInfo;
use core\plugin\PluginManager;
use core\plugin\SystemUpdater;
use core\support\OutboundHttpGuard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * 插件管理与生态市场中心
 *
 * GET  /adminapi/system.plugin/index          本地已装与已下载插件列表（丰富元数据）
 * GET  /adminapi/system.plugin/market         官方生态插件市场完整目录（双端动态聚合）
 * POST /adminapi/system.plugin/downloadMarket 市场插件在线购买/云端拉取ZIP/解压安装闭环
 * POST /adminapi/system.plugin/{install|uninstall|enable|disable|upgrade} { name }
 * GET  /adminapi/system.plugin/config?name=x  读取配置（含表单定义）
 * POST /adminapi/system.plugin/config         { name, config: {} } 保存配置
 * GET  /adminapi/system.plugin/checkUpdates   检测框架与插件最新版本与更新日志
 * POST /adminapi/system.plugin/setUpdateChannel 切换系统在线更新通道（stable | beta）
 * POST /adminapi/system.plugin/updateFramework 执行主框架在线升级与备份
 * POST /adminapi/system.plugin/updatePlugin    执行指定插件在线升级
 * POST /adminapi/system.plugin/rollbackUpdate 从备份快照一键回滚
 * GET  /adminapi/system.plugin/getBackups     获取系统更新历史备份快照
 */
class Plugin extends AdminController
{
    protected array $noNeedRight = [
        'index', 'market', 'checkUpdates', 'getBackups', 'microView',
    ];

    protected PluginManager $manager;
    protected SystemUpdater $updater;

    /**
     * 官方/默认插件视觉与归类元数据预设
     */
    private const PLUGIN_PRESETS = [
        'crud' => [
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'apps-2-line',
            'color'          => 'linear-gradient(135deg, #3b82f6 0%, #6366f1 100%)',
            'source'         => 'official',
            'source_label'   => '内置插件',
            'entry_path'     => '/plugin/crud',
            'tags'           => ['数据字典', '定时任务', '开发运维', '可视化CRUD'],
        ],
        'quiz' => [
            'category'       => 'business',
            'category_label' => '核心商业与运营',
            'icon'           => 'trophy-line',
            'color'          => 'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/quiz/dashboard',
            'tags'           => ['移动端H5', '排位赛', '积分兑奖'],
        ],
        'oss' => [
            'category'       => 'storage',
            'category_label' => '对象存储与云原生',
            'icon'           => 'cloud-line',
            'color'          => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/oss/config',
            'tags'           => ['腾讯云COS', '阿里云OSS', '七牛云'],
        ],
        'cms' => [
            'category'       => 'content',
            'category_label' => '内容管理与富文本',
            'icon'           => 'article-line',
            'color'          => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/cms/article',
            'tags'           => ['文章发布', '栏目管理', '标签库'],
        ],
        'sms' => [
            'category'       => 'message',
            'category_label' => '消息推送与通信',
            'icon'           => 'message-3-line',
            'color'          => 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/sms/log',
            'tags'           => ['腾讯云短信', 'TC3原生签名', '沙盒调试'],
        ],
        'editor' => [
            'category'       => 'content',
            'category_label' => '内容管理与富文本',
            'icon'           => 'edit-line',
            'color'          => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '',
            'tags'           => ['富文本', 'WangEditor', 'XSS过滤'],
        ],
        'demo' => [
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'function-line',
            'color'          => 'linear-gradient(135deg, #64748b 0%, #475569 100%)',
            'source'         => 'local',
            'source_label'   => '本地示范',
            'entry_path'     => '/demo/index',
            'tags'           => ['脚手架模板', '生命周期演示'],
        ],
        'webhook' => [
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'broadcast-line',
            'color'          => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/plugin/webhook/index',
            'tags'           => ['企业微信', '钉钉机器人', '飞书告警'],
        ],
        'ip_guard' => [
            'category'       => 'security',
            'category_label' => '安全审计与风控',
            'icon'           => 'shield-check-line',
            'color'          => 'linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/ip_guard/rules',
            'tags'           => ['IP黑白名单', 'CC限流防御', '拦截审计'],
        ],
        'ai_copilot' => [
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'brain-line',
            'color'          => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/ai_copilot/console',
            'tags'           => ['LLM大模型', 'SQL诊断', '表结构代码生成'],
        ],
        'bi_screen' => [
            'category'       => 'business',
            'category_label' => '核心商业与运营',
            'icon'           => 'tv-line',
            'color'          => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
            'source'         => 'official',
            'source_label'   => '官方精选',
            'entry_path'     => '/bi_screen/screen',
            'tags'           => ['监控大屏', '实时审计', '服务器负载'],
        ],
    ];

    /**
     * 官方生态市场应用本地备用目录（离线或云端网关异常时优雅降级）
     */
    private const MARKET_CATALOG = [
        [
            'name'           => 'webhook',
            'title'          => '群机器人 Webhook',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => '支持企业微信、钉钉、飞书机器人 Webhook 实时通知，支持关键操作事件订阅、消息队列异步派发与自定义加签密钥。',
            'author'         => 'OpenWebhook Org',
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'broadcast-line',
            'color'          => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 5.0,
            'downloads'      => 0,
            'tags'           => ['企业微信', '钉钉机器人', '飞书告警'],
            'features'       => ['三端机器人聚合', 'HMAC-SHA256安全加签', '可视化推送日志'],
        ],
        [
            'name'           => 'ip_guard',
            'title'          => 'IP 访问防火墙与安全审计',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => '企业级 IP 黑白名单过滤、CC 与高频防刷频流控、异常登录审计拦截与国家/地区 GeoIP 快速识别。',
            'author'         => 'Security Lab',
            'category'       => 'security',
            'category_label' => '安全审计与风控',
            'icon'           => 'shield-check-line',
            'color'          => 'linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)',
            'price_type'     => 'paid',
            'price'          => 99,
            'price_label'    => '¥99',
            'rating'         => 4.9,
            'downloads'      => 0,
            'tags'           => ['付费应用', 'IP黑白名单', 'CC防御'],
            'features'       => ['CIDR掩码支持', '滑动窗口CC防御', '商业License鉴权'],
        ],
        [
            'name'           => 'quiz',
            'title'          => '知识答题与互动抽奖',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => '全功能答题运营插件，支持题库导入、闯关计时、成绩排行榜与奖品核销。',
            'author'         => 'HwAdmin Official',
            'category'       => 'business',
            'category_label' => '核心商业与运营',
            'icon'           => 'trophy-line',
            'color'          => 'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 4.9,
            'downloads'      => 0,
            'tags'           => ['在线答题', '闯关积分', '奖品核销'],
            'features'       => ['题库批量导入', '段位排行榜', '积分核销闭环'],
        ],
        [
            'name'           => 'oss',
            'title'          => '对象云存储 (COS/OSS/七牛)',
            'version'        => '1.0.2',
            'latest_version' => '1.0.2',
            'description'    => '多云厂商对象存储无缝切换、直传签名与全局附件劫持驱动。',
            'author'         => 'HwAdmin Official',
            'category'       => 'storage',
            'category_label' => '对象存储与云原生',
            'icon'           => 'cloud-line',
            'color'          => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 4.9,
            'downloads'      => 0,
            'tags'           => ['阿里云OSS', '腾讯云COS', '七牛云'],
            'features'       => ['三大存储聚合', '前端直传签名', '沙盒模拟模式'],
        ],
        [
            'name'           => 'sms',
            'title'          => '腾讯云短信',
            'version'        => '1.0.0',
            'latest_version' => '1.0.0',
            'description'    => 'TC3-HMAC-SHA256 原生签名、沙盒模拟调试与防刷频日志审计。',
            'author'         => 'HwAdmin Official',
            'category'       => 'message',
            'category_label' => '消息推送与通信',
            'icon'           => 'message-3-line',
            'color'          => 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 5.0,
            'downloads'      => 0,
            'tags'           => ['腾讯云', '原生签名', '沙盒调试'],
            'features'       => ['零SDK冗余', '原生TC3签名', '防刷频限流'],
        ],
        [
            'name'           => 'cms',
            'title'          => '内容管理系统 (CMS)',
            'version'        => '2.1.2',
            'latest_version' => '2.1.2',
            'description'    => '轻量级门户内容管理插件，支持无限级栏目分类、多套主题模板与封面管理。',
            'author'         => 'HwAdmin Official',
            'category'       => 'content',
            'category_label' => '内容管理与富文本',
            'icon'           => 'article-line',
            'color'          => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 4.9,
            'downloads'      => 0,
            'tags'           => ['CMS', '文章发布', '多主题模板'],
            'features'       => ['多套官方响应式模板', '分主题独立内容配置', 'SEO友好'],
        ],
        [
            'name'           => 'crud',
            'title'          => '可视化全栈 CRUD 生成器',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => 'HwAdmin 官方旗舰级可视化全栈敏捷 CRUD 代码生成器，支持在线建表、代码预览与安全撤回。',
            'author'         => 'HwAdmin Team',
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'code-box-line',
            'color'          => 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 5.0,
            'downloads'      => 0,
            'tags'           => ['CRUD代码生成', '在线建表', '全栈提效'],
            'features'       => ['跨库在线建表', '前后端类型精准推导', '原子撤回'],
        ],
        [
            'name'           => 'editor',
            'title'          => '富文本编辑器套件',
            'version'        => '1.0.0',
            'latest_version' => '1.0.0',
            'description'    => '开箱即用的富文本编辑器扩展，内置服务端 XSS 消毒机制与媒体管理器集成。',
            'author'         => 'HwAdmin Official',
            'category'       => 'content',
            'category_label' => '内容管理与富文本',
            'icon'           => 'edit-line',
            'color'          => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
            'price_type'     => 'free',
            'price'          => 0,
            'price_label'    => '免费开源',
            'rating'         => 4.8,
            'downloads'      => 0,
            'tags'           => ['富文本', 'WangEditor', 'XSS过滤'],
            'features'       => ['拖拽批量上传', '服务端XSS消毒'],
        ],
        [
            'name'           => 'ai_copilot',
            'title'          => 'AI 助手与代码生成',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => '智能副驾驶，支持 Gemini、OpenAI、DeepSeek 多模型热切换与自动化代码提示。',
            'author'         => 'HwAdmin Official',
            'category'       => 'tools',
            'category_label' => '开发提效与工具',
            'icon'           => 'brain-line',
            'color'          => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
            'price_type'     => 'paid',
            'price'          => 199,
            'price_label'    => '¥199',
            'rating'         => 4.9,
            'downloads'      => 0,
            'tags'           => ['LLM大模型', '代码生成', 'Copilot'],
            'features'       => ['多模型支持', '自然语言生成CRUD', 'SQL优化建议'],
        ],
        [
            'name'           => 'bi_screen',
            'title'          => 'BI 数据可视化大屏',
            'version'        => '1.0.1',
            'latest_version' => '1.0.1',
            'description'    => '基于真实系统审计日志与硬件指标的深色大屏，支持全屏轮询、24小时访问趋势与接口排行。',
            'author'         => 'HwAdmin Official',
            'category'       => 'business',
            'category_label' => '核心商业与运营',
            'icon'           => 'tv-line',
            'color'          => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
            'price_type'     => 'paid',
            'price'          => 159,
            'price_label'    => '¥159',
            'rating'         => 4.8,
            'downloads'      => 0,
            'tags'           => ['大屏展示', '实时监控', '运维审计'],
            'features'       => ['实时PV/UV走势', '自适应全屏缩放', '硬件巡检指标'],
        ],
    ];

    protected function initialize(): void
    {
        parent::initialize();
        $this->manager = $this->app->make(PluginManager::class);
        $this->updater = $this->app->make(SystemUpdater::class);
    }

    /**
     * 获取本地已装与已下载插件列表（丰富元数据）
     */
    public function index(): void
    {
        $list = array_map(function (array $item): array {
            return $this->enrichPluginMeta($item);
        }, $this->manager->all());

        $this->page($list, count($list));
    }

    /**
     * 获取官方生态市场插件目录（实时向插件市场云网关同步并比对本地状态）
     */
    public function market(): void
    {
        $localAll = $this->manager->all();
        $localPlugins = array_column($localAll, null, 'name');

        // 优先请求云端市场 API 网关
        $gatewayRes = $this->requestMarketGateway('catalog');
        $rawList = [];
        $isMarketOnline = false;

        if (!empty($gatewayRes['code']) && (int) $gatewayRes['code'] === 1 && !empty($gatewayRes['data']['list'])) {
            $rawList = (array) $gatewayRes['data']['list'];
            $isMarketOnline = true;
        } else {
            // 云端离线或异常时优雅回退本地备用目录
            $rawList = self::MARKET_CATALOG;
        }

        $marketAuth = $this->getStoredMarketAuth();
        $licensedPlugins = (array) ($marketAuth['licensed_plugins'] ?? []);
        $licenseMap = (array) ($marketAuth['license_map'] ?? []);

        $list = [];
        foreach ($rawList as $item) {
            $name = (string) ($item['name'] ?? '');
            $local = $localPlugins[$name] ?? null;
            $isLocalDir = is_dir(plugin_path($name));

            $item['local_installed'] = $local !== null && !empty($local['installed']);
            $item['local_enabled']   = $local !== null && !empty($local['enabled']);
            $item['local_version']   = $local['installed_version'] ?? ($local['version'] ?? null);
            $item['upgradable']      = $local !== null && !empty($local['upgradable']);
            $item['delivery_mode']   = $isLocalDir ? 'local_ready' : 'cloud_market';
            $item['market_online']   = $isMarketOnline;
            $item['zip_ready']       = !empty($item['zip_ready']) || $isLocalDir;
            $item['price_label']     = !empty($item['is_free_launch'])
                ? '限时免费'
                : (!empty($item['price']) && (int) $item['price'] > 0 ? "¥{$item['price']}" : '免费开源');
            $item['has_license']     = in_array($name, $licensedPlugins, true) || isset($licenseMap[$name]);
            $item['license_key']     = $licenseMap[$name] ?? '';

            if ($local !== null) {
                $item['title'] = $local['title'] ?? $item['title'];
            }

            $list[] = $item;
        }

        $configuredUrl = trim((string) (\think\facade\Db::name("config")->where("name", "market_server_url")->value("value") ?: ""));
        $defaultUrl    = trim((string) config("hwadmin.market.default_url", ""));
        if ($defaultUrl === "") {
            $defaultUrl = trim((string) (env("MARKET_SERVER_URL", "") ?: env("HW_MARKET_GATEWAY", "") ?: "https://hw.baotoutv.cn"));
        }
        $activeGateway = $this->getActiveMarketGatewayUrl();
        $effectiveRoot = $configuredUrl ?: $defaultUrl;
        if ($effectiveRoot !== "" && str_contains($effectiveRoot, "/api/plugin/market/gateway")) {
            $effectiveRoot = preg_replace("#/api/plugin/market/gateway.*$#", "", $effectiveRoot) ?: $effectiveRoot;
        }
        $effectiveRoot = rtrim((string) $effectiveRoot, "/");
        $marketInfo    = !empty($gatewayRes["data"]["market_info"]) ? $gatewayRes["data"]["market_info"] : [];
        $portalUrl     = (string) ($marketInfo["portal_url"] ?? $effectiveRoot);
        $adminUrl      = (string) ($marketInfo["admin_url"] ?? ($effectiveRoot !== "" ? $effectiveRoot . "/admin/" : ""));

        $this->success([
            'list'                  => $list,
            'total'                 => count($list),
            'market_online'         => $isMarketOnline,
            'market_portal_url'     => $portalUrl,
            'market_admin_url'      => $adminUrl,
            'configured_market_url' => $configuredUrl,
            'default_market_url'    => $defaultUrl,
            'is_custom_market_url'  => $configuredUrl !== '',
            'active_gateway_url'    => $activeGateway,
        ]);
    }

    /**
     * 市场插件购买/下载解压/一键激活全闭环
     */
    public function downloadMarket(): void
    {
        $this->requireSuperAdmin();
        $name = trim((string) $this->request->param('name', ''));
        $licenseKey = trim((string) $this->request->param('license_key', ''));
        $actionType = trim((string) $this->request->param('action_type', 'install'));
        $forceDownload = (bool) $this->request->param('force_download', false);

        if ($name === '' || !preg_match(PluginInfo::NAME_PATTERN, $name)) {
            $this->error('非法的插件标识 name');
        }

        // 应用商城插件（无论免费或付费）均需先登录并绑定官网市场账号；本地插件请通过“我的应用”直接安装
        $auth = $this->getStoredMarketAuth();
        if (empty($auth['bound']) || empty($auth['token'])) {
            $this->error('安装应用商城上的插件需要先登录并绑定官网账号', 0, ['need_login' => true]);
        }

        // 若未手动传入 license_key，自动检测当前绑定的官网通行证名下是否已激活商业授权
        if ($licenseKey === '' && !empty($auth['license_map'][$name])) {
            $licenseKey = (string) $auth['license_map'][$name];
        }

        // 分支 1：在线申领/购买商业 License 授权码
        if ($actionType === 'purchase') {
            $headers = [];
            if (!empty($auth['token'])) {
                $headers[] = 'Authorization: Bearer ' . $auth['token'];
            }
            $siteDomain = trim((string) ($auth['site_domain'] ?? $this->request->host()));
            $purchaseRes = $this->requestMarketGateway('purchase', [
                'plugin_name'   => $name,
                'client_domain' => $siteDomain,
                'pay_type'      => 'free',
            ], 'POST', $headers);

            if (!empty($purchaseRes['code']) && (int) $purchaseRes['code'] === 1 && !empty($purchaseRes['data'])) {
                $this->success($purchaseRes['data'], $purchaseRes['msg'] ?? '授权成功');
                return;
            }

            $this->error($purchaseRes['msg'] ?? '授权失败，请确认已绑定官网账号且网络正常');
            return;
        }

        // 分支 2：执行安装与下载
        // 若本地源码目录不存在或强制拉取，先从市场云中心下载 ZIP 并解压
        $isLocalSourceReady = is_dir(plugin_path($name)) && is_file(plugin_path($name) . DIRECTORY_SEPARATOR . 'plugin.json');

        if (!$isLocalSourceReady || $forceDownload) {
            try {
                // 1. 从市场云端拉取 ZIP 发行包
                $tmpZipFile = $this->downloadPluginZipFromMarket($name, $licenseKey);

                // 2. 解压到 server/plugins/{name}/
                $this->extractPluginZip($tmpZipFile, $name);
            } catch (Throwable $e) {
                $errData = $e instanceof ApiException ? $e->getData() : null;
                $this->error('下载失败：' . $e->getMessage(), 0, $errData);
                return;
            }
        }

        // 3. 执行插件安装或启用闭环
        $actionStatus = 'installed';
        $actionMsg = "「{$name}」已安装";

        try {
            $state = \think\facade\Db::name('plugin')->where('name', $name)->find();
            if ($state === null) {
                // 未安装过，执行标准安装与启用
                $this->manager->install($name, true);
                $actionStatus = 'installed';
                $actionMsg = "「{$name}」已安装";
            } elseif (empty($state['status'])) {
                // 已安装但未启用
                $this->manager->enable($name);
                $actionStatus = 'enabled';
                $actionMsg = "「{$name}」已启用";
            } else {
                $actionStatus = 'already_active';
                $actionMsg = "「{$name}」已在运行";
            }
        } catch (Throwable $e) {
            if ($e instanceof \think\exception\HttpResponseException) {
                throw $e;
            }
            $this->error('安装失败：' . $e->getMessage());
            return;
        }

        $this->success([
            'name'   => $name,
            'status' => $actionStatus,
        ], $actionMsg);
    }

    public function install(): void
    {
        $this->manager->install($this->pluginName(), (bool) $this->request->post('enable', true));
        $this->success(null, '安装成功');
    }

    public function uninstall(): void
    {
        $this->manager->uninstall($this->pluginName());
        $this->success(null, '卸载成功');
    }

    public function enable(): void
    {
        $this->manager->enable($this->pluginName());
        $this->success(null, '已启用');
    }

    public function disable(): void
    {
        $this->manager->disable($this->pluginName());
        $this->success(null, '已禁用');
    }

    public function upgrade(): void
    {
        $this->manager->upgrade($this->pluginName());
        $this->success(null, '升级成功');
    }

    public function config(): void
    {
        $name = $this->pluginName();
        if ($this->request->isPost()) {
            $this->manager->setConfig($name, (array) $this->request->post('config', []));
            $this->success(null, '保存成功');
        }
        $this->success([
            'schema' => $this->manager->info($name)->config,
            'values' => $this->manager->getConfig($name),
        ]);
    }

    /**
     * 智能补充丰富插件视觉、来源与分类元数据
     */
    private function enrichPluginMeta(array $item): array
    {
        $name = (string) ($item['name'] ?? '');
        $preset = self::PLUGIN_PRESETS[$name] ?? [];

        $source = (string) ($item['source'] ?? ($preset['source'] ?? 'local'));
        $sourceLabel = (string) ($item['source_label'] ?? ($preset['source_label'] ?? ($source === 'official' ? '官方精选' : '本地自研')));
        $category = (string) ($item['category'] ?? ($preset['category'] ?? 'custom'));
        $categoryLabel = (string) ($item['category_label'] ?? ($preset['category_label'] ?? '自研扩展'));
        $icon = (string) ($item['icon'] ?? ($preset['icon'] ?? 'puzzle-2-line'));
        $color = (string) ($item['color'] ?? ($preset['color'] ?? 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)'));
        $entryPath = (string) ($item['entry_path'] ?? ($preset['entry_path'] ?? ''));
        $tags = !empty($item['tags']) && is_array($item['tags'])
            ? $item['tags']
            : ($preset['tags'] ?? ['自研扩展', '独立插件']);

        $item['source'] = $source;
        $item['source_label'] = $sourceLabel;
        $item['category'] = $category;
        $item['category_label'] = $categoryLabel;
        $item['icon'] = $icon;
        $item['color'] = $color;
        $item['entry_path'] = $entryPath;
        $item['tags'] = $tags;
        $item['status'] = !empty($item['enabled']) ? 1 : (int) ($item['status'] ?? 0);

        return $item;
    }

    protected function requireSuperAdmin(): void
    {
        if (!$this->auth->isSuperAdmin()) {
            $this->error('仅超级管理员可执行该操作', Result::FORBIDDEN, null, 403);
        }
    }

    private function pluginName(): string
    {
        $name = trim((string) $this->request->param('name', ''));
        if ($name === '' || !preg_match(PluginInfo::NAME_PATTERN, $name)) {
            $this->error('非法的插件标识 name');
        }
        if (!$this->request->isPost() && $this->actionName() !== 'config') {
            $this->error('请求方式错误', 0, null, 405);
        }
        return $name;
    }

    /**
     * 请求插件市场云端网关（优先 Docker 内网，超时 5 秒，支持自动优雅容灾）
     */
    protected function requestMarketGateway(string $endpoint, array $params = [], string $method = 'GET', array $headers = []): array
    {
        $baseUrls = $this->getMarketCandidateBaseUrls();
        if (empty($baseUrls)) {
            return ['code' => 0, 'msg' => '还没有商城地址，请先设置商城源', 'data' => null];
        }

        $clientHost = (string) $this->request->host();
        if ($clientHost !== '') {
            $headers[] = 'X-Client-Domain: ' . $clientHost;
        }
        $hasAuthHeader = false;
        foreach ($headers as $h) {
            if (str_starts_with(strtolower($h), 'authorization:')) {
                $hasAuthHeader = true;
                break;
            }
        }
        if (!$hasAuthHeader) {
            $auth = $this->getStoredMarketAuth();
            if (!empty($auth['token'])) {
                $headers[] = 'Authorization: Bearer ' . $auth['token'];
            }
        }

        $lastError = '无法连接到插件市场网关';
        foreach ($baseUrls as $baseUrl) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
            try {
                if ($method === 'POST') {
                    $resp = OutboundHttpGuard::request($url, 'POST', http_build_query($params), $headers, 5, 3, 2, 'HwAdmin-Market-Client/1.0');
                } else {
                    $qs = !empty($params) ? '?' . http_build_query($params) : '';
                    $resp = OutboundHttpGuard::request($url . $qs, 'GET', '', $headers, 5, 3, 2, 'HwAdmin-Market-Client/1.0');
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                continue;
            }

            if ($resp['ok'] && $resp['http_code'] >= 200 && $resp['http_code'] < 500) {
                $json = json_decode($resp['body'], true);
                if (is_array($json)) {
                    return $json;
                }
            }
            if ($resp['error'] !== '') {
                $lastError = $resp['error'];
            }
        }

        return ['code' => 0, 'msg' => $lastError, 'data' => null];
    }

    /**
     * 从官方插件市场下载指定插件 ZIP 发行包至本地临时文件
     */
    protected function downloadPluginZipFromMarket(string $pluginName, string $licenseKey = ''): string
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $pluginName)) {
            throw new ApiException('非法的插件标识');
        }

        $baseUrls = array_map(function (string $u): string {
            return rtrim($u, "/") . "/download";
        }, $this->getMarketCandidateBaseUrls());

        if (empty($baseUrls)) {
            throw new \core\exception\ApiException('还没有商城地址，无法下载');
        }

        $params = [
            'name'        => $pluginName,
            'license_key' => $licenseKey,
        ];

        $dlHeaders = [];
        $clientHost = (string) $this->request->host();
        if ($clientHost !== '') {
            $dlHeaders[] = 'X-Client-Domain: ' . $clientHost;
        }
        $auth = $this->getStoredMarketAuth();
        if (!empty($auth['token'])) {
            $dlHeaders[] = 'Authorization: Bearer ' . $auth['token'];
        }

        $tmpDir = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'temp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpZipPath = $tmpDir . DIRECTORY_SEPARATOR . "pkg_{$pluginName}_" . uniqid() . '.zip';

        $downloadSuccess = false;
        $errorMessage = '下载发行包失败';
        $needRelogin = false;

        foreach ($baseUrls as $baseUrl) {
            $url = $baseUrl . '?' . http_build_query($params);
            try {
                $dl = OutboundHttpGuard::downloadToFile($url, $tmpZipPath, $dlHeaders, 30, 5, 3);
            } catch (Throwable $e) {
                $errorMessage = $e->getMessage();
                @unlink($tmpZipPath);
                continue;
            }
            $res = $dl['ok'];
            $httpCode = $dl['http_code'];
            $contentType = $dl['content_type'];

            if ($res && $httpCode === 200 && (str_contains($contentType, 'zip') || str_contains($contentType, 'octet-stream') || (is_file($tmpZipPath) && filesize($tmpZipPath) > 500))) {
                // 验证是否是合法有效的 ZIP 压缩包
                $testZip = new ZipArchive();
                if ($testZip->open($tmpZipPath) === true) {
                    $testZip->close();
                    $downloadSuccess = true;
                    break;
                }
            }

            // 识别错误响应 JSON
            if ($httpCode === 401) {
                $needRelogin = true;
            }
            if (is_file($tmpZipPath) && filesize($tmpZipPath) < 5000) {
                $content = (string) file_get_contents($tmpZipPath);
                $errJson = json_decode($content, true);
                if (is_array($errJson)) {
                    if (!empty($errJson['msg'])) {
                        $errorMessage = $errJson['msg'];
                    }
                    if (!empty($errJson['data']['need_login']) || (int) ($errJson['code'] ?? 0) === 401) {
                        $needRelogin = true;
                    }
                }
            }
            @unlink($tmpZipPath);
        }

        if (!$downloadSuccess) {
            throw new ApiException($errorMessage, 0, $needRelogin ? ['need_login' => true] : null);
        }

        return $tmpZipPath;
    }

    /**
     * 将下载的 ZIP 压缩包安全校验并解压装载到 server/plugins/{name}/ 目录（防 ZipSlip 与符号链接逃逸）
     */
    protected function extractPluginZip(string $zipFilePath, string $pluginName): void
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $pluginName)) {
            throw new ApiException('非法的插件标识');
        }

        $targetDir = plugin_path($pluginName);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new ApiException("安装包解压失败，无法打开压缩包");
        }

        $tempExtractDir = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'tmp_extract_' . $pluginName . '_' . uniqid('', true);
        if (!@mkdir($tempExtractDir, 0755, true) && !is_dir($tempExtractDir)) {
            $zip->close();
            throw new ApiException("无法创建插件临时解压目录");
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawName = (string) $zip->getNameIndex($i);
                if ($rawName === '' || str_contains($rawName, "\0")) {
                    $zip->close();
                    throw new ApiException("安装包包含非法空字节路径条目");
                }
                $norm = str_replace('\\', '/', $rawName);
                if (str_starts_with($norm, '/') || preg_match('/^[A-Za-z]:/', $norm) || in_array('..', explode('/', $norm), true)) {
                    $zip->close();
                    throw new ApiException("安装包包含非法路径条目：{$rawName}");
                }
                $opsys = 0;
                $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                    $mode = (($attr >> 16) & 0xF000);
                    if ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                        $zip->close();
                        throw new ApiException("安装包包含非法符号链接条目：{$rawName}");
                    }
                }
                $entries[] = ['index' => $i, 'path' => $norm];
            }

            $baseReal = realpath($tempExtractDir);
            if ($baseReal === false) {
                $zip->close();
                throw new ApiException("临时解压目录不可访问");
            }

            foreach ($entries as $entry) {
                $rel = ltrim($entry['path'], '/');
                if ($rel === '') {
                    continue;
                }
                $dest = $tempExtractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($rel, '/'));
                if (str_ends_with($entry['path'], '/')) {
                    if (!is_dir($dest)) {
                        @mkdir($dest, 0755, true);
                    }
                    continue;
                }
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                $realParent = realpath($destDir);
                if ($realParent === false || (!str_starts_with($realParent, $baseReal . DIRECTORY_SEPARATOR) && $realParent !== $baseReal)) {
                    $zip->close();
                    throw new ApiException("安装包解压路径逃逸：{$entry['path']}");
                }
                $content = $zip->getFromIndex($entry['index']);
                if ($content === false) {
                    $zip->close();
                    throw new ApiException("读取压缩包条目失败：{$entry['path']}");
                }
                if (@file_put_contents($dest, $content) === false) {
                    $zip->close();
                    throw new ApiException("写入解压临时文件失败：{$entry['path']}");
                }
                @chmod($dest, 0644);
            }
            $zip->close();

            // 智能检测 ZIP 根部是否套了一层子目录
            $sourceDir = $tempExtractDir;
            if (!is_file($tempExtractDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                $subDirs = glob($tempExtractDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $sd) {
                    if (is_file($sd . DIRECTORY_SEPARATOR . 'plugin.json')) {
                        $sourceDir = $sd;
                        break;
                    }
                }
            }

            if (!is_file($sourceDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                throw new ApiException("安装包格式不正确：缺少 plugin.json");
            }

            // 拷贝至目标插件物理目录
            $this->copyDirectory($sourceDir, $targetDir);

            // 递归收敛安全权限（目录 0755 / 文件 0644）
            $this->chmodRecursive($targetDir, 0755, 0644);
        } finally {
            $this->deleteDirectory($tempExtractDir);
            if (is_file($zipFilePath)) {
                @unlink($zipFilePath);
            }
        }
    }

    protected function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            @mkdir($target, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    @mkdir($destPath, 0755, true);
                }
            } else {
                @copy($item->getPathname(), $destPath);
                @chmod($destPath, 0644);
            }
        }
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    protected function chmodRecursive(string $dir, int $dirMode = 0755, int $fileMode = 0644): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, $dirMode);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            @chmod($item->getPathname(), $item->isDir() ? $dirMode : $fileMode);
        }
    }

    /**
     * 规范化商城地址为标准 /api/plugin/market/gateway 前缀
     */
    protected function normalizeMarketGatewayUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!str_contains($raw, '://')) {
            $raw = 'http://' . $raw;
        }
        $clean = rtrim($raw, '/');
        if (!str_contains($clean, '/api/plugin/market/gateway')) {
            return $clean . '/api/plugin/market/gateway';
        }
        return $clean;
    }

    /**
     * 获取应用商城候选 API 网关列表（优先用户后台自定义覆盖，其次官方默认配置）
     */
    protected function getMarketCandidateBaseUrls(): array
    {
        $urls = [];
        try {
            $configured = trim((string) (\think\facade\Db::name("config")->where("name", "market_server_url")->value("value") ?: ""));
            if ($configured !== "") {
                $urls[] = $this->normalizeMarketGatewayUrl($configured);
            }
        } catch (Throwable) {
        }

        // 官方固定默认商城地址（config('hwadmin.market.default_url') / MARKET_SERVER_URL / HW_MARKET_GATEWAY / 官方正式域名）
        $defaultUrl = trim((string) config("hwadmin.market.default_url", ""));
        if ($defaultUrl === "") {
            $defaultUrl = trim((string) (env("MARKET_SERVER_URL", "") ?: env("HW_MARKET_GATEWAY", "") ?: "https://hw.baotoutv.cn"));
        }
        if ($defaultUrl !== "") {
            $urls[] = $this->normalizeMarketGatewayUrl($defaultUrl);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * 获取当前探测连通的最优网关 URL
     */
    protected function getActiveMarketGatewayUrl(): string
    {
        foreach ($this->getMarketCandidateBaseUrls() as $baseUrl) {
            $url = rtrim($baseUrl, "/") . "/catalog";
            try {
                $resp = OutboundHttpGuard::request($url, 'GET', '', [], 2, 1, 2, 'HwAdmin-Market-Client/1.0');
            } catch (Throwable) {
                continue;
            }
            if ($resp['ok'] && $resp['http_code'] >= 200 && $resp['http_code'] < 400) {
                return $baseUrl;
            }
        }
        $candidates = $this->getMarketCandidateBaseUrls();
        return $candidates[0] ?? "";
    }

    /**
     * 自定义切换应用商城云端服务地址（留空恢复官方默认渠道）
     * POST /adminapi/system.plugin/saveMarketUrl
     */
    public function saveMarketUrl(): void
    {
        $this->requireSuperAdmin();
        $marketServerUrl = trim((string) $this->request->post("market_server_url", ""));

        $now = time();
        $targetGateway = "";

        if ($marketServerUrl !== "") {
            if (!str_contains($marketServerUrl, "://")) {
                $marketServerUrl = "https://" . $marketServerUrl;
            }
            try {
                $marketServerUrl = OutboundHttpGuard::assertSafeUrl($marketServerUrl);
            } catch (ApiException $e) {
                $this->error($e->getMessage());
                return;
            }

            $cleanUrl = rtrim($marketServerUrl, "/");
            $targetGateway = $this->normalizeMarketGatewayUrl($cleanUrl);

            // 进行连通性探测
            $testUrl = $targetGateway . "/catalog";
            try {
                $probe = OutboundHttpGuard::request($testUrl, 'GET', '', [], 4, 2, 2, 'HwAdmin-Market-Client/1.0');
            } catch (ApiException $e) {
                $this->error("连接失败：" . $e->getMessage());
                return;
            }
            $res = $probe['ok'] ? $probe['body'] : false;
            $httpCode = $probe['http_code'];
            $err = $probe['error'];

            $marketTitle = "HwAdmin 官方商城";
            $connected = false;

            if ($res !== false && $httpCode >= 200 && $httpCode < 400) {
                $json = json_decode((string) $res, true);
                if (is_array($json) && !empty($json["code"]) && (int) $json["code"] === 1) {
                    $connected = true;
                    $marketTitle = $json["data"]["market_info"]["title"] ?? ($json["data"]["market_info"]["name"] ?? $marketTitle);
                }
            }

            if (!$connected) {
                $this->error("连接失败（" . ($err ?: "HTTP " . $httpCode) . "），请检查地址是否正确");
                return;
            }
        }

        // 保存或更新到 hw_config 表
        $exists = \think\facade\Db::name("config")->where("name", "market_server_url")->find();
        if ($exists) {
            \think\facade\Db::name("config")->where("name", "market_server_url")->update([
                "value"       => $marketServerUrl,
                "update_time" => $now,
            ]);
        } else {
            \think\facade\Db::name("config")->insert([
                "group"       => "basic",
                "name"        => "market_server_url",
                "title"       => "商城源地址",
                "type"        => "string",
                "value"       => $marketServerUrl,
                "options"     => "",
                "tip"         => "留空使用官方商城",
                "weight"      => 10,
                "create_time" => $now,
                "update_time" => $now,
            ]);
        }

        $msg = $marketServerUrl === ""
            ? "已恢复官方商城"
            : "商城源已更新，已连接「{$marketTitle}」";

        $this->success([
            "market_server_url" => $marketServerUrl,
            "target_gateway"    => $targetGateway,
        ], $msg);
    }

    /**
     * 解析当前站点域名（优先请求 Host，其次已绑定的官网账号站点域名）
     */
    protected function resolveCurrentSiteDomain(): string
    {
        $host = trim((string) $this->request->host(true));
        if ($host !== '') {
            return strtolower($host);
        }

        $auth = $this->getStoredMarketAuth();
        $stored = trim((string) ($auth['site_domain'] ?? ''));
        if ($stored !== '') {
            $parsedHost = parse_url(str_contains($stored, '://') ? $stored : 'https://' . $stored, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                return strtolower($parsedHost);
            }
        }

        return '';
    }

    /**
     * 全局检测主框架与插件在线更新状态
     * GET /adminapi/system.plugin/checkUpdates
     */
    public function checkUpdates(): void
    {
        $gateway = $this->getActiveMarketGatewayUrl();
        $siteDomain = $this->resolveCurrentSiteDomain();
        $result = $this->updater->checkUpdates($gateway, $siteDomain);
        $this->success($result);
    }

    /**
     * 切换系统在线更新通道（仅超级管理员可操作）
     * POST /adminapi/system.plugin/setUpdateChannel
     */
    public function setUpdateChannel(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $this->requireSuperAdmin();
        $channel = trim((string) $this->request->post('channel', ''));
        $saved = $this->updater->setUpdateChannel($channel);
        $this->success([
            'update_channel' => $saved,
        ], $saved === SystemUpdater::CHANNEL_BETA ? '已切换至内测版 (Beta) 更新通道' : '已切换至稳定版 (Stable) 更新通道');
    }

    /**
     * 执行主框架在线升级与自动备份
     * POST /adminapi/system.plugin/updateFramework
     */
    public function updateFramework(): void
    {
        $this->requireSuperAdmin();
        $downloadUrl = trim((string) $this->request->post('download_url', ''));
        $patchSha256 = trim((string) $this->request->post('patch_sha256', ''));
        $rawPreserve = $this->request->post('preserve_frontend', null);
        $preserveFrontend = ($rawPreserve === null || $rawPreserve === '')
            ? null
            : filter_var($rawPreserve, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $result = $this->updater->updateFramework(
            $downloadUrl !== '' ? $downloadUrl : null,
            $patchSha256 !== '' ? $patchSha256 : null,
            $preserveFrontend
        );
        $this->success($result, $result['msg'] ?? '升级成功');
    }

    /**
     * 执行指定插件在线升级
     * POST /adminapi/system.plugin/updatePlugin
     */
    public function updatePlugin(): void
    {
        $this->requireSuperAdmin();
        $name = trim((string) $this->request->post('name', ''));
        $licenseKey = trim((string) $this->request->post('license_key', ''));
        if ($name === '' || !preg_match(PluginInfo::NAME_PATTERN, $name)) {
            $this->error('非法的插件标识 name');
        }
        $auth = $this->getStoredMarketAuth();
        if ($licenseKey === '' && !empty($auth['bound']) && !empty($auth['license_map'][$name])) {
            $licenseKey = (string) $auth['license_map'][$name];
        }
        $marketToken = (!empty($auth['bound']) && !empty($auth['token'])) ? (string) $auth['token'] : '';
        $gateway = $this->getActiveMarketGatewayUrl();
        $result = $this->updater->updatePlugin($name, $gateway, $licenseKey, $marketToken);
        $this->success($result, $result['msg'] ?? '更新成功');
    }

    /**
     * 从历史备份快照一键回滚
     * POST /adminapi/system.plugin/rollbackUpdate
     */
    public function rollbackUpdate(): void
    {
        $this->requireSuperAdmin();
        $backupId = trim((string) $this->request->post('backup_id', ''));
        if ($backupId === '') {
            $this->error('缺少参数 backup_id');
        }
        $result = $this->updater->rollbackUpdate($backupId);
        $this->success($result, $result['msg'] ?? '回滚成功');
    }

    /**
     * 获取更新历史备份快照列表
     * GET /adminapi/system.plugin/getBackups
     */
    public function getBackups(): void
    {
        $backups = $this->updater->getBackups();
        $this->success($backups);
    }

    /**
     * 获取当前客户端已绑定的官网市场账号状态、站点域名及商业授权清单
     * GET /adminapi/system.plugin/marketAccount
     */
    public function marketAccount(): void
    {
        $auth = $this->getStoredMarketAuth();
        $auth['site_domain'] = $auth['site_domain'] ?: request()->domain();

        $this->success([
            'bound'            => (bool) ($auth['bound'] ?? false),
            'user'             => $auth['user'] ?? null,
            'site_domain'      => $auth['site_domain'],
            'bind_time'        => $auth['bind_time'] ?? null,
            'licensed_plugins' => (array) ($auth['licensed_plugins'] ?? []),
            'license_map'      => (array) ($auth['license_map'] ?? []),
        ]);
    }

    /**
     * 登录官网市场账号并绑定当前站点，同步商业授权
     * POST /adminapi/system.plugin/loginMarketAccount
     */
    public function loginMarketAccount(): void
    {
        $username   = trim((string) $this->request->post('username', ''));
        $password   = (string) $this->request->post('password', '');
        $siteDomain = trim((string) $this->request->post('site_domain', ''));
        if ($siteDomain === '') {
            $siteDomain = request()->domain();
        }

        if ($username === '' || $password === '') {
            $this->error('请输入账号和密码');
        }

        // 向官网插件市场网关发起绑定鉴权 (clientBind)
        $remoteRes = $this->requestMarketGateway('clientBind', [
            'username'    => $username,
            'password'    => $password,
            'site_domain' => $siteDomain,
        ], 'POST');

        if (empty($remoteRes['code']) || (int) $remoteRes['code'] !== 1 || empty($remoteRes['data'])) {
            $this->error($remoteRes['msg'] ?? '绑定失败，请检查账号密码或网络');
            return;
        }

        $remoteData     = (array) $remoteRes['data'];
        $serverLicenses = (array) ($remoteData['license_map'] ?? $remoteData['licenses'] ?? []);
        $token          = trim((string) ($remoteData['token'] ?? ''));
        if ($token === '') {
            $this->error('登录失败，请稍后重试');
            return;
        }

        $authData = [
            'bound'            => true,
            'token'            => $token,
            'user'             => (array) ($remoteData['user'] ?? [
                'username' => $username,
                'nickname' => $remoteData['nickname'] ?? $username,
            ]),
            'site_domain'      => $siteDomain,
            'bind_time'        => date('Y-m-d H:i:s'),
            'licensed_plugins' => (array) ($remoteData['licensed_plugins'] ?? array_keys($serverLicenses)),
            'license_map'      => $serverLicenses,
        ];

        $this->saveStoredMarketAuth($authData);

        $this->success([
            'bound'            => true,
            'user'             => $authData['user'],
            'site_domain'      => $authData['site_domain'],
            'bind_time'        => $authData['bind_time'],
            'licensed_plugins' => $authData['licensed_plugins'],
            'license_map'      => $authData['license_map'],
        ], '绑定成功，已同步已购应用');
    }

    /**
     * 一键向云端市场刷新同步当前账号名下最新商业授权
     * POST /adminapi/system.plugin/syncMarketLicenses
     */
    public function syncMarketLicenses(): void
    {
        $auth = $this->getStoredMarketAuth();
        if (empty($auth['bound']) || empty($auth['token'])) {
            $this->error('还没有绑定官网账号，请先登录');
        }

        $siteDomain = $auth['site_domain'] ?: request()->domain();

        // 通过请求头携带令牌调用 syncLicenses
        $remoteRes = $this->requestMarketGateway('syncLicenses', [
            'site_domain' => $siteDomain,
        ], 'POST', [
            'Authorization: Bearer ' . $auth['token'],
        ]);

        if (empty($remoteRes['code']) || (int) $remoteRes['code'] !== 1 || empty($remoteRes['data'])) {
            $isRelogin = (int) ($remoteRes['code'] ?? 0) === 401 || !empty($remoteRes['data']['need_login']);
            $this->error(
                (string) ($remoteRes['msg'] ?? ($isRelogin ? '官网商城登录已失效，请重新登录并绑定官网账号' : '同步失败，请检查登录状态或网络')),
                0,
                $isRelogin ? ['need_login' => true] : null
            );
            return;
        }

        $remoteData     = (array) $remoteRes['data'];
        $serverLicenses = (array) ($remoteData['license_map'] ?? $remoteData['licenses'] ?? []);
        $auth['licensed_plugins'] = !empty($remoteData['licensed_plugins'])
            ? (array) $remoteData['licensed_plugins']
            : array_keys($serverLicenses);
        $auth['license_map'] = $serverLicenses;
        if (!empty($remoteData['user'])) {
            $auth['user'] = array_merge((array) ($auth['user'] ?? []), (array) $remoteData['user']);
        }

        $auth['bind_time'] = date('Y-m-d H:i:s');
        $this->saveStoredMarketAuth($auth);

        $this->success([
            'bound'            => true,
            'user'             => $auth['user'],
            'site_domain'      => $auth['site_domain'],
            'bind_time'        => $auth['bind_time'],
            'licensed_plugins' => $auth['licensed_plugins'],
            'license_map'      => $auth['license_map'],
        ], '已同步已购应用');
    }

    /**
     * 在当前站点直接通过已绑定的官网账号向云端商城申领/获取指定插件授权凭证
     * POST /adminapi/system.plugin/claimLicense
     */
    public function claimLicense(): void
    {
        $pluginName   = trim((string) $this->request->post('plugin_name', ''));
        $clientDomain = trim((string) $this->request->post('client_domain', ''));
        if ($pluginName === '' || !preg_match('/^[a-zA-Z0-9_-]{2,50}$/', $pluginName)) {
            $this->error('无效的插件标识 plugin_name');
        }

        $auth = $this->getStoredMarketAuth();
        if (empty($auth['bound']) || empty($auth['token'])) {
            $this->error('请先登录并绑定官网市场账号后再申领授权', 0, ['need_login' => true]);
            return;
        }

        if ($clientDomain === '') {
            $clientDomain = (string) ($auth['site_domain'] ?: request()->domain());
        }

        $remoteRes = $this->requestMarketGateway('purchase', [
            'plugin_name'   => $pluginName,
            'client_domain' => $clientDomain,
            'pay_type'      => 'free',
        ], 'POST', [
            'Authorization: Bearer ' . $auth['token'],
        ]);

        if (empty($remoteRes['code']) || (int) $remoteRes['code'] !== 1 || empty($remoteRes['data'])) {
            $isRelogin = (int) ($remoteRes['code'] ?? 0) === 401 || !empty($remoteRes['data']['need_login']);
            $this->error(
                (string) ($remoteRes['msg'] ?? ($isRelogin ? '官网商城登录已失效，请重新登录并绑定官网账号' : '获取授权失败，请稍后重试')),
                0,
                $isRelogin ? ['need_login' => true] : (!empty($remoteRes['data']) ? (array) $remoteRes['data'] : null)
            );
            return;
        }

        $remoteData = (array) $remoteRes['data'];
        $licenseKey = (string) ($remoteData['license_key'] ?? '');
        if ($licenseKey !== '') {
            $auth['license_map'] = (array) ($auth['license_map'] ?? []);
            $auth['license_map'][$pluginName] = $licenseKey;
            $licensed = (array) ($auth['licensed_plugins'] ?? []);
            if (!in_array($pluginName, $licensed, true)) {
                $licensed[] = $pluginName;
            }
            $auth['licensed_plugins'] = $licensed;
            $this->saveStoredMarketAuth($auth);
        }

        $this->success([
            'plugin_name'      => $pluginName,
            'license_key'      => $licenseKey,
            'client_domain'    => (string) ($remoteData['client_domain'] ?? $clientDomain),
            'licensed_plugins' => (array) ($auth['licensed_plugins'] ?? []),
            'license_map'      => (array) ($auth['license_map'] ?? []),
            'reused'           => !empty($remoteData['reused']),
        ], (string) ($remoteRes['msg'] ?? "已获取授权凭证：{$licenseKey}"));
    }

    /**
     * 解绑当前客户端的官网市场账号
     * POST /adminapi/system.plugin/logoutMarketAccount
     */
    public function logoutMarketAccount(): void
    {
        $this->saveStoredMarketAuth(null);
        $this->success([
            'bound'            => false,
            'user'             => null,
            'site_domain'      => request()->domain(),
            'bind_time'        => null,
            'licensed_plugins' => [],
            'license_map'      => [],
        ], '已成功解绑官网市场账号');
    }

    /**
     * 获取安全持久化的官网市场账号绑定凭证
     */
    protected function getStoredMarketAuth(): array
    {
        $filePath = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'market_auth.json';
        $data = null;
        if (is_file($filePath)) {
            $content = (string) file_get_contents($filePath);
            $json = json_decode($content, true);
            if (is_array($json)) {
                $data = $json;
            }
        }

        if ($data === null) {
            try {
                $val = \think\facade\Db::name('config')->where('name', 'market_auth_info')->value('value');
                if ($val) {
                    $json = json_decode((string) $val, true);
                    if (is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $defaultDomain = request()->domain();
        if (empty($data) || empty($data['bound'])) {
            return [
                'bound'            => false,
                'token'            => '',
                'user'             => null,
                'site_domain'      => $defaultDomain,
                'bind_time'        => null,
                'licensed_plugins' => [],
                'license_map'      => [],
            ];
        }

        return [
            'bound'            => true,
            'token'            => (string) ($data['token'] ?? ''),
            'user'             => (array) ($data['user'] ?? []),
            'site_domain'      => (string) ($data['site_domain'] ?? $defaultDomain),
            'bind_time'        => (string) ($data['bind_time'] ?? date('Y-m-d H:i:s')),
            'licensed_plugins' => (array) ($data['licensed_plugins'] ?? []),
            'license_map'      => (array) ($data['license_map'] ?? []),
        ];
    }

    /**
     * 安全返回免编译微视图 HTML（兼容历史 Nginx 规则拦截 /plugins/*.html 的站点）
     */
    public function microView(): \think\Response
    {
        $name = strtolower(trim((string) $this->request->param('name', '')));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $name)) {
            return json(['code' => 0, 'msg' => '非法的插件标识'], 400);
        }

        $candidates = ['admin.html', 'web/admin.html'];
        $pluginRoot = plugin_path($name);
        $publicRoot = root_path() . 'public' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $name;

        foreach ($candidates as $rel) {
            $relOs = str_replace('/', DIRECTORY_SEPARATOR, $rel);
            foreach ([$pluginRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $relOs, $publicRoot . DIRECTORY_SEPARATOR . $relOs] as $file) {
                if (is_file($file)) {
                    $html = (string) file_get_contents($file);
                    return response($html, 200, [
                        'Content-Type'           => 'text/html; charset=utf-8',
                        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                }
            }
        }

        return json(['code' => 0, 'msg' => '未找到插件微视图页面'], 404);
    }

    /**
     * 安全持久化保存/清空官网市场账号凭证
     */
    protected function saveStoredMarketAuth(?array $data): void
    {
        $runtimeDir = root_path() . 'runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        $filePath = $runtimeDir . DIRECTORY_SEPARATOR . 'market_auth.json';

        if ($data === null) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            try {
                \think\facade\Db::name('config')->where('name', 'market_auth_info')->delete();
            } catch (Throwable $e) {
            }
            return;
        }

        file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            $now = time();
            $exists = \think\facade\Db::name('config')->where('name', 'market_auth_info')->find();
            $valStr = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($exists) {
                \think\facade\Db::name('config')->where('name', 'market_auth_info')->update([
                    'value'       => $valStr,
                    'update_time' => $now,
                ]);
            } else {
                \think\facade\Db::name('config')->insert([
                    'group'       => 'market',
                    'name'        => 'market_auth_info',
                    'title'       => '官网账号授权',
                    'type'        => 'string',
                    'value'       => $valStr,
                    'options'     => '',
                    'tip'         => '已绑定的官网账号与已购应用',
                    'weight'      => 20,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        } catch (Throwable $e) {
        }
    }

}
