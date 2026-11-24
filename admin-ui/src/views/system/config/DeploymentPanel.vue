<template>
  <el-dialog v-model="visible" title="部署配置与安全复查" width="min(900px, 96vw)" destroy-on-close @opened="mountPanel">
    <template v-if="isDesktop">
      <el-alert title="部署安全复查需要在服务端同源网页中执行，请在浏览器登录后台后使用此功能。" type="info" :closable="false" />
      <el-button @click="openExternalUrl(serverOrigin)">在浏览器打开服务端</el-button>
    </template>
    <el-alert v-if="error" :title="error" type="warning" :closable="false" />
    <div v-if="!isDesktop" ref="host"></div>
  </el-dialog>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useUserStore } from '/@/store/modules/user'
import { isDesktop, openExternalUrl, serverOrigin } from '/@/utils/platform'

const visible = defineModel<boolean>({ default: false })
const host = ref<HTMLElement>()
const error = ref('')
const user = useUserStore()
type DeploymentUI = { mount: (host: HTMLElement, options: {
  installed: () => boolean
  request: (action: string, data: Record<string, string>) => Promise<unknown>
}) => unknown }

async function mountPanel() {
  error.value = ''
  if (isDesktop) return
  try {
    const context = window as Window & { HwDeployment?: DeploymentUI }
    if (!context.HwDeployment) {
      await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script')
        script.src = '/admin/deployment-check.js?v=' + Date.now()
        script.onload = () => { script.remove(); resolve() }
        script.onerror = () => { script.remove(); reject(new Error('部署检查组件缺失，请重新部署完整前端文件')) }
        document.head.append(script)
      })
    }
    if (!host.value || host.value.shadowRoot) return
    if (!context.HwDeployment) throw new Error('部署检查组件加载失败')
    context.HwDeployment.mount(host.value, {
      installed: () => true,
      request: async (action, data) => {
        if (typeof user.token !== 'string' || !user.token) throw new Error('请以超级管理员登录后复查')
        const response = await fetch('/index.php?s=/install/index/' + encodeURIComponent(action), {
          method: 'POST', credentials: 'same-origin', redirect: 'error', signal: AbortSignal.timeout(15000),
          headers: { 'X-Token': user.token, 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: new URLSearchParams(data),
        })
        const result = await response.json()
        if (result.code !== 1) throw new Error(result.msg || '部署检查失败，请确认超级管理员登录状态')
        return result.data
      },
    })
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : '部署检查组件加载失败'
  }
}
</script>
