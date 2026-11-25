## [1.6.5] - 2026-11-25

### 二开前端保护与 OTA 全量备份回滚升级
- **官方纯净前端指纹生成（区分官方纯净前端与二开自定义编译前端）**：
  - [sync-to-server.mjs](../admin-ui/scripts/sync-to-server.mjs)：执行 `pnpm --dir admin-ui build:sync` 同步构建产物至 `server/public/admin/` 时自动生成 `hwadmin-official-ui.json`（仅当 `HWADMIN_OFFICIAL_BUILD=1` 时 `official: true`，普通本地二开构建默认 `official: false`）；
  - [build-release.py](../scripts/build-release.py) 与 [build-release.sh](../scripts/build-release.sh)：官方发版打包时自动写入 `official: true` 与 `server/public/admin/index.html` 实时 SHA256 至发行包内 `server/public/admin/hwadmin-official-ui.json`。
- **后端智能检测、强制跳过保护与全量快照回滚**：
  - [SystemUpdater.php](../server/core/plugin/SystemUpdater.php)：新增 `detectFrontendStatus()` 自动识别 `official` / `custom` / `legacy_custom_suspected` / `legacy_clean` 四态；`checkUpdates()` 透传 `frontend_status`、`core_download_url`、`core_patch_sha256`；`applyPatchArchive()` 与 `updateFramework()` 支持 `preserveFrontend` 硬跳过 `public/admin/` 覆写；
  - `backupFramework()` 新增打包 `public/admin/`（`public_admin.zip`）与 OTA 覆盖范围 `app/` 核心文件（`app_core.zip`），`rollbackUpdate()` 支持完整还原前后端代码、配置与 OPcache 失效；
  - [Plugin.php](../server/app/adminapi/controller/system/Plugin.php)：`updateFramework()` 支持接收并透传可选 `preserve_frontend` 布尔参数。
- **前端升级弹窗透明选择与风险提示**：
  - [FrameworkUpdateDialog.vue](../admin-ui/src/components/FrameworkUpdateDialog.vue)、[hwadmin.ts](../admin-ui/src/api/hwadmin.ts)、[index/index.vue](../admin-ui/src/views/index/index.vue) 与 [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)：展示前端环境识别结果，提供“仅更新后端内核（保留当前前端 `public/admin/`）”与“同步更新前后端”模式选择，二开站点手动选择全量覆盖时弹出醒目红色警告。
- **自动化验证**：
  - [OtaChannelAndDependencyGuardTest.php](../server/tests/Unit/OtaChannelAndDependencyGuardTest.php)：新增官方指纹同步、二开前端保护（含 `.custom-ui` 与指纹不一致）、历史老站业务模块探测、全量备份与一键无损回滚、控制器参数透传正反例 PHPUnit 测试。
- **版本号判定**：本次为 OTA 升级二开前端保护与全量快照回滚机制架构升级，无数据库表结构变化，按 patch 从 `1.6.4` 升级至 `1.6.5`。

## [1.6.4] - 2026-11-24

### OTA 热更新加固（P2）
- **热更新覆写后逐文件 OPcache 失效**：
  - [SystemUpdater.php](../server/core/plugin/SystemUpdater.php)：`applyPatchArchive()` 在每次成功覆写 `.php` 文件后立即调用 `@opcache_invalidate($dest, true)`，消除高并发热更新瞬态 OPcache 脏缓存。
- **下载补丁包可选 SHA256 完整性验签 (`patch_sha256`)**：
  - [SystemUpdater.php](../server/core/plugin/SystemUpdater.php)、[Plugin.php](../server/app/adminapi/controller/system/Plugin.php)、[hwadmin.ts](../admin-ui/src/api/hwadmin.ts) 与 [FrameworkUpdateDialog.vue](../admin-ui/src/components/FrameworkUpdateDialog.vue)：`checkUpdates()` 透传 `framework.patch_sha256`，`updateFramework()` / `downloadAndApplyPatch()` 支持可选 64 位 SHA256 验签，校验失败立即删除临时文件并中止覆盖；
  - [OtaChannelAndDependencyGuardTest.php](../server/tests/Unit/OtaChannelAndDependencyGuardTest.php)：新增正反例 PHPUnit 单元测试覆盖合法 SHA256 放行、错误 SHA256 中止与非法格式拦截。
- **版本号判定**：本次为 OTA 热更新 OPcache 失效与补丁 SHA256 验签加固，无数据库表结构或破坏性接口变更，按 patch 从 `1.6.3` 升级至 `1.6.4`。

## [1.6.3] - 2026-11-24

### 更新通道与 OTA 安全防线（Beta 内测）
- **「更新通道（稳定版 Stable / 内测版 Beta）」切换支持**：
  - [SystemUpdater.php](../server/core/plugin/SystemUpdater.php)、[Plugin.php](../server/app/adminapi/controller/system/Plugin.php) 与 [hwadmin.ts](../admin-ui/src/api/hwadmin.ts)：支持在系统配置中切换 `stable` / `beta` 更新通道，检测更新与下载框架补丁包时透传 `channel` 与当前站点域名 `site_domain`，并与普通 `stable` 通道完全隔离；
  - [FrameworkUpdateDialog.vue](../admin-ui/src/components/FrameworkUpdateDialog.vue)、[index/index.vue](../admin-ui/src/views/index/index.vue) 与 [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)：新增更新通道切换开关与内测版（Beta）标识展示。
- **OTA 静态类依赖防线**：
  - [SystemUpdater.php](../server/core/plugin/SystemUpdater.php) 与 [OtaChannelAndDependencyGuardTest.php](../server/tests/Unit/OtaChannelAndDependencyGuardTest.php)：新增框架补丁包核心控制器、公共服务与模型类依赖完整性静态校验，防止缺失关键依赖类导致线上 OTA 升级异常。
- **版本号判定**：本次为更新通道切换与 OTA 静态类依赖防线增强，无数据库表结构或破坏性接口变更，按 patch 从 `1.6.2` 升级至 `1.6.3`。

## [1.6.2] - 2026-11-24

### 性能优化与体验改进
- **后端热路径缓存与游标分页**：
  - 新增 [ConfigService.php](../server/app/common/service/ConfigService.php) 与 [DictService.php](../server/app/common/service/DictService.php)（60s 快照缓存 + 模型写操作自动失效），接入 [Index.php](../server/app/adminapi/controller/Index.php)、[Dashboard.php](../server/app/adminapi/controller/Dashboard.php)、[Config.php](../server/app/adminapi/controller/system/Config.php)、[Dict.php](../server/app/adminapi/controller/system/Dict.php) 与 [Attachment.php](../server/app/adminapi/controller/system/Attachment.php)，减少重复配置/字典查询；
  - [CrudTrait.php](../server/core/crud/CrudTrait.php) 新增主键游标分页（`cursor` / `next_cursor` / `has_more`）与严格整数校验，[AdminLog.php](../server/app/adminapi/controller/auth/AdminLog.php) 与 [PerformanceOptimizationTest.php](../server/tests/Unit/PerformanceOptimizationTest.php) 配套覆盖。
