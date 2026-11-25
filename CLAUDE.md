# HwAdmin Agent 快速入口

框架版本：1.6.8。目标是让用户描述业务，你负责尽快交付可预览、可使用的应用。

先读 [START_HERE.md](START_HERE.md)。按网站、后台或小程序选择 `hw start`，默认 SQLite（`runtime/hwadmin.sqlite` / `runtime/dev.sqlite`），不要求用户先配数据库、阅读路线图或完成框架审计清单。

先把页面和主要业务跑起来，再按反馈完善。根据当前工作查阅根目录 [AGENTS.md](AGENTS.md)、[后端约定](server/AGENTS.md)、[管理端约定](admin-ui/AGENTS.md) 或 [移动端约定](admin-ui/mobile/AGENTS.md)。

用户让你开发应用时，直接推进应用；不要把它转换成框架底座整改项目。工程检查由你和脚本完成（收尾运行 `php -l` / `php think hw:test`、`pnpm --dir admin-ui check && pnpm --dir admin-ui build:sync` 及 `php think hw:doctor --mode=prod --json`），向用户报告预览地址、功能和下一步即可。

业务开发只维护 `app_version`，不修改 `framework_version` 或 `server/core/` 内核底座。

## AI 业务与插件开发目录安全边界（OTA 升级红绿灯）

1. 🟢 **永久安全区（框架 OTA 升级永不覆写，业务/插件代码请放这里）**：
   - `server/plugins/{插件名}/` 与 `server/public/plugins/{插件名}/`（**最推荐**：独立插件 + 免编译微视图 `admin.html`，与框架完全解耦，框架任意升级不受影响）；
   - `server/app/adminapi/controller/{自定义业务目录}/`（避开 `system/`、`auth/`、`user/`、`Index.php`、`Dashboard.php`）；
   - `server/app/common/model/{自定义业务模型}/`（避开 `system/` 与 8 个内置基础模型）；
   - `server/app/common/service/{自定义业务服务}/`（避开 `ConfigService.php`、`DictService.php`、`DeploymentService.php`）；
   - `server/app/api/controller/{自定义业务}/`（前台/小程序/H5 业务接口，避开 `Index.php`、`User.php`）；
   - `server/database/migrations/` 中**新建**的时间戳迁移文件（严禁修改已有框架迁移文件）；
   - `server/.env`、`server/config/database.php`、`server/public/uploads/`、`server/runtime/`。
2. 🔴 **框架内核升级区（OTA 在线更新会同步覆写，严禁直接修改）**：
   - `server/core/`、`server/app/adminapi/controller/{system,auth,user}/`、`server/app/adminapi/controller/{Index,Dashboard}.php`、`server/app/adminapi/route/app.php`；
   - `server/app/api/controller/{Index,User}.php`、`server/app/api/route/app.php`、`server/app/index/`、`server/app/*.php`；
   - `server/app/common/service/{ConfigService,DictService,DeploymentService}.php`、`server/app/common/model/system/`、8 个内置基础模型（`Admin`、`AdminLog`、`Attachment`、`AuthGroup`、`AuthRule`、`Config`、`User`、`UserGroup`）、`server/public/index.php`。
3. 🟡 **前端二开特别约定（`admin-ui/` 与 `server/public/admin/`）**：
   - 本地二次开发修改 `admin-ui/` 后运行 `pnpm build:sync` 会自动写入 `server/public/admin/hwadmin-official-ui.json`（`official: false`），框架在线更新会自动跳过 `public/admin/` 仅升级后端内核，绝不覆盖二开页面；**严禁删除或手动伪造 `hwadmin-official-ui.json`**；
   - 若希望同时享受官方管理后台 UI 自动在线升级，建议优先采用「独立插件 + `public/plugins/{插件名}/admin.html` 免编译微视图」模式开发业务页面。
4. 🛡️ **站点级保护文件与开发红线**：
   - `server/config/hwadmin.php`、`server/public/.htaccess`、`server/public/nginx.htaccess`、`server/public/favicon.ico`、`server/public/admin/hwadmin.config.js` 由站点自主维护，OTA 不会覆写；禁止 AI 擅自破坏安全头或硬编码/私改 `admin_entry`。
   - 严禁 `ALTER`/删除 `hw_config`、`hw_auth_rule`、`hw_admin`、`hw_plugin` 等系统核心表原有列，配置与菜单只能走 `ConfigService` / `MenuSync`。
   - 执行生产迁移/上线前必须先运行 `python3 scripts/backup_restore.py backup`（或 `scripts/backup-restore.sh backup`）备份数据库。

上线阶段一次收集必要服务器/域名/平台信息，复用 [现有部署流程](docs/部署与运维指南.md)，实际验证后交付访问入口。

开源与多远程安全红线：提交/推送前必须先运行 `git remote -v` 核对目标仓库，禁止直接将内部研发历史分支推送至公开仓；发版同步公开仓须使用受控安全同步流程并确认 0 敏感文件与凭据残留。
