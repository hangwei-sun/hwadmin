import { resolveProjectConfig } from '../../packages/pc-core/src/runtime.mjs'

declare global {
  interface Window {
    HWADMIN_CONFIG?: Parameters<typeof resolveProjectConfig>[0]
  }
}

// The public script is loaded before the module graph. Build tooling uses defaults.
export const projectConfig = resolveProjectConfig(typeof window === 'undefined' ? {} : window.HWADMIN_CONFIG)