- **前端虚拟树与离线微视图支持**：
  - [group/index.vue](../admin-ui/src/views/auth/group/index.vue) 权限树切换为 `ElTreeV2` 虚拟列表，提升大量权限节点下的渲染与勾选性能；
  - [runner.vue](../admin-ui/src/views/system/plugin/runner.vue) 更新离线免编译微视图单文件 HTML 示例；
  - [hw-deploy.py](../scripts/hw-deploy.py) 与 [test_t03_t04_prod_deploy_recovery.py](../scripts/tests/test_t03_t04_prod_deploy_recovery.py) 完善部署与恢复校验。
- **开源社区规范与自动化发版工作流**：
  - 新增 [CONTRIBUTING.md](../CONTRIBUTING.md)、[.github/workflows/release.yml](../.github/workflows/release.yml) 与 Issue/PR 模板，完善 [README.md](../README.md)、[AGENTS.md](../AGENTS.md) 与 [CLAUDE.md](../CLAUDE.md) 公开仓安全协作规范。
- **版本号判定**：本次为性能优化、离线微视图与开源社区模板完善，无数据库表结构或破坏性接口变更，按 patch 从 `1.6.1` 升级至 `1.6.2`。

## [1.6.1] - 2026-11-24

### 优化与界面升级
- **主框架插件管理样式全面看齐应用商城**：
  - [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)：全面重构插件中心顶部品牌横幅、商城连接状态胶囊卡、主标签页、胶囊筛选组与插件卡片网格视觉层级，统一卡片密度、状态徽标、权限提示与操作按钮交互体验，整体视觉风格与官方应用商城门户保持一致。
- **版本号判定**：本次为前端插件中心 UI 样式全面精修与交互升级，无数据库表结构或后端接口破坏性变更，按 patch 从 `1.6.0` 升级至 `1.6.1`。

## [1.6.0] - 2026-11-24

### 安全加固
- **后端核心与权限边界加固**：
  - `crud` 生成器（[Generator.php](../server/plugins/crud/controller/adminapi/Generator.php)、[CrudGeneratorService.php](../server/plugins/crud/service/CrudGeneratorService.php)）：强制超管鉴权、严格校验表名/模块/字段标识符与 DDL 类型白名单，补全 `SYSTEM_PROTECTED_TABLES` 核心保护表清单；
  - 插件中心与 OTA 热更新（[Plugin.php](../server/app/adminapi/controller/system/Plugin.php)、[SystemUpdater.php](../server/core/plugin/SystemUpdater.php)、[OutboundHttpGuard.php](../server/core/support/OutboundHttpGuard.php)）：高危写操作移出 `$noNeedRight` 并限超管，ZIP 解压防 ZipSlip 与 POSIX 符号链接穿透，新增出站 HTTP/HTTPS SSRF 防护与同源下载校验；
  - 运维与定时任务（[Devops.php](../server/app/adminapi/controller/system/Devops.php)、[Crontab.php](../server/app/adminapi/controller/system/Crontab.php)、[CrontabExecutor.php](../server/core/support/CrontabExecutor.php)）：`Devops::optimize()` 强制 POST 与真实表名白名单，`Crontab` 禁绝 `sql` 任务并将 `command` 限制在安全白名单、URL 任务接入 `OutboundHttpGuard`；
  - 鉴权、会话与数据脱敏（[Admin.php](../server/app/adminapi/controller/auth/Admin.php)、[Group.php](../server/app/adminapi/controller/auth/Group.php)、[Config.php](../server/app/adminapi/controller/system/Config.php)、[CrudTrait.php](../server/core/crud/CrudTrait.php)、[cookie.php](../server/config/cookie.php)、[Installer.php](../server/core/install/Installer.php)、[InstallCommand.php](../server/core/console/InstallCommand.php)）：`multi()` 拦截越权与禁用联动吊销令牌，过滤敏感配置与可筛选敏感列，默认启用 Cookie `HttpOnly` 与 `SameSite=Lax`，CLI 安装默认生成 16 位随机强密码；
  - CMS 插件安全修复（[Member.php](../server/plugins/cms/controller/api/Member.php)、[Article.php](../server/plugins/cms/controller/api/Article.php)）：会员登录接入 `LoginThrottle` 与恒定时间校验、改密吊销全量令牌，留言仅展示审核通过记录并限流防刷。
- **前端与开源供应链安全合规**：
  - [VabDeepSeek/index.vue](../admin-ui/library/components/VabDeepSeek/index.vue) 错误分支转义并统一经 `DOMPurify.sanitize()` 净化防 XSS；[runner.vue](../admin-ui/src/views/system/plugin/runner.vue) 移除 URL 明文 `?token=` 并收紧 `postMessage` 至同源 `window.location.origin`；[user.ts](../admin-ui/src/store/modules/user.ts) 权限加载改 fail-closed；
  - [default.conf](../docker/nginx/default.conf)、[nginx.htaccess](../server/public/nginx.htaccess) 拦截隐藏点文件与 `.env`/`.sql`/`.sqlite`/`.zip` 等敏感后缀；[publish_plugin.py](../scripts/publish_plugin.py) 移除硬编码内网地址并加固 SSH 主机指纹校验（默认 `RejectPolicy`）；[hw-deploy.py](../scripts/hw-deploy.py) 数据库凭据改由环境变量透传；[docker-compose.dev.yml](../docker-compose.dev.yml) 开发端口绑定 `127.0.0.1`；新增 [SECURITY.md](../SECURITY.md)，全端统一 `Apache-2.0` 许可证。

### 性能优化与数据库升级
- **后端热路径与数据库索引升级**：
  - [AdminAuth.php](../server/core/auth/AdminAuth.php)、[AdminController.php](../server/core/base/AdminController.php)、[ApiController.php](../server/core/base/ApiController.php)：权限规则与角色组接入 60s 版本化缓存（写操作自动失效），`check()` 改 O(1) 匹配，`writeAdminLog()` 消除全表扫描，`$noNeedLogin` 路由延迟校验 Token；
  - [Dashboard.php](../server/app/adminapi/controller/Dashboard.php)：近 7 天趋势改写为多库兼容 SQL `GROUP BY` 聚合与 60s 缓存，消除大表 OOM；
  - [database.php](../server/config/database.php)、[cache.php](../server/config/cache.php)、[20261124000001_add_performance_indexes.php](../server/database/migrations/20261124000001_add_performance_indexes.php)：开启生产字段缓存与 SQLite `WAL`/`NORMAL` 模式，新增 8 组高频查询性能索引迁移，[PluginManager.php](../server/core/plugin/PluginManager.php) 增强跨库索引适配；[cms/Plugin.php](../server/plugins/cms/Plugin.php) 通过版本哨兵消除热路径磁盘 `md5_file` 校验。
- **前端构建瘦身与 Nginx 强缓存**：
  - [vite.config.ts](../admin-ui/vite.config.ts)、[vab.scss](../admin-ui/library/styles/vab.scss)、[cli.config.ts](../admin-ui/src/config/cli.config.ts)：剔除冗余 `remixicon` `.svg/.eot/.ttf/.woff` 字体（仅保留 `.woff2`）体积直减 ~4.3 MB；路由与 6 套布局改异步懒加载，`marked`/`DOMPurify`/`sortablejs` 改交互动态导入，开启 gzip 预压缩；移除冗余地图 JSON 与外链图片占位，滚动记忆改节流写入；
  - Nginx 配置与 `docker/php/` 启用 `/admin/static/` 1 年 `immutable` 强缓存、`gzip_static on;` 与 PHP-FPM/OPcache JIT 调优。

