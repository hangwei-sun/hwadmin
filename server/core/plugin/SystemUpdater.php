<?php
declare(strict_types=1);

namespace core\plugin;

use core\exception\ApiException;
use core\support\OutboundHttpGuard;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use Throwable;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * HwAdmin 框架与插件在线更新核心引擎
 *
 * 职责：
 * 1. 框架与插件版本比对检测 (checkUpdates)
 * 2. 升级前安全物理备份与历史快照管理 (backupFramework / backupPlugin)
 * 3. 框架在线平滑热更新与数据库增量迁移 (updateFramework)
 * 4. 插件热升级、依赖校验与静态资源重新分发 (updatePlugin)
 * 5. 一键原子快照版本回滚 (rollbackUpdate)
 */
class SystemUpdater
{
    public const CURRENT_VERSION = '1.6.7';
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const VALID_CHANNELS = [self::CHANNEL_STABLE, self::CHANNEL_BETA];

    /**
     * 官方内置后台控制器相对路径列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_ADMIN_CONTROLLERS = [
        'Dashboard.php',
        'Index.php',
        'auth/Admin.php',
        'auth/AdminLog.php',
        'auth/Group.php',
        'auth/Rule.php',
        'system/Attachment.php',
        'system/Config.php',
        'system/Crontab.php',
        'system/Devops.php',
        'system/Dict.php',
        'system/Plugin.php',
        'user/Group.php',
        'user/User.php',
    ];

    /**
     * 官方内置公共模型相对路径列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_COMMON_MODELS = [
        'Admin.php',
        'AdminLog.php',
        'Attachment.php',
        'AuthGroup.php',
        'AuthRule.php',
        'Config.php',
        'User.php',
        'UserGroup.php',
        'system/Crontab.php',
        'system/CrontabLog.php',
        'system/Dict.php',
        'system/DictItem.php',
    ];

    /**
     * 官方内置前端 API 控制器文件名列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_API_CONTROLLERS = [
        'Index.php',
        'User.php',
    ];

    /**
     * 官方内置前台控制器文件名列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_INDEX_CONTROLLERS = [
        'Index.php',
    ];

    /**
     * 官方内置公共服务文件名列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_COMMON_SERVICES = [
        'ConfigService.php',
        'DeploymentService.php',
        'DictService.php',
    ];

    /**
     * 官方内置源码插件目录名列表（用于无指纹历史站二开探测）
     */
    private const OFFICIAL_BUILTIN_PLUGINS = [
        'cms',
        'crud',
        'demo',
    ];

    /**
     * 站点级保护文件：仅当目标站点尚不存在时允许初始化写入，已存在时严格跳过覆写
     */
    private const SITE_PRESERVE_IF_EXISTS_PATHS = [
        'config/hwadmin.php',
        'public/.htaccess',
        'public/nginx.htaccess',
        'public/favicon.ico',
        'public/admin/hwadmin.config.js',
    ];

    /**
     * 客户端允许 OTA 补丁写入的框架路径前缀白名单
     */
    private const ALLOWED_FRAMEWORK_PATCH_PREFIXES = [
        'core/',
        'public/admin/',
        'database/migrations/',
        'app/adminapi/controller/system/',
        'app/adminapi/controller/auth/',
        'app/common/model/system/',
    ];

    /**
     * 客户端允许 OTA 补丁写入的框架精确文件路径白名单
     */
    private const ALLOWED_FRAMEWORK_PATCH_FILES = [
        'version.json',
        'config/hwadmin.php',
        'app/adminapi/controller/Index.php',
        'app/adminapi/controller/Dashboard.php',
        'app/common/model/Admin.php',
        'app/common/model/AdminLog.php',
        'app/common/model/Attachment.php',
        'app/common/model/AuthGroup.php',
        'app/common/model/AuthRule.php',
        'app/common/model/Config.php',
        'app/common/service/ConfigService.php',
        'app/common/service/DictService.php',
        'app/common/service/DeploymentService.php',
        'app/index/controller/Index.php',
        'public/index.php',
        'public/.htaccess',
        'public/nginx.htaccess',
        'public/favicon.ico',
        'public/router.php',
        'public/deployment-check.js',
    ];

    /**
     * 升级前需要额外备份的 public/ 入口与部署探测文件
     */
    private const PUBLIC_ENTRY_BACKUP_FILES = [
        'index.php',
        'router.php',
        'deployment-check.js',
    ];

    /**
     * OTA 在线更新覆盖范围的官方 app/ 核心文件清单（用于升级前原子备份与一键回滚）
     */
    private const OFFICIAL_APP_CORE_FILES = [
        '.htaccess',
        'AppService.php',
        'ExceptionHandle.php',
        'Request.php',
        'common.php',
        'event.php',
        'middleware.php',
        'provider.php',
        'service.php',
        'adminapi/route/app.php',
        'adminapi/controller/Dashboard.php',
        'adminapi/controller/Index.php',
        'adminapi/controller/auth/Admin.php',
        'adminapi/controller/auth/AdminLog.php',
        'adminapi/controller/auth/Group.php',
        'adminapi/controller/auth/Rule.php',
        'adminapi/controller/system/Attachment.php',
        'adminapi/controller/system/Config.php',
        'adminapi/controller/system/Crontab.php',
        'adminapi/controller/system/Devops.php',
        'adminapi/controller/system/Dict.php',
        'adminapi/controller/system/Plugin.php',
        'adminapi/controller/user/Group.php',
        'adminapi/controller/user/User.php',
        'api/controller/Index.php',
        'api/controller/User.php',
        'api/route/app.php',
        'common/model/Admin.php',
        'common/model/AdminLog.php',
        'common/model/Attachment.php',
        'common/model/AuthGroup.php',
        'common/model/AuthRule.php',
        'common/model/Config.php',
        'common/model/User.php',
        'common/model/UserGroup.php',
        'common/model/system/Crontab.php',
        'common/model/system/CrontabLog.php',
        'common/model/system/Dict.php',
        'common/model/system/DictItem.php',
        'common/service/ConfigService.php',
        'common/service/DeploymentService.php',
        'common/service/DictService.php',
        'index/controller/Index.php',
        'index/route/app.php',
        'index/view/cms/index.html',
        'index/view/portal/index.html',
        'install/controller/Index.php',
        'install/view/index.html',
    ];

    protected string $runtimeBackupDir;
    protected string $metaFile;

    public function __construct(protected App $app)
    {
        $this->runtimeBackupDir = $this->app->getRuntimePath() . 'backups' . DIRECTORY_SEPARATOR;
        $this->metaFile = $this->runtimeBackupDir . 'backups.json';
        if (!is_dir($this->runtimeBackupDir)) {
            @mkdir($this->runtimeBackupDir, 0755, true);
        }
    }

    /**
     * 获取当前系统框架真实版本
     */
    public function getVersion(): string
    {
        $versionFile = $this->app->getRootPath() . 'version.json';
        if (is_file($versionFile)) {
            $json = json_decode((string) file_get_contents($versionFile), true);
            if (is_array($json) && !empty($json['version'])) {
                return (string) $json['version'];
            }
        }

        return (string) config('hwadmin.version', self::CURRENT_VERSION);
    }

    /**
     * 规范化系统更新通道标识（仅允许 stable | beta，默认 stable）
     */
    public static function normalizeUpdateChannel(?string $channel): string
    {
        $val = strtolower(trim((string) $channel));
        return in_array($val, self::VALID_CHANNELS, true) ? $val : self::CHANNEL_STABLE;
    }

    /**
     * 获取当前站点配置的更新通道（stable | beta，默认 stable）
     */
    public function getUpdateChannel(): string
    {
        try {
            if (class_exists(\app\common\service\ConfigService::class)) {
                $val = \app\common\service\ConfigService::get('update_channel');
                if ($val !== null && $val !== '') {
                    return self::normalizeUpdateChannel((string) $val);
                }
            }
        } catch (Throwable) {
        }

        try {
            $val = Db::name('config')->where('name', 'update_channel')->value('value');
            if ($val !== null && $val !== '') {
                return self::normalizeUpdateChannel((string) $val);
            }
        } catch (Throwable) {
        }

        return self::CHANNEL_STABLE;
    }

