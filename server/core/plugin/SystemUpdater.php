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
    public const CURRENT_VERSION = '1.6.3';
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const VALID_CHANNELS = [self::CHANNEL_STABLE, self::CHANNEL_BETA];

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
     * 安全追加或补全 URL 查询参数（不覆盖已有非空参数）
     */
    public static function appendUrlQueryParams(string $url, array $extraParams): string
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
            if (!array_key_exists($key, $query) || $query[$key] === '') {
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
        $releaseDate = $remoteCatalog['framework']['release_date'] ?? '2026-11-05';

        // 计算框架下载地址
        $rawFrameworkUrl = $remoteCatalog['framework']['download_url'] ?? '';
        if ($rawFrameworkUrl !== '') {
            if (str_starts_with($rawFrameworkUrl, 'http://') || str_starts_with($rawFrameworkUrl, 'https://')) {
                $downloadUrl = $rawFrameworkUrl;
            } elseif ($cleanGatewayUrl) {
                $parts = parse_url($cleanGatewayUrl);
                $scheme = $parts['scheme'] ?? 'http';
                $host = $parts['host'] ?? '127.0.0.1';
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $downloadUrl = $scheme . '://' . $host . $port . '/' . ltrim($rawFrameworkUrl, '/');
            } else {
                $downloadUrl = $rawFrameworkUrl;
            }
        } else {
            $downloadUrl = $cleanGatewayUrl ? $cleanGatewayUrl . '/download?name=framework' : '';
        }

        if ($rawReleaseChannel === '' && $downloadUrl !== '' && str_contains($downloadUrl, 'channel=beta')) {
            $rawReleaseChannel = self::CHANNEL_BETA;
        }
        $releaseChannel = self::normalizeUpdateChannel($rawReleaseChannel !== '' ? $rawReleaseChannel : self::CHANNEL_STABLE);

        if ($downloadUrl !== '' && ($releaseChannel === self::CHANNEL_BETA || $activeChannel === self::CHANNEL_BETA)) {
            $downloadUrl = self::appendUrlQueryParams($downloadUrl, [
                'channel'     => $releaseChannel,
                'site_domain' => $resolvedSiteDomain,
            ]);
        }

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
                'name'            => 'hwadmin',
                'current_version' => $currentFrameworkVer,
                'latest_version'  => $latestFrameworkVer,
                'has_update'      => version_compare($latestFrameworkVer, $currentFrameworkVer, '>'),
                'update_channel'  => $activeChannel,
                'release_channel' => $releaseChannel,
                'release_date'    => $releaseDate,
                'changelog'       => $frameworkChangelog,
                'download_url'    => $downloadUrl,
                'min_php'         => '8.1',
            ],
            'plugins'   => $pluginsUpdateMap,
            'backups'   => $this->getBackups(),
        ];
    }

    /**
     * 执行框架平滑在线升级
     */
    public function updateFramework(?string $packageUrl = null): array
    {
        $packageUrl = $packageUrl !== null ? trim($packageUrl) : null;
        if ($packageUrl !== null && $packageUrl !== '') {
            $packageUrl = $this->assertTrustedFrameworkPackageUrl($packageUrl);
        }

        $currentVer = $this->getVersion();
        $targetVer = self::CURRENT_VERSION;
        $versionFile = $this->app->getRootPath() . 'version.json';

        // 1. 升级前执行主框架元数据与核心关键配置备份
        $backupId = $this->backupFramework($currentVer);

        // 2. 若提供了远程下载包，执行下载与补丁解压覆盖
        if ($packageUrl !== null && $packageUrl !== '') {
            $this->downloadAndApplyPatch($packageUrl);
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
        file_put_contents($versionFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 4. 执行 Phinx 增量迁移 (若有新表结构或字段)
        try {
            \think\facade\Console::call('migrate:run');
        } catch (Throwable $e) {
            $this->app->log->warning('[updater] 框架升级迁移自动执行日志: ' . $e->getMessage());
        }

        // 5. 清理系统运行时缓存
        $this->clearRuntimeCache();

        return [
            'success'     => true,
            'new_version' => $targetVer,
            'backup_id'   => $backupId,
            'msg'         => "HwAdmin 主框架已成功升级至最新版本 v{$targetVer}！",
        ];
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
            }

            // 5. 重新发布插件静态资源，并同步覆盖遗留前台覆盖视图（避免同请求旧版 Plugin 类常驻内存未执行同步）
            AssetPublisher::publish($name);
            $pluginFrontIndex = $pluginDir . 'public' . DIRECTORY_SEPARATOR . 'index.html';
            $legacyFrontView = $this->app->getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'index.html';
            if (is_file($pluginFrontIndex) && is_file($legacyFrontView)) {
                @copy($pluginFrontIndex, $legacyFrontView);
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
                $this->app->log->warning("[updater] 插件 {$name} 升级失败后自动回滚异常: " . $rollbackEx->getMessage());
                $rollbackNote = "（回滚恢复提示：{$rollbackEx->getMessage()}）";
            }
            if ($e instanceof ApiException) {
                throw new ApiException("插件升级失败：{$e->getMessage()}{$rollbackNote}", (int) $e->getCode(), $e->getData(), $e->getHttpStatus());
            }
            throw new ApiException("插件升级失败：{$e->getMessage()}{$rollbackNote}");
        }
    }

    /**
     * 备份当前框架核心代码、元数据与配置
     */
    public function backupFramework(string $version): string
    {
        $timeStr = date('Ymd_His');
        $backupId = "{$timeStr}_framework_{$version}";
        $targetDir = $this->runtimeBackupDir . $backupId . DIRECTORY_SEPARATOR;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // 备份 version.json
        $versionFile = $this->app->getRootPath() . 'version.json';
        if (is_file($versionFile)) {
            @copy($versionFile, $targetDir . 'version.json');
        }

        // 备份 config/hwadmin.php
        $configPath = $this->app->getConfigPath();
        if (is_file($configPath . 'hwadmin.php')) {
            @copy($configPath . 'hwadmin.php', $targetDir . 'hwadmin.php');
        }

        // 备份核心框架目录 core/
        $coreDir = $this->app->getRootPath() . 'core';
        $coreZip = $targetDir . 'core.zip';
        if (is_dir($coreDir)) {
            $this->zipDirectoryToFile($coreDir, $coreZip);
        }

        $totalBytes = (is_file($targetDir . 'version.json') ? (int) filesize($targetDir . 'version.json') : 0)
            + (is_file($targetDir . 'hwadmin.php') ? (int) filesize($targetDir . 'hwadmin.php') : 0)
            + (is_file($coreZip) ? (int) filesize($coreZip) : 0);
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
     * 将目录递归打包至指定 ZIP 文件
     */
    protected function zipDirectoryToFile(string $sourceDir, string $zipFile): void
    {
        if (!class_exists(ZipArchive::class) || !is_dir($sourceDir)) {
            return;
        }
        $normalizedRoot = rtrim((string) realpath($sourceDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalizedRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                if ($filePath !== false && str_starts_with($filePath, $normalizedRoot)) {
                    $relativePath = str_replace('\\', '/', substr($filePath, strlen($normalizedRoot)));
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        $zip->close();
    }

    /**
     * 备份插件源码至 ZIP 文件
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

        $backupId = "{$timeStr}_plugin_{$name}_{$version}";
        $zipFile = $this->runtimeBackupDir . "{$backupId}.zip";
        $this->zipDirectoryToFile($pluginDir, $zipFile);

        $size = is_file($zipFile) ? round(filesize($zipFile) / 1024, 1) . ' KB' : '0 KB';

        $meta = [
            'id'         => $backupId,
            'type'       => 'plugin',
            'name'       => $name,
            'version'    => $version,
            'created_at' => date('Y-m-d H:i:s'),
            'size'       => $size,
            'path'       => $zipFile,
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
            $path = (string) ($item['path'] ?? '');
            if (is_dir($path) || is_file($path)) {
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
            $dir = $target['path'];
            $coreZip = $dir . 'core.zip';
            if (class_exists(ZipArchive::class) && is_file($coreZip)) {
                $zip = new ZipArchive();
                if ($zip->open($coreZip) === true) {
                    $zip->extractTo($this->app->getRootPath() . 'core');
                    $zip->close();
                }
            }
            if (is_file($dir . 'version.json')) {
                @copy($dir . 'version.json', $this->app->getRootPath() . 'version.json');
            }
            if (is_file($dir . 'hwadmin.php')) {
                @copy($dir . 'hwadmin.php', $this->app->getConfigPath() . 'hwadmin.php');
            }
            $this->clearRuntimeCache();

            return [
                'success' => true,
                'msg'     => "已成功将 HwAdmin 主框架版本回滚至 {$target['version']}！",
            ];
        }

        if ($target['type'] === 'plugin') {
            $pluginName = (string) $target['name'];
            $zipFile = (string) ($target['path'] ?? '');
            $pluginDir = plugin_path($pluginName);

            if ($zipFile !== '' && is_file($zipFile)) {
                $this->extractPluginPackageSafely($zipFile, $pluginDir, false);
            }

            // 更新 plugin 表记录的版本号
            Db::name('plugin')->where('name', $pluginName)->update([
                'version'     => $target['version'],
                'update_time' => time(),
            ]);

            AssetPublisher::publish($pluginName);
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
     * 下载补丁并安全解压应用（保留站点环境配置、运行库与上传文件）
     */
    protected function downloadAndApplyPatch(string $url): void
    {
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
            $this->applyPatchArchive($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * 校验并安全解压升级补丁压缩包到当前后端根目录
     */
    public function applyPatchArchive(string $zipPath): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境缺少 zip 扩展');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ApiException('升级补丁压缩包损坏或格式无效');
        }

        $entries = [];
        $hasTopCore = false;
        $hasServerCore = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rawOrig = (string) $zip->getNameIndex($i);
            if (str_contains($rawOrig, "\0")) {
                $zip->close();
                throw new ApiException('升级包包含非法空字节路径条目');
            }
            $rawName = str_replace('\\', '/', $rawOrig);
            if ($rawName === '' || str_ends_with($rawName, '/')) {
                continue;
            }
            if (str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName) || in_array('..', explode('/', $rawName), true)) {
                $zip->close();
                throw new ApiException("升级包包含非法路径条目：{$rawName}");
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $mode = (($attr >> 16) & 0xF000);
                if ($opsys === ZipArchive::OPSYS_UNIX && $mode === 0xA000) {
                    $zip->close();
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
            if ($rel === '' || self::isProtectedPatchPath($rel)) {
                continue;
            }
            $content = $zip->getFromIndex($idx);
            if ($content === false) {
                continue;
            }
            $dest = $rootPath . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            if (@file_put_contents($dest, $content) === false) {
                $zip->close();
                throw new ApiException("写入升级文件失败：{$rel}");
            }
            @chmod($dest, 0644);
            $applied++;
        }

        $zip->close();
        return $applied;
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
    public function extractPluginPackageSafely(string $zipFile, string $targetDir, bool $requireManifest = false): void
    {
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

            $this->copyPluginDirectorySafely($sourceDir, rtrim($targetDir, '/\\'));
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