- **版本号判定**：包含新增数据库性能索引迁移（`20261124000001_add_performance_indexes.php`）、多端核心安全架构重构与前后端高性能优化，按 minor 从 `1.5.24` 升级至 `1.6.0`。

## [1.5.24] - 2026-11-18

### 修复与优化
- **修复「同步已购」接口 `TypeError` 引发的 HTTP 500 报错**：修复 `Plugin::syncMarketLicenses()` 错误将数组 `[]` 传入 `$this->error(string $msg, int $code, mixed $data)` 第二个参数导致的 PHP 8 类型异常（原表现为弹窗 `服务器内部错误 / 同步失败：网络错误`）；市场令牌过期时统一返回 `code=0` 及 `data.need_login=true`（HTTP 200），避免误登出本地管理员。
- **市场令牌过期自动引导重登与报错透传**：`SystemUpdater::updatePlugin()`、`Plugin::downloadMarket()` 与前端插件中心在检测到商城会话过期时统一返回并识别 `need_login=true`，自动弹出「登录官网账号」窗口引导重绑；同步补齐其余操作失败提示的 `e?.msg || e?.message` 提取。

## [1.5.23] - 2026-11-18

### 修复与优化
- **插件在线升级与回滚稳定性修复**：`SystemUpdater::updatePlugin()` 新增安全临时解压目录（`extractPluginPackageSafely()`），校验 ZipSlip 与目录结构后安全替换目标插件文件，避免直接 `ZipArchive::extractTo()` 遇文件属主/只读问题触发 PHP `ErrorException`（HTTP 500 `服务器内部错误`）；失败自动回滚 `rollbackUpdate()` 改用 `try/catch` 隔离保护并优先保留原始错误信息。
- **网关下载失败提示透传与前端错误显示修复**：`updatePlugin()` 下载插件包异常时优先解析并抛出应用商城返回的具体错误提示（不再掩盖为本地假升级），前端插件管理页错误弹窗改为优先读取 `e?.msg || e?.message`，修复「更新失败：未知错误」遮蔽真实报错的问题。
- **静态资源与菜单大小写兼容**：`AssetPublisher` 删除/复制增加显式权限异常处理；`MenuImporter::import()` 对已有规则键名按小写归一化，兼容历史大小写混合权限节点升级，防止 MySQL 唯一索引冲突。

# Changelog

## 1.5.22 — 2026-11-18

- **修复内置「应用开发 (crud)」插件安装与重装时的权限节点命名空间拦截报错**：
  - [MenuImporter.php](../server/core/plugin/MenuImporter.php)：修复 `walk()` 对精确根权限节点 `plugin/{plugin}`（如 `plugin/crud`）未带尾部斜杠导致的 `[2-P0-5]` 误拦截问题，并在该插件自身命名空间已被核心预置（`plugin=''`）时自动复用现有节点并保留归属与菜单布局，避免“已被其他模块占用”冲突报错，同时继续严格拦截所有越权核心/跨插件节点；
  - [V151MenuAndAgentGuidelineTest.php](../server/tests/Unit/V151MenuAndAgentGuidelineTest.php)：新增 `crud` 卸载重装及核心预置节点复用单元测试验证。
- **版本号判定**：修复内置插件安装权限节点导入缺陷，无表结构或对外接口破坏性变更，按 patch 从 1.5.21 升级至 1.5.22。

## 1.5.21 — 2026-11-18

- **框架升级自动刷新与发现应用「已安装」状态防重装优化**：
  - [FrameworkUpdateDialog.vue](../admin-ui/src/components/FrameworkUpdateDialog.vue) / [index.vue](../admin-ui/src/views/index/index.vue)：主框架在线升级或快照回滚完成后，自动清理浏览器运行时缓存并在 800ms 后刷新页面，确保新版静态资源、路由与版本号即时生效。
  - [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)：「发现应用」列表中对本地已安装应用显式展示「已安装」/「已安装·未启用」徽标与禁用防重复点击按钮（未启用时引导直接启用，有新版时引导更新），避免用户重复下载安装。
  - [PluginManager.php](../server/core/plugin/PluginManager.php)：增强 `adaptSqlDialect()`，在 SQLite / PostgreSQL 方言自动适配 `CREATE TABLE` 内的 MySQL 内联 `INDEX` / `KEY` / `UNIQUE KEY` 及 `LONGTEXT`/`TINYINT` 字段类型，解决含内联索引的插件在 SQLite 下安装报错的问题。
- **版本号判定**：包含前端交互优化与 `PluginManager` 跨库 SQL 方言适配修复，无数据库表结构或对外接口破坏性变更，按 patch 从 1.5.20 升级至 1.5.21。

## 1.5.20 — 2026-11-18

- **官网账号登录弹窗增加「去官方注册」直达入口**：
  - [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)：在「登录官网账号」弹窗底部新增 **「没有账号？去官方注册 →」** 按钮，支持自动跳转当前商城源官网注册页（`/?tab=login&mode=register`），打通新用户注册后返回绑定一键安装的完整闭环。
- **版本号判定**：新增官网账号弹窗注册直达入口，无接口或表结构变更，按 patch 从 1.5.19 升级至 1.5.20。

## 1.5.19 — 2026-11-18

- **应用商城严格区分本地插件直装与商城账号绑定安装**：
  - **本地插件继续开箱直装**：「我的应用」中来自本地目录的插件可通过 `/adminapi/system.plugin/install` 与启用接口直接安装使用，无需绑定市场账号；
  - **应用商城安装强制登录并绑定**：[Plugin.php](../server/app/adminapi/controller/system/Plugin.php) 的 `downloadMarket()` 与 [plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)「发现应用」页签在未绑定官网市场账号时拦截直接安装（返回 `need_login: true` 并引导一键弹出登录绑定框）；同步完善 [SystemUpdater.php](../server/core/plugin/SystemUpdater.php) 插件在线升级时透传 `Authorization: Bearer` 通行证令牌。
- **版本号判定**：新增应用商城安装前的官网账号绑定强制门禁与更新令牌透传，无破坏性表结构变更，按 patch 从 1.5.18 升级至 1.5.19。

## 1.5.18 — 2026-11-18

- **彻底合成为单一宝塔伪静态展示与自动配置状态探测**：
  - [deployment-check.js](../admin-ui/public/deployment-check.js)、[DeploymentService.php](../server/app/common/service/DeploymentService.php)、[Index.php](../server/app/install/controller/Index.php)：移除残余的双块/进阶 PHP 解析碎片展示与多余 `socket` 生成分支，彻底合成为唯一一片宝塔「伪静态」规则；
  - 新增无副作用实时探测（校验 `/install/index/deploymentRoute` 与 `/nginx.htaccess` 屏蔽状态）：若用户已手动配置伪静态，面板自动显示 **「✓ 已配置伪静态」** 绿色状态并收拢规则文本框，同时为 `/admin/deployment-check.js` 引用追加版本参数避免浏览器强缓存旧版面板。
- **移除与现有伪静态及 Hash 路由矛盾的两项无效检查**：
  - [deployment-check.js](../admin-ui/public/deployment-check.js)、[deployment.test.mjs](../admin-ui/packages/pc-core/test/deployment.test.mjs)、[部署与运维指南](部署与运维指南.md)：删除 `后台安全响应头` 与 `后台页面路由回退` 检查（`nginx.htaccess` 为支持自定义 `admin_entry` 将 `/admin/` 收口经 PHP 鉴权，且管理端采用 Hash 路由模式 `isHashRouterMode: true`），确保用户配置官方伪静态与 HTTPS 后所有部署检查项全部通过。
