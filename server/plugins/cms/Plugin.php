<?php
declare(strict_types=1);

namespace plugins\cms;

use core\plugin\Plugin as BasePlugin;
use core\plugin\MenuImporter;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

/**
 * HwAdmin 官方企业级 CMS 插件主类（内置三套标准网站模板）
 */
class Plugin extends BasePlugin
{
    private const VIEW_SYNC_CACHE_KEY = 'hw:cms:view_sync_ver';
    public function __construct(\think\App $app, ?\core\plugin\PluginInfo $info = null)
    {
        if ($info === null) {
            $info = \core\plugin\PluginInfo::fromDirectory(__DIR__);
        }
        parent::__construct($app, $info);
    }
    /**
     * 声明监听的系统事件与钩子
     */
    public function events(): array
    {
        return [
            'AdminLoginAfter' => [
                function ($admin) {
                    $username = is_array($admin) ? ($admin['username'] ?? 'admin') : ($admin->username ?? 'admin');
                    $this->app->log->info("[CMS Plugin] 监听到管理员登录事件：{$username}");
                },
            ],
        ];
    }

    /**
     * 请求启动自愈：客户端在线升级当次请求内存中仍执行旧版 Plugin::upgrade()，
     * 升级后下一次 HTTP 请求进入 boot() 时自动检测并同步最新门户视图与默认短文案。
     * 通过版本哨兵缓存避免普通请求触发磁盘 filesize / md5_file 校验。
     */
    public function boot(): void
    {
        $ver = (string) ($this->info->version ?? '');
        $pubTarget = public_path() . 'plugins' . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'index.html';

        try {
            if ($ver !== '' && Cache::get(self::VIEW_SYNC_CACHE_KEY) === $ver && is_file($pubTarget)) {
                return;
            }
        } catch (Throwable) {
        }

        $src = __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($src)) {
            return;
        }

