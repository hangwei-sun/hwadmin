# HwAdmin Agent 业务开发速查契约

> **开发入口**：先看 [START_HERE.md](START_HERE.md)。用户描述网站、后台或小程序需求时，优先在根目录执行 `./hw start website|admin|miniapp`（Windows 用 `./hw.ps1`）跑起 SQLite 预览，直接做业务应用，不要把需求转成框架审计或修改 `server/core/`。

## 一、目录边界

```
hwadmin/
├── admin-ui/              # 唯一前端源码：Vue 3 + Vite + TS + Pinia 管理端
│   ├── mobile/            # uni-app + Wot UI V2 移动端（独立依赖）
│   └── src-tauri/         # Tauri 2 Windows 桌面壳
├── server/                # ThinkPHP 8.1 后端
│   ├── app/               # 业务层：adminapi/ | api/ | index/ | common/ | install/
│   ├── core/              # 框架内核基座（业务开发严禁修改）
│   ├── database/migrations/ # Phinx 迁移脚本（唯一改表入口）
│   └── plugins/           # 可插拔扩展插件（独立 plugin.json 与生命周期）
└── scripts/               # 开发与部署工具链（hw、wsl-dev.sh、hw-deploy.py、backup_restore.py）
```

## AI 业务与插件开发目录安全边界（OTA 升级红绿灯）

1. 🟢 **永久安全区（框架 OTA 升级永不覆写，业务/插件代码请放这里）**：
   - `server/plugins/{插件名}/` 与 `server/public/plugins/{插件名}/`（**最推荐**：独立插件 + 免编译微视图 `admin.html`，与框架完全解耦，框架任意升级不受影响）；
   - `server/app/adminapi/controller/{自定义业务目录}/`（避开 `system/`、`auth/`、`Index.php`、`Dashboard.php`）；
   - `server/app/common/model/{自定义业务模型}/`（避开 `system/` 与框架内置基础模型）；
   - `server/app/common/service/{自定义业务服务}/`（避开 `ConfigService.php`、`DictService.php`、`DeploymentService.php`）；
   - `server/app/api/`（前台/小程序/H5 业务接口）；
   - `server/database/migrations/` 中**新建**的时间戳迁移文件（严禁修改已有框架迁移文件）；
   - `server/.env`、`server/config/database.php`、`server/public/uploads/`、`server/runtime/`。
2. 🔴 **框架内核升级区（OTA 在线更新会同步覆写，严禁直接修改）**：
   - `server/core/`、`server/app/adminapi/controller/system/`、`server/app/adminapi/controller/auth/`、`server/app/adminapi/controller/Index.php`、`server/app/adminapi/controller/Dashboard.php`、`server/app/common/service/{ConfigService,DictService,DeploymentService}.php`、`server/app/common/model/system/`、`server/config/hwadmin.php`、`server/public/index.php`。
3. 🟡 **前端二开特别约定（`admin-ui/` 与 `server/public/admin/`）**：
   - 本地二次开发修改 `admin-ui/` 后运行 `pnpm build:sync` 会自动写入 `server/public/admin/hwadmin-official-ui.json`（`official: false`），框架在线更新会自动跳过 `public/admin/` 仅升级后端内核，绝不覆盖二开页面；
   - 若希望同时享受官方管理后台 UI 自动在线升级，建议优先采用「独立插件 + `public/plugins/{插件名}/admin.html` 免编译微视图」模式开发业务页面。

## 二、业务开发核心红线

1. **权限与控制器 (`server/core/base/AdminController.php`)**：
   - `app\adminapi\controller\auth\AdminLog::index` → 权限节点 `auth/admin_log/index`，URL `/adminapi/auth.admin_log/index`（子目录用 `.` 分隔）。
   - 免登录 / 免鉴权显式声明 `$noNeedLogin` / `$noNeedRight`；控制器/基类禁止新增 `public` 非 Action 辅助方法。
2. **模型与安全白名单 (`UnixTimeModel` / `CrudTrait`)**：
   - 模型继承 `UnixTimeModel`（秒级整数时间戳），必须显式声明 `$allowFields` 与 `$validateClass`。
   - 前台公开 `app/api` 控制器必须显式指定安全返回字段白名单，严禁暴露密码、Token、归属人（`admin_id`/`user_id`/`tenant_id`）及权限字段。
