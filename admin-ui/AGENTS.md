# HwAdmin PC 管理端 Agent 协同契约与规范 (admin-ui/)

> **适用范围**：面向所有在 `admin-ui/` 目录下开发、优化、重构管理后台前端页面的 AI 智能体。  
> **上级基线**：项目根目录 [`../AGENTS.md`](../AGENTS.md)。

---

## 一、技术栈与工程定位

- **工程唯一性**：`admin-ui/` 为**当前唯一的生产级管理端前端工程源码**。严禁向代码仓库提交 `Vite-vue/` 或临时目录。
- **技术选型**：Vue 3 (Composition API / `<script setup>`) + Vite + TypeScript + Pinia + Element Plus。
- **接口对接**：对接后端 `/adminapi` 应用，统一通过 `src/api/` 发起强类型请求，请求头自动附加 `Authorization: Bearer <token>`。

---

## 二、目录结构规范

```
admin-ui/
├── src/
│   ├── api/                # 管理端业务 API 强类型封装（对应后端控制器）
│   ├── components/         # 全局通用组件（含强类型泛型表格 HwTable）
│   ├── hooks/              # 通用 Composition API Hooks（如 useCrudTable）
│   ├── layout/             # 管理端骨架布局
│   ├── router/             # 路由配置与动态路由映射
│   ├── store/              # Pinia 全局状态（admin、app、permission 等）
│   ├── types/              # TypeScript 类型声明（shared-api.d.ts 等）
│   └── views/              # 业务页面目录（由后端 auth_rule.component 自动映射）
└── package.json
```

---

## 三、前端开发核心铁律

1. **动态路由自动映射**：
   - 后端返回的 `component` 字段（如 `@/views/system/dict/index.vue`）由 `src/utils/routes.ts` 通过 `import.meta.glob('/src/views/**/*.vue')` 自动映射到 `src/views/` 对应文件。
   - 新增业务页面只需在 `src/views/<module>/<controller>/index.vue` 创建对应组件。
2. **强类型 CRUD 表格、操作按钮与 Hooks**：
   - 列表页优先复用强类型泛型表格 `<HwTable<T>>` 与 `useCrudTable<T, Q>`；`src/api/*.ts` 对接 `useCrudTable` 时，删除接口签名必须为 `(ids: (string | number)[]) => Promise<any>`（不可窄化为 `number[]`）。
   - Element Plus 2.14+ `<el-radio>` / `<el-radio-button>` 一律使用 `:value` 传值，禁止使用已废弃的 `label` 作为值绑定。
   - 格式化秒级时间戳时必须先转为数值且判空/判零：`const n = Number(ts || 0); if (!n || n <= 0) return '-'`，严禁直接 `if (!ts)`（防止 SQLite `"0"` 字符串渲染成 `1970-01-01`）。
   - **表格「操作」列统一规范**：严禁裸用 `link`/`text` 文字链，统一使用 `<div class="hw-table-actions">`（全局已定义）包裹 `size="small" plain` 紧凑边框按钮，并按按钮数量预留充足列宽（2 钮 ≥ 168px、3 钮 ≥ 240px、4 钮 ≥ 290px）。
   - **图标与 SVG 防拉伸**：图标容器保持 `flex-shrink: 0`；自绘 SVG 图表使用容器尺寸动态计算 `viewBox`，禁止固定 `viewBox` 配 `preserveAspectRatio="none"`。
3. **按钮权限与指令**：
   - 按钮级细粒度权限控制使用 `v-auth="'module/controller/action'"` 指令或 `hasPerm('...')` 函数，与后端权限节点命名对齐。
4. **Windows 桌面端 (Tauri 2) 与共享契约**：
   - 共享类型声明位于 `src/types/shared-api.d.ts`（`php think hw:project export-types`）。
   - 桌面端与 PC 端共享 `src/`，涉及服务端源地址、外链打开与平台差异必须走 `src/utils/platform.ts`，严禁硬编码同源相对路径绕过 CORS 白名单；联调须在 `server/.env` 配置 `HW_CORS_ORIGINS=https://tauri.localhost,http://127.0.0.1:15174`。
5. **本地质量与构建闸门**：
   - 提交前必须确保以下命令执行无阻断性错误：
     ```bash
     pnpm check          # 串联 vue-tsc 类型检查、oxlint 校验、单测、构建验证
     pnpm build:sync     # 生产打包并自动同步制品至 server/public/admin/
     pnpm desktop:check  # 改动共用前端代码或 src-tauri/ 后同步校验桌面端与 Cargo
     ```
