import { throttle } from 'lodash-es'
import type { App, DirectiveBinding } from 'vue'
import { hasPermission } from '/@/utils/permission'

export default {
  install: (app: App<Element>) => {
    /**
     * @description 权限自定义指令 v-permissions / v-auth（支持数组或单个字符串）
     */
    const checkPermissionDirective = {
      mounted(el: HTMLElement, binding: DirectiveBinding) {
        const { value } = binding
        const target = typeof value === 'string' ? [value] : value
        if (target && !hasPermission(target) && el.parentNode) el.parentNode.removeChild(el)
      },
    }
    app.directive('permissions', checkPermissionDirective)
    app.directive('auth', checkPermissionDirective)
    /**
     * @description 节流自定义指令v-throttle
     */
    const throttleHandlers = new WeakMap<HTMLElement, ReturnType<typeof throttle>>()
    app.directive('throttle', {
      mounted(el: HTMLElement, binding: DirectiveBinding) {
        const { value } = binding
        if (typeof value !== 'function') return
        const throttledFunction = throttle(value, 2000)
        throttleHandlers.set(el, throttledFunction)
        el.addEventListener('click', throttledFunction)
      },
      beforeUnmount(el: HTMLElement) {
        const handler = throttleHandlers.get(el)
        if (handler) {
          el.removeEventListener('click', handler)
          handler.cancel()
          throttleHandlers.delete(el)
        }
      },
    })
    /**
     * @description 防抖自定义指令v-debounce
     */
    const debounceCleanupMap = new WeakMap<HTMLElement, () => void>()
    app.directive('debounce', {
      mounted(el: HTMLElement, binding: DirectiveBinding) {
        const { value } = binding
        if (typeof value !== 'function') return
        let debounceTimer: ReturnType<typeof setTimeout> | null = null
        const handler = () => {
          if (debounceTimer) clearTimeout(debounceTimer)
          debounceTimer = setTimeout(() => {
            debounceTimer = null
            value()
          }, 1000)
        }
        el.addEventListener('click', handler)
        debounceCleanupMap.set(el, () => {
          el.removeEventListener('click', handler)
          if (debounceTimer) {
            clearTimeout(debounceTimer)
            debounceTimer = null
          }
        })
      },
      beforeUnmount(el: HTMLElement) {
        const cleanup = debounceCleanupMap.get(el)
        if (cleanup) {
          cleanup()
          debounceCleanupMap.delete(el)
        }
      },
    })
    /**
     * @description 获取焦点自定义指令v-focus
     */
    app.directive('focus', {
      mounted(el) {
        el.querySelector('input').focus()
      },
    })

  },
}