- **版本号判定**：安装与部署安全检查逻辑及体验精简，无表结构变更，按 patch 从 1.5.17 升级至 1.5.18。

## 1.5.17 — 2026-11-18

- **全端品牌视觉与启动加载体验焕新**：
  - 全量替换管理端、官网门户、安装向导、移动端、PWA Manifest 与 Tauri 桌面壳的品牌 Logo、Favicon 及应用图标（`server/public/brand/`、`admin-ui/public/`、`admin-ui/mobile/`、`admin-ui/src-tauri/icons/`），并优化异步初始化闪烁加载动效（`loading.css`）。
- **宝塔单步伪静态安全二合一整合与本机一键写入**：
  - 将原先分散的「替换 PHP 解析配置」合并为一条前置防穿透伪静态规则（`$hw_block_php` 前置拦截 + [nginx.htaccess](../server/public/nginx.htaccess)），大幅降低宝塔面板上手门槛；
  - 新增 `DeploymentService::applyBaotaRewrite()`（严格校验 `127.0.0.1` / `localhost` 回环地址）与安装向导「一键写入伪静态 / 复制一键终端命令」能力（[deployment-check.js](../admin-ui/public/deployment-check.js)）。
- **文档架构精简隔离与宝塔首选推荐落地**：
  - 将「安装与部署安全检查」并入 [部署与运维指南](部署与运维指南.md)，将所有面向使用者的开发规范与指南扁平收敛至 `docs/` 根目录，并将宝塔面板明确提升为单机生产部署首选推荐方案（[README.md](../README.md)、[部署与运维指南](部署与运维指南.md)）；
  - 将 `hw.project.schema.json` 归位至 `server/core/support/`，并将维护者自用备忘与私密部署脚本彻底隔离出开源仓库与发行包。
- **版本号判定**：新增安装向导回环写入接口、全端视觉与文档结构优化，无破坏性表结构或接口契约变更，按 patch 从 1.5.16 升级至 1.5.17。

## 1.5.16 — 2026-11-17

- **`START_HERE.md` 标准开发与部署模板**：
  - [START_HERE.md](../START_HERE.md)：新增可选的 YAML 格式「标准项目需求与部署模板」，支持一次性填写应用名称、起步模板、目标终端、风格、核心模块字段权限及目标部署/数据库选型；未填写项仍由 Agent 按默认 SQLite 预览推进。
- **发行包 `docs/` 目录白名单精简**：
  - [build-release.py](../scripts/build-release.py)：将统一包 `docs/` 收敛为 `DEV_PACKAGE_ALLOWED_DOCS` 显式白名单（仅保留架构、开发规范、插件规范、Schema、部署运维、安全、移动/桌面接入与合规/CHANGELOG，彻底排除作者自用的开发进展看板、路线图、调研评审记录与历代版本发布说明碎文件），并同步在 [test_v150_packages_and_docs.py](../scripts/tests/test_v150_packages_and_docs.py) 增加回归断言。
- **版本号判定**：入门模板与打包分发文档规则优化，无表结构或接口变更，按 patch 从 1.5.15 升级至 1.5.16。

## 1.5.15 — 2026-11-17

- **统一包脚本与测试布局适配**：
  - [test_v150_packages_and_docs.py](../scripts/tests/test_v150_packages_and_docs.py) 兼容 `server/.example.env`；[hw](../hw) 支持 `./hw bootstrap --with-test` 及 `./hw verify` 自动探测补齐 `vendor/bin/phpunit`；[hw-deploy.py](../scripts/hw-deploy.py) 与 [start-project.py](../scripts/start-project.py) 适配统一包 `/server/public`、排除 `src-tauri/target/` 并解析 `runtime/hwadmin.sqlite`。
- **三端脚手架防呆升级与 `--with-mobile` 一键骨架**：
  - [CrudCommand.php](../server/core/console/CrudCommand.php) 与 [CrudScaffoldBuilder.php](../server/core/support/CrudScaffoldBuilder.php)：生成模型附带 `protected $type` 强类型映射；公开提交 `submit()` 放行 `create_time` / `update_time`；控制器自定义区内置 `beforeWrite` / `afterWrite` 精确签名模板；新增 `--with-mobile` 生成移动端 API 与 Wot UI V2 页面骨架，配套 [V1515EfficiencyAndScaffoldTest.php](../server/tests/Unit/V1515EfficiencyAndScaffoldTest.php) 回归验证。
- **四端 Agent 契约补全**：
  - 补充根目录 [AGENTS.md](../AGENTS.md)、[server/AGENTS.md](../server/AGENTS.md)、[admin-ui/AGENTS.md](../admin-ui/AGENTS.md)、[admin-ui/mobile/AGENTS.md](../admin-ui/mobile/AGENTS.md) 与 [前端开发规范](前端开发规范.md) 的多端避坑与全栈质检条款。
- **版本号判定**：开发体验、脚本与文档契约增强，无破坏性表结构变更，按 patch 从 1.5.14 升级至 1.5.15。

## 1.5.14 — 2026-11-17

- **彻底移除商城源本地地址回退，默认统一走官方 HTTPS 域名**：
  - [Plugin.php](../server/app/adminapi/controller/system/Plugin.php)：移除 `getMarketCandidateBaseUrls()` 在 `APP_DEBUG` 下自动追加 `127.0.0.1:8100`、`localhost:8100` 与 `hwmarket-nginx` 的本地探测回退；默认官方商城源统一指向 `https://hw.baotoutv.cn`，同步增强 [MarketSourceAndUpdaterTest.php](../server/tests/Unit/MarketSourceAndUpdaterTest.php) 断言覆盖。
- **版本号判定**：默认网关候选节点清理（去除本地地址回退），无接口或表结构变更，按 patch 从 1.5.13 升级至 1.5.14。

## 1.5.13 — 2026-11-17

- **职责分离：主框架更新与回滚收敛至控制台首页，插件管理专注应用中心**：
  - **首页独享主框架更新闭环**（[index/index.vue](../admin-ui/src/views/index/index.vue)）：在顶部蓝色横幅新增主框架「备份与回滚」入口及 `type === 'framework'` 快照列表弹窗，集中承载主框架版本号、检查更新、一键升级与回滚能力。
  - **插件管理去主框架化**（[system/plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)）：移除主框架 `HwAdmin` 深色更新横幅与框架升级弹窗；在「应用商城」工具栏提供专属「检查更新」与「应用回滚」（仅展示 `type === 'plugin'` 应用快照），页面彻底只显示插件与应用信息。
- **版本号判定**：管理后台控制台首页与插件管理页面职责边界拆分与交互优化，不涉及后端接口或数据库表结构变更，按 patch 从 1.5.12 升级至 1.5.13。

## 1.5.12 — 2026-11-17

