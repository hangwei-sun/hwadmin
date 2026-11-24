<template>
  <vab-app />
</template>

<script lang="ts" setup>
import { useSettingsStore } from '/@/store/modules/settings'

defineOptions({
  name: 'App',
})

const settingsStore = useSettingsStore()
const { updateTheme } = settingsStore

// 使用防抖优化resize事件处理
const resizeContainer = useDebounceFn(() => {
  const vh = window.innerHeight * 0.01
  const el = ref<HTMLElement | null>(null)
  useCssVar('--vh', el).value = `${vh}px`
}, 100)

onBeforeMount(() => {
  updateTheme()
  /**
   * @description: 修复ios、android等移动端浏览器100vh兼容问题
   * @author sundan
   */
  resizeContainer()
})

onMounted(() => {
  // 添加事件监听器
  globalThis.addEventListener('orientationchange', resizeContainer)
  globalThis.addEventListener('resize', resizeContainer)
})

onUnmounted(() => {
  // 清理事件监听器
  globalThis.removeEventListener('orientationchange', resizeContainer)
  globalThis.removeEventListener('resize', resizeContainer)
})
</script>
