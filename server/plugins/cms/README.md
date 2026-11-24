# HwAdmin 官方示范 CMS 插件

## 插件简介
本插件是 HwAdmin 官方打造的示范级内容管理（CMS）插件，旨在向开发者展示：
1. **零侵入物理隔离架构**：所有代码、模型、控制器与配置位于 `plugins/cms/` 独立目录，完全不修改 core 底层；
2. **三端多数据库原生兼容**：在安装和运行阶段完美适配 MySQL 8.0+、PostgreSQL 16+ 与 SQLite3；
3. **极速 CRUD 开发范式**：基于 `core\crud\CrudTrait`，数十行代码实现完整后台列表、搜索、详情、新增、编辑、批量删除与权限映射；
4. **前后台分发体系**：
   - 后台管理路由：`/adminapi/plugin/cms/category/*`、`/adminapi/plugin/cms/article/*`
   - 前台公开接口：`/api/plugin/cms/article/index`、`/api/plugin/cms/article/detail`
5. **系统事件总线监听**：在 `Plugin::events()` 中监听 `AdminLoginAfter` 核心生命周期事件并写入安全审计日志。

## 数据表规范
- `cms_category`：CMS 栏目分类表
- `cms_article`：CMS 文章内容表

## 常用 CLI 命令
```bash
# 查看插件状态
php think hw:plugin list

# 安装 CMS 插件
php think hw:plugin install cms

# 禁用 / 启用 CMS 插件
php think hw:plugin disable cms
php think hw:plugin enable cms

# 卸载 CMS 插件（安全清理表与关联菜单）
php think hw:plugin uninstall cms
```