- **发行包二合一统一与真·源码热更新（OTA）全链路闭环**：
  - **主框架发行包合并为单一完整包 `hwadmin-v1.5.12.zip`**（[build-release.py](../scripts/build-release.py)、[prepare-release.sh](../scripts/prepare-release.sh)、[hw-deploy.py](../scripts/hw-deploy.py)、[ci.yml](../.github/workflows/ci.yml)）：默认 `--mode=unified` 产出唯一完整包 `hwadmin-v1.5.12.zip`（完整仓库结构 + 预构建 `server/public/admin/` + 生产 `--no-dev` 依赖 `server/vendor/`），兼顾 AI Agent 二开（`./hw start`）与生产直跑（Web 运行目录 `/server/public`）。
  - **在线更新检测与内核补丁应用全面加固**（[SystemUpdater.php](../server/core/plugin/SystemUpdater.php)）：
    - `checkUpdates()` 在比对前统一剥离框架与插件版本号的 `v`/`V` 前缀，彻底解决 PHP `version_compare('v1.5.x', '1.5.y', '>')` 误判为最新版的缺陷；
    - `backupFramework()` / `rollbackUpdate()` 增加 `core/` 内核目录压缩快照与恢复；
    - `applyPatchArchive()` 支持识别补丁内 `server/` 前缀、拦截 `../` 路径穿越并严格跳过 `.env`、`config/install.lock`、`config/database.php`、`runtime/`、`public/uploads/`、`public/plugins/` 与业务代码；`clearRuntimeCache()` 同步调用 `opcache_reset()` 使升级立即生效。
- **版本号判定**：合并单一发行包构建链路与 OTA 核心热更新引擎加固，无破坏性表结构变更，按 patch 从 1.5.11 升级至 1.5.12。

## 1.5.11 — 2026-11-17

- **切换主框架官方应用商城分发源至 `https://hw.baotoutv.cn` 并修复在线升级版本号回落缺陷**：
  - **官方商城网关切流**（[hwadmin.php](../server/config/hwadmin.php)、[Plugin.php](../server/app/adminapi/controller/system/Plugin.php)、[.example.env](../server/.example.env)、[plugin/index.vue](../admin-ui/src/views/system/plugin/index.vue)）：将 `hwadmin.market.default_url` 与客户端默认候选商城源统一切换为 `https://hw.baotoutv.cn`，后台「商城源设置」留空即默认走官方正式域名 `https://hw.baotoutv.cn`。
  - **在线热更新链路加固**（[SystemUpdater.php](../server/core/plugin/SystemUpdater.php)）：修复 `updateFramework()` 在应用补丁后被固定版本号覆盖 `version.json` 的缺陷，改为优先保留补丁写入的 `version.json` 或解析下载包版本号；同时为 `downloadAndApplyPatch()` 增加 `CURLOPT_FOLLOWLOCATION` 重定向跟随、60 秒超时及 HTTP 200 非空校验。
- **版本号判定**：默认官方分发源域名切换与框架在线升级稳定性修复，无表结构或破坏性 API 变更，按 patch 从 1.5.10 升级至 1.5.11。

## 1.5.10 — 2026-11-16

- **默认开启工具栏组件、首页体验完善、安全入口全链路修复与上传/附件交互重构**：
  - **默认启用通知与 DeepSeek AI**（[theme.config.ts](../admin-ui/src/config/theme.config.ts)）：将 `showNotice` 与 `showDeepSeek` 默认设为 `true`。
  - **控制台首页完善**（[index/index.vue](../admin-ui/src/views/index/index.vue)）：修复角色权限组 `/auth/group` 跳转路径，替换插件指标卡为「操作审计日志」「个人安全中心」，精简横幅栈描述为 `PHP 8+ + ThinkORM 4 + Vue 3 + MySQL 8.0+ / PG 16+ / SQLite`，并统一三排卡片等高拉伸对齐。
  - **修复管理后台安全入口路径（`admin_entry`）失效缺陷**（[AdminEntry.php](../server/core/support/AdminEntry.php)、[public/index.php](../server/public/index.php)、[nginx.htaccess](../server/public/nginx.htaccess)、[.htaccess](../server/public/.htaccess)、[branding.ts](../admin-ui/src/utils/branding.ts)）：改写自定义入口 HTML 相对路径与 JS 资源基址，并将 `/admin`、`/admin/` 与 `/admin/index.html` 收口至 PHP 鉴权拦截（修改入口后原 `/admin` 真实返回 404）。
  - **上传设置动态联动云存储插件**（[Config.php](../server/app/adminapi/controller/system/Config.php)、[config/index.vue](../admin-ui/src/views/system/config/index.vue)）：移除固定 404「配置云存储」链接，改为仅在安装启用 `oss` 插件后自动在「存储方式」下拉框中扩展云存储驱动选项。
  - **附件管理工具栏集成上传按钮**（[attachment/index.vue](../admin-ui/src/views/system/attachment/index.vue)）：将大图占位的拖拽上传区改为与查询/重置并排的显眼「上传附件」按钮。
- **版本号判定**：安全入口链路修正与多端控制台体验完善，无破坏性表结构变更，按 patch 从 1.5.9 升级至 1.5.10。

## 1.5.9 — 2026-11-16

- **SVG 统计图与图标防拉伸、全后台表格「操作」列按钮标准化**：
  - **图表与图标防拉伸（[index/index.vue](../admin-ui/src/views/index/index.vue)、[vab.scss](../admin-ui/library/styles/vab.scss)）**：移除 `preserveAspectRatio="none"`，通过 `ResizeObserver` 按实时像素比例动态计算趋势图 `viewBox` 与坐标点，修复数据圆点被拉扁及文字失真问题；为 `.el-icon`、`.vab-icon` 与指标/卡片图标容器补全 `flex-shrink: 0`。
  - **统一表格操作按钮规范（`.hw-table-actions`）**：在 `VabApp` 关闭中文双字强制空格（`autoInsertSpace: false`），在 `vab.scss`、`HwTable`、`CrudScaffoldBuilder`、`CrudGeneratorService` 及全站 20 个管理页统一将操作列由裸文字链升级为 `size="small" plain` 紧凑边框按钮并加宽列宽，同步将规范写入 [前端开发规范](前端开发规范.md) 与 [admin-ui/AGENTS.md](../admin-ui/AGENTS.md)。
- **版本号判定**：全局前端视觉标准统一与代码生成模板同步优化，未改变后端 API 与表结构，按 patch 从 1.5.8 升级至 1.5.9。

## 1.5.8 — 2026-11-16

- **全局样式防重叠与卡片头部对齐根因修复**：
  - 收口 `admin-ui/public/static/css/loading.css` 的 `.dot` / `figure` 选择器至 `#app > figure`，彻底消除 `.dot { position: absolute }` 泄漏导致的控制台图例圆点压字问题。
  - 修复 `admin-ui/library/styles/vab.scss` `.el-card__header` Flex 挤压右置标签/按钮的问题，规范 `.el-button` / `.el-tag` 图标文字间距并补充弹窗 `max-width: 94vw` 自适应保护。
- **控制台首页版本检查对齐与系统安全配置面板收口**：
  - 抽取 `admin-ui/src/api/hwadmin.ts` 更新接口与共用 `FrameworkUpdateDialog.vue` 组件，在控制台首页（`admin-ui/src/views/index/index.vue`）顶部横幅集成「当前版本 / 已是最新 / 新版本」状态徽标、一键检查更新与在线升级入口。
  - 将 `admin-ui/src/views/system/config/index.vue` 中的「系统安全加固与入口防护监控」面板严格收口到 `安全配置 (Security)` 分组内显示，不再于其他配置分组重复渲染。