3. **数据库迁移与单一菜单源 (`MenuSync`)**：
   - 改表必须写 Phinx 迁移（兼容 MySQL 8.0+ / PG 16+ / SQLite），**禁止手写 `auth_rule` 原生 SQL 拼接**。
   - 迁移中通过 `MenuSync::withAdapter($this->getAdapter(), fn () => MenuSync::ensureNode(...))` 同步菜单；安装器已保留迁移注册的业务菜单。
4. **SQLite 约定与前台单段路由**：
   - 本地 SQLite 统一约定路径为 `runtime/hwadmin.sqlite`（脚本同时兼容 `runtime/dev.sqlite`），确保 `scripts/backup_restore.py` 可正常备份恢复。
   - `server/config/app.php` 默认开启 `'app_express' => true`；在 `server/app/index/route/app.php` 中，自定义单段前台路由必须注册在 `Route::any(':entry', ...)` 之前。
5. **增量扩展保护区**：手写业务定制放在 `@hw-custom-start` / `@hw-custom-end` 标记内，`--incremental` 再次生成时会自动保留。
6. **UI 菜单简明原则**：左侧分栏 1 级目录标题严格 `≤ 3` 汉字、2 级菜单 `≤ 6` 汉字以防遮挡；支持用户在「菜单规则」自由归集插件目录，插件/框架升级默认保留自定义布局。
7. **依赖边界与防慢速 I/O**：统一包默认预置 `--no-dev` `server/vendor/`，跑 `hw:test` / `./hw verify` 前若缺 `vendor/bin/phpunit` 先执行 `./hw bootstrap --with-test`（或在 `server/` 执行 `composer install`）；Windows/WSL 跨盘目录复制严禁整包 `cp -r`，必须排除 `admin-ui/node_modules/`、`admin-ui/mobile/node_modules/`、`admin-ui/src-tauri/target/` 与 `.hw-state/`（推荐 `scripts/wsl-dev.sh` 或 `rsync --exclude`）。
8. **开源与多远程安全红线**：提交/推送前必须先运行 `git remote -v` 核对目标仓库，禁止直接将内部研发历史分支推送至公开仓；发版同步公开仓须使用受控安全同步流程并确认 0 敏感文件与凭据残留。

## 三、开发到上线黄金命令流

```bash
# 1. 在 server/ 自省当前表、路由、插件与接口契约
php think hw:inspect --json
php think hw:openapi --json

# 2. 正向/反向/零表生成（支持 --fields 内联建模，--with-api / --with-mobile / --with-nav 生成三端与首页入口）
php think hw:crud --table=guestbook --fields="nickname:string(64),contact:string(100),content:text" --all --with-api --with-mobile
# 或已有表 / 单独快速创建响应式前台页面与白名单公共接口
php think hw:page --name=guestbook --table=guestbook --title="留言板" --theme=light

# 3. 执行迁移并构建同步前端到 server/public/admin/
php think migrate:run
pnpm --dir admin-ui check && pnpm --dir admin-ui build:sync

# 4. 交付自检与测试（开发态 vs 生产交付态）
php think hw:test
php think hw:doctor --mode=dev --json
php think hw:doctor --mode=prod --json
```

> 按需参考：[后端规范](docs/后端开发规范.md) · [前端规范](docs/前端开发规范.md) · [数据库规范](docs/数据库开发规范.md) · [接口契约](docs/接口契约规范.md) · [插件规范](docs/插件系统规范.md) · [部署与运维指南](docs/部署与运维指南.md)  
> 业务开发仅递增 `app_version`，禁止修改框架基座版本号或 `server/core/`。  
> **插件全局更新规则**：对 `server/plugins/{name}` 优化更新后，必须按与主框架看齐的 `x.y.z` 规则（大改升 `y` 归零 `z`、小改升 `z`、`x` 禁自动改）递增 `plugin.json` 版本号并运行 `python scripts/publish_plugin.py --plugin <name> --bump <patch|minor> --changelog "..."` 同步打包发布到应用商城。
