<template>
  <div class="vab-right-tools">
    <vab-search v-show="!isHorizontal" class="hidden-xs-only" />
    <vab-color-picker v-show="theme.showColorPicker" />
    <vab-deep-seek v-show="theme.showDeepSeek" class="hidden-xs-only" />
    <vab-dark v-show="theme.showDark" :style="!isHorizontal ? '' : { marginLeft: 'var(--el-margin)' }" />
    <vab-theme v-show="theme.showTheme && routeName !== 'SeparateLayout'" />
    <vab-error-log class="hidden-xs-only" />
    <vab-font-size v-show="theme.showFontSize" class="hidden-xs-only" />
    <vab-lock v-show="theme.showLock" />
    <vab-notice v-show="theme.showNotice" />
    <el-dropdown trigger="hover" @command="handleClearCacheCommand">
      <div class="vab-clean-cache-trigger" title="清理全栈系统缓存" @click="handleClearCache('all')">
        <vab-icon :class="{ 'is-loading-spin': clearingCache }" icon="brush-line" />
      </div>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="all">
            <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
            一键清理全栈缓存
          </el-dropdown-item>
          <el-dropdown-item command="data" divided>
            <el-icon><vab-icon icon="database-2-line" /></el-icon>
            仅清理数据缓存 (Cache)
          </el-dropdown-item>
          <el-dropdown-item command="temp">
            <el-icon><vab-icon icon="file-code-line" /></el-icon>
            仅清理模板临时文件 (Runtime)
          </el-dropdown-item>
          <el-dropdown-item command="plugin">
            <el-icon><vab-icon icon="apps-2-line" /></el-icon>
            仅刷新应用列表
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
    <vab-language v-show="theme.showLanguage" />
    <vab-fullscreen v-show="theme.showFullScreen" />
    <vab-refresh v-show="theme.showRefresh" />
    <vab-avatar />
  </div>
</template>

<script lang="ts" setup>
import type Sortable from 'sortablejs'
import { clearCache } from '/@/api/hwadmin'
import { ElMessage } from 'element-plus'
import { useSettingsStore } from '/@/store/modules/settings'

defineOptions({
  name: 'VabRightTools',
})

defineProps({
  isHorizontal: {
    type: Boolean,
    default: false,
  },
})

const route = useRoute()
const settingsStore = useSettingsStore()
const { theme, device } = storeToRefs(settingsStore)
const routeName = ref<any>(route.name)

let sortable: Sortable | null = null
let isToolsUnmounted = false

const destroySortable = () => {
  if (sortable) {
    sortable.destroy()
    sortable = null
  }
}

const isDragEnabled = () => !isToolsUnmounted && theme.value.rightToolsDrag && device.value !== 'mobile'

const handleTabDrag = async () => {
  if (!isDragEnabled()) {
    destroySortable()
    return
  }
  const toolsElement = document.querySelector('.vab-right-tools-draggable') as HTMLElement | null
  if (!toolsElement) return

  const { default: SortableCtor } = await import('sortablejs')
  if (!isDragEnabled()) return

  destroySortable()
  sortable = new SortableCtor(toolsElement, {
    animation: 150,
    easing: 'cubic-bezier(1, 0, 0, 1)',
  })
}

watch(
  route,
  () => {
    routeName.value = route.name
  },
  { immediate: true }
)

onMounted(() => {
  nextTick(() => {
    void handleTabDrag()
  })
})

onBeforeUnmount(() => {
  isToolsUnmounted = true
  destroySortable()
})

watch(
  () => theme.value.rightToolsDrag,
  () => {
    void handleTabDrag()
  },
  {
    immediate: true,
  }
)

const clearingCache = ref(false)

