# HwAdmin 移动端 Agent 协同契约与规范 (admin-ui/mobile/)

> **适用范围**：面向所有在 `admin-ui/mobile/` 目录下开发、优化通用移动端、H5 与微信小程序的 AI 智能体。  
> **上级基线**：项目根目录 [`../../AGENTS.md`](../../AGENTS.md)。

---

## 一、技术栈与工程定位

- **工程独立性**：`admin-ui/mobile/` 保持**独立依赖与独立锁文件** (`pnpm-lock.yaml`)，不与管理端 `admin-ui` 混用依赖。
- **技术选型**：uni-app + Vue 3 + TypeScript + Pinia + Wot UI V2。
- **目标终端**：同时支持 **H5 网页** 与 **微信小程序 (WeChat Mini Program)**。
- **接口对接**：对接后端 `/api` 应用（前台会员体系），使用会员独立令牌 (`UserAuth`)，与后台管理端管理员令牌物理隔离。

---

## 二、目录结构规范

```
admin-ui/mobile/
├── src/
│   ├── api/                # 前台与会员 API 请求封装（user.ts、member.ts 等）
│   ├── http/               # uni.request 封装、统一拦截器与会员 Token 注入
│   ├── pages/              # 移动端业务页面（index、login、me、booking 等）
│   ├── store/              # Pinia 状态管理（member.ts 会员状态）
│   ├── types/              # 移动端 TypeScript 类型声明（shared-api.d.ts 等）
│   └── utils/              # 移动端工具函数（含 external-config.ts 集中配置检查器）
├── pages.json              # uni-app 页面路由与导航配置
└── package.json
```

---

## 三、移动端核心开发铁律

1. **会话与令牌隔离**：
   - 移动端仅使用 `/api` 前台会员接口与会员 Token，严禁混入或读取后台管理员 Token。
2. **可选基础能力与错误诊断**：
   - 会员注册 (`register`)、找回密码 (`resetPassword`)、会员上传 (`upload`)、微信授权登录 (`wechatLogin`) 为可选扩展能力。
   - 缺少微信小程序配置（`WECHAT_MP_APPID` / `WECHAT_MP_SECRET`）时，后端与前端必须返回明确的待办指示码（如 `CONFIGURE_WECHAT_CREDENTIALS`），严禁伪造授权成功。
3. **集中外部信息收集**：
   - 涉及小程序 AppID、服务端 API 地址等外部配置时，统一使用 `src/utils/external-config.ts` 集中诊断，客户端仅保留可公开变量。
4. **请求路径、Wot UI V2、自动路由与共享类型**：
   - `src/http` 已自动基于 `VITE_API_ORIGIN` 拼接 `/api` 前缀，业务 `src/api/*.ts` 中的 `path` 只需写 `/guestbook/index`，严禁重复写 `/api/guestbook/index`。
   - Wot UI V2 表单组件数值属性必须使用动态绑定（如 `<wd-input :maxlength="64" />`），禁止写成字符串 `maxlength="64"` 导致 `vue-tsc` 失败；页面路由与 `src/pages.json` 由 `@uni-helper/vite-plugin-uni-pages` 依据页面内 `definePage(...)` 自动生成，禁止手改 `src/pages.json`。
   - 共享数据类型契约维护在 `src/types/shared-api.d.ts`，与管理端及后端 OpenAPI 保持同步。
5. **本地质量与双端全构建闸门**：
   - 提交前必须通过根目录 `admin-ui` 执行全端校验（覆盖 `type-check` + `test` + `mp-weixin` 微信构建 + `h5` 构建）：
     ```bash
     pnpm --dir admin-ui mobile:check
     ```
