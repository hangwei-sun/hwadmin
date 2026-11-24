# 贡献指南 (Contributing to HwAdmin)

感谢你对 **HwAdmin** 的关注与支持！无论是修复 Bug、完善文档、提交新特性还是开发插件，你的每一次贡献都能让这套 AI 原生全栈框架更加健壮。

---

## 1. 本地开发启动

### 环境要求

- **PHP**：8.2+（推荐 8.3 / 8.4 LTS），启用 `pdo`、`pdo_sqlite`、`pdo_mysql`、`pdo_pgsql`、`redis`、`mbstring`、`json`、`curl`、`zip`、`gd`、`intl`、`bcmath` 扩展
- **Composer**：v2.x
- **Node.js**：>= 22.18.0 & **pnpm**：10.x（推荐 `10.33.0`）
- **Python**：3.10+（用于运行构建与运维测试脚本）

### 一键初始化与双端联调（推荐 WSL + SQLite）

```bash
# 1. 环境体检
bash scripts/wsl-dev.sh doctor

# 2. 初始化 SQLite 数据库、迁移与默认管理员 (admin / Admin12345)
bash scripts/wsl-dev.sh install-sqlite

# 3. 启动双端开发服务（后端 :8000 + Vite 前端 :15173）
bash scripts/dev.sh            # Windows PowerShell 可使用: pwsh scripts/dev.ps1
```

若仅调试后端或前端，也可分别启动：

```bash
# 后端 PHP 内置服务与迁移
cd server
composer install
php think migrate:run
php -S 127.0.0.1:8000 -t public public/router.php

# 前端管理端
cd admin-ui
pnpm install --frozen-lockfile
pnpm dev
```

---

## 2. 开发与工程规范

在编写代码前，请务必阅读项目根目录的 **[AGENTS.md](AGENTS.md)**（全局开发契约）以及对应模块的专项规范：

- **后端规范**：[后端开发规范](docs/后端开发规范.md) · [数据库开发规范](docs/数据库开发规范.md) · [接口契约规范](docs/接口契约规范.md)
- **前端规范**：[前端开发规范](docs/前端开发规范.md)
- **扩展与多端**：[插件系统规范](docs/插件系统规范.md) · [移动端接入指南](docs/移动端接入指南.md) · [桌面端接入指南](docs/桌面端接入指南.md)

### 核心红线约束

1. **多数据库兼容**：所有数据表与迁移必须通过 `Phinx` 驱动，查询使用 ThinkORM 参数化构造器，严禁使用单库方言拼接（必须同时兼容 SQLite 3 / MySQL 8.0+ / PostgreSQL 16+）。
2. **禁止硬编码凭据与敏感信息**：严禁提交 `.env`、`*.sqlite`、真实服务器 IP、SSH 凭据、API 密钥或 Token。
3. **扩展不改内核**：业务扩展优先通过 `server/plugins/{name}/` 插件机制实现，避免无故侵入 `server/core/`。
4. **Commit 规范**：遵循 [Conventional Commits](https://www.conventionalcommits.org/) 风格，例如：
   - `feat(crud): 支持布尔字段开关组件自动生成`
   - `fix(auth): 修复多端并发刷新令牌偶发失效问题`
   - `docs(readme): 补充 Docker Compose 快速启动说明`

---

## 3. 提交 Pull Request 流程

1. **Fork 并创建分支**：从 `main` 分支拉取特性分支（如 `feat/my-feature` 或 `fix/issue-123`）。
2. **运行本地全量质检**：提交 PR 前请确保本地通过以下检查：

```bash
# 后端语法与 PHPUnit 测试
find server -type f -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 -P4 php -l
cd server && composer test && cd ..

# SQLite 冒烟测试
bash scripts/wsl-dev.sh smoke

# 前端类型检查、Lint、单测与构建
cd admin-ui && pnpm check && cd ..

# 运维脚本单元测试
python3 -m unittest discover -s scripts/tests -v
```

3. **提交 PR**：对照 [Pull Request 模板](.github/PULL_REQUEST_TEMPLATE.md) 填写变更说明、测试记录并关联对应 Issue，等待 CI 多数据库矩阵（SQLite / MySQL 8.0 / PostgreSQL 16 + 前端 + 移动端 + 桌面端）全绿通过。

---

## 4. 安全漏洞报告

如果你发现了潜在的安全漏洞，**请勿在公开的 GitHub Issue、PR 或讨论区披露**。

请查阅 **[SECURITY.md](SECURITY.md)**，通过以下私密渠道提交报告：

- **GitHub Security Advisories**：在仓库 **Security** 页面点击 **Report a vulnerability**；
- **安全邮箱**：发送详情至 `support@hwadmin.cn`。

感谢你帮助提升 HwAdmin 的安全性！