const handleClearCache = async (type: 'all' | 'data' | 'temp' | 'plugin' = 'all') => {
  if (clearingCache.value) return
  clearingCache.value = true
  try {
    const res: any = await clearCache(type)
    const msg = res?.msg || '全栈系统缓存清理成功'
    if (typeof $baseMessage === 'function') {
      $baseMessage(msg, 'success', 'hey')
    } else {
      ElMessage.success(msg)
    }
  } catch (e: any) {
    const err = e?.message || '清理缓存失败'
    if (typeof $baseMessage === 'function') {
      $baseMessage(err, 'error', 'hey')
    } else {
      ElMessage.error(err)
    }
  } finally {
    clearingCache.value = false
  }
}

const handleClearCacheCommand = (command: string) => {
  handleClearCache(command as any)
}
</script>

<style lang="scss" scoped>
.vab-right-tools {
  display: flex;
  align-items: center;
  justify-content: flex-end;

  // 主题
  :deep(.ri-t-shirt-line):hover {
    animation: tshirtShake 0.5s;
  }

  @keyframes tshirtShake {
    0%,
    100% {
      transform: rotate(0);
    }
    20% {
      transform: rotate(-15deg);
    }
    40% {
      transform: rotate(10deg);
    }
    60% {
      transform: rotate(-10deg);
    }
    80% {
      transform: rotate(5deg);
    }
  }

  // 颜色选择
  :deep(.vab-color-picker):hover .el-color-picker__trigger {
    animation: paletteShake 0.5s;
  }

  @keyframes paletteShake {
    0%,
    100% {
      transform: scale(1);
    }
    50% {
      transform: scale(1.15);
    }
  }

  // 字体大小
  :deep(.ri-font-size-2):hover {
    animation: fontSizePulse 0.5s;
  }

  @keyframes fontSizePulse {
    0%,
    100% {
      transform: scale(1);
    }
    50% {
      transform: scale(1.2);
    }
  }

  // 锁屏
  :deep(.vab-lock):hover i {
    animation: lockShake 0.5s;
  }

  @keyframes lockShake {
    0%,
    100% {
      transform: rotate(0);
    }
    20% {
      transform: rotate(-18deg);
    }
    40% {
      transform: rotate(15deg);
    }
    60% {
      transform: rotate(-10deg);
    }
    80% {
      transform: rotate(8deg);
    }
  }

  // 通知
  :deep(.ri-notification-2-line):hover {
    animation: bellSwing 0.5s;
  }

  @keyframes bellSwing {
    0%,
    100% {
      transform: rotate(0);
    }
    20% {
      transform: rotate(-15deg);
    }
    40% {
      transform: rotate(10deg);
    }
    60% {
      transform: rotate(-10deg);
    }
    80% {
      transform: rotate(5deg);
    }
  }

  // 语言
  :deep(.vab-language):hover i {
    animation: langScale 0.4s;
  }

  @keyframes langScale {
    0%,
    100% {
      transform: scale(1);
    }
    50% {
      transform: scale(1.18);
    }
  }

  // 全屏
  :deep(.vab-fullscreen):hover {
    animation: zoomInOut 0.5s;
  }

  @keyframes zoomInOut {
    0%,
    100% {
      transform: scale(1);
    }
    50% {
      transform: scale(1.2);
    }
  }

  // 刷新
  :deep(.ri-refresh-line):hover {
    animation: refreshSpin 0.7s linear;
  }

  @keyframes refreshSpin {
    0% {
      transform: rotate(0);
    }
    100% {
      transform: rotate(360deg);
    }
  }

  // box 图标左右摇晃
  :deep(.vab-box):hover .vab-icon {
    animation: lockShake 0.5s;
  }

  // 清理缓存按钮与旋转动效
  .vab-clean-cache-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    margin-left: var(--el-margin);

    &:hover i {
      animation: brushWiggle 0.5s ease-in-out;
    }
  }

  .is-loading-spin {
    animation: refreshSpin 0.8s linear infinite !important;
  }

  @keyframes brushWiggle {
    0%,
    100% {
      transform: rotate(0);
    }
    25% {
      transform: rotate(-20deg) scale(1.15);
    }
    75% {
      transform: rotate(20deg) scale(1.15);
    }
  }
}
</style>
