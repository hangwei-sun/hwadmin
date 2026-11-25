/**
 * @description: 动态导入hooks
 * @author sundan
 */
import { loadingText, messageDuration } from '/@/config'
import { gp } from '/@vab/plugins/vab'

export const $baseLoading: any = (text = loadingText, background = 'var(--el-color-white)') => {
  return gp.$baseLoading(text, background)
}

export const $baseMessage: any = (
  message: string | VNode,
  type: 'success' | 'warning' | 'info' | 'error' = 'info',
  customClass: string,
  dangerouslyUseHTMLString: boolean,
  callback?: any
) => {
  return gp.$baseMessage(message, type, customClass, dangerouslyUseHTMLString, callback)
}

export const $baseAlert: any = (content: string | VNode, title = '温馨提示', callback?: any) => {
  return gp.$baseAlert(content, title, callback)
}

export const $baseNotify: any = (
  message: string,
  title: string,
  type: 'success' | 'warning' | 'info' | 'error' = 'success',
  position: 'top-right' | 'top-left' | 'bottom-right' | 'bottom-left' = 'top-right',
  duration: number = messageDuration
) => {
  return gp.$baseNotify(message, title, type, position, duration)
}

export const $baseConfirm: any = (
  content: string | VNode,
  title: string,
  callback1: any,
  callback2: any,
  confirmButtonText = '确定',
  cancelButtonText = '取消'
) => {
  return gp.$baseConfirm(content, title, callback1, callback2, confirmButtonText, cancelButtonText)
}

export const $pub: any = (...args: any[]) => {
  return gp.$pub(...args)
}

export const $sub: any = (...args: any[]) => {
  return gp.$sub(...args)
}

export const $unsub: any = (...args: any[]) => {
  return gp.$unsub(...args)
}

/**
 * @description: 清理PWA缓存（Service Worker和Cache Storage）
 * @author AI
 */
export async function $clearPWACache() {
  // 注销所有 Service Worker
  try {
    if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator && navigator.serviceWorker?.getRegistrations) {
      const registrations = await navigator.serviceWorker.getRegistrations()
      await Promise.all(registrations.map((registration) => registration.unregister()))
    }
  } catch {
    // 忽略浏览器 Service Worker 注销异常
  }
  // 清空所有 Cache Storage
  try {
    if (typeof caches !== 'undefined' && caches?.keys) {
      const cacheNames = await caches.keys()
      await Promise.all(cacheNames.map((cacheName) => caches.delete(cacheName)))
    }
  } catch {
    // 忽略浏览器 CacheStorage 清理异常
  }
}
