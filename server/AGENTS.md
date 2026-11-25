# HwAdmin 后端 Agent 协同契约与规范 (server/)

> **适用范围**：面向所有在 `server/` 目录下开发、重构、测试业务应用或框架底座的 AI 智能体。  
> **上级基线**：项目根目录 [`../AGENTS.md`](../AGENTS.md)。

---

## 一、版本分离契约（核心理念）

HwAdmin 明确区分 **框架维护** 与 **业务应用开发** 两个层级：

1. **框架版本 (`version`)**：定义框架内核基座版本（当前为 `1.6.8`），维护 `core/` 底座时由核心发布流程变更。
2. **业务应用版本 (`app_version`)**：由业务开发者在 `server/config/hwadmin.php`（或环境变量 `APP_VERSION`）及根目录 `hw.project.json` 中独立维护。
3. **免繁重版本同步**：业务开发日常增加模块、接口、迁移与业务逻辑，**只需递增 `app_version`**，**严禁**修改 `config/hwadmin.php` 中的框架基座版本号或侵入 `core/` 目录。

---

## 二、目录分层与边界

```
server/
├── app/                    # 业务应用层（业务控制器、模型、验证器、服务）
│   ├── adminapi/           # 管理后台接口（继承 core\base\AdminController）
│   ├── api/                # 前台/小程序会员接口（继承 core\base\ApiController）
│   └── common/             # 业务公共服务（service/）、模型（model/）、验证器（validate/）
├── core/                   # 框架基座内核（严禁业务逻辑侵入！）
│   ├── auth/               # 认证鉴权、令牌、密码哈希与限流
│   ├── base/               # AdminController / ApiController / UnixTimeModel 基类
│   ├── crud/               # CrudTrait 与 QueryParams 筛选契约解析器
│   └── support/            # RouteInspector、ProjectSchemaService、OpenApiExporter 等基座服务
├── database/migrations/    # Phinx 迁移脚本（遵循 MySQL/PG/SQLite 双栈兼容）
└── tests/                  # 自动化测试套件 (Unit / Feature)
```

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
4. 🛡️ **站点级保护文件（站点自主维护，OTA 不覆写）**：
   - `server/config/hwadmin.php`、`server/public/.htaccess`、`server/public/nginx.htaccess`、`server/public/favicon.ico`、`server/public/admin/hwadmin.config.js` 由站点自主维护，OTA 不会覆写已有站点配置；**严禁 AI 擅自破坏安全头、删除防脚本执行规则或硬编码/私改 `admin_entry`**。

---

## 三、后端编码核心铁律

1. **控制器分层与基类**：
   - 管理后台控制器继承 `core\base\AdminController`，公开接口或免鉴权必须显式声明 `protected array $noNeedLogin = [...]` 或 `$noNeedRight = [...]`。
   - 前台/会员接口控制器继承 `core\base\ApiController`，默认无需权限节点校验，登录校验通过 `$noNeedLogin` 豁免。
   - 控制器严禁随意新增 `public` 辅助方法，非 HTTP Action 方法必须声明为 `protected` 或 `private`。
2. **复杂业务下沉服务层**：
   - 避免将复杂联表查询、状态机流转、防并发冲突与外部调用堆积在控制器中，统一封装于 `app/common/service/`。
3. **模型与字段写入白名单**：
   - 业务模型统一继承 `core\base\UnixTimeModel`，必须声明主键、状态与整型时间戳等列的类型转换 `protected $type = ['id' => 'integer', 'status' => 'integer', 'create_time' => 'integer', 'update_time' => 'integer', ...]`（防止 SQLite PDO 返回字符串 `"0"`）；在 `app/api` 自写写入逻辑并调用 `$model->allowField(...)` 时，必须同步放行或显式赋值 `create_time` / `update_time`。
   - 控制器使用 `CrudTrait` 时，必须显式配置 `$allowFields`（或模型定义可写白名单），禁止暴露未经筛选的全局字段批量写入；重写生命周期钩子必须严格对齐基类签名：`protected function beforeWrite(array $data, ?\think\Model $row = null): array`（必须返回 `$data`）与 `protected function afterWrite(\think\Model $row, array $data, bool $isNew): void`。
4. **数据库迁移、核心表保护与单一菜单数据源**：
   - 数据库结构变更必须使用 Phinx 迁移文件，兼顾 MySQL 8.0+ / PostgreSQL 16+ / SQLite 敏捷开发。
   - **严禁 `ALTER`/删除 `hw_config`、`hw_auth_rule`、`hw_admin`、`hw_plugin` 等系统核心表原有列**，配置与菜单只能走 `ConfigService` / `MenuSync` (`php think hw:menu:sync`)，严禁在迁移中手写 `auth_rule` 原生 SQL 拼接。
   - **执行生产迁移/上线前必须先运行 `python3 scripts/backup_restore.py backup`（或 `scripts/backup-restore.sh backup`）备份数据库**。
5. **Feature 测试契约与自测闸门**：
   - `tests/Feature/` 用例统一继承 `tests\TestCase`，使用 `$this->guestGet/guestPost/adminGet/adminPost`；该方法直接返回解码后的 API JSON 数组（如 `['code' => 1|0|401, 'data' => ...]`），严禁断言 `$res['status']` 或 `$res['json']`，统一使用 `$this->assertApiSuccess($res)` 与 `$this->assertApiFail($res, 0|401)`。
   - 每次后端代码修改后，必须运行并通过：
     ```bash
     composer test                         # 运行 PHPUnit 自动化测试套件
     bash scripts/wsl-dev.sh lint          # 严格 php -l 语法检查
     bash scripts/wsl-dev.sh smoke         # 完整冒烟测试（含环境隔离与还原）
     ```
