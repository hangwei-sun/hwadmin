<p>
  <img src="public/brand/logo-horizontal.webp" alt="HwAdmin Admin UI" width="210" />
</p>

# HwAdmin 前端工程 (admin-ui)

HwAdmin 生产级管理后台前端工程，基于 **Vue 3 + Vite + TypeScript + Pinia + Element Plus** 构建，全面对接后端 `/adminapi` 接口。

## 目录结构

```text
admin-ui/
├── src/
│   ├── api/           # 后端 API 接口封装（统一对接 /adminapi）
│   ├── config/        # 网络、主题与系统配置
│   ├── router/        # 动态路由映射与权限守卫
│   ├── store/         # Pinia 状态管理（user、routes、settings）
│   ├── views/         # 业务视图组件与后台管理页面
│   └── utils/         # 常用工具函数与 HTTP 请求封装
├── library/           # 核心基座与 UI 通用组件库
└── packages/          # 内部适配包
```

## 本地开发与构建

### 1. 安装依赖
```bash
pnpm install
```

### 2. 启动开发服务器
```bash
pnpm dev
```
开发服务器默认运行在 `http://localhost:15173`，代理 `/adminapi` 至后端服务（默认 `http://127.0.0.1:8000`）。如需自定义代理地址，可配置 `admin-ui/.env.development.local`。

### 3. 生产打包并同步至后端
```bash
pnpm check && pnpm build:sync
```
`pnpm check` 会执行 `vue-tsc` 类型检查、oxlint 校验、单元测试与构建验证；`pnpm build:sync` 会将构建产物原子同步到 `server/public/admin/` 并清理旧文件，同时自动写入二开保护指纹文件 `server/public/admin/hwadmin-official-ui.json`（标记 `official: false`），确保后续框架 OTA 在线更新自动跳过 `public/admin/` 而不覆盖本地二开页面（严禁删除或手动伪造该指纹文件）。

运行时部署配置为 `public/hwadmin.config.js`，通过 `window.HWADMIN_CONFIG` 提供同域 API 地址、标题和本地存储命名空间；不能在该文件中放置密钥。更新部署时需同时发布新入口和该配置文件。