        $targets = [$pubTarget];
        $legacyView = root_path() . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($legacyView)) {
            $targets[] = $legacyView;
        }

        $srcSize = @filesize($src);
        $srcMd5 = null;
        $needsSync = false;

        foreach ($targets as $dst) {
            if (!is_file($dst) || @filesize($dst) !== $srcSize) {
                $needsSync = true;
                break;
            }
            $srcMd5 ??= @md5_file($src);
            if ($srcMd5 !== false && @md5_file($dst) !== $srcMd5) {
                $needsSync = true;
                break;
            }
        }

        if ($needsSync) {
            $this->syncFrontViews();
            $this->normalizeLegacyHeaderDefaults();
        }

        if ($ver !== '') {
            try {
                Cache::set(self::VIEW_SYNC_CACHE_KEY, $ver, 86400);
            } catch (Throwable) {
            }
        }
    }

    /**
     * 插件安装：自动跨库创建表结构、初始化数据与导入菜单
     */
    public function install(): void
    {
        $this->createTables();
        $this->seedData();
        $this->syncMenus();
        $this->syncFrontViews();
        $this->markViewSynced();
    }

    /**
     * 插件升级：幂等补齐表结构/字段、同步菜单并保留既有用户配置
     */
    public function upgrade(string $from): void
    {
        $this->createTables();
        $this->upgradeSchemaColumns();
        $this->syncMenus();
        $this->syncFrontViews();
        $this->normalizeLegacyHeaderDefaults();
        $this->markViewSynced();
        $this->app->log->info("[CMS Plugin] 已从 v{$from} 平滑升级至 v{$this->info->version}");
    }

    protected function markViewSynced(): void
    {
        $ver = (string) ($this->info->version ?? '');
        if ($ver !== '') {
            try {
                Cache::set(self::VIEW_SYNC_CACHE_KEY, $ver, 86400);
            } catch (Throwable) {
            }
        }
    }

    /**
     * 同步门户前台视图文件（兼容旧版框架 app/index/view/cms/index.html 优先加载链路）
     */
    protected function syncFrontViews(): void
    {
        $src = __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($src)) {
            return;
        }
        $targets = [
            public_path() . 'plugins' . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'index.html',
        ];
        $legacyView = root_path() . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($legacyView)) {
            $targets[] = $legacyView;
        }
        foreach ($targets as $dst) {
            try {
                $dir = dirname($dst);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @copy($src, $dst);
            } catch (Throwable) {
            }
        }
    }

    /**
     * 将旧版出厂默认顶部按钮文案平滑精简为一字/两字短文案（保留用户自定义内容）
     */
    protected function normalizeLegacyHeaderDefaults(): void
    {
        try {
            $row = Db::name('plugin')->where('name', 'cms')->find();
            if (!$row || empty($row['config'])) {
                return;
            }
            $cfg = is_array($row['config']) ? $row['config'] : (json_decode((string) $row['config'], true) ?: []);
            if (!is_array($cfg)) {
                return;
            }
            $changed = false;
            $legacyMap = [
                'corp_cta_text'     => ['预约演示' => '在线预约', '预约' => '在线预约'],
                'blog_cta_text'     => ['写留言' => '留言'],
                'showcase_cta_text' => ['询价单' => '询价'],
            ];
            foreach ($legacyMap as $key => $repl) {
                $val = (string) ($cfg[$key] ?? '');
                if (isset($repl[$val])) {
                    $cfg[$key] = $repl[$val];
                    $changed = true;
                }
            }
            if ($changed) {
                Db::name('plugin')->where('name', 'cms')->update([
                    'config'      => json_encode($cfg, JSON_UNESCAPED_UNICODE),
                    'update_time' => time(),
                ]);
            }
        } catch (Throwable) {
        }
    }

    /**
     * 插件卸载：清理相关数据表
     */
    public function uninstall(): void
    {
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        $driver = strtolower((string) (Db::connect()->getConfig('type') ?: 'mysql'));

        $tables = ['cms_inquiry', 'cms_banner', 'cms_article', 'cms_category'];

        foreach ($tables as $tbl) {
            $tableName = $driver === 'pgsql' ? "\"{$prefix}{$tbl}\"" : "`{$prefix}{$tbl}`";
            try {
                Db::execute("DROP TABLE IF EXISTS {$tableName}");
            } catch (Throwable $e) {
                $this->app->log->warning("[CMS Plugin] 卸载数据表 {$tbl} 时发生警告：" . $e->getMessage());
            }
        }
    }

    /**
     * 检查并补齐数据库表结构、迁移字段、导入菜单与示范数据
     */
    public function syncMenusAndSchema(): void
    {
        $this->createTables();
        $this->upgradeSchemaColumns();
        $this->seedData();
        $this->syncMenus();
    }

    /**
     * 同步 plugin.json 中的菜单到 auth_rule
     */
    public function syncMenus(): void
    {
        $jsonPath = __DIR__ . '/plugin.json';
        if (!is_file($jsonPath)) {
            return;
        }

        $info = json_decode((string) file_get_contents($jsonPath), true);
        if (!empty($info['menus']) && is_array($info['menus'])) {
            MenuImporter::import('cms', $info['menus'], true);
        }
    }

    /**
     * 跨库幂等补齐 category 与 article 表的新增列
     */
    protected function upgradeSchemaColumns(): void
    {
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        $driver = strtolower((string) (Db::connect()->getConfig('type') ?: 'mysql'));

        // 1. 检查并添加 cms_category 字段
        $categoryCols = [
            'type'           => "VARCHAR(20) NOT NULL DEFAULT 'article'",
            'template_scope' => "VARCHAR(20) NOT NULL DEFAULT 'all'",
            'icon'           => "VARCHAR(64) NOT NULL DEFAULT 'folder-line'",
            'description'    => "VARCHAR(300) NOT NULL DEFAULT ''",
        ];
        $this->ensureColumns("{$prefix}cms_category", $categoryCols, $driver);

        // 2. 检查并添加 cms_article 字段
        $articleCols = [
            'content_type'       => "VARCHAR(20) NOT NULL DEFAULT 'article'",
            'author'             => "VARCHAR(64) NOT NULL DEFAULT 'Admin'",
            'tags'               => "VARCHAR(255) NOT NULL DEFAULT ''",
            'is_recommend'       => "INT NOT NULL DEFAULT 0",
            'is_hot'             => "INT NOT NULL DEFAULT 0",
            'price_label'        => "VARCHAR(64) NOT NULL DEFAULT ''",
            'market_price_label' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'sku_code'           => "VARCHAR(64) NOT NULL DEFAULT ''",
            'badge_text'         => "VARCHAR(32) NOT NULL DEFAULT ''",
            'specs_json'         => "TEXT",
            'gallery_json'       => "TEXT",
            'external_link'      => "VARCHAR(255) NOT NULL DEFAULT ''",
            'likes'              => "INT NOT NULL DEFAULT 0",
        ];
        $this->ensureColumns("{$prefix}cms_article", $articleCols, $driver);

        // 3. 检查并添加 cms_inquiry 会员关联字段
        $inquiryCols = [
            'user_id'       => "INT NOT NULL DEFAULT 0",
            'user_nickname' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ];
        $this->ensureColumns("{$prefix}cms_inquiry", $inquiryCols, $driver);
    }

    /**
     * 安全幂等添加缺失列
     */
    protected function ensureColumns(string $fullTable, array $columns, string $driver): void
    {
        foreach ($columns as $column => $definition) {
            try {
                if ($driver === 'sqlite') {
                    $cols = Db::query("PRAGMA table_info('{$fullTable}')");
                    $existing = array_column($cols, 'name');
                    if (!in_array($column, $existing, true)) {
                        Db::execute("ALTER TABLE `{$fullTable}` ADD COLUMN `{$column}` {$definition}");
                    }
                } elseif ($driver === 'pgsql') {
                    $has = Db::query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$fullTable}' AND column_name = '{$column}'");
                    if (empty($has)) {
                        Db::execute("ALTER TABLE \"{$fullTable}\" ADD COLUMN \"{$column}\" {$definition}");
                    }
                } else {
                    // MySQL
                    $has = Db::query("SHOW COLUMNS FROM `{$fullTable}` LIKE '{$column}'");
                    if (empty($has)) {
                        Db::execute("ALTER TABLE `{$fullTable}` ADD COLUMN `{$column}` {$definition}");
                    }
                }
            } catch (Throwable $e) {
                $this->app->log->warning("[CMS Plugin] 检查/添加列 {$column} 提示: " . $e->getMessage());
            }
        }
    }

    /**
     * 创建数据表（MySQL / PostgreSQL / SQLite 原生自适应）
     */
    protected function createTables(): void
    {
        $driver = strtolower((string) (Db::connect()->getConfig('type') ?: 'mysql'));
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');

        if ($driver === 'pgsql') {
            $sqls = [
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_category\" (
                    \"id\" SERIAL PRIMARY KEY,
                    \"pid\" INT NOT NULL DEFAULT 0,
                    \"name\" VARCHAR(100) NOT NULL DEFAULT '',
                    \"slug\" VARCHAR(100) NOT NULL DEFAULT '',
                    \"type\" VARCHAR(20) NOT NULL DEFAULT 'article',
                    \"template_scope\" VARCHAR(20) NOT NULL DEFAULT 'all',
                    \"icon\" VARCHAR(64) NOT NULL DEFAULT 'folder-line',
                    \"description\" VARCHAR(300) NOT NULL DEFAULT '',
                    \"weight\" INT NOT NULL DEFAULT 0,
                    \"status\" INT NOT NULL DEFAULT 1,
                    \"create_time\" BIGINT NOT NULL DEFAULT 0,
                    \"update_time\" BIGINT NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_article\" (
                    \"id\" SERIAL PRIMARY KEY,
                    \"category_id\" INT NOT NULL DEFAULT 0,
                    \"title\" VARCHAR(200) NOT NULL DEFAULT '',
                    \"summary\" VARCHAR(500) NOT NULL DEFAULT '',
                    \"content\" TEXT,
                    \"cover\" VARCHAR(255) NOT NULL DEFAULT '',
                    \"content_type\" VARCHAR(20) NOT NULL DEFAULT 'article',
                    \"author\" VARCHAR(64) NOT NULL DEFAULT 'Admin',
                    \"tags\" VARCHAR(255) NOT NULL DEFAULT '',
                    \"is_recommend\" INT NOT NULL DEFAULT 0,
                    \"is_hot\" INT NOT NULL DEFAULT 0,
                    \"price_label\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"market_price_label\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"sku_code\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"badge_text\" VARCHAR(32) NOT NULL DEFAULT '',
                    \"specs_json\" TEXT,
                    \"gallery_json\" TEXT,
                    \"external_link\" VARCHAR(255) NOT NULL DEFAULT '',
                    \"likes\" INT NOT NULL DEFAULT 0,
                    \"views\" INT NOT NULL DEFAULT 0,
                    \"weight\" INT NOT NULL DEFAULT 0,
                    \"status\" INT NOT NULL DEFAULT 1,
                    \"create_time\" BIGINT NOT NULL DEFAULT 0,
                    \"update_time\" BIGINT NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_banner\" (
                    \"id\" SERIAL PRIMARY KEY,
                    \"template_code\" VARCHAR(20) NOT NULL DEFAULT 'all',
                    \"title\" VARCHAR(150) NOT NULL DEFAULT '',
                    \"subtitle\" VARCHAR(300) NOT NULL DEFAULT '',
                    \"badge\" VARCHAR(50) NOT NULL DEFAULT '',
                    \"image\" VARCHAR(255) NOT NULL DEFAULT '',
                    \"btn_text\" VARCHAR(50) NOT NULL DEFAULT '',
                    \"btn_link\" VARCHAR(255) NOT NULL DEFAULT '',
                    \"weight\" INT NOT NULL DEFAULT 100,
                    \"status\" INT NOT NULL DEFAULT 1,
                    \"create_time\" BIGINT NOT NULL DEFAULT 0,
                    \"update_time\" BIGINT NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_inquiry\" (
                    \"id\" SERIAL PRIMARY KEY,
                    \"scene\" VARCHAR(20) NOT NULL DEFAULT 'general',
                    \"target_id\" INT NOT NULL DEFAULT 0,
                    \"target_title\" VARCHAR(200) NOT NULL DEFAULT '',
                    \"user_id\" INT NOT NULL DEFAULT 0,
                    \"user_nickname\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"name\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"contact\" VARCHAR(100) NOT NULL DEFAULT '',
                    \"company\" VARCHAR(128) NOT NULL DEFAULT '',
                    \"content\" TEXT,
                    \"reply_content\" TEXT,
                    \"status\" INT NOT NULL DEFAULT 0,
                    \"ip\" VARCHAR(64) NOT NULL DEFAULT '',
                    \"create_time\" BIGINT NOT NULL DEFAULT 0,
                    \"update_time\" BIGINT NOT NULL DEFAULT 0
                )",
            ];
        } elseif ($driver === 'sqlite') {
            $sqls = [
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_category\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"pid\" INTEGER NOT NULL DEFAULT 0,
                    \"name\" TEXT NOT NULL DEFAULT '',
                    \"slug\" TEXT NOT NULL DEFAULT '',
                    \"type\" TEXT NOT NULL DEFAULT 'article',
                    \"template_scope\" TEXT NOT NULL DEFAULT 'all',
                    \"icon\" TEXT NOT NULL DEFAULT 'folder-line',
                    \"description\" TEXT NOT NULL DEFAULT '',
                    \"weight\" INTEGER NOT NULL DEFAULT 0,
                    \"status\" INTEGER NOT NULL DEFAULT 1,
                    \"create_time\" INTEGER NOT NULL DEFAULT 0,
                    \"update_time\" INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_article\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"category_id\" INTEGER NOT NULL DEFAULT 0,
                    \"title\" TEXT NOT NULL DEFAULT '',
                    \"summary\" TEXT NOT NULL DEFAULT '',
                    \"content\" TEXT,
                    \"cover\" TEXT NOT NULL DEFAULT '',
                    \"content_type\" TEXT NOT NULL DEFAULT 'article',
                    \"author\" TEXT NOT NULL DEFAULT 'Admin',
                    \"tags\" TEXT NOT NULL DEFAULT '',
                    \"is_recommend\" INTEGER NOT NULL DEFAULT 0,
                    \"is_hot\" INTEGER NOT NULL DEFAULT 0,
                    \"price_label\" TEXT NOT NULL DEFAULT '',
                    \"market_price_label\" TEXT NOT NULL DEFAULT '',
                    \"sku_code\" TEXT NOT NULL DEFAULT '',
                    \"badge_text\" TEXT NOT NULL DEFAULT '',
                    \"specs_json\" TEXT,
                    \"gallery_json\" TEXT,
                    \"external_link\" TEXT NOT NULL DEFAULT '',
                    \"likes\" INTEGER NOT NULL DEFAULT 0,
                    \"views\" INTEGER NOT NULL DEFAULT 0,
                    \"weight\" INTEGER NOT NULL DEFAULT 0,
                    \"status\" INTEGER NOT NULL DEFAULT 1,
                    \"create_time\" INTEGER NOT NULL DEFAULT 0,
                    \"update_time\" INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_banner\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"template_code\" TEXT NOT NULL DEFAULT 'all',
                    \"title\" TEXT NOT NULL DEFAULT '',
                    \"subtitle\" TEXT NOT NULL DEFAULT '',
                    \"badge\" TEXT NOT NULL DEFAULT '',
                    \"image\" TEXT NOT NULL DEFAULT '',
                    \"btn_text\" TEXT NOT NULL DEFAULT '',
                    \"btn_link\" TEXT NOT NULL DEFAULT '',
                    \"weight\" INTEGER NOT NULL DEFAULT 100,
                    \"status\" INTEGER NOT NULL DEFAULT 1,
                    \"create_time\" INTEGER NOT NULL DEFAULT 0,
                    \"update_time\" INTEGER NOT NULL DEFAULT 0
                )",
                "CREATE TABLE IF NOT EXISTS \"{$prefix}cms_inquiry\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"scene\" TEXT NOT NULL DEFAULT 'general',
                    \"target_id\" INTEGER NOT NULL DEFAULT 0,
                    \"target_title\" TEXT NOT NULL DEFAULT '',
                    \"user_id\" INTEGER NOT NULL DEFAULT 0,
                    \"user_nickname\" TEXT NOT NULL DEFAULT '',
                    \"name\" TEXT NOT NULL DEFAULT '',
                    \"contact\" TEXT NOT NULL DEFAULT '',
                    \"company\" TEXT NOT NULL DEFAULT '',
                    \"content\" TEXT,
                    \"reply_content\" TEXT,
                    \"status\" INTEGER NOT NULL DEFAULT 0,
                    \"ip\" TEXT NOT NULL DEFAULT '',
                    \"create_time\" INTEGER NOT NULL DEFAULT 0,
                    \"update_time\" INTEGER NOT NULL DEFAULT 0
                )",
            ];
        } else {
            // MySQL 8.0+
            $sqls = [
                "CREATE TABLE IF NOT EXISTS `{$prefix}cms_category` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `pid` INT NOT NULL DEFAULT 0,
                    `name` VARCHAR(100) NOT NULL DEFAULT '',
                    `slug` VARCHAR(100) NOT NULL DEFAULT '',
                    `type` VARCHAR(20) NOT NULL DEFAULT 'article',
                    `template_scope` VARCHAR(20) NOT NULL DEFAULT 'all',
                    `icon` VARCHAR(64) NOT NULL DEFAULT 'folder-line',
                    `description` VARCHAR(300) NOT NULL DEFAULT '',
                    `weight` INT NOT NULL DEFAULT 0,
                    `status` INT NOT NULL DEFAULT 1,
                    `create_time` BIGINT NOT NULL DEFAULT 0,
                    `update_time` BIGINT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS栏目分类表'",
                "CREATE TABLE IF NOT EXISTS `{$prefix}cms_article` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `category_id` INT NOT NULL DEFAULT 0,
                    `title` VARCHAR(200) NOT NULL DEFAULT '',
                    `summary` VARCHAR(500) NOT NULL DEFAULT '',
                    `content` TEXT,
                    `cover` VARCHAR(255) NOT NULL DEFAULT '',
                    `content_type` VARCHAR(20) NOT NULL DEFAULT 'article',
                    `author` VARCHAR(64) NOT NULL DEFAULT 'Admin',
                    `tags` VARCHAR(255) NOT NULL DEFAULT '',
                    `is_recommend` INT NOT NULL DEFAULT 0,
                    `is_hot` INT NOT NULL DEFAULT 0,
                    `price_label` VARCHAR(64) NOT NULL DEFAULT '',
                    `market_price_label` VARCHAR(64) NOT NULL DEFAULT '',
                    `sku_code` VARCHAR(64) NOT NULL DEFAULT '',
                    `badge_text` VARCHAR(32) NOT NULL DEFAULT '',
                    `specs_json` TEXT,
                    `gallery_json` TEXT,
                    `external_link` VARCHAR(255) NOT NULL DEFAULT '',
                    `likes` INT NOT NULL DEFAULT 0,
                    `views` INT NOT NULL DEFAULT 0,
                    `weight` INT NOT NULL DEFAULT 0,
                    `status` INT NOT NULL DEFAULT 1,
                    `create_time` BIGINT NOT NULL DEFAULT 0,
                    `update_time` BIGINT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS内容与商品表'",
                "CREATE TABLE IF NOT EXISTS `{$prefix}cms_banner` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `template_code` VARCHAR(20) NOT NULL DEFAULT 'all',
                    `title` VARCHAR(150) NOT NULL DEFAULT '',
                    `subtitle` VARCHAR(300) NOT NULL DEFAULT '',
                    `badge` VARCHAR(50) NOT NULL DEFAULT '',
                    `image` VARCHAR(255) NOT NULL DEFAULT '',
                    `btn_text` VARCHAR(50) NOT NULL DEFAULT '',
                    `btn_link` VARCHAR(255) NOT NULL DEFAULT '',
                    `weight` INT NOT NULL DEFAULT 100,
                    `status` INT NOT NULL DEFAULT 1,
                    `create_time` BIGINT NOT NULL DEFAULT 0,
                    `update_time` BIGINT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS网站横幅表'",
                "CREATE TABLE IF NOT EXISTS `{$prefix}cms_inquiry` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `scene` VARCHAR(20) NOT NULL DEFAULT 'general',
                    `target_id` INT NOT NULL DEFAULT 0,
                    `target_title` VARCHAR(200) NOT NULL DEFAULT '',
                    `user_id` INT NOT NULL DEFAULT 0,
                    `user_nickname` VARCHAR(64) NOT NULL DEFAULT '',
                    `name` VARCHAR(64) NOT NULL DEFAULT '',
                    `contact` VARCHAR(100) NOT NULL DEFAULT '',
                    `company` VARCHAR(128) NOT NULL DEFAULT '',
                    `content` TEXT,
                    `reply_content` TEXT,
                    `status` INT NOT NULL DEFAULT 0,
                    `ip` VARCHAR(64) NOT NULL DEFAULT '',
                    `create_time` BIGINT NOT NULL DEFAULT 0,
                    `update_time` BIGINT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMS网站留言与询单表'",
            ];
        }

        foreach ($sqls as $sql) {
            try {
                Db::execute($sql);
            } catch (Throwable $e) {
                $this->app->log->warning('[CMS Plugin] 建表执行提示: ' . $e->getMessage());
            }
        }
    }

    /**
     * 插入初始示范数据（针对三套模板，幂等灌入）
     */
    public function seedData(): void
    {
        try {
            $now = time();

            // 1. 种子栏目定义
            $categorySeeds = [
                // 博客模板分类
                ['name' => '架构与后端', 'slug' => 'arch-backend', 'type' => 'article', 'template_scope' => 'blog', 'icon' => 'server-line', 'description' => '微服务、高并发架构与云原生系统核心实战', 'weight' => 100],
                ['name' => '前端与交互', 'slug' => 'frontend-ui', 'type' => 'article', 'template_scope' => 'blog', 'icon' => 'layout-masonry-line', 'description' => 'Vue 3、现代 CSS、状态管理与极致用户体验设计', 'weight' => 90],
                ['name' => 'AI 与效能工具', 'slug' => 'ai-tools', 'type' => 'article', 'template_scope' => 'blog', 'icon' => 'cpu-line', 'description' => 'LLM Agent、提示词工程与新一代研发效能工具跃升', 'weight' => 80],
                ['name' => '开源生活随笔', 'slug' => 'geek-life', 'type' => 'article', 'template_scope' => 'blog', 'icon' => 'compass-discover-line', 'description' => '技术思考、读书沉淀、独立开发与开源心路历程', 'weight' => 70],

                // 企业官网分类
                ['name' => '核心解决方案', 'slug' => 'solutions', 'type' => 'solution', 'template_scope' => 'corporate', 'icon' => 'shield-check-line', 'description' => '面向金融、能源、高端制造与政企的高可用数字化基座', 'weight' => 100],
                ['name' => '客户成功案例', 'slug' => 'cases', 'type' => 'solution', 'template_scope' => 'corporate', 'icon' => 'trophy-line', 'description' => '世界 500 强与头部先锋企业的数字化标杆转型落地实践', 'weight' => 90],
                ['name' => '企业新闻动态', 'slug' => 'corp-news', 'type' => 'article', 'template_scope' => 'corporate', 'icon' => 'newspaper-line', 'description' => '公司重大版本发布、行业战略签约与品牌生态资讯', 'weight' => 80],

                // 购物产品展示分类
                ['name' => '智能算力终端', 'slug' => 'edge-hardware', 'type' => 'product', 'template_scope' => 'showcase', 'icon' => 'hard-drive-2-line', 'description' => '高能效边缘推理、超低延迟嵌入式异构计算终端', 'weight' => 100],
                ['name' => '云原生一体机', 'slug' => 'cloud-server', 'type' => 'product', 'template_scope' => 'showcase', 'icon' => 'server-fill', 'description' => '软硬一体化调优、私有化敏捷集群交钥匙部署装备', 'weight' => 90],
                ['name' => '企业级安全网关', 'slug' => 'security-gateway', 'type' => 'product', 'template_scope' => 'showcase', 'icon' => 'shield-keyhole-line', 'description' => '零信任网络访问控制、第七层高防与全链路审计设备', 'weight' => 80],
                ['name' => '开发者周边严选', 'slug' => 'gear', 'type' => 'product', 'template_scope' => 'showcase', 'icon' => 't-shirt-air-line', 'description' => '极客定制人体工学装备、高质感金属桌搭与精选周边', 'weight' => 70],
            ];

            $catMap = [];
            foreach ($categorySeeds as $item) {
                $row = Db::name('cms_category')->where('slug', $item['slug'])->find();
                if (!$row) {
                    $id = (int) Db::name('cms_category')->insertGetId(array_merge($item, [
                        'pid'         => 0,
                        'status'      => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]));
                    $catMap[$item['slug']] = $id;
                } else {
                    $catMap[$item['slug']] = (int) $row['id'];
                    // 确保旧记录补齐字段
                    Db::name('cms_category')->where('id', $row['id'])->update([
                        'type'           => $item['type'],
                        'template_scope' => $item['template_scope'],
                        'icon'           => $item['icon'],
                        'description'    => $item['description'],
                    ]);
                }
            }

            // 2. 博客文章 5 篇
            $blogArticles = [
                [
                    'category_id'  => $catMap['arch-backend'] ?? 1,
                    'title'        => '从单体到高可用微服务：HwAdmin 架构演进与零侵入插件实践',
                    'summary'      => '本文深度复盘我们如何基于 ThinkPHP 8.1 与 ThinkORM 4 打造轻量敏捷底座，并通过类加载隔离与反射路由实现物理级零侵入插件系统。',
                    'content'      => '<p>在现代中后台框架的设计中，灵活性与可维护性往往是一对天然矛盾。许多传统 PHP 框架在引入插件机制后，代码库迅速变得臃肿且脆弱，Hook 钩子满天飞，升级主系统时动辄遭遇破坏性变更。</p><h3>一、架构分层与零侵入设计理念</h3><p>HwAdmin 采用严格的三层解耦架构：<strong>core 核心基座</strong>、<strong>app 业务宿主</strong> 与 <strong>plugins 独立功能包</strong>。所有插件具备物理级代码隔离：</p><pre><code>// 插件控制器完全自包含，宿主通过 PluginDispatcher 动态路由分发
namespace plugins\cms\controller\admin;

use core\base\AdminController;
use core\crud\CrudTrait;

class Article extends AdminController {
    use CrudTrait;
}</code></pre><p>这种设计的核心价值在于：插件插拔如乐高积木，卸载即净空，安装即生效，永不污染宿主代码。</p><h3>二、双数据库引擎原生兼容</h3><p>面对信创环境与不同企业技术选型，HwAdmin 放弃了平台专有 SQL 方言，全量基于 Phinx 迁移与统一抽象层构建，MySQL 8.0+ 与 PostgreSQL 16+ 100% 具备同等第一公民待遇。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => '极客老王',
                    'tags'         => '微服务,架构设计,插件系统,ThinkPHP8',
                    'is_recommend' => 1,
                    'is_hot'       => 1,
                    'badge_text'   => '深度好文',
                    'views'        => 3420,
                    'likes'        => 268,
                    'weight'       => 100,
                ],
                [
                    'category_id'  => $catMap['frontend-ui'] ?? 1,
                    'title'        => 'Vue 3 + Vite + TypeScript 高性能管理后台状态机与动态权限路由设计',
                    'summary'      => '深入剖析基于 Pinia 的状态流设计、基于 JWT 的双 Token 刷新策略，以及管理后台无限级动态树状路由的秒级渲染优化。',
                    'content'      => '<p>在现代企业级管理后台中，动态路由与权限拦截是前端架构的基石。本文将拆解 HwAdmin 管理后台的前端核心链路：</p><h3>一、无感知令牌自动刷新机制</h3><p>传统拦截器往往在 401 时强制退出登录，极大影响运营操作。我们引入了队列锁与双 Token 静默刷新：当 Access Token 过期时，挂起并发请求，通过 Refresh Token 换取新令牌后再批量重放。</p><h3>二、动态路由映射与组件懒加载</h3><p>后端仅下发权限规则与组件文件路径，前端通过 Vite 的 <code>import.meta.glob</code> 实现安全的异步动态映射，有效防止恶意组件注入。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => '前端小柒',
                    'tags'         => 'Vue3,TypeScript,Vite,Pinia,权限路由',
                    'is_recommend' => 1,
                    'is_hot'       => 0,
                    'badge_text'   => '实战精粹',
                    'views'        => 2180,
                    'likes'        => 195,
                    'weight'       => 90,
                ],
                [
                    'category_id'  => $catMap['ai-tools'] ?? 1,
                    'title'        => '大模型驱动的敏捷研发：如何利用 Agent 架构实现 10x 生产力跃升',
                    'summary'      => '探索 Claude Code、Antigravity 与 Cursor 在真实工程落地中的最佳协作范式，从单兵编码转向顶层架构指挥与分级派发执行。',
                    'content'      => '<p>随着大模型推理能力的爆发，软件工程的工作流正在经历颠覆性重构。过去工程师把 80% 的时间花在重复编写 CRUD、对齐接口与排查语法错误，而今天我们正在迈入「主脑指挥 + 子 Agent 闭环交付」的新范式。</p><h3>一、主脑与子 Agent 的职责分权</h3><p>主模型聚焦顶层架构设计、数据字典统一与边界约束；子 Agent 则在明确边界内专注完成具体的代码落盘、单元测试与自动化验证，实现质量与效率的双重飞跃。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => 'AI 探索者',
                    'tags'         => 'LLM,Agent,生产力,AI工程化',
                    'is_recommend' => 1,
                    'is_hot'       => 1,
                    'badge_text'   => '趋势前瞻',
                    'views'        => 4150,
                    'likes'        => 380,
                    'weight'       => 85,
                ],
                [
                    'category_id'  => $catMap['arch-backend'] ?? 1,
                    'title'        => '数据库选型与性能调优：MySQL 8.0 与 PostgreSQL 16 深度同台评测',
                    'summary'      => '针对高并发写入、复杂 JSON 检索、全文检索与分表分库场景，详尽对比 MySQL 8 与 PostgreSQL 16 在真实业务中的基准性能。',
                    'content'      => '<p>技术团队在启动新项目时，最常面临的抉择之一就是数据库底座。MySQL 凭借庞大的生态与简单易用深受中小团队青睐，而 PostgreSQL 则以强悍的 ACID 特性、丰富的扩展支持被誉为世界上最先进的开源关系数据库。</p><p>HwAdmin 创新的提供了两者兼得的方案：底层抽象兼顾两端，用户按需自由无缝切换，实现资产保值。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => '极客老王',
                    'tags'         => 'MySQL,PostgreSQL,性能压测,数据库',
                    'is_recommend' => 0,
                    'is_hot'       => 1,
                    'badge_text'   => '干货评测',
                    'views'        => 1920,
                    'likes'        => 142,
                    'weight'       => 80,
                ],
                [
                    'category_id'  => $catMap['geek-life'] ?? 1,
                    'title'        => '一个独立全栈开发者的十年：保持热爱，奔赴山海',
                    'summary'      => '从懵懂写下第一行 PHP 代码，到独立构筑数套开源产品，记录这十年关于技术信仰、审美追求与自由创造的心路历程。',
                    'content'      => '<p>代码是现代数字世界的诗篇。每一行清晰的缩进，每一个经过深思熟虑的变量命名，都承载着创造者对确定性与秩序美的追求。</p><p>在瞬息万变的技术浪潮中，框架会迭代，语法会演进，但解决问题、创造价值并用优美工具帮助他人的初心，历久弥新。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => '极客老王',
                    'tags'         => '独立开发,生活随笔,全栈成长,开源心路',
                    'is_recommend' => 0,
                    'is_hot'       => 0,
                    'badge_text'   => '读者共鸣',
                    'views'        => 1560,
                    'likes'        => 210,
                    'weight'       => 70,
                ],
            ];

            foreach ($blogArticles as $art) {
                $has = Db::name('cms_article')->where('title', $art['title'])->find();
                if (!$has) {
                    Db::name('cms_article')->insert(array_merge($art, [
                        'status'      => 1,
                        'create_time' => $now - rand(3600, 86400 * 5),
                        'update_time' => $now,
                    ]));
                }
            }

            // 3. 企业官网解决方案与案例 4 篇
            $corporateArticles = [
                [
                    'category_id'  => $catMap['solutions'] ?? 1,
                    'title'        => '金融级高可用数字化运维与业务中台解决方案',
                    'summary'      => '针对大型银行与证券机构面临的高并发交易峰值、跨机房多活与 99.999% SLA 可靠性要求，打造软硬一体自主可控中台底座。',
                    'content'      => '<div class="solution-body"><h3>业务痛点与转型诉求</h3><p>传统金融核心系统耦合度高，单点故障风险显著；每逢年末结息或营销大促，交易TPS瞬间冲高，极易引发服务雪崩与数据库锁死。</p><h3>解决方案全景架构</h3><p>HwAdmin 企业数字化方案采用动静分离微中台架构，依托双活数据库镜像与分级分布式缓存，实现核心事务毫秒级下沉与只读报表平滑引流。</p><h3>客户交付成果</h3><ul><li>系统年平均可用率提升至 <strong>99.999%</strong>；</li><li>高并发下单峰值延迟由 480ms 骤降至 <strong>35ms</strong>；</li><li>每年降低 IT 基础设施维护成本约 <strong>42%</strong>。</li></ul></div>',
                    'cover'        => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=800&q=80',
                    'content_type' => 'solution',
                    'author'       => '政企方案事业部',
                    'tags'         => '金融科技,高可用,数字化中台,容灾多活',
                    'is_recommend' => 1,
                    'is_hot'       => 1,
                    'badge_text'   => '标杆方案',
                    'price_label'  => '专属定制',
                    'views'        => 2890,
                    'likes'        => 178,
                    'weight'       => 100,
                ],
                [
                    'category_id'  => $catMap['solutions'] ?? 1,
                    'title'        => '高端智能制造工业物联网（IIoT）边缘云协同平台',
                    'summary'      => '打通离散制造车间全域 PLC 与传感器数据链路，提供微秒级工业总线协议解析、数字孪生呈现与预测性维护闭环。',
                    'content'      => '<div class="solution-body"><h3>痛点背景</h3><p>传统工厂设备品牌各异，Modbus、OPC-UA、Profinet 协议割裂，数据如同孤岛，缺乏统一实时监视与故障预警体系。</p><h3>解决方案架构</h3><p>通过部署 HwAdmin 智能算力边缘一体机，实现设备边缘微秒级采集与时序特征提取，云端中台统一管控模型训练与算法下发，真正实现无人化黑灯车间预测性维护。</p><h3>成效指标</h3><ul><li>非计划性设备停机时间锐减 <strong>65%</strong>；</li><li>产线综合缺陷率降低 <strong>28%</strong>；</li><li>设备综合运行效率（OEE）突破 <strong>88.5%</strong>。</li></ul></div>',
                    'cover'        => 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=800&q=80',
                    'content_type' => 'solution',
                    'author'       => '智能制造专家组',
                    'tags'         => '工业互联网,边缘计算,数字孪生,智能制造',
                    'is_recommend' => 1,
                    'is_hot'       => 0,
                    'badge_text'   => '工信首批',
                    'price_label'  => '方案询价',
                    'views'        => 1750,
                    'likes'        => 120,
                    'weight'       => 90,
                ],
                [
                    'category_id'  => $catMap['cases'] ?? 1,
                    'title'        => '某世界 500 强跨国能源集团全球供应链中台落地案例',
                    'summary'      => '跨越 18 个国家分支机构与 24 小时跨时区协同，构建全球一体化采购、报关、物流追踪与全生命周期数字档案系统。',
                    'content'      => '<div class="solution-body"><h3>项目背景</h3><p>该集团原有多套孤立的 ERP、WMS 资产系统，业务流程流转周期长达 3 周，汇率与合规风险难以实时阻断。</p><h3>落地举措</h3><p>采用 HwAdmin 快速敏捷交付中台体系，在 90 天内完成全链路 26 个系统的 API 网关对接与多语言本地化配置，实现资金流与物流秒级对齐。</p><h3>客户评价</h3><p>“HwAdmin 团队卓越的架构能力与敏捷交付速度，帮助我们提前 2 个月完成了全球供应链数字化闭环，成效超乎预期。”</p></div>',
                    'cover'        => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=800&q=80',
                    'content_type' => 'solution',
                    'author'       => '战略交付中心',
                    'tags'         => '世界500强,全球供应链,跨国协同,数字化转型',
                    'is_recommend' => 1,
                    'is_hot'       => 1,
                    'badge_text'   => '全球标杆',
                    'price_label'  => '标杆案例',
                    'views'        => 3120,
                    'likes'        => 245,
                    'weight'       => 85,
                ],
                [
                    'category_id'  => $catMap['solutions'] ?? 1,
                    'title'        => '大型智慧医疗健康集团多院区云原生数据中台解决方案',
                    'summary'      => '解决跨院区电子病历非标割裂、高并发挂号缴费峰值排队与医疗影像秒级调阅传输痛点，构建全栈安全可信医疗云基座。',
                    'content'      => '<div class="solution-body"><h3>痛点背景</h3><p>跨院区异构系统多达 40 套，数据无法互通互认，重大诊疗抢救时调阅影像往往需要分钟级等待。</p><h3>方案架构</h3><p>引入 HwAdmin 医疗信创专有云中台，借助双活分布式数据库与私有化对象存储缓存，实现跨院区 5000 万级患者电子病历毫秒级互认互通。</p><h3>成效指标</h3><ul><li>门诊挂号缴费平均排队耗时降低 <strong>70%</strong>；</li><li>CT/MRI 高清影像多院区协同调阅耗时压缩至 <strong>1.2秒</strong>；</li><li>一次性通过国家医疗健康信息互联互通标准化成熟度五级乙等评审。</li></ul></div>',
                    'cover'        => 'https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?w=800&q=80',
                    'content_type' => 'solution',
                    'author'       => '智慧医疗事业部',
                    'tags'         => '智慧医疗,数据中台,跨院协同,信创医疗',
                    'is_recommend' => 1,
                    'is_hot'       => 0,
                    'badge_text'   => '五级乙等认证',
                    'price_label'  => '专属方案',
                    'views'        => 2460,
                    'likes'        => 189,
                    'weight'       => 75,
                ],
                [
                    'category_id'  => $catMap['corp-news'] ?? 1,
                    'title'        => 'HwAdmin 正式发布 2.0 商业级敏捷套件：全面兼容国产化双数据库底座',
                    'summary'      => '经过 180 天攻关，HwAdmin 迎来里程碑式版本升级，推出全新企业级插件生态市场、零侵入可插拔架构及信创双库无缝兼容。',
                    'content'      => '<p>2026 年秋，HwAdmin 研发委员会正式对外发布 2.0 全新架构版本。本次版本不仅在性能与开发体验上取得质的突破，更确立了“极致敏捷、双库兼容、生态共享”的长期技术基石。</p><p>未来，HwAdmin 将持续深耕企业级高可靠开发底座，为全球开发者与现代化企业带来更高效、更安全的数字创新工具。</p>',
                    'cover'        => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=800&q=80',
                    'content_type' => 'article',
                    'author'       => '品牌公关部',
                    'tags'         => '企业动态,版本发布,国产信创,技术生态',
                    'is_recommend' => 0,
                    'is_hot'       => 1,
                    'badge_text'   => '官方重磅',
                    'views'        => 2240,
                    'likes'        => 167,
                    'weight'       => 80,
                ],
            ];

            foreach ($corporateArticles as $art) {
                $has = Db::name('cms_article')->where('title', $art['title'])->find();
                if (!$has) {
                    Db::name('cms_article')->insert(array_merge($art, [
                        'status'      => 1,
                        'create_time' => $now - rand(7200, 86400 * 4),
                        'update_time' => $now,
                    ]));
                }
            }

            // 4. 展示商品 6 款（含丰富规格参数）
            $showcaseProducts = [
                [
                    'category_id'        => $catMap['edge-hardware'] ?? 1,
                    'title'              => 'EdgeBox-9000 工业级边缘 AI 推理智能终端',
                    'summary'            => '搭载旗舰级 12 核 NPU 异构加速计算单元，提供高达 48 TOPS 稠密张量算力，专为智慧工厂缺陷检测、全场景安防视频结构化分析而生。',
                    'content'            => '<p>EdgeBox-9000 是专为严苛工业现场打造的高可靠无风扇嵌入式边缘 AI 计算终端。支持 -40℃ ~ 75℃ 宽温严苛工作环境，提供双千兆网口、双隔离 RS485 与 8 路 GPIO 接口。</p><h3>核心亮点</h3><ul><li>48 TOPS 峰值 AI 算力，微秒级模型推理响应；</li><li>全铝合金散热机身，IP40 防护等级，抗强电磁干扰；</li><li>预装工业 Linux 与边缘容器管理环境，开箱即用。</li></ul>',
                    'cover'              => 'https://images.unsplash.com/photo-1591488320449-011701bb6704?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '智能硬件实验室',
                    'tags'               => '边缘计算,AI终端,工业硬件,48TOPS',
                    'is_recommend'       => 1,
                    'is_hot'             => 1,
                    'price_label'        => '¥4,999',
                    'market_price_label' => '¥5,999',
                    'sku_code'           => 'HW-EB9000-PRO',
                    'badge_text'         => '2026旗舰新品',
                    'specs_json'         => json_encode([
                        ['label' => '主控处理器', 'value' => 'ARMv9 12核 64位高性能异构架构 (2.8GHz)'],
                        ['label' => 'AI 算力引擎', 'value' => '自研双核 NPU，48 TOPS @ INT8 / 12 TFLOPS @ FP16'],
                        ['label' => '系统内存', 'value' => '32GB LPDDR5 6400MT/s 宽温工业内存'],
                        ['label' => '板载存储', 'value' => '512GB PCIe 4.0 NVMe 固态硬盘 + 扩展 M.2 Key-M'],
                        ['label' => '网络与通讯', 'value' => '2×2.5GbE RJ45 + 5G 全网通模块 + WiFi 6E'],
                        ['label' => '工业接口', 'value' => '2×隔离 RS485/232 + 8×GPIO + 4×USB 3.2 Gen2'],
                        ['label' => '工作温度', 'value' => '-40℃ 至 +75℃（无风扇自然散热设计）'],
                    ], JSON_UNESCAPED_UNICODE),
                    'gallery_json'       => json_encode([
                        'https://images.unsplash.com/photo-1591488320449-011701bb6704?w=800&q=80',
                        'https://images.unsplash.com/photo-1587202372775-e229f172b9d7?w=800&q=80',
                    ]),
                    'views'              => 4280,
                    'likes'              => 312,
                    'weight'             => 100,
                ],
                [
                    'category_id'        => $catMap['cloud-server'] ?? 1,
                    'title'              => 'CloudMatrix-X6000 云原生敏捷一体机',
                    'summary'            => '面向私有云与分支机构敏捷交付的 2U 双路高密机架式服务器，出厂预调优分布式存储与容器虚拟化算力池。',
                    'content'            => '<p>CloudMatrix-X6000 是一套为中大型企业私有化部署量身打造的高密一体机解决方案。免去冗长繁琐的操作系统、网络堆栈配置，开箱上电 30 分钟内即可拉起完备的企业级私有云中台服务。</p>',
                    'cover'              => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '云基础设施事业部',
                    'tags'               => '云原生,一体机,私有云,高密计算',
                    'is_recommend'       => 1,
                    'is_hot'             => 1,
                    'price_label'        => '¥32,800',
                    'market_price_label' => '¥38,800',
                    'sku_code'           => 'HW-CM6000-2U',
                    'badge_text'         => '企业级首选',
                    'specs_json'         => json_encode([
                        ['label' => '处理器架构', 'value' => '双路 64核 高性能服务器级处理器 (总计 128核/256线程)'],
                        ['label' => '运行内存', 'value' => '256GB DDR5 5600MHz ECC 容错纠错内存 (最大扩容 2TB)'],
                        ['label' => '存储系统', 'value' => '8×3.84TB 企业级 U.2 NVMe SSD (RAID 5/10/60)'],
                        ['label' => '高速网络', 'value' => '双口 25GbE SFP28 光口 + 双千兆 IPMI 远程带外管理'],
                        ['label' => '电源冗余', 'value' => '1+1 1600W 80 Plus 白金级高效热插拔冗余电源'],
                        ['label' => '机箱规格', 'value' => '标准 2U 机架式，深 750mm'],
                    ], JSON_UNESCAPED_UNICODE),
                    'gallery_json'       => json_encode([
                        'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?w=800&q=80',
                        'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?w=800&q=80',
                    ]),
                    'views'              => 2950,
                    'likes'              => 224,
                    'weight'             => 90,
                ],
                [
                    'category_id'        => $catMap['security-gateway'] ?? 1,
                    'title'              => 'SecGate-X5 零信任智能企业安全网关',
                    'summary'            => '集成第七层应用防火墙、零信任微分段访问控制、SSL 解密引擎与智能入侵阻断，护航数字业务资产安全。',
                    'content'            => '<p>在远程办公与多分支混合云常态化的今天，传统网络边界已荡然无存。SecGate-X5 基于持续身份鉴权与设备信誉评估，提供企业级全链路加密访问通道与流量威胁清洗能力。</p>',
                    'cover'              => 'https://images.unsplash.com/photo-1563770660941-20978e870e26?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '网络安全工程部',
                    'tags'               => '安全网关,零信任,防火墙,流量清洗',
                    'is_recommend'       => 1,
                    'is_hot'             => 0,
                    'badge_text'   => '安全基石',
                    'price_label'        => '¥12,800',
                    'market_price_label' => '¥15,000',
                    'sku_code'           => 'HW-SG5000-ZT',
                    'badge_text'         => '等保三级必备',
                    'specs_json'         => json_encode([
                        ['label' => '吞吐能力', 'value' => '应用层防火墙吞吐 40Gbps / 并发连接数 800万'],
                        ['label' => '安全特性', 'value' => '零信任动态鉴权、国密 SM2/3/4 加解密硬件加速芯片'],
                        ['label' => '接口规格', 'value' => '8×10GbE SFP+ 光纤口 + 8×1GbE RJ45 电口 + 1×Bypass 口'],
                        ['label' => '管理认证', 'value' => '支持 SAML 2.0 / OIDC / LDAP / 统一身份与设备绑定'],
                    ], JSON_UNESCAPED_UNICODE),
                    'views'              => 1890,
                    'likes'              => 146,
                    'weight'             => 85,
                ],
                [
                    'category_id'        => $catMap['edge-hardware'] ?? 1,
                    'title'              => 'VisionPro-M2 工业级高帧率高灵敏度机器视觉智能相机',
                    'summary'            => '集成 2000 万像素全局快门传感器与边缘神经网络处理单元，毫秒级完成微米级工件瑕疵、文字 OCR 与尺寸测量。',
                    'content'            => '<p>VisionPro-M2 是现代化高精尖产线不可或缺的机器视觉眼睛。自带可调光源与自适应自动对焦液体镜头，支持 GigE Vision 协议直接接入工业网络。</p>',
                    'cover'              => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '机器视觉产品组',
                    'tags'               => '机器视觉,智能相机,工业质检,2000万像素',
                    'is_recommend'       => 0,
                    'is_hot'             => 1,
                    'price_label'        => '¥8,600',
                    'market_price_label' => '¥9,800',
                    'sku_code'           => 'HW-VP-M20',
                    'badge_text'         => '高精质检',
                    'specs_json'         => json_encode([
                        ['label' => '感光元件', 'value' => '1 英寸索尼高灵敏度全局快门 CMOS 传感器'],
                        ['label' => '有效分辨率', 'value' => '5472 × 3648 (2000万有效像素) @ 90fps 高帧率'],
                        ['label' => '光学镜头', 'value' => '标准 C-Mount 接口，支持软件受控电调变倍对焦'],
                        ['label' => '数据输出', 'value' => 'PoE 千兆以太网 (单线缆供电与图像传输)'],
                    ], JSON_UNESCAPED_UNICODE),
                    'views'              => 1630,
                    'likes'              => 98,
                    'weight'             => 80,
                ],
                [
                    'category_id'        => $catMap['gear'] ?? 1,
                    'title'              => 'Titanium-Key 极客定制全CNC铝合金机械键盘',
                    'summary'            => '阳极氧化全铝合金机身，Gasket 结构设计，定制超耐久线性轴体，三模低延迟无线连接，专为工程师与码农设计。',
                    'content'            => '<p>每一位优秀工程师的指尖生产力工具。采用航空级 6063 铝合金经 8 道精密 CNC 加工打造，内部配备五重消音缓冲结构，带来如丝顺滑的敲击质感与沉稳声响。</p>',
                    'cover'              => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '极客周边严选',
                    'tags'               => '机械键盘,全铝客制化,Gasket结构,桌面美学',
                    'is_recommend'       => 1,
                    'is_hot'             => 1,
                    'price_label'        => '¥899',
                    'market_price_label' => '¥1,199',
                    'sku_code'           => 'HW-TK-87PRO',
                    'badge_text'         => '极客严选爆款',
                    'specs_json'         => json_encode([
                        ['label' => '外壳材质', 'value' => '航空级 6063 铝合金全 CNC 切削 + 阳极氧化喷砂工艺'],
                        ['label' => '结构方案', 'value' => '板簧 Gasket 软弹减震结构 + FR4 沉金定位板'],
                        ['label' => '连接方式', 'value' => 'Type-C 有线 + 2.4G 无线 (1000Hz) + 蓝牙 5.3 (三设备记忆)'],
                        ['label' => '电池续航', 'value' => '内置 8000mAh 大容量安全锂电，无光状态续航约 300 小时'],
                    ], JSON_UNESCAPED_UNICODE),
                    'views'              => 3860,
                    'likes'              => 450,
                    'weight'             => 75,
                ],
                [
                    'category_id'        => $catMap['gear'] ?? 1,
                    'title'              => 'ErgoPro-360 旗舰双背人体工学办公工程椅',
                    'summary'            => '自适应重力感应底盘、仿生动态双背脊柱支撑、4D 软包联动扶手与全透气抗拉伸进口杜邦网布。',
                    'content'            => '<p>长达 10 小时的深度沉浸编码，需要一套始终与脊椎曲线贴合的支撑系统。ErgoPro-360 提供 135° 大角度无极后仰锁定与舒适隐藏式脚托，守护工程师的腰椎健康。</p>',
                    'cover'              => 'https://images.unsplash.com/photo-1580481077190-7361456dd06f?w=800&q=80',
                    'content_type'       => 'product',
                    'author'             => '极客周边严选',
                    'tags'               => '人体工学椅,健康办公,杜邦网布,脊椎呵护',
                    'is_recommend'       => 0,
                    'is_hot'             => 0,
                    'price_label'        => '¥1,699',
                    'market_price_label' => '¥2,199',
                    'sku_code'           => 'HW-CHAIR-E360',
                    'badge_text'         => '健康生活',
                    'specs_json'         => json_encode([
                        ['label' => '支撑骨架', 'value' => '高强度加厚铝合金一体铸造五星脚 + 德国 TUV 四级气压棒'],
                        ['label' => '面料材质', 'value' => '高弹力抗疲劳透气杜邦尼龙网布（抗磨损、易清洁）'],
                        ['label' => '调节维度', 'value' => '座深前后滑动 / 4D 升降旋转扶手 / 自适应双背顶腰力学调校'],
                        ['label' => '后仰角度', 'value' => '90° 专注办公 ~ 135° 休憩午睡无极锁定'],
                    ], JSON_UNESCAPED_UNICODE),
                    'views'              => 2140,
                    'likes'              => 188,
                    'weight'             => 70,
                ],
            ];

            foreach ($showcaseProducts as $art) {
                $has = Db::name('cms_article')->where('title', $art['title'])->find();
                if (!$has) {
                    Db::name('cms_article')->insert(array_merge($art, [
                        'status'      => 1,
                        'create_time' => $now - rand(3600, 86400 * 3),
                        'update_time' => $now,
                    ]));
                }
            }

            // 5. 网站横幅数据 (hw_cms_banner) 为 blog, corporate, showcase 各灌入 2 条
            $bannerSeeds = [
                // 博客模板横幅
                [
                    'template_code' => 'blog',
                    'title'         => '探索架构与工程美学的无垠边界',
                    'subtitle'      => '汇聚分布式后端、微服务架构、现代前端交互与大语言模型工程落地实战长文。',
                    'badge'         => '极客老王 · 个人技术空间',
                    'image'         => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=1600&q=80',
                    'btn_text'      => '阅读精选文章',
                    'btn_link'      => '#articles-section',
                    'weight'        => 100,
                ],
                [
                    'template_code' => 'blog',
                    'title'         => '开源生活与独立全栈开发手记',
                    'subtitle'      => '记录从 0 到 1 打造数字化产品的思考、工具链革新与技术审美。',
                    'badge'         => '保持好奇 · 热爱创造',
                    'image'         => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=1600&q=80',
                    'btn_text'      => '探索作者随笔',
                    'btn_link'      => '#articles-section',
                    'weight'        => 90,
                ],

                // 企业官网横幅
                [
                    'template_code' => 'corporate',
                    'title'         => '新一代企业级数字化高可用敏捷基座',
                    'subtitle'      => 'ThinkPHP 8 + Vue 3 极速架构，双数据库原生兼容与零侵入插件体系，驱动企业数智化腾飞。',
                    'badge'         => 'HwAdmin 2.0 旗舰发布',
                    'image'         => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=1600&q=80',
                    'btn_text'      => '查看核心解决方案',
                    'btn_link'      => '#solutions-section',
                    'weight'        => 100,
                ],
                [
                    'template_code' => 'corporate',
                    'title'         => '信创时代下值得托付的全栈技术底座',
                    'subtitle'      => '已服务超过 500+ 家头部企业与政企机构，99.999% SLA 工业级可靠运行保障。',
                    'badge'         => '政企数字化转型首选',
                    'image'         => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=1600&q=80',
                    'btn_text'      => '预约专家演示',
                    'btn_link'      => '#contact-section',
                    'weight'        => 90,
                ],

                // 产品展厅横幅
                [
                    'template_code' => 'showcase',
                    'title'         => '2026 智能算力与极客严选硬件橱窗',
                    'subtitle'      => '从 48 TOPS 工业边缘计算终端，到云原生敏捷服务器与全铝定制外设，官方原厂直供。',
                    'badge'         => '全系原厂现货 · 企业批量询价',
                    'image'         => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?w=1600&q=80',
                    'btn_text'      => '浏览全部展品',
                    'btn_link'      => '#products-grid',
                    'weight'        => 100,
                ],
                [
                    'template_code' => 'showcase',
                    'title'         => '企业定制采购方案，尊享阶梯专属优惠',
                    'subtitle'      => '支持小批量柔性打样、非标参数定制与全国联保技术支持，一键索取采购清单报价。',
                    'badge'         => 'B2B 采购通道开通',
                    'image'         => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?w=1600&q=80',
                    'btn_text'      => '索取企业报价单',
                    'btn_link'      => '#inquiry-dialog',
                    'weight'        => 90,
                ],
            ];

            foreach ($bannerSeeds as $b) {
                $has = Db::name('cms_banner')->where('title', $b['title'])->find();
                if (!$has) {
                    Db::name('cms_banner')->insert(array_merge($b, [
                        'status'      => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]));
                }
            }

            // 6. 幂等初始化 2 个演示会员账号 (hw_user)
            $vipUser = Db::name('user')->where('username', 'vip_member')->find();
            if (!$vipUser) {
                $vipId = (int) Db::name('user')->insertGetId([
                    'group_id'        => 1,
                    'username'        => 'vip_member',
                    'nickname'        => '林星河 (企业VIP)',
                    'password'        => \core\auth\PasswordHasher::hash('123456'),
                    'email'           => 'vip@example.com',
                    'mobile'          => '13888886666',
                    'avatar'          => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=200&q=80',
                    'level'           => 3,
                    'score'           => 580,
                    'money'           => 1200.00,
                    'status'          => 1,
                    'join_ip'         => '127.0.0.1',
                    'last_login_time' => $now,
                    'last_login_ip'   => '127.0.0.1',
                    'create_time'     => $now - 86400 * 30,
                    'update_time'     => $now,
                ]);
            } else {
                $vipId = (int) $vipUser['id'];
            }

            $geekUser = Db::name('user')->where('username', 'geek_reader')->find();
            if (!$geekUser) {
                $geekId = (int) Db::name('user')->insertGetId([
                    'group_id'        => 1,
                    'username'        => 'geek_reader',
                    'nickname'        => '极客小陈',
                    'password'        => \core\auth\PasswordHasher::hash('123456'),
                    'email'           => 'geek@example.com',
                    'mobile'          => '13900139000',
                    'avatar'          => 'https://images.unsplash.com/photo-1570295999919-56ceb5ecca61?w=200&q=80',
                    'level'           => 2,
                    'score'           => 260,
                    'money'           => 0.00,
                    'status'          => 1,
                    'join_ip'         => '127.0.0.1',
                    'last_login_time' => $now,
                    'last_login_ip'   => '127.0.0.1',
                    'create_time'     => $now - 86400 * 15,
                    'update_time'     => $now,
                ]);
            } else {
                $geekId = (int) $geekUser['id'];
            }

            // 7. 示例留言与询单数据 (hw_cms_inquiry) 并绑定会员
            $inquirySeeds = [
                [
                    'scene'         => 'blog_comment',
                    'target_id'     => 1,
                    'target_title'  => '从单体到高可用微服务：HwAdmin 架构演进与零侵入插件实践',
                    'user_id'       => $geekId,
                    'user_nickname' => '极客小陈',
                    'name'          => '极客小陈',
                    'contact'       => 'geek@example.com',
                    'company'       => '某开源极客组织',
                    'content'       => '拜读了老王的文章，对零侵入插件设计深有共鸣！请教下在多插件并发路由分发时，如何做好中间件的按需穿透过滤？',
                    'reply_content' => '感谢认可！我们在 PluginDispatcher 中通过统一命名空间映射，由宿主 App 在分发目标控制器前动态绑定插件独立的中间件配置，既隔离又高效。',
                    'status'        => 1,
                    'ip'            => '127.0.0.1',
                ],
                [
                    'scene'         => 'corp_contact',
                    'target_id'     => 0,
                    'target_title'  => '企业数字化转型商务咨询',
                    'user_id'       => $vipId,
                    'user_nickname' => '林星河 (企业VIP)',
                    'name'          => '林星河 (企业VIP)',
                    'contact'       => '13888886666',
                    'company'       => '华东某智能装备集团',
                    'content'       => '我们正在规划新一代车间 IIoT 边缘平台，计划年内覆盖 6 条核心装配线，希望与贵司专家沟通解决方案并预约系统演示。',
                    'reply_content' => '林总您好！已将您的需求同步至华东区技术顾问，稍后顾问将通过电话与您约定线上演示时间，期待合作！',
                    'status'        => 1,
                    'ip'            => '114.250.88.12',
                ],
                [
                    'scene'         => 'product_inquiry',
                    'target_id'     => 1,
                    'target_title'  => 'EdgeBox-9000 工业级边缘 AI 推理智能终端',
                    'user_id'       => $vipId,
                    'user_nickname' => '林星河 (企业VIP)',
                    'name'          => '林星河 (企业VIP)',
                    'contact'       => 'vip@example.com',
                    'company'       => '深圳某机器人视觉系统集成商',
                    'content'       => '咨询 EdgeBox-9000 终端 50 台采购批次交期与专属阶梯报价，以及是否支持预刷我们自研的 Ubuntu 衍生版镜像。',
                    'reply_content' => '林总您好！50 台现货充足，我们支持工厂级镜像批量代刷服务，已向您的邮箱发送阶梯报价单与技术对接协议。',
                    'status'        => 1,
                    'ip'            => '113.108.122.35',
                ],
            ];

            foreach ($inquirySeeds as $inq) {
                $has = Db::name('cms_inquiry')->where('scene', $inq['scene'])->where(function ($q) use ($inq) {
                    $q->where('name', $inq['name'])->whereOr('contact', $inq['contact']);
                })->find();
                if (!$has) {
                    Db::name('cms_inquiry')->insert(array_merge($inq, [
                        'create_time' => $now - rand(1800, 86400 * 2),
                        'update_time' => $now,
                    ]));
                } else {
                    if (empty($has['user_id'])) {
                        Db::name('cms_inquiry')->where('id', $has['id'])->update([
                            'user_id'       => $inq['user_id'],
                            'user_nickname' => $inq['user_nickname'],
                            'name'          => $inq['name'],
                            'contact'       => $inq['contact'],
                        ]);
                    }
                }
            }

        } catch (Throwable $e) {
            $this->app->log->warning('[CMS Plugin] 初始化示例数据异常: ' . $e->getMessage());
        }
    }
}
