<template>
  <div class="hw-editor-container" :class="{ 'is-disabled': disabled }">
    <toolbar
      v-if="editorVisible && !disabled"
      class="hw-editor-toolbar"
      :editor="editorRef"
      :default-config="toolbarConfig"
      :mode="mode"
    />
    <editor
      v-if="editorVisible"
      v-model="valueHtml"
      class="hw-editor-content"
      :style="{ height: computedHeight }"
      :default-config="editorConfig"
      :mode="mode"
      @on-created="handleCreated"
      @on-change="handleChange"
      @on-destroyed="handleDestroyed"
    />
  </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { DomEditor } from '@wangeditor/editor'
import type { IDomEditor, IEditorConfig, IToolbarConfig } from '@wangeditor/editor'
import { getToken } from '/@/utils/token'
import { sanitizeHtml } from '/@/utils/sanitizeHtml'
import { projectConfig } from '/@/config/project'
import { serverOrigin, serverUrl } from '/@/utils/platform'

defineOptions({
  name: 'HwEditor',
})

const props = withDefaults(
  defineProps<{
    modelValue?: string
    height?: string | number
    placeholder?: string
    disabled?: boolean
    mode?: 'default' | 'simple'
    uploadUrl?: string
  }>(),
  {
    modelValue: '',
    height: '400px',
    placeholder: '请输入正文内容...',
    disabled: false,
    mode: 'default',
    uploadUrl: '',
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', val: string): void
  (e: 'change', editor: IDomEditor): void
  (e: 'created', editor: IDomEditor): void
}>()

// 编辑器实例，必须用 shallowRef
const editorRef = shallowRef<IDomEditor>()
const editorVisible = ref(true)
let editorVersion = 0
let unmounted = false

// 内容 HTML 响应式绑定
const valueHtml = ref(sanitizeHtml(props.modelValue || ''))

// 计算实际高度
const computedHeight = computed(() => {
  if (typeof props.height === 'number') {
    return `${props.height}px`
  }
  return props.height || '400px'
})

// 计算上传基础接口
const resolvedUploadUrl = computed(() => {
  if (props.uploadUrl) return serverUrl(props.uploadUrl)
  const apiBase = projectConfig.apiBase || '/adminapi'
  return serverUrl(`${apiBase}/plugin/editor/index/upload`)
})

// 获取鉴权 Token
const getAuthHeaders = () => {
  const token = getToken() || ''
  const target = new URL(resolvedUploadUrl.value, window.location.href)
  if (target.origin !== serverOrigin) return {}
  return {
    Authorization: `Bearer ${token}`,
    'X-Token': token,
  }
}

// 工具栏配置
const toolbarConfig: Partial<IToolbarConfig> = {
  excludeKeys: ['fullScreen'], // 全屏通过外层工作台统一控制，避免工具栏与外层布局冲突
}

// 编辑器配置
const editorConfig: Partial<IEditorConfig> = {
  placeholder: props.placeholder,
  readOnly: props.disabled,
  MENU_CONF: {
    uploadImage: {
      server: resolvedUploadUrl.value,
      fieldName: 'file',
      maxFileSize: 10 * 1024 * 1024, // 10MB
      headers: getAuthHeaders(),
      // 超时时间 30 秒
      timeout: 30 * 1000,
    },
    uploadVideo: {
      server: resolvedUploadUrl.value,
      fieldName: 'file',
      maxFileSize: 50 * 1024 * 1024, // 50MB
      headers: getAuthHeaders(),
      timeout: 60 * 1000,
    },
  },
}

const destroyEditor = (editor: IDomEditor) => {
  // WangEditor 5 removes selectionchange listeners in destroy(), but a
  // debounced callback may already be queued against the removed DOM instance.
  const textarea = DomEditor.getTextarea(editor) as unknown as {
    onDOMSelectionChange?: { cancel?: () => void }
  }
  textarea.onDOMSelectionChange?.cancel?.()
  editor.destroy()
}

// 监听外部 modelValue 变更
watch(
  () => props.modelValue,
  async (newVal) => {
    if (newVal === valueHtml.value) return
    // WangEditor's setHtml can corrupt a null/stale Slate selection when it
    // inserts a fragment containing void nodes. Recreate for external values.
    const version = ++editorVersion
    editorVisible.value = false
    const editor = editorRef.value
    editorRef.value = undefined
    if (editor) destroyEditor(editor)
    valueHtml.value = sanitizeHtml(newVal || '')
    await nextTick()
    if (!unmounted && version === editorVersion) editorVisible.value = true
  }
)

// 监听 disabled 禁用状态
watch(
  () => props.disabled,
  (val) => {
    const editor = editorRef.value
    if (!editor) return
    if (val) {
      editor.disable()
    } else {
      editor.enable()
    }
  }
)

const handleCreated = (editor: IDomEditor) => {
  editorRef.value = editor
  if (props.disabled) {
    editor.disable()
  }
  emit('created', editor)
}

const handleChange = (editor: IDomEditor) => {
  const html = editor.getHtml()
  valueHtml.value = html
  emit('update:modelValue', html)
  emit('change', editor)
}

const handleDestroyed = () => {
  // editor 销毁完成
}

// 组件卸载时安全销毁
onBeforeUnmount(() => {
  unmounted = true
  const editor = editorRef.value
  if (editor) {
    try {
      destroyEditor(editor)
    } catch {
      // 忽略可能存在的销毁错误
    }
  }
})

// 对外暴露常用方法
defineExpose({
  getEditor: () => editorRef.value,
  getHtml: () => editorRef.value?.getHtml() || '',
  getText: () => editorRef.value?.getText() || '',
  clear: () => editorRef.value?.clear(),
})
</script>

<style lang="scss" scoped>
.hw-editor-container {
  display: flex;
  flex-direction: column;
  width: 100%;
  border: 1px solid var(--el-border-color);
  border-radius: 6px;
  background-color: var(--el-bg-color);
  overflow: hidden;
  transition: border-color 0.2s ease;

  &:hover {
    border-color: var(--el-border-color-darker);
  }

  &.is-disabled {
    opacity: 0.7;
    cursor: not-allowed;
    background-color: var(--el-fill-color-light);
  }

  .hw-editor-toolbar {
    border-bottom: 1px solid var(--el-border-color-light);
    background-color: var(--el-fill-color-blank);
    z-index: 10;
  }

  .hw-editor-content {
    overflow-y: hidden;
    background-color: var(--el-bg-color);

    :deep(.w-e-text-container) {
      background-color: transparent;
      color: var(--el-text-color-primary);

      p {
        line-height: 1.6;
        margin: 8px 0;
      }
    }
  }
}
</style>
