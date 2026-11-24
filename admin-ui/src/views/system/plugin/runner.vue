<template>
  <div class="plugin-runner-container" :class="{ 'is-fullscreen': isFullscreen }">
    <!-- 状态 1: 检测到第三方免编译微视图 (public/plugins/{plugin}/admin.html) -->
    <div v-if="hasMicroView" class="micro-view-wrapper">
      <!-- 顶部轻量级控制条 -->
      <div class="micro-view-toolbar">
        <div class="toolbar-left">
          <el-icon class="pulse-icon"><vab-icon icon="apps-2-line" /></el-icon>
          <span class="view-title">{{ routeTitle || '免编译插件后台' }}</span>
          <el-tag size="small" type="success" effect="plain" class="plugin-badge">
            Micro-View: {{ resolvedPluginName }}
          </el-tag>
          <span class="bridge-status">
            <span class="status-dot live" />
            <span>Token 与 API 桥接已注入</span>
          </span>
        </div>
        <div class="toolbar-right">
          <el-tooltip content="刷新微视图容器" placement="bottom">
            <el-button size="small" circle @click="reloadIframe">
              <vab-icon icon="refresh-line" />
            </el-button>
          </el-tooltip>
          <el-tooltip :content="isFullscreen ? '退出全屏' : '全屏展示'" placement="bottom">
            <el-button size="small" circle @click="toggleFullscreen">
              <vab-icon :icon="isFullscreen ? 'fullscreen-exit-line' : 'fullscreen-line'" />
            </el-button>
          </el-tooltip>
          <el-tooltip content="在新窗口独立打开" placement="bottom">
            <el-button size="small" circle @click="openExternal">
              <vab-icon icon="external-link-line" />
            </el-button>
          </el-tooltip>
          <el-button size="small" plain @click="goToPluginManager">
            插件中心
          </el-button>
        </div>
      </div>

      <!-- Iframe 沙箱容器 -->
      <div class="iframe-container" v-loading="iframeLoading">
        <iframe
          ref="iframeRef"
          :src="microViewHtml ? undefined : microViewUrl"
          :srcdoc="microViewHtml || undefined"
          class="micro-view-iframe"
          sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals"
          @load="onIframeLoad"
        />
      </div>
    </div>

    <!-- 状态 2: 正在探测中 -->
    <div v-else-if="detecting" class="detecting-box" v-loading="true" element-loading-text="正在探测插件免编译微视图..." />

    <!-- 状态 3: 未找到免编译 admin.html，展示开发者友好引导卡片 -->
    <el-card v-else shadow="hover" class="runner-card">
      <template #header>
        <div class="card-header">
          <div class="header-title">
            <el-icon class="plugin-icon"><vab-icon icon="apps-2-line" /></el-icon>
            <span>插件运行容器 (HwAdmin Micro-View Runner)</span>
          </div>
          <el-tag :type="resolvedPluginName ? 'success' : 'info'" effect="light">
            {{ resolvedPluginName ? `Plugin: ${resolvedPluginName}` : '通用动态路由' }}
          </el-tag>
        </div>
      </template>

      <el-alert
        :title="`未编译独立前端视图: ${routeTitle || '动态插件页面'}`"
        type="warning"
        :closable="false"
        show-icon
        class="runner-alert"
      >
        <template #default>
          <p>
            当前插件尚未在后台前端工程中内置专属 <code>.vue</code> 页面，且未检测到免编译单文件微视图：
            <code>/plugins/{{ resolvedPluginName || 'plugin_name' }}/admin.html</code> 或 <code>/plugins/{{ resolvedPluginName || 'plugin_name' }}/web/admin.html</code>。
          </p>
        </template>
      </el-alert>

      <el-descriptions title="路由上下文元信息" :column="2" border class="runner-desc">
        <el-descriptions-item label="页面标题">{{ routeTitle || '-' }}</el-descriptions-item>
        <el-descriptions-item label="探测插件标识">{{ resolvedPluginName || '无 (系统内置)' }}</el-descriptions-item>
        <el-descriptions-item label="当前路由路径">{{ currentRoute.path }}</el-descriptions-item>
        <el-descriptions-item label="当前路由名称">{{ String(currentRoute.name || '-') }}</el-descriptions-item>
        <el-descriptions-item label="免编译微视图预期地址">
          <code>/plugins/{{ resolvedPluginName || '{name}' }}/admin.html</code>
        </el-descriptions-item>
        <el-descriptions-item label="免编译即插即用">原生无缝支持 (离线微视图 / PostMessage 桥接)</el-descriptions-item>
      </el-descriptions>

      <!-- 两种开发模式极速指引 -->
      <div class="solution-grid">
        <div class="solution-card mode-micro">
          <div class="sol-header">
            <span class="sol-badge">推荐方案 1 (免编译即插即用)</span>
            <h4>单文件 HTML 微视图模式</h4>
          </div>
          <p class="sol-desc">
            在插件源码根目录提供 <code>public/admin.html</code>。插件启用时将由 <code>AssetPublisher</code> 自动同步至 Web 根目录，后台将以自适应沙箱 Iframe 自动注入 Token 桥接加载，生产环境<strong>无需 Node.js 编译与外部 CDN</strong>，纯离线本地即可运行！
          </p>
          <div class="code-header">
            <span>标准离线 HTML 示例 (零外部依赖)</span>
            <el-button type="primary" link size="small" @click="copyTemplateCode">复制示例代码</el-button>
          </div>
          <pre class="code-preview"><code>&lt;!DOCTYPE html&gt;