    /**
     * 设置并持久化系统更新通道（仅允许 stable | beta）
     */
    public function setUpdateChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));
        if (!in_array($normalized, self::VALID_CHANNELS, true)) {
            throw new ApiException('非法的更新通道，仅支持 stable 或 beta');
        }

        $now = time();
        $exists = Db::name('config')->where('name', 'update_channel')->find();
        if ($exists) {
            Db::name('config')->where('name', 'update_channel')->update([
                'value'       => $normalized,
                'update_time' => $now,
            ]);
        } else {
            Db::name('config')->insert([
                'group'       => 'basic',
                'name'        => 'update_channel',
                'title'       => '系统更新通道',
                'type'        => 'select',
                'value'       => $normalized,
                'options'     => '{"stable":"稳定版 (Stable)","beta":"内测版 (Beta)"}',
                'tip'         => '切换在线更新通道：稳定版适合生产环境，内测版可抢先体验新功能',
                'weight'      => 5,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        if (class_exists(\app\common\service\ConfigService::class)) {
            \app\common\service\ConfigService::clearCache();
        }

        return $normalized;
    }

    /**
     * 解析当前站点域名（用于内测白名单匹配）
     */
    public function resolveSiteDomain(?string $siteDomain = null): string
    {
        $candidate = trim((string) $siteDomain);
        if ($candidate === '') {
            try {
                $candidate = trim((string) $this->app->request->host(true));
            } catch (Throwable) {
                $candidate = '';
            }
        }
        if ($candidate === '') {
            try {
                $authFile = $this->app->getRuntimePath() . 'market_auth.json';
                if (is_file($authFile)) {
                    $json = json_decode((string) file_get_contents($authFile), true);
                    if (is_array($json) && !empty($json['site_domain'])) {
                        $candidate = trim((string) $json['site_domain']);
                    }
                }
            } catch (Throwable) {
            }
        }
        if ($candidate === '') {
            try {
                $val = Db::name('config')->where('name', 'market_auth_info')->value('value');
                if ($val) {
                    $json = json_decode((string) $val, true);
                    if (is_array($json) && !empty($json['site_domain'])) {
                        $candidate = trim((string) $json['site_domain']);
                    }
                }
            } catch (Throwable) {
            }
        }
        if ($candidate === '') {
            $candidate = trim((string) (env('APP_URL', '') ?: ''));
        }
        if ($candidate === '') {
            return '';
        }

        $parsedHost = parse_url(str_contains($candidate, '://') ? $candidate : 'https://' . $candidate, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            return strtolower($parsedHost);
        }

        return strtolower(preg_replace('/:\d+$/', '', $candidate) ?: $candidate);
    }

    /**
     * 安全追加或补全 URL 查询参数（默认不覆盖已有非空参数，可选覆盖）
     */
    public static function appendUrlQueryParams(string $url, array $extraParams, bool $overwrite = false): string
    {
        if ($url === '' || empty($extraParams)) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($extraParams as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($overwrite || !array_key_exists($key, $query) || $query[$key] === '') {
                $query[$key] = (string) $value;
            }
        }

        $rebuilt = '';
        if (!empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (!empty($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (!empty($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }
        if (!empty($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if (!empty($query)) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * 全局检测框架与插件的最新可用版本
     */
    public function checkUpdates(?string $gatewayUrl = null, ?string $siteDomain = null, ?string $channel = null): array
    {
        $currentFrameworkVer = ltrim((string) $this->getVersion(), 'vV');
        $activeChannel = $channel !== null ? self::normalizeUpdateChannel($channel) : $this->getUpdateChannel();
        $resolvedSiteDomain = $this->resolveSiteDomain($siteDomain);

        // 收集本地已安装插件状态
        /** @var PluginManager $pluginManager */
        $pluginManager = $this->app->make(PluginManager::class);
        $localPlugins = $pluginManager->all();

        // 规范化网关地址
        $cleanGatewayUrl = $gatewayUrl ? rtrim($gatewayUrl, '/') : null;

        // 尝试从网关拉取最新目录
        $remoteCatalog = null;
        if ($cleanGatewayUrl) {
            $remoteCatalog = $this->fetchFromGateway($cleanGatewayUrl, $activeChannel, $resolvedSiteDomain);
        }

        // 官方内置最新基线定义 (作为主更新端模拟或离线回退源)
        $latestFrameworkVer = ltrim((string) ($remoteCatalog['framework']['latest_version'] ?? self::CURRENT_VERSION), 'vV');
        $rawReleaseChannel = (string) ($remoteCatalog['framework']['release_channel'] ?? ($remoteCatalog['framework']['channel'] ?? ''));
        $frameworkChangelog = $remoteCatalog['framework']['changelog'] ?? [
            '1. 新增可视化全栈CRUD生成插件 (hwadmin-crud)',
            '2. 新增框架与插件在线热更新与安全备份回滚中枢',
            '3. 新增数据字典、定时任务与系统运维工具箱',
            '4. 增强免编译微视图容器 (Micro-View) 即插即用运行支持',
            '5. 完善 Web 安装向导 SQLite 零配置极速安装选项',
        ];
        $releaseDate = $remoteCatalog['framework']['release_date'] ?? '2026-11-25';
        $patchSha256 = strtolower(trim((string) ($remoteCatalog['framework']['patch_sha256'] ?? '')));
        $corePatchSha256 = strtolower(trim((string) ($remoteCatalog['framework']['core_patch_sha256'] ?? '')));
        $fullPatchSha256 = strtolower(trim((string) ($remoteCatalog['framework']['full_patch_sha256'] ?? '')));

        // 计算框架默认补丁包、仅内核包与前后端完整包下载地址
        $rawFrameworkUrl = (string) ($remoteCatalog['framework']['download_url'] ?? '');
        $rawCoreUrl = (string) ($remoteCatalog['framework']['core_download_url'] ?? '');
        $rawFullUrl = (string) ($remoteCatalog['framework']['full_download_url'] ?? '');

        if ($rawReleaseChannel === '' && $rawFrameworkUrl !== '' && str_contains($rawFrameworkUrl, 'channel=beta')) {
            $rawReleaseChannel = self::CHANNEL_BETA;
        }
        $releaseChannel = self::normalizeUpdateChannel($rawReleaseChannel !== '' ? $rawReleaseChannel : self::CHANNEL_STABLE);

        $downloadUrl = $this->resolveFrameworkPackageUrl(
            $rawFrameworkUrl,
            $cleanGatewayUrl,
            $releaseChannel,
            $activeChannel,
            $resolvedSiteDomain,
            $cleanGatewayUrl ? $cleanGatewayUrl . '/download?name=framework' : ''
        );
        $coreDownloadUrl = $this->resolveFrameworkPackageUrl(
            $rawCoreUrl,
            $cleanGatewayUrl,
            $releaseChannel,
            $activeChannel,
            $resolvedSiteDomain,
            ''
        );
        $fullDownloadUrl = $rawFullUrl !== ''
            ? $this->resolveFrameworkPackageUrl(
                $rawFullUrl,
                $cleanGatewayUrl,
                $releaseChannel,
                $activeChannel,
                $resolvedSiteDomain,
                ''
            )
            : ($downloadUrl !== '' ? self::appendUrlQueryParams($downloadUrl, ['scope' => 'full'], true) : '');

        // 插件版本对比
        $pluginsUpdateMap = [];
        $marketCatalogList = [];

        if (!empty($remoteCatalog['plugins']) && is_array($remoteCatalog['plugins'])) {
            $marketCatalogList = $remoteCatalog['plugins'];
        } elseif (!empty($remoteCatalog['list']) && is_array($remoteCatalog['list'])) {
            // 兼容官网 catalog 接口返回的 list 数组结构
            foreach ($remoteCatalog['list'] as $item) {
                if (!empty($item['name'])) {
                    $pName = (string) $item['name'];
                    $rawPluginUrl = $item['download_url'] ?? '';
                    if ($rawPluginUrl !== '' && (str_starts_with($rawPluginUrl, 'http://') || str_starts_with($rawPluginUrl, 'https://'))) {
                        $pDownloadUrl = $rawPluginUrl;
                    } elseif ($cleanGatewayUrl) {
                        $pDownloadUrl = $cleanGatewayUrl . '/download?name=' . urlencode($pName);
                    } else {
                        $pDownloadUrl = $rawPluginUrl;
                    }

                    $marketCatalogList[$pName] = [
                        'name'           => $pName,
                        'title'          => $item['title'] ?? $pName,
                        'latest_version' => $item['latest_version'] ?? ($item['version'] ?? '1.0.0'),
                        'changelog'      => !empty($item['features']) && is_array($item['features'])
                            ? implode("\n", $item['features'])
                            : ($item['description'] ?? '官方云端最新版本'),
                        'download_url'   => $pDownloadUrl,
                        'price_type'     => $item['price_type'] ?? 'free',
                        'price'          => $item['price'] ?? 0,
                    ];
                }
            }
        } else {
            // 离线回退目录
            $marketCatalogList = [
                'cms' => [
                    'latest_version' => '2.1.5',
                    'changelog'      => "1. 增加文章多标签与富文本图片拖拽直传\n2. 优化栏目无限极树形性能",
                    'download_url'   => '',
                ],
                'crud' => [
                    'latest_version' => '1.0.2',
                    'changelog'      => '可视化全栈 CRUD 代码生成器，支持一键建表与逆向工程',
                    'download_url'   => '',
                ],
                'demo' => [
                    'latest_version' => '1.0.2',
                    'changelog'      => '完善插件生命周期示范钩子',
                    'download_url'   => '',
                ],
            ];
        }

        foreach ($localPlugins as $p) {
            $name = (string) $p['name'];
            $curVer = ltrim((string) ($p['installed_version'] ?? ($p['version'] ?? '1.0.0')), 'vV');
            $marketItem = $marketCatalogList[$name] ?? null;
            $latestVer = ltrim((string) ($marketItem['latest_version'] ?? $curVer), 'vV');

            $hasUpdate = version_compare($latestVer, $curVer, '>');
            $pluginsUpdateMap[$name] = [
                'title'           => $p['title'] ?? ($marketItem['title'] ?? $name),
                'current_version' => $curVer,
                'latest_version'  => $latestVer,
                'has_update'      => $hasUpdate,
                'installed'       => !empty($p['installed']),
                'enabled'         => !empty($p['enabled']),
                'changelog'       => $marketItem['changelog'] ?? '暂无更新日志',
                'download_url'    => $marketItem['download_url'] ?? ($cleanGatewayUrl ? $cleanGatewayUrl . '/download?name=' . urlencode($name) : ''),
            ];
        }

        return [
            'framework' => [
                'name'              => 'hwadmin',
                'current_version'   => $currentFrameworkVer,
                'latest_version'    => $latestFrameworkVer,
                'has_update'        => version_compare($latestFrameworkVer, $currentFrameworkVer, '>'),
                'update_channel'    => $activeChannel,
                'release_channel'   => $releaseChannel,
                'release_date'      => $releaseDate,
                'changelog'         => $frameworkChangelog,
                'download_url'      => $downloadUrl,
                'patch_sha256'      => $patchSha256,
                'core_download_url' => $coreDownloadUrl,
                'core_patch_sha256' => $corePatchSha256,
                'full_download_url' => $fullDownloadUrl,
                'full_patch_sha256' => $fullPatchSha256,
                'frontend_status'   => $this->detectFrontendStatus(),
                'min_php'           => '8.1',
            ],
            'plugins'   => $pluginsUpdateMap,
            'backups'   => $this->getBackups(),
        ];
    }

    /**
     * 规范化框架更新包 URL 并透传内测通道与站点域名参数
     */
    protected function resolveFrameworkPackageUrl(
        string $rawUrl,
        ?string $cleanGatewayUrl,
        string $releaseChannel,
        string $activeChannel,
        string $resolvedSiteDomain,
        string $fallbackUrl = ''
    ): string {
        if ($rawUrl !== '') {
            if (str_starts_with($rawUrl, 'http://') || str_starts_with($rawUrl, 'https://')) {
                $resolved = $rawUrl;
            } elseif ($cleanGatewayUrl) {
                $parts = parse_url($cleanGatewayUrl);
                $scheme = $parts['scheme'] ?? 'http';
                $host = $parts['host'] ?? '127.0.0.1';
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $resolved = $scheme . '://' . $host . $port . '/' . ltrim($rawUrl, '/');
            } else {
                $resolved = $rawUrl;
            }
        } else {
            $resolved = $fallbackUrl;
        }

        if ($resolved !== '' && ($releaseChannel === self::CHANNEL_BETA || $activeChannel === self::CHANNEL_BETA)) {
            $resolved = self::appendUrlQueryParams($resolved, [
                'channel'     => $releaseChannel,
                'site_domain' => $resolvedSiteDomain,
            ]);
        }

        return $resolved;
    }

    /**
     * 检测当前站点前端 UI 状态（区分官方纯净前端、二开自定义前端与历史站点）
     *
     * @return array{mode:string,is_custom:bool,preserve_frontend_default:bool,label:string,reason:string}
     */
    public function detectFrontendStatus(): array
    {
        $rootPath = rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/') . '/';
        $adminDir = $rootPath . 'public/admin/';
        $customMarker = $adminDir . '.custom-ui';
        $manifestFile = $adminDir . 'hwadmin-official-ui.json';
        $indexFile = $adminDir . 'index.html';

        if (is_file($customMarker)) {
            return [
                'mode'                      => 'custom',
                'is_custom'                 => true,
                'preserve_frontend_default' => true,
                'label'                     => '二开自定义前端',
                'reason'                    => '检测到 public/admin/.custom-ui 自定义标记，默认跳过覆盖前端构建产物',
            ];
        }

        if (is_file($manifestFile)) {
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            $isOfficialFlag = is_array($manifest) && (($manifest['official'] ?? false) === true);
            $expectedSha = is_array($manifest) ? strtolower(trim((string) ($manifest['index_html_sha256'] ?? ''))) : '';
            $actualSha = is_file($indexFile) ? strtolower((string) hash_file('sha256', $indexFile)) : '';

            if ($isOfficialFlag && $expectedSha !== '' && $actualSha !== '' && hash_equals($expectedSha, $actualSha)) {
                return [
                    'mode'                      => 'official',
                    'is_custom'                 => false,
                    'preserve_frontend_default' => false,
                    'label'                     => '官方纯净前端',
                    'reason'                    => 'hwadmin-official-ui.json 官方指纹与 public/admin/index.html SHA256 完全匹配，可安全同步更新前后端',
                ];
            }

            if (!$isOfficialFlag) {
                $reason = 'hwadmin-official-ui.json 标记为本地自定义编译前端（official=false），默认保留当前前端';
            } elseif (!is_file($indexFile)) {
                $reason = '缺少 public/admin/index.html，无法验证官方纯净前端指纹，默认保留当前前端';
            } else {
                $reason = 'public/admin/index.html SHA256 与官方指纹不一致，检测到前端已被修改或重新编译，默认保留当前前端';
            }

            return [
                'mode'                      => 'custom',
                'is_custom'                 => true,
                'preserve_frontend_default' => true,
                'label'                     => '二开自定义前端',
                'reason'                    => $reason,
            ];
        }

        $customModules = $this->findCustomAppModules($rootPath);
        if (!empty($customModules)) {
            $preview = implode('、', array_slice($customModules, 0, 3));
            $suffix = count($customModules) > 3 ? ' 等 ' . count($customModules) . ' 个文件' : '';
            return [
                'mode'                      => 'legacy_custom_suspected',
                'is_custom'                 => true,
                'preserve_frontend_default' => true,
                'label'                     => '疑似二开历史站点',
                'reason'                    => "未检测到官方前端指纹清单，且发现自建业务模块（{$preview}{$suffix}），安全起见默认保留当前前端",
            ];
        }

        return [
            'mode'                      => 'legacy_clean',
            'is_custom'                 => false,
            'preserve_frontend_default' => false,
            'label'                     => '历史纯净站点',
            'reason'                    => '未检测到官方前端指纹清单，且未发现自建业务控制器或模型，可同步更新前后端',
        ];
    }

    /**
     * 扫描内置业务目录及 plugins/ 下是否存在非官方内置的二开模块
     *
     * @return string[]
     */
    protected function findCustomAppModules(string $rootPath): array
    {
        $targets = [
            'app/adminapi/controller' => array_flip(self::OFFICIAL_ADMIN_CONTROLLERS),
            'app/common/model'        => array_flip(self::OFFICIAL_COMMON_MODELS),
            'app/api/controller'      => array_flip(self::OFFICIAL_API_CONTROLLERS),
            'app/index/controller'    => array_flip(self::OFFICIAL_INDEX_CONTROLLERS),
            'app/common/service'      => array_flip(self::OFFICIAL_COMMON_SERVICES),
        ];

        $customFiles = [];
        foreach ($targets as $relDir => $officialSet) {
            $fullDir = $rootPath . $relDir;
            if (!is_dir($fullDir)) {
                continue;
            }
            $normalizedDir = rtrim(str_replace('\\', '/', (string) realpath($fullDir)), '/') . '/';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($normalizedDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }
                $filename = $file->getFilename();
                if (str_starts_with($filename, '.')) {
                    continue;
                }
                $realFile = str_replace('\\', '/', (string) $file->getRealPath());
                if (!str_starts_with($realFile, $normalizedDir)) {
                    continue;
                }
                $relPath = substr($realFile, strlen($normalizedDir));
                if (!isset($officialSet[$relPath])) {
                    $customFiles[] = $relDir . '/' . $relPath;
                }
            }
        }

        $pluginsDir = $rootPath . 'plugins';
        if (is_dir($pluginsDir)) {
            $officialPluginSet = array_flip(self::OFFICIAL_BUILTIN_PLUGINS);
            foreach (scandir($pluginsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                    continue;
                }
                if (is_dir($pluginsDir . '/' . $entry) && !isset($officialPluginSet[$entry])) {
                    $customFiles[] = 'plugins/' . $entry;
                }
            }
        }

        sort($customFiles);
        return $customFiles;
    }

    /**
     * 执行框架平滑在线升级
     */
    public function updateFramework(?string $packageUrl = null, ?string $expectedSha256 = null, ?bool $preserveFrontend = null): array
    {
        $packageUrl = $packageUrl !== null ? trim($packageUrl) : null;
        if ($packageUrl !== null && $packageUrl !== '') {
            $packageUrl = $this->assertTrustedFrameworkPackageUrl($packageUrl);
        }
        $normalizedSha256 = self::normalizePatchSha256($expectedSha256);

        if ($preserveFrontend === null) {
            $frontendStatus = $this->detectFrontendStatus();
            $effectivePreserveFrontend = (bool) ($frontendStatus['preserve_frontend_default'] ?? false);
        } else {
            $effectivePreserveFrontend = $preserveFrontend;
        }

        $currentVer = $this->getVersion();
        $targetVer = self::CURRENT_VERSION;
        $versionFile = $this->app->getRootPath() . 'version.json';

        // 1. 升级前执行主框架全量备份（含 core/、public/admin/、app/ 核心文件、入口文件、配置与 SQLite 快照）
        $backupId = $this->backupFramework($currentVer);
        $backupDir = $this->runtimeBackupDir . $backupId . DIRECTORY_SEPARATOR;

        // 2. 若提供了远程下载包，执行下载与补丁解压覆盖（失败若已落盘则自动回滚）
        if ($packageUrl !== null && $packageUrl !== '') {
            $this->downloadAndApplyPatch($packageUrl, $normalizedSha256, $effectivePreserveFrontend, $backupDir);
            $targetVer = self::resolvePatchedVersion($versionFile, $packageUrl, $targetVer);
        }

        // 3. 安全平滑更新 server/version.json
        $versionData = [
            'name'         => 'hwadmin',
            'version'      => $targetVer,
            'release_date' => date('Y-m-d'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'author'       => 'HwAdmin Team',
            'description'  => '基于 ThinkPHP 8.1 + ThinkORM 4 + Vue 3 的现代化敏捷管理后台基座',
        ];
        if (@file_put_contents($versionFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            $this->rollbackAfterFrameworkFailure($backupId, $currentVer, '无法写入 version.json');
        }

        // 4. 执行 Phinx 增量迁移（严禁静默吞掉异常，任何报错立即回滚并阻断）
        try {
            $output = $this->executeMigrateRun();
            $text = is_object($output) && method_exists($output, 'fetch')
                ? (string) $output->fetch()
                : (is_string($output) ? $output : '');
            if ($text !== '' && (stripos($text, 'exception') !== false || stripos($text, 'sqlstate') !== false || stripos($text, 'error') !== false)) {
                throw new ApiException('数据库迁移输出异常：' . trim($text));
            }
        } catch (Throwable $e) {
            $this->logMessage('error', '[updater] 框架升级数据库迁移失败: ' . $e->getMessage());
            $this->rollbackAfterFrameworkFailure($backupId, $currentVer, '数据库迁移失败：' . $e->getMessage());
        }

        // 5. 清理系统运行时缓存
        $this->clearRuntimeCache();

        $modeHint = $effectivePreserveFrontend
            ? '（已自动保留当前前端 public/admin/ 未覆写）'
            : '（前后端已同步更新）';

        return [
            'success'            => true,
            'new_version'        => $targetVer,
            'backup_id'          => $backupId,
            'frontend_preserved' => $effectivePreserveFrontend,
            'msg'                => "HwAdmin 主框架已成功升级至最新版本 v{$targetVer}{$modeHint}！",
        ];
    }

    /**
     * 执行框架增量数据库迁移命令（独立封装便于测试替身注入）
     */
    protected function executeMigrateRun(): mixed
    {
        return \think\facade\Console::call('migrate:run');
    }

    /**
     * 框架升级关键步骤失败后的自动原子回滚并抛出清晰 ApiException
     */
    protected function rollbackAfterFrameworkFailure(string $backupId, string $fromVersion, string $reason): never
    {
        $rollbackNote = '';
        try {
            $this->rollbackUpdate($backupId);
        } catch (Throwable $rollbackEx) {
            $this->logMessage('warning', "[updater] 框架升级失败后自动回滚异常: " . $rollbackEx->getMessage());
            $rollbackNote = "（自动回滚异常：{$rollbackEx->getMessage()}）";
        }
        throw new ApiException("框架升级失败，已自动回滚至 v{$fromVersion}：{$reason}{$rollbackNote}");
    }

    /**
     * 解析框架热更新后的目标版本号（优先尊重补丁包写入的 version.json 与下载地址中的 version 参数）
     */
    public static function resolvePatchedVersion(
        string $versionFile,
        ?string $packageUrl = null,
        string $fallback = self::CURRENT_VERSION
    ): string {
        $targetVer = ltrim($fallback, 'vV');
        if (is_file($versionFile)) {
            $patchedMeta = json_decode((string) file_get_contents($versionFile), true);
            if (is_array($patchedMeta) && !empty($patchedMeta['version'])) {
                $targetVer = ltrim((string) $patchedMeta['version'], 'vV');
            }
        }
        if ($packageUrl && preg_match('/[?&]version=v?([0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z]+)*)/', $packageUrl, $vm)) {
            if (version_compare($vm[1], $targetVer, '>')) {
                $targetVer = $vm[1];
            }
        }
        return $targetVer;
    }

    /**
     * 执行指定插件在线升级
     */
    public function updatePlugin(string $name, ?string $gatewayUrl = null, string $licenseKey = '', string $authToken = ''): array
    {
        if (!preg_match(PluginInfo::NAME_PATTERN, $name)) {
            throw new ApiException("非法的插件标识：{$name}");
        }

        /** @var PluginManager $pluginManager */
        $pluginManager = $this->app->make(PluginManager::class);
        $info = $pluginManager->info($name);
        $pluginDir = plugin_path($name);

        if (!is_dir($pluginDir) || !is_file($pluginDir . 'plugin.json')) {
            throw new ApiException("插件目录不存在：{$name}");
        }

        $oldVersion = $info->version;

        // 1. 升级前安全物理备份插件目录
        $backupId = $this->backupPlugin($name);

        try {
            $updatedFromRemote = false;

            // 2. 如果提供了网关地址，下载最新 ZIP 发行包并通过安全中转目录解压覆盖
            if ($gatewayUrl) {
                $downloadUrl = rtrim($gatewayUrl, '/') . '/download?name=' . urlencode($name);
                if ($licenseKey !== '') {
                    $downloadUrl .= '&license_key=' . urlencode($licenseKey);
                }

                $tmpFile = $this->runtimeBackupDir . 'plugin_pkg_' . $name . '_' . time() . '.zip';
                $dlHeaders = $authToken !== '' ? ['Authorization: Bearer ' . $authToken] : [];
                $dl = OutboundHttpGuard::downloadToFile($downloadUrl, $tmpFile, $dlHeaders, 30, 5, 3);
                $ok = $dl['ok'];
                $httpCode = $dl['http_code'];
                $curlErr = $dl['error'];

                try {
                    if (!$ok || $httpCode !== 200 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
                        $errMsg = $curlErr ?: "HTTP {$httpCode}";
                        $needRelogin = ($httpCode === 401);
                        if (is_file($tmpFile) && filesize($tmpFile) > 0 && filesize($tmpFile) < 8192) {
                            $errJson = json_decode((string) file_get_contents($tmpFile), true);
                            if (is_array($errJson)) {
                                if (!empty($errJson['msg'])) {
                                    $errMsg = (string) $errJson['msg'];
                                }
                                if (!empty($errJson['data']['need_login']) || (int) ($errJson['code'] ?? 0) === 401) {
                                    $needRelogin = true;
                                }
                            }
                        }
                        throw new ApiException(
                            "从应用商城下载插件更新包失败：{$errMsg}",
                            0,
                            $needRelogin ? ['need_login' => true] : null
                        );
                    }

                    $this->extractPluginPackageSafely($tmpFile, $pluginDir, true);
                    $updatedFromRemote = true;
                } finally {
                    if (is_file($tmpFile)) {
                        @unlink($tmpFile);
                    }
                }
            }

            // 3. 纯本地无网关模式下的版本递增演练逻辑
            if (!$updatedFromRemote) {
                $jsonFile = $pluginDir . 'plugin.json';
                $manifest = json_decode((string) file_get_contents($jsonFile), true) ?: [];
                $curVer = (string) ($manifest['version'] ?? '1.0.0');
                $parts = explode('.', $curVer);
                if (count($parts) === 3) {
                    $parts[1] = (int)$parts[1] + 1;
                    $newVer = implode('.', $parts);
                } else {
                    $newVer = '1.1.0';
                }
                $manifest['version'] = $newVer;
                if (@file_put_contents($jsonFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                    throw new ApiException("无法写入插件清单文件，请检查目录权限：{$jsonFile}");
                }
            }

            // 重新读取新版本号
            $newManifest = json_decode((string) file_get_contents($pluginDir . 'plugin.json'), true) ?: [];
            $newVer = (string) ($newManifest['version'] ?? '1.1.0');

            // 4. 若插件已安装，调用 PluginManager::upgrade 执行数据库增量升级与 upgrade 钩子
            $state = Db::name('plugin')->where('name', $name)->find();
            if ($state !== null) {
                $pluginManager->upgrade($name);
                $state = Db::name('plugin')->where('name', $name)->find();
            }

            // 5. 仅当插件处于启用状态时重新发布插件静态资源；遗留前台视图仅当不存在时才初始化，绝不覆写站点已有视图
            if ($state !== null && (int) ($state['status'] ?? 0) === 1) {
                AssetPublisher::publish($name);
                $pluginFrontIndex = $pluginDir . 'public' . DIRECTORY_SEPARATOR . 'index.html';
                $legacyFrontView = $this->app->getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'index.html';
                if (is_file($pluginFrontIndex) && !is_file($legacyFrontView)) {
                    $legacyFrontDir = dirname($legacyFrontView);
                    if (!is_dir($legacyFrontDir)) {
                        @mkdir($legacyFrontDir, 0755, true);
                    }
                    @copy($pluginFrontIndex, $legacyFrontView);
                }
            }

            // 6. 清除缓存
            $pluginManager->clearCache();
            $this->clearRuntimeCache();

            return [
                'success'     => true,
                'name'        => $name,
                'old_version' => $oldVersion,
                'new_version' => $newVer,
                'backup_id'   => $backupId,
                'msg'         => "插件【{$info->title} ({$name})】已成功升级至 v{$newVer}！",
            ];
        } catch (Throwable $e) {
            // 升级失败，尽力从备份快照自动回滚，绝不吞掉原始异常或抛出未捕获的 500
            $rollbackNote = '';
            try {
                $this->rollbackUpdate($backupId);
            } catch (Throwable $rollbackEx) {
                $this->logMessage('warning', "[updater] 插件 {$name} 升级失败后自动回滚异常: " . $rollbackEx->getMessage());
                $rollbackNote = "（回滚恢复提示：{$rollbackEx->getMessage()}）";
            }
            if ($e instanceof ApiException) {
                throw new ApiException("插件升级失败：{$e->getMessage()}{$rollbackNote}", (int) $e->getCode(), $e->getData(), $e->getHttpStatus());
            }
            throw new ApiException("插件升级失败：{$e->getMessage()}{$rollbackNote}");
        }
    }

    /**
     * 备份当前框架核心代码、前端静态构建产物、app/ 核心文件、入口文件、配置与 SQLite 数据库快照
     */
    public function backupFramework(string $version): string
    {
        $timeStr = date('Ymd_His');
        $backupId = "{$timeStr}_framework_{$version}";
        if (is_dir($this->runtimeBackupDir . $backupId)) {
            $backupId .= '_' . bin2hex(random_bytes(3));
        }
        $targetDir = $this->runtimeBackupDir . $backupId . DIRECTORY_SEPARATOR;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new ApiException("无法创建框架备份目录：{$targetDir}");
        }

        $rootPath = $this->app->getRootPath();

        // 备份 version.json
        $versionFile = $rootPath . 'version.json';
        if (is_file($versionFile) && !@copy($versionFile, $targetDir . 'version.json')) {
            throw new ApiException('备份 version.json 失败');
        }

        // 备份 config/hwadmin.php
        $configPath = $this->app->getConfigPath();
        if (is_file($configPath . 'hwadmin.php') && !@copy($configPath . 'hwadmin.php', $targetDir . 'hwadmin.php')) {
            throw new ApiException('备份 config/hwadmin.php 失败');
        }

        // 备份核心框架目录 core/
        $coreDir = $rootPath . 'core';
        $coreZip = $targetDir . 'core.zip';
        if (is_dir($coreDir)) {
            $this->zipDirectoryToFile($coreDir, $coreZip);
        }

        // 备份前端编译产物目录 public/admin/
        $publicAdminDir = $rootPath . 'public' . DIRECTORY_SEPARATOR . 'admin';
        $publicAdminZip = $targetDir . 'public_admin.zip';
        if (is_dir($publicAdminDir)) {
            $this->zipDirectoryToFile($publicAdminDir, $publicAdminZip);
        }

        // 备份被 OTA 覆盖范围的 app/ 核心文件
        $appDir = $rootPath . 'app';
        $appCoreZip = $targetDir . 'app_core.zip';
        if (is_dir($appDir)) {
            $this->zipAppCoreFiles($appDir, $appCoreZip);
        }

        // 备份 public/ 入口与部署检查文件（index.php、router.php、deployment-check.js）
        $publicDir = $rootPath . 'public';
        $publicEntryZip = $targetDir . 'public_entry.zip';
        if (is_dir($publicDir)) {
            $this->zipPublicEntryFiles($publicDir, $publicEntryZip);
        }

        // 若当前为 SQLite 驱动，自动快照数据库文件
        $sqliteBackup = $targetDir . 'database.sqlite';
        $sqlitePath = $this->resolveSqliteDatabasePath();
        if ($sqlitePath !== '' && is_file($sqlitePath)) {
            try {
                if (strtolower((string) (Db::connect()->getConfig('type') ?? '')) === 'sqlite') {
                    $activePath = (string) (Db::connect()->getConfig('database') ?? '');
                    if ($activePath !== '' && (realpath($activePath) === realpath($sqlitePath) || $activePath === $sqlitePath)) {
                        Db::execute('PRAGMA wal_checkpoint(TRUNCATE)');
                    }
                }
            } catch (Throwable) {
            }
            if (!@copy($sqlitePath, $sqliteBackup)) {
                throw new ApiException("备份 SQLite 数据库快照失败：{$sqliteBackup}");
            }
        }

        $totalBytes = 0;
        foreach ([
            $targetDir . 'version.json',
            $targetDir . 'hwadmin.php',
            $coreZip,
            $publicAdminZip,
            $appCoreZip,
            $publicEntryZip,
            $sqliteBackup,
        ] as $artifact) {
            if (is_file($artifact)) {
                $totalBytes += (int) filesize($artifact);
            }
        }
        $sizeLabel = $totalBytes > 0 ? round($totalBytes / 1024, 1) . ' KB' : '0 KB';

        $meta = [
            'id'         => $backupId,
            'type'       => 'framework',
            'name'       => 'hwadmin',
            'version'    => $version,
            'created_at' => date('Y-m-d H:i:s'),
            'size'       => $sizeLabel,
            'path'       => $targetDir,
        ];

        $this->saveBackupRecord($meta);
        return $backupId;
    }

    /**
     * 解析当前激活配置的 SQLite 数据库文件路径（非 SQLite 驱动或内存库返回空串）
     */
    protected function resolveSqliteDatabasePath(): string
    {
        $dbConfig = [];
        try {
            $rawConfig = $this->app->config->get('database');
            if (is_array($rawConfig) && !empty($rawConfig)) {
                $dbConfig = $rawConfig;
            }
        } catch (Throwable) {
        }

        if (!empty($dbConfig)) {
            $defaultConn = (string) ($dbConfig['default'] ?? '');
            $conn = $defaultConn !== '' ? (array) ($dbConfig['connections'][$defaultConn] ?? []) : [];
            $type = strtolower(trim((string) ($conn['type'] ?? '')));
            if ($type !== 'sqlite') {
                return '';
            }
            $dbPath = trim((string) ($conn['database'] ?? ''));
        } else {
            try {
                $type = strtolower(trim((string) (Db::connect()->getConfig('type') ?? '')));
                if ($type !== 'sqlite') {
                    return '';
                }
                $dbPath = trim((string) (Db::connect()->getConfig('database') ?? ''));
            } catch (Throwable) {
                return '';
            }
        }

        if ($dbPath === '' || $dbPath === ':memory:') {
            return '';
        }
        if (!str_starts_with($dbPath, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $dbPath)) {
            $dbPath = $this->app->getRootPath() . ltrim($dbPath, '/\\');
        }
        return $dbPath;
    }

    /**
     * 备份 public/ 入口与部署探测文件到 public_entry.zip
     */
    protected function zipPublicEntryFiles(string $publicDir, string $zipFile): void
    {
        if (!is_dir($publicDir)) {
            throw new ApiException("备份源目录不存在：{$publicDir}");
        }
        $normalizedRoot = rtrim(str_replace('\\', '/', $publicDir), '/') . '/';
        $fileMap = [];
        foreach (self::PUBLIC_ENTRY_BACKUP_FILES as $relPath) {
            $fullPath = $normalizedRoot . $relPath;
            if (is_file($fullPath)) {
                $fileMap[$relPath] = $fullPath;
            }
        }
        $this->createZipFromFileMap($zipFile, $fileMap, '入口文件备份');
    }

    /**
     * 备份被 OTA 覆盖范围的 app/ 核心文件（保留用户自建业务模块不冗余打包）
     */
    protected function zipAppCoreFiles(string $appDir, string $zipFile): void
    {
        if (!is_dir($appDir)) {
            throw new ApiException("备份源目录不存在：{$appDir}");
        }
        $normalizedAppRoot = rtrim(str_replace('\\', '/', $appDir), '/') . '/';
        $fileMap = [];
        foreach (self::OFFICIAL_APP_CORE_FILES as $relPath) {
            $fullPath = $normalizedAppRoot . $relPath;
            if (is_file($fullPath)) {
                $fileMap[$relPath] = $fullPath;
            }
        }
        $this->createZipFromFileMap($zipFile, $fileMap, 'app 核心文件备份');
    }

    /**
     * 将目录递归打包至指定 ZIP 文件
     */
    protected function zipDirectoryToFile(string $sourceDir, string $zipFile): void
    {
        if (!is_dir($sourceDir)) {
            throw new ApiException("备份源目录不存在：{$sourceDir}");
        }
        $normalizedRoot = rtrim((string) realpath($sourceDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalizedRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $fileMap = [];
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                if ($filePath !== false && str_starts_with($filePath, $normalizedRoot)) {
                    $relativePath = str_replace('\\', '/', substr($filePath, strlen($normalizedRoot)));
                    $fileMap[$relativePath] = $filePath;
                }
            }
        }
        $this->createZipFromFileMap($zipFile, $fileMap, '目录备份');
    }

    /**
     * 将映射文件集合安全写入 ZIP 压缩包（失败硬阻断并清理残留空包）
     *
     * @param array<string, string> $fileMap [zipRelPath => localFilePath]
     */
    protected function createZipFromFileMap(string $zipFile, array $fileMap, string $contextLabel): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException("当前 PHP 环境缺少 zip 扩展，无法完成{$contextLabel}");
        }
        if (empty($fileMap)) {
            if (is_file($zipFile)) {
                @unlink($zipFile);
            }
            return 0;
        }
        $zipDir = dirname($zipFile);
        if (!is_dir($zipDir) || !is_writable($zipDir)) {
            throw new ApiException("创建{$contextLabel}压缩包失败，目标目录不可写或不存在：{$zipDir}");
        }
        $zip = new ZipArchive();
        if (@$zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiException("创建{$contextLabel}压缩包失败，请检查目录写入权限：{$zipFile}");
        }
        $added = 0;
        foreach ($fileMap as $relPath => $fullPath) {
            if (!@$zip->addFile($fullPath, $relPath)) {
                @$zip->close();
                @unlink($zipFile);
                throw new ApiException("写入{$contextLabel}压缩包条目失败：{$relPath}");
            }
            $added++;
        }
        if (@$zip->close() !== true) {
            @unlink($zipFile);
            throw new ApiException("保存{$contextLabel}压缩包失败，请检查磁盘空间与权限：{$zipFile}");
        }
        if ($added > 0 && (!is_file($zipFile) || filesize($zipFile) === 0)) {
            @unlink($zipFile);
            throw new ApiException("生成的{$contextLabel}压缩包为空（0 KB），已中止升级：{$zipFile}");
        }
        return $added;
    }

    /**
     * 备份插件源码至 ZIP 文件（同时记录数据库原始启用状态）
     */
    public function backupPlugin(string $name): string
    {
        $timeStr = date('Ymd_His');
        $pluginDir = plugin_path($name);
        $version = '1.0.0';

        if (is_file($pluginDir . 'plugin.json')) {
            $manifest = json_decode((string) file_get_contents($pluginDir . 'plugin.json'), true);
            $version = (string) ($manifest['version'] ?? '1.0.0');
        }

        $originalStatus = null;
        try {
            $state = Db::name('plugin')->where('name', $name)->find();
            if ($state !== null && isset($state['status'])) {
                $originalStatus = (int) $state['status'];
            }
        } catch (Throwable) {
        }

        $backupId = "{$timeStr}_plugin_{$name}_{$version}";
        if (is_file($this->runtimeBackupDir . "{$backupId}.zip")) {
            $backupId .= '_' . bin2hex(random_bytes(3));
        }
        $zipFile = $this->runtimeBackupDir . "{$backupId}.zip";
        $this->zipDirectoryToFile($pluginDir, $zipFile);

        $size = is_file($zipFile) ? round(filesize($zipFile) / 1024, 1) . ' KB' : '0 KB';

        $meta = [
            'id'            => $backupId,
            'type'          => 'plugin',
            'name'          => $name,
            'version'       => $version,
            'plugin_status' => $originalStatus,
            'created_at'    => date('Y-m-d H:i:s'),
            'size'          => $size,
            'path'          => $zipFile,
        ];

        $this->saveBackupRecord($meta);
        return $backupId;
    }

    /**
     * 获取历史备份快照列表
     */
    public function getBackups(): array
    {
        if (!is_file($this->metaFile)) {
            return [];
        }

        $list = json_decode((string) file_get_contents($this->metaFile), true);
        if (!is_array($list)) {
            return [];
        }

        // 过滤并按时间倒序排列
        $validList = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            if (is_dir($path) || is_file($path)) {
                if (($item['type'] ?? '') === 'framework') {
                    $dir = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
                    $item['has_frontend_backup'] = is_dir($path) && is_file($dir . 'public_admin.zip');
                }
                $validList[] = $item;
            }
        }

        usort($validList, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $validList;
    }

    /**
     * 从指定备份快照回滚
     */
    public function rollbackUpdate(string $backupId): array
    {
        $backups = $this->getBackups();
        $target = null;
        foreach ($backups as $b) {
            if ($b['id'] === $backupId) {
                $target = $b;
                break;
            }
        }

        if (!$target) {
            throw new ApiException("未找到备份快照：{$backupId}");
        }

        if ($target['type'] === 'framework') {
            $dir = rtrim((string) $target['path'], '/\\') . DIRECTORY_SEPARATOR;
            $rootPath = $this->app->getRootPath();

            $coreZip = $dir . 'core.zip';
            if (is_file($coreZip)) {
                $this->extractBackupZipSafely($coreZip, $rootPath . 'core', false);
            }

            $publicAdminZip = $dir . 'public_admin.zip';
            if (is_file($publicAdminZip)) {
                $this->extractBackupZipSafely($publicAdminZip, $rootPath . 'public' . DIRECTORY_SEPARATOR . 'admin', true);
            }

            $appCoreZip = $dir . 'app_core.zip';
            if (is_file($appCoreZip)) {
                $this->extractBackupZipSafely($appCoreZip, $rootPath . 'app', false);
            }

            $publicEntryZip = $dir . 'public_entry.zip';
            if (is_file($publicEntryZip)) {
                $this->extractBackupZipSafely($publicEntryZip, $rootPath . 'public', false);
            }

            if (is_file($dir . 'version.json')) {
                @copy($dir . 'version.json', $rootPath . 'version.json');
            }
            if (is_file($dir . 'hwadmin.php')) {
                $configDest = $this->app->getConfigPath() . 'hwadmin.php';
                @copy($dir . 'hwadmin.php', $configDest);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($configDest, true);
                }
            }

            $this->cleanupPatchManifestCreatedFiles($dir, $rootPath);
            $this->clearRuntimeCache();

            return [
                'success' => true,
                'msg'     => is_file($publicAdminZip)
                    ? "已成功将 HwAdmin 主框架版本回滚至 {$target['version']}（含前后端代码与核心配置）！"
                    : "已成功将 HwAdmin 主框架版本回滚至 {$target['version']}（含后端内核与核心配置，该历史快照不含前端产物）！",
            ];
        }

        if ($target['type'] === 'plugin') {
            $pluginName = (string) $target['name'];
            $zipFile = (string) ($target['path'] ?? '');
            $pluginDir = plugin_path($pluginName);

            if ($zipFile !== '' && is_file($zipFile)) {
                // 回滚前清理新版在插件目录产生的残留文件，再还原旧版快照
                $this->extractPluginPackageSafely($zipFile, $pluginDir, false, true);
            }

            $hasRecordedStatus = array_key_exists('plugin_status', $target) && $target['plugin_status'] !== null;
            $targetStatus = $hasRecordedStatus ? (int) $target['plugin_status'] : null;

            // 恢复 plugin 表记录的版本号与原始启用状态
            $updateData = [
                'version'     => $target['version'],
                'update_time' => time(),
            ];
            if ($targetStatus !== null) {
                $updateData['status'] = $targetStatus;
            }
            Db::name('plugin')->where('name', $pluginName)->update($updateData);

            $effectiveStatus = $targetStatus;
            if ($effectiveStatus === null) {
                $row = Db::name('plugin')->where('name', $pluginName)->find();
                $effectiveStatus = $row !== null ? (int) ($row['status'] ?? 0) : 0;
            }

            if ($targetStatus !== null) {
                MenuImporter::setStatus($pluginName, $targetStatus);
            }
            if ($effectiveStatus === 1) {
                AssetPublisher::publish($pluginName);
            }

            /** @var PluginManager $pluginManager */
            $pluginManager = $this->app->make(PluginManager::class);
            $pluginManager->clearCache();
            $this->clearRuntimeCache();

            return [
                'success' => true,
                'msg'     => "已成功将插件【{$pluginName}】回滚至 {$target['version']}！",
            ];
        }

        throw new ApiException('不支持的快照类型');
    }

    /**
     * 保存备份元数据
     */
    protected function saveBackupRecord(array $record): void
    {
        $list = [];
        if (is_file($this->metaFile)) {
            $list = json_decode((string) file_get_contents($this->metaFile), true) ?: [];
        }

        $list[] = $record;
        if (@file_put_contents($this->metaFile, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new ApiException("无法写入备份元数据文件，请检查目录权限：{$this->metaFile}");
        }
    }

    /**
     * 校验框架更新包 URL 安全性且必须与官方/已配置商城地址同源
     */
    protected function assertTrustedFrameworkPackageUrl(string $packageUrl): string
    {
        $safeUrl = OutboundHttpGuard::assertSafeUrl($packageUrl);

        $trustedOrigins = [];
        $defaultUrl = trim((string) config('hwadmin.market.default_url', ''));
        if ($defaultUrl === '') {
            $defaultUrl = trim((string) (env('MARKET_SERVER_URL', '') ?: env('HW_MARKET_GATEWAY', '') ?: 'https://hw.baotoutv.cn'));
        }
        if ($defaultUrl !== '') {
            $trustedOrigins[] = $defaultUrl;
        }

        try {
            $configuredUrl = trim((string) (Db::name('config')->where('name', 'market_server_url')->value('value') ?: ''));
            if ($configuredUrl !== '') {
                if (!str_contains($configuredUrl, '://')) {
                    $configuredUrl = 'https://' . $configuredUrl;
                }
                $trustedOrigins[] = $configuredUrl;
            }
        } catch (Throwable) {
        }

        if (!OutboundHttpGuard::isSameOrigin($safeUrl, $trustedOrigins)) {
            throw new ApiException('非法的框架更新包下载地址：仅允许来自官方或已配置的同源商城地址');
        }

        return $safeUrl;
    }

    /**
     * 构造请求商城网关 /catalog 的完整 URL（自动附带 channel 与 site_domain 参数）
     */
    public static function buildCatalogUrl(
        string $gatewayUrl,
        string $channel = self::CHANNEL_STABLE,
        string $siteDomain = ''
    ): string {
        $base = rtrim($gatewayUrl, '/') . '/catalog';
        $query = http_build_query([
            'channel'     => self::normalizeUpdateChannel($channel),
            'site_domain' => trim($siteDomain),
        ]);
        return $base . (str_contains($base, '?') ? '&' : '?') . $query;
    }

    /**
     * 从网关读取数据
     */
    protected function fetchFromGateway(string $gatewayUrl, ?string $channel = null, ?string $siteDomain = null): ?array
    {
        $activeChannel = $channel !== null ? self::normalizeUpdateChannel($channel) : $this->getUpdateChannel();
        $resolvedDomain = $this->resolveSiteDomain($siteDomain);
        $url = self::buildCatalogUrl($gatewayUrl, $activeChannel, $resolvedDomain);
        $headers = $resolvedDomain !== '' ? ['X-Site-Domain: ' . $resolvedDomain] : [];
        try {
            $resp = OutboundHttpGuard::request($url, 'GET', '', $headers, 4, 2, 2, 'HwAdmin-Updater/1.0');
        } catch (Throwable) {
            return null;
        }

        if ($resp['ok'] && $resp['http_code'] >= 200 && $resp['http_code'] < 400) {
            $json = json_decode($resp['body'], true);
            if (is_array($json) && !empty($json['code']) && (int) $json['code'] === 1 && !empty($json['data'])) {
                return (array) $json['data'];
            }
        }

        return null;
    }

    /**
     * 校验并规范化可选的补丁 SHA256 摘要（64 位十六进制）
     */
    public static function normalizePatchSha256(?string $sha256): ?string
    {
        if ($sha256 === null) {
            return null;
        }
        $clean = strtolower(trim($sha256));
        if ($clean === '') {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $clean)) {
            throw new ApiException('非法的框架升级包 SHA256 校验格式');
        }
        return $clean;
    }

    /**
     * 安全还原框架备份 ZIP（防路径穿越，并在覆写 .php 后执行 OPcache 失效）
     */
    protected function extractBackupZipSafely(string $zipPath, string $targetDir, bool $cleanTargetFilesFirst = false): void
    {
        if (!class_exists(ZipArchive::class) || !is_file($zipPath)) {
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ApiException("无法打开备份快照压缩包：{$zipPath}");
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rawOrig = (string) $zip->getNameIndex($i);
            if ($rawOrig === '' || str_contains($rawOrig, "\0")) {
                $zip->close();
                throw new ApiException('备份快照包含非法路径条目');
            }
            $rawName = str_replace('\\', '/', $rawOrig);
            if (str_ends_with($rawName, '/')) {
                continue;
            }
            if (str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName) || in_array('..', explode('/', $rawName), true)) {
                $zip->close();
                throw new ApiException("备份快照包含非法路径条目：{$rawName}");
            }
            $entries[] = ['index' => $i, 'rel' => ltrim($rawName, '/')];
        }

        if ($cleanTargetFilesFirst && !empty($entries) && is_dir($targetDir)) {
            $this->cleanDirectoryExceptGitkeep($targetDir);
        }
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $destBase = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
        foreach ($entries as $entry) {
            if ($entry['rel'] === '') {
                continue;
            }
            $content = $zip->getFromIndex($entry['index']);
            if ($content === false) {
                continue;
            }
            $dest = $destBase . $entry['rel'];
            $destParent = dirname($dest);
            if (!is_dir($destParent)) {
                @mkdir($destParent, 0755, true);
            }
            if (@file_put_contents($dest, $content) === false) {
                $zip->close();
                throw new ApiException("回滚写入文件失败：{$entry['rel']}");
            }
            @chmod($dest, 0644);
            if (str_ends_with(strtolower($dest), '.php') && function_exists('opcache_invalidate')) {
                @opcache_invalidate($dest, true);
            }
        }
        $zip->close();
    }

    /**
     * 清理目录内旧文件与空子目录（保留 .gitkeep）
     */
    protected function cleanDirectoryExceptGitkeep(string $dir): void
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
            } elseif ($item->getFilename() !== '.gitkeep') {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * 依据 patch_manifest.json 精准删除升级期间新建的残留文件及空子目录
     */
    protected function cleanupPatchManifestCreatedFiles(string $backupDir, string $rootPath): void
    {
        $manifestFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . 'patch_manifest.json';
        if (!is_file($manifestFile)) {
            return;
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $createdFiles = is_array($manifest) ? ($manifest['created_files'] ?? []) : [];
        if (!is_array($createdFiles) || empty($createdFiles)) {
            return;
        }

        $normRoot = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
        $protectedBaseDirs = ['core', 'app', 'public', 'public/admin', 'config', 'database', 'database/migrations'];

        foreach ($createdFiles as $rawRel) {
            $rel = ltrim(str_replace('\\', '/', (string) $rawRel), '/');
            if (
                $rel === ''
                || str_contains($rel, "\0")
                || str_starts_with($rel, '/')
                || preg_match('/^[A-Za-z]:/', $rel)
                || in_array('..', explode('/', $rel), true)
                || self::isProtectedPatchPath($rel)
                || !self::isAllowedFrameworkPatchPath($rel)
            ) {
                continue;
            }
            $targetFile = $normRoot . $rel;
            if (is_file($targetFile) || is_link($targetFile)) {
                @unlink($targetFile);
                if (str_ends_with(strtolower($targetFile), '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($targetFile, true);
                }
            }

            $relDir = str_replace('\\', '/', dirname($rel));
            while ($relDir !== '' && $relDir !== '.' && !in_array($relDir, $protectedBaseDirs, true)) {
                $fullDir = $normRoot . $relDir;
                if (!is_dir($fullDir)) {
                    break;
                }
                $entries = @scandir($fullDir);
                if (!is_array($entries) || count(array_diff($entries, ['.', '..'])) > 0) {
                    break;
                }
                @rmdir($fullDir);
                $relDir = str_replace('\\', '/', dirname($relDir));
            }
        }
    }

    /**
     * 校验补丁文件 SHA256（若提供预期摘要）并执行安全解压应用
     */
    public function verifyAndApplyPatchArchive(
        string $zipPath,
        ?string $expectedSha256 = null,
        bool $preserveFrontend = false,
        ?string $backupDir = null
    ): int {
        $normalizedSha256 = self::normalizePatchSha256($expectedSha256);
        if ($normalizedSha256 !== null) {
            if (!is_file($zipPath)) {
                throw new ApiException('框架升级包文件不存在，无法执行 SHA256 校验');
            }
            $actualSha256 = strtolower((string) hash_file('sha256', $zipPath));
            if (!hash_equals($normalizedSha256, $actualSha256)) {
                throw new ApiException('框架升级包 SHA256 完整性校验失败，已中止更新');
            }
        }

        return $this->applyPatchArchive($zipPath, $preserveFrontend, $backupDir);
    }

    /**
     * 下载补丁并安全解压应用（保留站点环境配置、运行库与上传文件）
     */
    protected function downloadAndApplyPatch(
        string $url,
        ?string $expectedSha256 = null,
        bool $preserveFrontend = false,
        ?string $backupDir = null
    ): void {
        $normalizedSha256 = self::normalizePatchSha256($expectedSha256);
        $tmpFile = $this->runtimeBackupDir . 'patch_' . time() . '.zip';
        $siteDomain = $this->resolveSiteDomain();
        $headers = $siteDomain !== '' ? ['X-Site-Domain: ' . $siteDomain] : [];
        $dl = OutboundHttpGuard::downloadToFile($url, $tmpFile, $headers, 60, 10, 3);
        $ok = $dl['ok'];
        $httpCode = $dl['http_code'];
        $curlErr = $dl['error'];

        if (!$ok || $httpCode !== 200 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            throw new ApiException('下载框架升级包失败（' . ($curlErr ?: 'HTTP ' . $httpCode) . '）');
        }

        try {
            $this->verifyAndApplyPatchArchive($tmpFile, $normalizedSha256, $preserveFrontend, $backupDir);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * 校验并安全解压升级补丁压缩包到当前后端根目录
     */
    public function applyPatchArchive(string $zipPath, bool $preserveFrontend = false, ?string $backupDir = null): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境缺少 zip 扩展');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ApiException('升级补丁压缩包损坏或格式无效');
        }

        $zipClosed = false;
        $createdFiles = [];

        try {
            $entries = [];
            $hasTopCore = false;
            $hasServerCore = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawOrig = (string) $zip->getNameIndex($i);
                if (str_contains($rawOrig, "\0")) {
                    throw new ApiException('升级包包含非法空字节路径条目');
                }
                $rawName = str_replace('\\', '/', $rawOrig);
                if ($rawName === '' || str_ends_with($rawName, '/')) {
                    continue;
                }
                if (str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName) || in_array('..', explode('/', $rawName), true)) {
                    throw new ApiException("升级包包含非法路径条目：{$rawName}");
                }
                $opsys = 0;
                $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                    $mode = (($attr >> 16) & 0xF000);
                    if ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                        throw new ApiException("升级包包含非法符号链接条目：{$rawName}");
                    }
                }
                if (str_starts_with($rawName, 'core/')) {
                    $hasTopCore = true;
                } elseif (str_starts_with($rawName, 'server/core/')) {
                    $hasServerCore = true;
                }
                $entries[] = $i;
            }

            $stripServerPrefix = !$hasTopCore && $hasServerCore;
            $rootPath = rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/') . '/';
            $applied = 0;

            foreach ($entries as $idx) {
                $rawName = str_replace('\\', '/', (string) $zip->getNameIndex($idx));
                $rel = $stripServerPrefix && str_starts_with($rawName, 'server/')
                    ? substr($rawName, strlen('server/'))
                    : $rawName;
                $rel = ltrim($rel, '/');
                if ($rel === '' || self::isProtectedPatchPath($rel) || !self::isAllowedFrameworkPatchPath($rel)) {
                    continue;
                }
                if ($preserveFrontend && ($rel === 'public/admin' || str_starts_with($rel, 'public/admin/'))) {
                    continue;
                }
                $dest = $rootPath . $rel;
                // 站点级配置/伪静态/图标/前端配置：若目标站点已存在则跳过覆写，仅在缺失时初始化写入
                if (in_array($rel, self::SITE_PRESERVE_IF_EXISTS_PATHS, true) && (is_file($dest) || is_link($dest))) {
                    continue;
                }
                $content = $zip->getFromIndex($idx);
                if ($content === false) {
                    continue;
                }
                $existedBefore = file_exists($dest) || is_link($dest);
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                if (@file_put_contents($dest, $content) === false) {
                    throw new ApiException("写入升级文件失败：{$rel}");
                }
                @chmod($dest, 0644);
                if (str_ends_with(strtolower($dest), '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($dest, true);
                }
                if (!$existedBefore) {
                    $createdFiles[] = $rel;
                }
                $applied++;
            }

            $zip->close();
            $zipClosed = true;
            return $applied;
        } finally {
            if (!$zipClosed) {
                $zip->close();
            }
            if ($backupDir !== null && trim($backupDir) !== '' && is_dir($backupDir)) {
                $manifestPath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . 'patch_manifest.json';
                $manifestPayload = [
                    'created_files' => array_values(array_unique($createdFiles)),
                    'applied_at'    => date('Y-m-d H:i:s'),
                ];
                @file_put_contents($manifestPath, json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
     * 客户端允许 OTA 补丁写入的白名单校验（严禁越权覆写 app/api、app/index/route、app/index/view、非白名单 config/ 等业务目录）
     */
    public static function isAllowedFrameworkPatchPath(string $relPath): bool
    {
        $norm = ltrim(str_replace('\\', '/', $relPath), '/');
        if ($norm === '' || str_contains($norm, "\0") || str_contains($norm, '..') || self::isProtectedPatchPath($norm)) {
            return false;
        }
        foreach (self::ALLOWED_FRAMEWORK_PATCH_PREFIXES as $prefix) {
            if (str_starts_with($norm, $prefix)) {
                return true;
            }
        }
        return in_array($norm, self::ALLOWED_FRAMEWORK_PATCH_FILES, true);
    }

    /**
     * 严格禁止在在线热更新中覆写的站点私有/运行态路径
     */
    public static function isProtectedPatchPath(string $relPath): bool
    {
        $norm = ltrim(str_replace('\\', '/', $relPath), '/');
        if (str_starts_with($norm, '.env') || in_array($norm, ['config/install.lock', 'config/install.token', 'config/database.php'], true)) {
            return true;
        }
        foreach (['runtime/', 'public/uploads/', 'public/plugins/', 'public/storage/', 'tests/', '.git/'] as $prefix) {
            if (str_starts_with($norm, $prefix)) {
                return true;
            }
        }
        return (bool) preg_match('/\.(sqlite3?|db)(?:-(?:wal|shm|journal))?$/i', $norm);
    }

    /**
     * 安全校验并将插件 ZIP 解压并覆写至目标插件目录（兼容单层目录包裹、符号链接阻断与不同属主文件替换）
     */
    public function extractPluginPackageSafely(
        string $zipFile,
        string $targetDir,
        bool $requireManifest = false,
        bool $cleanTargetBeforeCopy = false
    ): void {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境缺少 zip 扩展');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new ApiException('插件更新包格式损坏，无法打开压缩包');
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rawOrig = (string) $zip->getNameIndex($i);
            if ($rawOrig === '' || str_contains($rawOrig, "\0")) {
                $zip->close();
                throw new ApiException('插件安装包包含非法空字节路径条目');
            }
            $rawName = str_replace('\\', '/', $rawOrig);
            if (str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName) || in_array('..', explode('/', $rawName), true)) {
                $zip->close();
                throw new ApiException("插件安装包包含非法路径条目：{$rawName}");
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $mode = (($attr >> 16) & 0xF000);
                if ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                    $zip->close();
                    throw new ApiException("插件安装包包含非法符号链接条目：{$rawName}");
                }
            }
            $entries[] = ['index' => $i, 'path' => $rawName];
        }

        $stagingDir = $this->runtimeBackupDir . 'tmp_plugin_extract_' . uniqid('', true);
        if (!@mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
            $zip->close();
            throw new ApiException("无法创建插件临时解压目录：{$stagingDir}");
        }

        try {
            $baseReal = realpath($stagingDir);
            if ($baseReal === false) {
                $zip->close();
                throw new ApiException('临时解压目录不可访问');
            }

            foreach ($entries as $entry) {
                $rel = ltrim($entry['path'], '/');
                if ($rel === '') {
                    continue;
                }
                $dest = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($rel, '/'));
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
                    throw new ApiException("插件安装包解压路径逃逸：{$entry['path']}");
                }
                $content = $zip->getFromIndex($entry['index']);
                if ($content === false) {
                    $zip->close();
                    throw new ApiException("读取插件压缩包条目失败：{$entry['path']}");
                }
                if (@file_put_contents($dest, $content) === false) {
                    $zip->close();
                    throw new ApiException("写入插件临时文件失败：{$entry['path']}");
                }
                @chmod($dest, 0644);
            }
            $zip->close();

            $sourceDir = $stagingDir;
            if (!is_file($sourceDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                $subDirs = glob($stagingDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $sd) {
                    if (is_file($sd . DIRECTORY_SEPARATOR . 'plugin.json')) {
                        $sourceDir = $sd;
                        break;
                    }
                }
            }

            if ($requireManifest && !is_file($sourceDir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                throw new ApiException('插件更新包格式不正确：缺少 plugin.json');
            }

            $cleanTarget = rtrim($targetDir, '/\\');
            if ($cleanTargetBeforeCopy && is_dir($cleanTarget)) {
                $this->cleanDirectoryExceptGitkeep($cleanTarget);
            }
            $this->copyPluginDirectorySafely($sourceDir, $cleanTarget);
        } finally {
            $this->removeDirectoryRecursively($stagingDir);
        }
    }

    /**
     * 安全覆写插件目录文件（若目标文件存在但不可写，先尝试 unlink 后复制；仍失败则给出清晰路径错误）
     */
    protected function copyPluginDirectorySafely(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new ApiException("插件目录不可写或无法创建：{$targetDir}");
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $rel = str_replace('\\', '/', $iterator->getSubPathName());
            $dest = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                    throw new ApiException("创建插件子目录失败，请检查目录权限：{$dest}");
                }
                continue;
            }
            $destParent = dirname($dest);
            if (!is_dir($destParent) && !@mkdir($destParent, 0755, true) && !is_dir($destParent)) {
                throw new ApiException("创建插件目录失败，请检查目录权限：{$destParent}");
            }
            if (is_file($dest) && !is_writable($dest)) {
                @unlink($dest);
            }
            if (!@copy($item->getPathname(), $dest)) {
                throw new ApiException("写入插件文件失败，请检查目录与文件写权限（如 chown -R www:www server/plugins）：{$dest}");
            }
            @chmod($dest, 0644);
        }
    }

    protected function removeDirectoryRecursively(string $dir): void
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

    /**
     * 安全记录 updater 日志（兼容未完整初始化日志驱动的独立 App 容器）
     */
    protected function logMessage(string $level, string $message): void
    {
        try {
            $this->app->log->{$level}($message);
        } catch (Throwable) {
        }
    }

    /**
     * 清理运行时缓存
     */
    protected function clearRuntimeCache(): void
    {
        try {
            Cache::clear();
        } catch (Throwable) {
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
