# HwAdmin 后端 Agent 协同契约与规范 (server/)

> **适用范围**：面向所有在 `server/` 目录下开发、重构、测试业务应用或框架底座的 AI 智能体。  
> **上级基线**：项目根目录 [`../AGENTS.md`](../AGENTS.md)。

---

## 一、版本分离契约（核心理念）

HwAdmin 明确区分 **框架维护** 与 **业务应用开发** 两个层级：

1. **框架版本 (`version`)**：定义框架内核基座版本（当前为 `1.6.4`），维护 `core/` 底座时由核心发布流程变更。
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
4. **数据库迁移与单一菜单数据源**：
   - 数据库结构变更必须使用 Phinx 迁移文件，兼顾 MySQL 8.0+ / PostgreSQL 16+ / SQLite 敏捷开发。
   - 菜单与权限节点统一通过 `MenuSync` (`php think hw:menu:sync`) 同步，严禁在迁移中手写 `auth_rule` 原生 SQL 拼接。
5. **Feature 测试契约与自测闸门**：
   - `tests/Feature/` 用例统一继承 `tests\TestCase`，使用 `$this->guestGet/guestPost/adminGet/adminPost`；该方法直接返回解码后的 API JSON 数组（如 `['code' => 1|0|401, 'data' => ...]`），严禁断言 `$res['status']` 或 `$res['json']`，统一使用 `$this->assertApiSuccess($res)` 与 `$this->assertApiFail($res, 0|401)`。
   - 每次后端代码修改后，必须运行并通过：
     ```bash
     composer test                         # 运行 PHPUnit 自动化测试套件
     bash scripts/wsl-dev.sh lint          # 严格 php -l 语法检查
     bash scripts/wsl-dev.sh smoke         # 完整冒烟测试（含环境隔离与还原）
     ```