&lt;html lang="zh-CN"&gt;
&lt;head&gt;
  &lt;meta charset="UTF-8"&gt;
  &lt;style&gt;
    body { margin: 0; padding: 20px; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .btn { padding: 8px 16px; border: none; border-radius: 6px; background: #3b82f6; color: #fff; cursor: pointer; }
  &lt;/style&gt;
&lt;/head&gt;
&lt;body&gt;
  &lt;div class="card"&gt;
    &lt;h3&gt;欢迎使用 {{ resolvedPluginName || 'plugin' }} 插件后台&lt;/h3&gt;
    &lt;p&gt;认证状态: &lt;code id="token-state"&gt;等待上下文注入...&lt;/code&gt;&lt;/p&gt;
    &lt;button class="btn" onclick="pingApi()"&gt;测试离线 API 桥接&lt;/button&gt;
  &lt;/div&gt;
  &lt;script&gt;
    const ctx = window.__HWADMIN_CONTEXT__ || {};
    let token = ctx.token || '';
    let apiBase = ctx.apiBase || '/adminapi';
    const renderState = () =&gt; {
      document.getElementById('token-state').textContent = token ? '已注入 (' + token.slice(0, 8) + '...)' : '未登录';
    };
    renderState();
    window.addEventListener('message', (e) =&gt; {
      if (e.origin === window.location.origin &amp;&amp; e.data?.type === 'HWADMIN_INIT') {
        token = e.data.token || '';
        apiBase = e.data.apiBase || apiBase;
        renderState();
      }
    });
    async function pingApi() {
      const res = await fetch(apiBase + '/index/info', { headers: { Authorization: 'Bearer ' + token } });
      alert('HTTP ' + res.status);
    }
  &lt;/script&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>
        </div>

        <div class="solution-card mode-source">
          <div class="sol-header">
            <span class="sol-badge source">开发方案 2 (深度定制源码)</span>
            <h4>编译级 Vue 3 组件模式</h4>
          </div>
          <p class="sol-desc">
            若需与后台共享全局 Pinia 状态、国际化与核心打包机制，可在管理端前端工程源码中新建对应的组件：
          </p>
          <div class="path-box">
            <code>admin-ui/src/views/plugin/{{ resolvedPluginName || 'example' }}/index.vue</code>
          </div>
          <p class="sol-desc" style="margin-top: 12px;">
            并在后端插件 <code>menu.json</code> 或菜单表中将路由的 <code>component</code> 字段指向此路径。
          </p>
        </div>
      </div>

      <div class="action-bar">
        <el-button type="primary" @click="detectMicroView">
          <el-icon><vab-icon icon="refresh-line" /></el-icon>
          重新探测微视图页面
        </el-button>
        <el-button @click="goToPluginManager">
          <el-icon><vab-icon icon="settings-3-line" /></el-icon>
          前往插件管理中心
        </el-button>
        <el-button @click="goToDashboard">
          <el-icon><vab-icon icon="home-2-line" /></el-icon>
          返回控制台
        </el-button>
      </div>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl, serverUrl } from '/@/utils/platform'
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '/@/store/modules/user'

defineOptions({
  name: 'PluginRunner',
})

const currentRoute = useRoute()
const router = useRouter()
const userStore = useUserStore()

const routeTitle = computed(() => (currentRoute.meta?.title as string) || '')
const isFullscreen = ref(false)
const detecting = ref(true)
const hasMicroView = ref(false)
const activeMicroViewBase = ref('')
const microViewHtml = ref('')
const iframeLoading = ref(true)
const iframeRef = ref<HTMLIFrameElement | null>(null)

// 智能提取插件名称
const resolvedPluginName = computed(() => {
  if (currentRoute.meta?.plugin) {
    return String(currentRoute.meta.plugin).toLowerCase()
  }
  const path = currentRoute.path
  const pluginMatch = path.match(/^\/plugin\/([^/]+)/) || path.match(/^\/plugins\/([^/]+)/)
  if (pluginMatch) {
    return pluginMatch[1].toLowerCase()
  }
  // 从第一级路径猜测
  const segments = path.split('/').filter(Boolean)
  if (segments.length > 0 && segments[0] !== 'system' && segments[0] !== 'auth' && segments[0] !== 'index') {
    return segments[0].toLowerCase()
  }
  return ''
})

// 构造安全注入参数的微视图 URL（不在 URL 中拼接明文 token，认证信息仅通过同源 postMessage/上下文注入）
const microViewUrl = computed(() => {
  if (!resolvedPluginName.value) return ''
  const base = activeMicroViewBase.value || serverUrl(`/plugins/${resolvedPluginName.value}/admin.html`)
  const theme = document.documentElement.getAttribute('data-theme') || 'light'
  const apiBase = serverUrl('/adminapi')
  const query = new URLSearchParams({
    theme,
    apiBase,
    route: currentRoute.path,
    t: String(Date.now()),
  })
  return `${base}?${query.toString()}`
})

const injectMicroViewContext = (html: string, pluginName: string) => {
  const token = typeof userStore.token === 'string' ? userStore.token : ''
  const theme = document.documentElement.getAttribute('data-theme') || 'light'
  const apiBase = serverUrl('/adminapi')
  const baseHref = serverUrl(`/plugins/${pluginName}/`)
  const ctxJson = JSON.stringify({
    token,
    theme,
    apiBase,
    route: currentRoute.path,
  }).replace(/</g, '\\u003c')
  const headInjection = `<base href="${baseHref}" /><script>window.__HWADMIN_CONTEXT__=${ctxJson};<` + `/script>`
  if (/<head[^>]*>/i.test(html)) {
    return html.replace(/<head[^>]*>/i, (m) => `${m}\n  ${headInjection}`)
  }
  return `${headInjection}\n${html}`
}

// 探测微视图 HTML 是否存在（优先静态地址，被 Nginx 规则拦截时自动降级到带 Token 的后端 microView 接口）
const detectMicroView = async () => {
  if (!resolvedPluginName.value) {
    activeMicroViewBase.value = ''
    microViewHtml.value = ''
    hasMicroView.value = false
    detecting.value = false
    return
  }

  detecting.value = true
  iframeLoading.value = true
  microViewHtml.value = ''
  const candidates = [
    serverUrl(`/plugins/${resolvedPluginName.value}/admin.html`),
    serverUrl(`/plugins/${resolvedPluginName.value}/web/admin.html`),
  ]

  let foundUrl = ''
  for (const probeUrl of candidates) {
    try {
      const res = await fetch(probeUrl, { method: 'HEAD', cache: 'no-cache' })
      if (res.ok) {
        foundUrl = probeUrl
        break
      }
    } catch {
      // ignore
    }
  }

  if (foundUrl) {
    activeMicroViewBase.value = foundUrl
    hasMicroView.value = true
    setupBridge()
    detecting.value = false
    return
  }

  // 回退方案：通过已鉴权的后端接口拉取微视图源码并用 srcdoc 隔离渲染
  try {
    const token = typeof userStore.token === 'string' ? userStore.token : ''
    const fallbackUrl = serverUrl(`/adminapi/system.plugin/microView?name=${encodeURIComponent(resolvedPluginName.value)}`)
    const res = await fetch(fallbackUrl, {
      method: 'GET',
      cache: 'no-cache',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    const ctype = res.headers.get('content-type') || ''
    if (res.ok && ctype.includes('text/html')) {
      const html = await res.text()
      if (html.trim().length > 0) {
        activeMicroViewBase.value = candidates[0]
        microViewHtml.value = injectMicroViewContext(html, resolvedPluginName.value)
        hasMicroView.value = true
        setupBridge()
        detecting.value = false
        return
      }
    }
  } catch {
    // ignore
  }

  activeMicroViewBase.value = ''
  microViewHtml.value = ''
  hasMicroView.value = false
  detecting.value = false
}

// 建立父容器与 Iframe 的双向通信桥接（仅向同源插件视图传递认证信息）
const setupBridge = () => {
  const targetOrigin = window.location.origin
  ;(window as any).__HWADMIN_BRIDGE__ = {
    token: userStore.token,
    user: userStore.userInfo,
    theme: document.documentElement.getAttribute('data-theme') || 'light',
    postMessage: (type: string, data: any) => {
      if (iframeRef.value?.contentWindow) {
        iframeRef.value.contentWindow.postMessage({ type, data, source: 'hwadmin' }, targetOrigin)
      }
    },
  }
}

const onIframeLoad = () => {
  iframeLoading.value = false
  // 注入数据到同源子 iframe
  try {
    if (iframeRef.value?.contentWindow) {
      iframeRef.value.contentWindow.postMessage(
        {
          type: 'HWADMIN_INIT',
          token: userStore.token,
          userInfo: userStore.userInfo,
          theme: document.documentElement.getAttribute('data-theme') || 'light',
          apiBase: serverUrl('/adminapi'),
          route: currentRoute.path,
        },
        window.location.origin
      )
    }
  } catch {
    // 跨域微视图不传递同源凭据
  }
}

const reloadIframe = () => {
  iframeLoading.value = true
  if (microViewHtml.value) {
    void detectMicroView()
    return
  }
  if (iframeRef.value) {
    iframeRef.value.src = microViewUrl.value
  }
}

const toggleFullscreen = () => {
  isFullscreen.value = !isFullscreen.value
}

const openExternal = () => {
  if (microViewHtml.value) {
    const blob = new Blob([microViewHtml.value], { type: 'text/html;charset=utf-8' })
    const blobUrl = URL.createObjectURL(blob)
    window.open(blobUrl, '_blank', 'noopener')
    return
  }
  if (microViewUrl.value) {
    void openExternalUrl(microViewUrl.value)
  }
}

const goToPluginManager = () => {
  router.push('/system/plugin')
}

const goToDashboard = () => {
  router.push('/index')
}

const copyTemplateCode = async () => {
  const closeScript = '</' + 'script>'
  const pluginLabel = resolvedPluginName.value || 'Plugin'
  const code = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title>${pluginLabel} Admin</title>
  <style>
    :root { --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; --text: #1e293b; --muted: #64748b; --primary: #3b82f6; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 20px; font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; background: #ecfdf5; color: #059669; font-size: 12px; }
    .btn { margin-top: 12px; padding: 8px 16px; border: none; border-radius: 6px; background: var(--primary); color: #fff; cursor: pointer; font-size: 14px; }
    .btn:hover { opacity: 0.9; }
    pre { margin-top: 12px; padding: 12px; background: #0f172a; color: #e2e8f0; border-radius: 6px; font-size: 12px; overflow: auto; }
  </style>
</head>
<body>
  <div class="card">
    <h3>${pluginLabel} 免编译离线微视图</h3>
    <span class="badge">无需 Node.js 编译与外部 CDN，纯离线即插即用运行</span>
    <p style="margin-top: 16px; color: var(--muted);">认证桥接状态: <code id="token-status">等待初始化...</code></p>
    <button class="btn" id="fetch-btn" type="button">获取后台信息</button>
    <pre id="result">// 点击上方按钮发起带认证桥接的本地请求</pre>
  </div>
  <script>
    const ctx = window.__HWADMIN_CONTEXT__ || {};
    let token = ctx.token || '';
    let apiBase = ctx.apiBase || '/adminapi';
    const statusEl = document.getElementById('token-status');
    const resultEl = document.getElementById('result');
    const updateStatus = () => {
      statusEl.textContent = token ? '已注入 (' + token.slice(0, 8) + '...)' : '未携带 Token';
    };
    updateStatus();
    window.addEventListener('message', (e) => {
      if (e.origin === window.location.origin && e.data?.type === 'HWADMIN_INIT') {
        token = e.data.token || '';
        apiBase = e.data.apiBase || apiBase;
        updateStatus();
      }
    });
    document.getElementById('fetch-btn').addEventListener('click', async () => {
      try {
        const res = await fetch(apiBase + '/index/info', {
          headers: token ? { Authorization: 'Bearer ' + token } : {},
        });
        const data = await res.json();
        resultEl.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        resultEl.textContent = '请求失败: ' + (err?.message || err);
      }
    });
  ${closeScript}
</body>
</html>`

  try {
    await navigator.clipboard.writeText(code)
    ElMessage.success('离线免编译微视图单文件 HTML 示例已成功复制至剪贴板！')
  } catch {
    ElMessage.info('复制失败，请手动在上方预览区域复制')
  }
}

onMounted(() => {
  detectMicroView()
})

watch(() => currentRoute.path, () => {
  detectMicroView()
})
</script>

<style lang="scss" scoped>
.plugin-runner-container {
  padding: 20px;
  min-height: calc(100vh - 120px);

  &.is-fullscreen {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 9999;
    padding: 0;
    background: #ffffff;

    .micro-view-wrapper {
      height: 100vh;
    }
  }

  .micro-view-wrapper {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 140px);
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    overflow: hidden;

    .micro-view-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 16px;
      background: #f8fafc;
      border-bottom: 1px solid var(--el-border-color-lighter);

      .toolbar-left {
        display: flex;
        align-items: center;
        gap: 10px;

        .pulse-icon {
          font-size: 18px;
          color: var(--el-color-primary);
        }

        .view-title {
          font-size: 15px;
          font-weight: 600;
          color: var(--el-text-color-primary);
        }

        .bridge-status {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin-left: 8px;

          .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #cbd5e1;

            &.live {
              background: #10b981;
              box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
            }
          }
        }
      }

      .toolbar-right {
        display: flex;
        align-items: center;
        gap: 8px;
      }
    }

    .iframe-container {
      flex: 1;
      width: 100%;
      height: 100%;
      position: relative;

      .micro-view-iframe {
        width: 100%;
        height: 100%;
        border: none;
        display: block;
      }
    }
  }

  .detecting-box {
    min-height: 300px;
  }

  .runner-card {
    border-radius: 8px;

    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;

      .header-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 16px;
        font-weight: 600;

        .plugin-icon {
          font-size: 20px;
          color: var(--el-color-primary);
        }
      }
    }

    .runner-alert {
      margin-bottom: 20px;
    }

    .runner-desc {
      margin-bottom: 24px;
    }

    .solution-grid {
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 16px;
      margin-bottom: 24px;

      @media (max-width: 992px) {
        grid-template-columns: 1fr;
      }

      .solution-card {
        padding: 16px;
        border-radius: 8px;
        border: 1px solid var(--el-border-color-lighter);
        background: #fafafa;

        .sol-header {
          margin-bottom: 8px;

          .sol-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
            background: #e0f2fe;
            color: #0284c7;
            margin-bottom: 6px;

            &.source {
              background: #f3e8ff;
              color: #7e22ce;
            }
          }

          h4 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--el-text-color-primary);
          }
        }

        .sol-desc {
          font-size: 12px;
          line-height: 1.6;
          color: var(--el-text-color-regular);
          margin-bottom: 12px;
        }

        .code-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin-bottom: 6px;
        }

        .code-preview {
          background: #1e293b;
          color: #e2e8f0;
          padding: 12px;
          border-radius: 6px;
          font-size: 11px;
          line-height: 1.5;
          max-height: 180px;
          overflow-y: auto;
          margin: 0;
        }

        .path-box {
          background: #ffffff;
          border: 1px dashed var(--el-border-color);
          padding: 10px 12px;
          border-radius: 6px;
          font-size: 12px;
          color: var(--el-color-primary);
        }
      }
    }

    .action-bar {
      display: flex;
      gap: 12px;
      justify-content: flex-start;
    }
  }
}
</style>