- **优化「应用开发」插件 4 个功能页面的响应式自适应与防重叠样式**：
  - **可视化 CRUD（`admin-ui/src/views/plugin/crud/index.vue`）**：重构生成参数表单为自适应网格与紧凑 `inline-prompt` 开关（消除开关标签竖向折行及 `(admin_id)` 重叠），修复卡片头部/字段表头在 Flex 容器下的挤靠错位，优化字段类型防裁切并增加 `<= 1180px` 上下堆叠布局。
  - **定时任务（`admin-ui/src/views/system/crontab/index.vue`）**：加宽操作列与 Cron 表达式列并统一动作按钮间隙对齐（解决「删除」折行错位与表达式裁切），补充查询栏、分页与日志抽屉移动端适配。
  - **开发运维中心（`admin-ui/src/views/system/devops/index.vue`）**：修复「在线接口调试器」头部全宽两端对齐（解决「立即发送请求」按钮挤靠标题问题），优化 Query 参数行、统计横幅与 `<= 1024px` 侧栏上下堆叠适配。
  - **数据字典（`admin-ui/src/views/system/dict/index.vue`）**：调整桌面端（`>= 992px`）与中窄屏切换策略避免上下叠压，补充头部自动折行与弹窗 `min(..., 92vw)` 自适应尺寸。
- **版本号判定**：纯前端视图样式与响应式适配优化，无数据库与后端契约变更，按 patch 从 1.5.7 升级至 1.5.8。

## 1.5.7 — 2026-11-16

- **修复插件管理检查更新/备份接口 404 报错**：
  - 修复 `admin-ui/src/views/system/plugin/index.vue` 中 `checkUpdates`、`updateFramework`、`updatePlugin`、`rollbackUpdate`、`getBackups` 遗漏 `/adminapi`（`API_BASE`）前缀导致的 404（`访问资源不存在` / `暂时无法检查更新`）报错，并在首次进入页面时静默检查更新。
- **整合「应用开发」内置插件及左侧边栏结构**：
  - 将原顶级目录「插件应用（`plugin`）」更名为 **「应用开发」**，通过 `menus.php` 与迁移脚本 `20261116000001_regroup_app_dev_menus.php` 将 **数据字典**、**定时任务**、**开发运维中心** 与 **CRUD 生成** 统一归集至「应用开发」目录（原「系统」目录仅保留系统配置、附件管理、个人中心、插件管理）。
  - 将内置 `crud` 插件（`server/plugins/crud/`）升级标识为「应用开发」套件（同步更新 `Plugin::PLUGIN_PRESETS`、`Installer` 与启禁用联动逻辑），并修正控制台首页与通知中心指向 `/plugin/dict`、`/plugin/crontab`、`/plugin/crud` 的快捷跳转路径。
- **版本号判定**：修复插件中心 404 接口路径与菜单目录/内置插件整合迁移（不改变原有后台 API 契约），按 patch 从 1.5.6 升级至 1.5.7。

## 1.5.6 — 2026-11-16

- **管理端控制台视觉精修与全端「智能」文案统一**：
  - 将控制台欢迎标题、版本徽标及各前后台界面文案中的「敏捷管理后台 / 瑞士高定版 / 敏捷研发 / 敏捷框架」等统一调整为「智能管理后台 / 智能版 / 智能研发与运维 / 智能框架」。
  - 为控制台顶部渐变横幅中的「刷新」「清理缓存」按钮新增高对比度白色圆角背景样式，大幅提升可读性与视觉层次。
  - 精简「近 7 日请求趋势」卡片标题与图例文案（「API 请求」「安全事件」），并为图表头部增加自适应换行与防挤压样式，彻底消除文字裁切与重叠遮挡。
- **版本号判定**：界面样式优化与文案精简升级，不涉及核心接口契约与数据库结构变更，按 patch 从 1.5.5 升级至 1.5.6。

## 1.5.5 — 2026-11-16

- **前台首页与后台浏览器图标（Favicon）统一并支持动态修改**：
  - 将 `server/public/favicon.ico` 与 `server/public/favicon.svg` 统一同步为 HwAdmin 品牌图标（并在 `admin-ui/scripts/sync-to-server.mjs` 执行 `pnpm build:sync` 时自动同步），替换原 ThinkPHP 默认图标。
  - 修复 `server/app/index/controller/Index.php` 中 `site_favicon` 为空字符串时直接输出 `href=""` 的缺陷：未配置 `site_favicon` 时默认回退为 `/favicon.svg`（与后台一致），若在后台「系统配置 → 基础配置」中修改了浏览器图标（支持相对上传路径与外链 URL），门户首页、CMS 门户、静态官网 `site/index.html` 与 `hw:page` 生成的前台页面均同步生效（且返回 `Cache-Control: no-cache` 即时刷新），安装向导页同步补充图标链接。
- **版本号判定**：修复前台默认 Favicon 与后台不一致及空配置兜底缺陷，不涉及表结构与核心接口破坏，按 patch 从 1.5.4 升级至 1.5.5。

## 1.5.4 — 2026-11-16

- **项目描述与后台文案统一精简优化**：优化根目录 `README.md` 产品介绍表达，并将管理端首页、快捷刷新菜单及 `Plugin.php` 中的「插件市场 / 商业授权」提示文案精简对齐为更直观的「应用商城 / 已购应用 / 授权」表述。
- **版本号判定**：产品描述与前后台界面提示文案优化，未改动核心接口契约与数据库结构，按 patch 从 1.5.3 升级至 1.5.4。

## 1.5.3 — 2026-11-16

- **修复纯净开发包基线单测回滚边界误删问题**：`R06R07R08TemplateCrudAuditTest::testCrudRollbackManifestSafetyBoundaryAndMenuCleanup` 在首次解压且尚未创建 `app/common/validate/` 目录时，`file_put_contents($validateFile, ...)` 静默失败导致回滚清误删而非恢复；现已预先确保目录存在并断言写入成功。
- **统一 `.env.dev.sqlite.example` 默认 SQLite 路径**：将 `DB_NAME` 从 `runtime/dev.sqlite` 统一为 `runtime/hwadmin.sqlite`，与 `hw:doctor`、文档及 `release` 包默认路径保持一致。
- **增强 `--fields` 可空语法容错与错误提示**：`CrudScaffoldBuilder::parseInlineFields()` 同时兼容类型前缀（`?text` / `?string(100)`）与后缀（`text?` / `string(100)?`）可空写法，并在解析报错中附带完整 DSL 示例。
- **版本号判定**：修复基线单测环境隔离、`.env` 示例库名一致性及 `--fields` 参数容错，未改动接口与数据结构，按 patch 从 1.5.2 升级至 1.5.3。

## 1.5.2 — 2026-11-15

