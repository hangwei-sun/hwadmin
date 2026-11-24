# HwAdmin Agent 快速入口

框架版本：1.6.2。目标是让用户描述业务，你负责尽快交付可预览、可使用的应用。

先读 [START_HERE.md](START_HERE.md)。按网站、后台或小程序选择 `hw start`，默认 SQLite（`runtime/hwadmin.sqlite` / `runtime/dev.sqlite`），不要求用户先配数据库、阅读路线图或完成框架审计清单。

先把页面和主要业务跑起来，再按反馈完善。根据当前工作查阅根目录 [AGENTS.md](AGENTS.md)、[后端约定](server/AGENTS.md)、[管理端约定](admin-ui/AGENTS.md) 或 [移动端约定](admin-ui/mobile/AGENTS.md)。

用户让你开发应用时，直接推进应用；不要把它转换成框架底座整改项目。工程检查由你和脚本完成（收尾运行 `php think hw:doctor --mode=prod --json`），向用户报告预览地址、功能和下一步即可。

业务开发只维护 `app_version`，不修改 `framework_version` 或 `server/core/` 内核底座。

上线阶段一次收集必要服务器/域名/平台信息，复用 [现有部署流程](docs/部署与运维指南.md)，实际验证后交付访问入口。

开源与多远程安全红线：提交/推送前必须先运行 `git remote -v` 核对目标仓库，禁止直接将内部研发历史分支推送至公开仓；发版同步公开仓须使用受控安全同步流程并确认 0 敏感文件与凭据残留。
