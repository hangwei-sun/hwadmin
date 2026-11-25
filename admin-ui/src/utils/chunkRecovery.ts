import { $clearPWACache } from '/@/hooks'

const CHUNK_RELOAD_LOCK_KEY = 'hwadmin:chunk_reload_at'
const CHUNK_RELOAD_LOCK_TTL_MS = 30_000

const CHUNK_ERROR_PATTERNS = [
  'Failed to fetch dynamically imported module',
  'Importing a module script failed',
  'Loading chunk',
  'ChunkLoadError',
  'error loading dynamically imported module',
  'Unable to preload CSS',
]

/**
 * 判断异常是否属于静态资源/动态 Chunk 失效错误（常见于版本升级后浏览器仍持有旧版索引或 SW 缓存）
 */
export const isChunkLoadError = (error: unknown): boolean => {
  const message =
    typeof error === 'string'
      ? error
      : error && typeof error === 'object' && 'message' in error
        ? String((error as { message?: unknown }).message ?? '')
        : String(error ?? '')

  if (!message) return false
  const lower = message.toLowerCase()
  return CHUNK_ERROR_PATTERNS.some((pattern) => lower.includes(pattern.toLowerCase()))
}

/**
 * 执行带 30 秒 sessionStorage 防死循环锁的一次性 PWA 缓存清理与页面刷新自愈
 */
export const recoverFromChunkError = (): boolean => {
  if (typeof window === 'undefined') return false

  try {
    const raw = window.sessionStorage.getItem(CHUNK_RELOAD_LOCK_KEY)
    const lastReloadAt = raw ? Number(raw) : 0
    if (Number.isFinite(lastReloadAt) && lastReloadAt > 0 && Date.now() - lastReloadAt < CHUNK_RELOAD_LOCK_TTL_MS) {
      return false
    }
    window.sessionStorage.setItem(CHUNK_RELOAD_LOCK_KEY, String(Date.now()))
  } catch {
    // 隐私模式或禁用 sessionStorage 时直接尝试兜底刷新一次
  }

  void Promise.race([
    $clearPWACache(),
    new Promise((resolve) => window.setTimeout(resolve, 600)),
  ]).finally(() => {
    window.location.reload()
  })

  return true
}
