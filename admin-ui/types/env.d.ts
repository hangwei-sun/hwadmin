/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_UPDATE_TIME: string
  readonly VITE_DESKTOP_SERVER_ORIGIN?: string
  // 更多环境变量...
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
