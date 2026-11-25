# Security Policy / 安全策略

[English](#english) | [中文](#中文)

---

## English

### Supported Versions

| Version | Supported |
| --- | --- |
| 1.6.x (current `main`) | :white_check_mark: |
| < 1.6.0 | :x: |

### Reporting a Vulnerability

Please **do not** disclose security vulnerabilities in public GitHub issues, pull requests, or discussions.

Instead, please report them privately through one of the following channels:
- **GitHub Security Advisories**: Use the **"Report a vulnerability"** button on the repository's **Security** tab.
- **Email**: Send details (affected version, reproduction steps, impact, and suggested fix if any) to `support@hwadmin.cn`.

We will acknowledge receipt within 48 hours and work with you to verify, remediate, and coordinate disclosure once a patch is available.

### Production Security Best Practices

1. **Web Root Isolation**: Point your web server `root` strictly to `server/public/` and deny access to dotfiles (`/.git`, `/.env`), backups (`*.sql`, `*.bak`, `*.sqlite`, `*.zip`), and PHP execution inside `uploads/` and `plugins/`.
2. **Disable Debug Mode**: Ensure `APP_DEBUG=false` in production `.env` and remove or lock down installation artifacts (`config/install.lock` must exist).
3. **Strong Credentials & Secrets**: Replace all default/example secrets, JWT/app keys, database passwords, and Redis passwords; never commit `.env` or `PROJECT_SECRETS.md`.
4. **HTTPS & Security Headers**: Serve production traffic behind TLS 1.2+ with `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, and `X-Frame-Options: SAMEORIGIN`.
5. **Least Privilege & Backups**: Bind internal databases/Redis to localhost or private Docker networks (`127.0.0.1`), and maintain encrypted off-site backups via `scripts/backup_restore.py`.

---

## 中文

### 支持版本

| 版本 | 安全支持状态 |
| --- | --- |
| 1.6.x（当前主分支） | :white_check_mark: 支持 |
| < 1.6.0 | :x: 已停止支持 |

### 漏洞反馈渠道

请**勿**在公开的 GitHub Issue、Pull Request 或讨论区直接披露未修复的安全漏洞。

请通过以下私密渠道提交漏洞详情（包含受影响版本、复现步骤、潜在影响及修复建议）：
- **GitHub Security Advisories**：在仓库 **Security** 页面点击 **Report a vulnerability** 私密上报；
- **安全邮箱**：发送报告至 `support@hwadmin.cn`。

我们会在 48 小时内确认收到，并在核实与修复后同步发布安全更新与致谢。

### 生产环境安全最佳实践

1. **Web 根目录隔离**：仅将站点 `root` 指向 `server/public/`，严禁外部访问隐藏点文件（`/.git`、`/.env`）、备份/数据库文件（`*.sql`、`*.bak`、`*.sqlite`、`*.zip`），并禁止 `uploads/` 与 `plugins/` 目录解析执行脚本。
2. **关闭调试模式**：生产环境务必设置 `APP_DEBUG=false`，确认 `server/config/install.lock` 已锁定且删除临时初始化凭据文件。
3. **强密钥与凭据管理**：部署前更换所有示例密码、数据库/Redis 口令与签名密钥；严禁将 `.env` 或 `PROJECT_SECRETS.md` 提交至代码仓库。
4. **HTTPS 与安全响应头**：公网生产环境强制启用 TLS 1.2/1.3，并配置 `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN` 等安全头。
5. **最小暴露面与定期备份**：数据库与 Redis 仅绑定 `127.0.0.1` 或容器内网，使用 `scripts/backup_restore.py` 定期冷备。