- **`hw:crud --all` 在精简部署包中自动跳过测试骨架**：当项目目录缺少 `tests/bootstrap.php`（及 `phpunit.xml`）时，`--all` 自动跳过生成 `tests/Feature/*CrudTest.php` 并在 JSON 结果中通过 `advisories` 提示；仅显式传入 `--with-test` 时仍会产出并提示需在完整开发包（`hwadmin-dev`）中运行。
- **零表内联字段定义 DSL（`--fields`）**：`hw:crud` 与 `hw:page` 新增 `--fields="nickname:string(64),contact:string(100),content:text"` 支持（含 `?` 可空、`=default`、`decimal(p,s)` 与 `:comment`），无需预先连库建表即可直接生成 Phinx 迁移、模型验证与前后台骨架。
- **声明式只收不展与公开只读字段（`--public-fields` / `--submit-fields`）**：`hw:crud --with-api`、`hw:page` 与 `hw.project.json`（`entities[].public_fields` / `submit_fields`）支持独立声明“仅访客提交”与“公开只读”字段，同时硬性拦截敏感/归属/权限列，并在 `advisories` 输出完整分组明细。
- **Element Plus 2.14.5 兼容性与前台独立页导航提示**：将 `CrudScaffoldBuilder` 与系统内置 12 个管理端 `.vue` 视图的 `<el-radio>` / `<el-radio-button>` 统一升级为 `:value` / `value`；为前台独立页头部增加 `<a href="/">返回首页</a>`、在官网门户增加 `--with-nav` 可选快捷挂载（`<!-- @hw-page-nav-start/end -->`）及 `advisories` 提示。
- **版本号判定**：打磨既有脚手架指令参数、Element Plus 标签与前沿提示，未破坏现有 API 与数据表结构，按 patch 从 1.5.1 升级至 1.5.2。

## 1.5.1 — 2026-11-15

- **AI Agent 菜单与 UI 设计规范**：在 `前端开发规范.md` 与 `AGENTS.md` 明确分栏布局 1 级目录 `≤ 3` 汉字、2 级菜单 `≤ 6` 汉字原则；精简核心与 CMS 内置过长菜单标题；`hw:crud` 新增菜单字数 `advisories` 提醒。
- **插件菜单自由整理与升级保留**：`MenuImporter` 与 `MenuSync` 默认保留用户自定义 `pid`、标题、图标、权重与显隐（新增 `hw:menu:sync --force-layout` 显式重置）；增强 `Rule` 防环与父类型校验、`MenuFormatter` 同级 `path` 自动去重，管理端「菜单规则」新增「归集插件菜单」与字数提示。
- **修复 `hw:crud` / `hw:page` 前台与后台 5 项实战问题**：修复 `use think\facade\Db;` `\f` 变为 `0x0c` 语法错误；通过 `partitionPublicApiFields()` 从公开接口与访客表单默认剔除 `contact/email/phone/ip/reply*/is_top/audit*`；修复后台 `index.vue` 与 `HwTable` 事件绑定（`useCrudTable` 导出 `handlePageChange` 并接入工具栏搜索/重置）；`DoctorService` 优先识别 `${prefix}migrations` 表；`RouteInspector` 支持采集 `app/index/route/*.php` 前台自定义路由。
- **版本号判定**：修复既定脚手架、菜单同步、路由自省与诊断逻辑，按 patch 从 1.5.0 升级至 1.5.1。

## 1.5.0 — 2026-11-15

- **新增 `php think hw:doctor --mode=dev|prod --json`**：统一开发态与生产交付态体检，覆盖 PHP/扩展、Node/pnpm、SQLite 误用拦截、`APP_DEBUG=true`、`install.lock`、未执行迁移、目录权限、前端 `public/admin` 过期检测及上线核对清单。
- **新增 `php think hw:page` 与 `hw:crud --with-api`**：一键生成仅暴露白名单安全字段的前台 `ApiController`、响应式亮/暗双主题前台页面骨架（`app/index`）及自动路由注册。
- **修复三大隐性部署断点**：`Installer::seed()` 改用 `MenuSync::syncCore(false)` 保留迁移注册业务菜单；`config/app.php` 默认开启 `'app_express' => true`；修复 `CrudScaffoldBuilder` SQLite `DEFAULT ''` 转义 Bug 并将默认 SQLite 路径统一为 `runtime/hwadmin.sqlite`。
- **文档与双包对齐**：根目录 `AGENTS.md` 精简至 80 行以内聚焦业务开发，并与发行包保持一致文档边界；`build-release.py` 为部署精简包生成无死链的部署专属文档并校验开发包链接完整性。
- **版本号判定**：新增 CLI 脚手架/体检能力与核心安装/路由行为变更，按 minor 从 1.4.5 升级至 1.5.0。

## 1.4.5 — 2026-11-15

- 新增 START_HERE 一句话需求入口与 `hw start website|admin|miniapp`，自动准备所需客户端、SQLite、业务模板与预览服务，重复启动保留定制。
- 官网模板补充响应式前台、动态内容与实际咨询表单；README/CLAUDE/AGENTS 回归快速应用创造流程。
- 复用现有业务模块的入口与模板体验优化，未变更接口或表结构，按 patch 从 1.4.4 更新至 1.4.5。

## 1.4.4 — 2026-11-15

修复运行时清单路径/重定向、最低工具链与工程不一致、恢复凭证明文传输、容器数据库密码参数和生产探针误放行；补充 FPM 工作用户权限检查，T06 严格校验制品并明确脚本验收范围。。

## 1.4.2 — 2026-11-15

- 收紧密码恢复：普通 debug/local 环境不回传恢复凭证；生产发送必须由 `UserPasswordResetRequested` 监听器经已验证渠道完成；按会员串行消费凭证，改密同时吊销会话和既有恢复凭证。
- 微信身份只使用服务端绑定记录，不再信任可编辑的 bio 或预占用户名；按 AppID/数据库隔离，防止身份重新绑定覆盖，测试适配器仅在 testing 环境启用。
- 部署包强制外部校验和，拒绝清单外成员、重复/危险路径和符号链接；恢复前校验整个快照；SQLite 使用包含 WAL 的一致性备份，关闭连接后释放文件。
- 明确执行范围：`apply --target=local` 用于 SQLite 本地目录验收；Docker/宝塔提供计划及正式运维指南，不将 PHP CLI 临时探针视为生产部署。首次本地安装生成随机管理员密码并保存至受限运行目录。
- 验证报告绑定当前源码指纹和具体 ZIP 哈希，变更后需重新验证；PHPUnit 在隔离工程执行；修复过期打包测试接口与 Windows SQLite 测试资源释放，新增部署与认证边界回归并接入 CI。
- 发布包及 Git 排除旧 `public/storage/` 运行上传目录，补齐部署工具依赖和 Rust 锁文件版本。
- `1.4.1 → 1.4.2`：既有能力安全与可靠性修复，按 patch 递增；未变更业务数据表结构。

## 1.3.1 — 2026-11-15

- **发布包面向 Agent 二次开发体验对齐**：
  - `php think hw:test`（`TestCommand`）自动识别源码仓库（`server/` + `admin-ui/`）与解压发布包（`.` + `sources/admin-ui/`）双布局，不再误导提示 `server/` 目录；发布包保留 `phpunit.xml` 与 `tests/`（仅排除 `.phpunit.cache`），`CliCommandsTest` 同步兼容 `sources/admin-ui/src/` 路径解析。
  - 发布包内一并保留 `.env.dev.sqlite.example` 与 `scripts/{wsl-dev.sh,dev.sh,dev.ps1,pack-release.sh,prepare-release.sh,build-release.py,build-release.sh}`，且所有开发与发布脚本自动适配双布局；`AGENTS.md` 补充双布局与测试依赖前置说明。
- **前端构建与静态检查净化**：
  - 修复 `admin-ui/package.json` 中 `check` / `desktop:check` 脚本的 `pnpm vue-tsc --noEmit` 重复参数，统一为 `pnpm vue-tsc`。
  - 彻底清理 `admin-ui/` 全量 23 条 `oxlint` 非阻断 warning，实现 `oxlint` **0 warnings, 0 errors**。
- 版本 `1.3.0 → 1.3.1`：发布包 Agent 二次开发兼容、测试脚手架引导修复与前端构建/lint 细节打磨，按 patch 递增。

## 1.3.0 — 2026-11-14

- 新增 Tauri 2 Windows 桌面壳，复用 HwAdmin PC 管理后台界面、主题与权限；移动端继续采用 uni-app + Wot UI V2。
- 增加独立桌面构建、服务端源地址和资源适配、官方 opener 系统浏览器能力、NSIS 配置与 Windows CI。
- 增加桌面凭据发送边界测试、[桌面端接入指南](桌面端接入指南.md)及发布产物隔离。
- 版本 `1.2.1 → 1.3.0`：新增客户端能力，按 minor 递增；不绑定线上域名、签名或自动更新服务。

## 1.2.1 — 2026-11-14

- **框架定位全面升级**：正式确立「让你的 AI 智能体读懂并快速创造应用」的 AI-Native 开发框架定位，重构根目录 `README.md`、`AGENTS.md`、`CLAUDE.md` 与门户首页展示文案。
- **全面清除过时环境内容**：彻底移除所有文档、代码注释与发布打包校验中不再维护的旧本地集成面板相关内容。
- **环境推荐与文档收敛**：
  - 将分散于根目录及 `server/` 下的 `CHANGELOG.md`、`使用说明-*`、`server/README.md` 等冗余文件统一整理或迁入 `docs/`，保持源码仓库与发布包根目录极致清爽（仅保留核心引导 `README.md` / `AGENTS.md`）。
  - 新增统一正式文档 `docs/部署与运维指南.md`，给出按场景选择的 **Docker Compose / WSL + SQLite / 宝塔面板 / 原生 Linux Nginx + PHP-FPM** 部署矩阵及开箱即用指南。
- **发布打包脚本升级**：同步升级 `scripts/build-release.py`，发布包根目录纳入 `README.md` 与 `AGENTS.md`，将说明与许可文档统一归档至 `docs/`。
- **版本号变更**：由 `1.2.0` 升至 `1.2.1`（文档结构重构、定位升级与打包规则优化，按 patch 递增），同步全库 7 处版本号位置并新增 `docs/1.2.1发布说明.md`。

## 1.2.0 — 2026-11-14

- 修复 `CrudTrait::add()` / `edit()` 配置 `$validateClass` 未开启 `failException(true)` 导致校验失败静默写入的问题。
- 新增 `core\plugin\MenuSync` 与 `php think hw:menu:sync`，支持 `MenuSync::withAdapter()` 复用 Phinx 迁移连接做参数化增删，消除原生 SQL 拼接与 SQLite 锁库问题。
- 新增前端强类型泛型表格 `<HwTable<T>>` 与 `useCrudTable<T, Q>`，根治 `routes.ts` / `VabBreadcrumb` / `VabSearch` 的 `indexOf` 运行时报错并清空 `setting.config.ts` 的 `errorFilter`。
- 新增项目自省命令 `php think hw:inspect --json`、开发测试令牌命令 `php think hw:dev:token`、后端 PHPUnit 11 + SQLite 隔离测试套件（`composer test` / `php think hw:test`），并将 `php think hw:crud` 升级为全栈脚手架（`--all` 等参数）。
- 新增 PC 端 `pnpm check` 与 `pnpm build:sync`，修复 Vite 8 `configLoader: 'native'` 警告；新增 `server/.env.dev.sqlite.example`、`admin-ui/.env.development.example`、`scripts/dev.sh` / `scripts/dev.ps1` 与 `scripts/pack-release.sh`。
- 框架版本由 `1.1.1` 升至 `1.2.0`（新增多个跨系统核心 CLI、PHPUnit 测试基建与前端组件库，按 minor 递增），新增 `docs/1.2.0发布说明.md`。

## 1.1.1 — 2026-11-14

- 优化发布打包与验收脚本（`prepare-release.sh`、`build-release.py`、`offline-acceptance.sh`、`wsl-dev.sh`），自动读取 `server/config/hwadmin.php` 版本号并排除 `unpackage/` 目录。
- 更新部署指南与二次开发说明，去除旧版本号硬编码并补充下载后后端 `hw:crud`、PC 前端与移动端二次开发指引。
- 新增 `docs/1.1.1发布说明.md`，同步全库框架版本号至 `1.1.1`（patch：发布流程与文档优化）。

## 1.1.0 — 2026-11-13

- 新增 `admin-ui/mobile/` 通用客户端，采用 unibest 精简工程配置、uni-app、Vue 3、TypeScript、Pinia 与 Wot UI V2 `@wot-ui/ui@2.4.0`。
- 提供组件按需加载、统一品牌主题及弹出层、组件示例分包、会员登录/资料/退出与健康检查，遵循 HwAdmin 统一接口契约。
- 提供独立依赖锁文件、微信小程序与 H5 构建命令、请求契约测试及移动端接入指南；具体 AppID、域名、业务与发布由后续项目配置。
- 框架版本由 1.0.3 升至 1.1.0：新增用户可见的移动端客户端，按 minor 递增；未新增数据库结构或微信授权登录接口。

## 1.0.1 — 2026-10-30

- 升级 Vue I18n 至 11.4.16 并更新锁文件，修复本轮依赖审计发现的高危公告；生产依赖审计归零。
- 改善安装令牌验证与无伪静态时的安装入口，向导可生成宝塔 PHP 入口和完整伪静态配置。
- 增加安装后自动部署实测和超级管理员后台复查，检查入口、上传目录、安全头、路由、HTTPS 与安装锁，临时探针结束后清理。
- 调整 Nginx 前缀匹配以避免通用规则覆盖目录保护及后台安全头，补充面向新手的部署说明。
- 纳入此前 GitHub 的 quiz 答题插件、oss 云存储插件、插件中心与个人中心更新；插件需单独安装配置，具体业务与真实云服务仍需目标环境验收。
- 更新版本及发布文件必备检查，继续隔离生产依赖、运行数据与本地敏感配置。

升级与验证边界见 docs/1.0.1发布说明.md。

## 1.0.0 — 2026-10-28

- 加固安装令牌、互斥锁、成功响应和环境配置写入，禁止生产 FPM/Apache 开启 debug。
- 净化编辑器前后端 HTML，隔离草稿与私有附件，限制令牌随同源请求发送。
- 修复 PostgreSQL 新装种子、自增列、聚合分页和整数时间戳兼容问题。
- 更新前端依赖及锁文件，增加 CI 类型检查与 high 级生产依赖审计。
- 增加严格清单校验的数据库/附件备份恢复、路径和符号链接预检，禁止 SQLite 不一致复制回退。
- 增加宝塔 HTTPS、SPA 重写、单 PHP 入口配置及部署/回滚指南。
- 发布包内置生产依赖、前端、对应前端源码与每文件 SHA-256 清单，排除本地配置、测试和运行数据。

本版为供宝塔目标环境测试的 1.0.0 制品；本地验证范围、限制及目标环境验收清单见 docs/1.0.0发布说明.md。
