<template>
  <div class="editor-workbench-container">
    <!-- 顶部操作条 -->
    <el-card shadow="never" class="header-card">
      <div class="header-left">
        <el-input
          v-model="draftTitle"
          placeholder="请输入文章或草稿标题..."
          class="title-input"
          clearable
        >
          <template #prefix>
            <el-icon><vab-icon icon="edit-line" /></el-icon>
          </template>
        </el-input>
        <el-tag v-if="currentDraftId" type="info" size="small" class="draft-tag">
          草稿 #{{ currentDraftId }}
        </el-tag>
        <span class="save-status-text">
          <el-icon v-if="saving"><vab-icon icon="loader-4-line" class="is-loading" /></el-icon>
          <el-icon v-else><vab-icon icon="check-line" /></el-icon>
          {{ lastSavedText }}
        </span>
      </div>

      <div class="header-actions">
        <!-- 一键模板下拉 -->
        <el-dropdown trigger="click" @command="handleApplyTemplate">
          <el-button>
            <el-icon><vab-icon icon="layout-masonry-line" /></el-icon>
            载入模板
            <el-icon class="el-icon--right"><vab-icon icon="arrow-down-s-line" /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="release">
                <el-icon><vab-icon icon="rocket-line" /></el-icon>
                新版本发布公告
              </el-dropdown-item>
              <el-dropdown-item command="tech">
                <el-icon><vab-icon icon="cpu-line" /></el-icon>
                技术架构白皮书
              </el-dropdown-item>
              <el-dropdown-item command="notice">
                <el-icon><vab-icon icon="notification-line" /></el-icon>
                系统升级维护通知
              </el-dropdown-item>
              <el-dropdown-item command="guide">
                <el-icon><vab-icon icon="book-read-line" /></el-icon>
                开发者新手入门指南
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>

        <!-- 源码模式切换 -->
        <el-button :type="isSourceMode ? 'warning' : 'default'" @click="toggleSourceMode">
          <el-icon><vab-icon icon="code-box-line" /></el-icon>
          {{ isSourceMode ? '返回富文本' : 'HTML 源码' }}
        </el-button>

        <!-- 移动端预览 -->
        <el-button @click="showMobilePreview = true">
          <el-icon><vab-icon icon="smartphone-line" /></el-icon>
          移动端预览
        </el-button>

        <!-- 草稿箱抽屉 -->
        <el-badge :value="draftList.length" :max="99" class="draft-badge" :hidden="draftList.length === 0">
          <el-button @click="openDraftDrawer">
            <el-icon><vab-icon icon="folder-history-line" /></el-icon>
            草稿箱
          </el-button>
        </el-badge>

        <!-- 保存草稿 -->
        <el-button type="primary" :loading="saving" @click="handleSaveDraft(false)">
          <el-icon><vab-icon icon="save-line" /></el-icon>
          保存草稿 (Ctrl+S)
        </el-button>
      </div>
    </el-card>

    <!-- 中部主工作区 -->
    <el-card shadow="never" class="workbench-card">
      <!-- 源码编辑模式 -->
      <div v-if="isSourceMode" class="source-editor-wrapper">
        <div class="source-header">
          <span>HTML 源代码编辑模式（可直接修改 HTML，返回富文本时自动解析生效）</span>
          <el-button type="primary" size="small" @click="toggleSourceMode">同步并返回富文本</el-button>
        </div>
        <el-input
          v-model="sourceHtml"
          type="textarea"
          :rows="22"
          class="source-textarea"
          placeholder="在此处编写或粘贴标准 HTML 源代码..."
        />
      </div>

      <!-- 富文本编辑器模式 -->
      <div v-if="!isSourceMode" class="editor-wrapper">
        <hw-editor
          ref="hwEditorRef"
          v-model="editorContent"
          height="calc(100vh - 310px)"
          placeholder="在此畅快创作！支持直接拖拽图片、粘贴截图、插入表格、代码块及音视频..."
          @change="handleContentChange"
        />
      </div>

      <!-- 底部字数与统计栏 -->
      <div class="editor-statusbar">
        <div class="status-left">
          <span class="status-item">
            <el-icon><vab-icon icon="font-size" /></el-icon>
            字数统计：<strong>{{ wordCount }}</strong> 字
          </span>
          <span class="status-item">
            <el-icon><vab-icon icon="file-text-line" /></el-icon>
            段落数：<strong>{{ paragraphCount }}</strong>
          </span>
          <span class="status-item">
            <el-icon><vab-icon icon="image-line" /></el-icon>
            包含图片：<strong>{{ imageCount }}</strong> 张
          </span>
        </div>
        <div class="status-right">
          <span class="status-hint">💡 提示：按 Ctrl+S 可随时快速保存草稿</span>
        </div>
      </div>
    </el-card>

    <!-- 移动端外壳弹窗预览 -->
    <el-dialog
      v-model="showMobilePreview"
      title="移动端阅读预览"
      width="430px"
      destroy-on-close
      center
      class="mobile-preview-dialog"
    >
      <div class="mobile-phone-frame">
        <div class="phone-notch"></div>
        <div class="phone-screen">
          <div class="phone-status-bar">
            <span>09:41</span>
            <div class="icons">
              <el-icon><vab-icon icon="signal-tower-fill" /></el-icon>
              <el-icon><vab-icon icon="wifi-line" /></el-icon>
              <el-icon><vab-icon icon="battery-2-charge-line" /></el-icon>
            </div>
          </div>
          <div class="phone-nav-bar">
            <span class="title">{{ draftTitle || '文章详情' }}</span>
          </div>
          <div class="phone-content-scroll">
            <h1 class="article-title">{{ draftTitle || '欢迎使用 HwAdmin' }}</h1>
            <div class="article-meta">
              <span>HwAdmin 团队</span>
              <span>{{ currentTimeText }}</span>
            </div>
            <!-- 正文渲染 -->
            <div class="article-html-body" v-html="safeEditorContent"></div>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 草稿箱抽屉 -->
    <el-drawer
      v-model="showDraftDrawer"
      title="草稿箱与历史版本"
      size="400px"
      direction="rtl"
    >
      <div v-loading="loadingDrafts" class="draft-drawer-container">
        <div class="drawer-header-actions">
          <span>共 {{ draftList.length }} 篇草稿</span>
          <el-button size="small" text type="primary" @click="fetchDrafts">
            <el-icon><vab-icon icon="refresh-line" /></el-icon>
            刷新
          </el-button>
        </div>

        <el-empty v-if="draftList.length === 0" description="暂无历史草稿" />

        <div v-else class="draft-item-list">
          <div
            v-for="item in draftList"
            :key="item.id"
            class="draft-card-item"
            :class="{ 'is-active': item.id === currentDraftId }"
          >
            <div class="item-title" :title="item.title" @click="loadDraftToWorkbench(item)">
              {{ item.title || '无标题草稿' }}
            </div>
            <div class="item-meta">
              <span>{{ item.word_count || 0 }} 字</span>
              <span>{{ formatTime(item.update_time) }}</span>
            </div>
            <div class="item-actions">
              <el-button size="small" type="primary" text @click="loadDraftToWorkbench(item)">
                载入编辑
              </el-button>
              <el-popconfirm
                title="确定删除此篇草稿吗？"
                confirm-button-text="删除"
                cancel-button-text="取消"
                confirm-button-type="danger"
                @confirm="handleDeleteDraft(item.id)"
              >
                <template #reference>
                  <el-button size="small" type="danger" text>删除</el-button>
                </template>
              </el-popconfirm>
            </div>
          </div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script lang="ts" setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import HwEditor from '/@/components/HwEditor/index.vue'
import { getEditorDrafts, saveEditorDraft, deleteEditorDraft } from '/@/api/hwadmin'
import dayjs from 'dayjs'
import { sanitizeHtml } from '/@/utils/sanitizeHtml'

defineOptions({
  name: 'EditorWorkbench',
})

const hwEditorRef = ref<InstanceType<typeof HwEditor>>()
const draftTitle = ref('欢迎使用 HwAdmin 富文本编辑器')
const currentDraftId = ref<number | null>(null)
const editorContent = ref('')
const saving = ref(false)
const lastSavedTime = ref<number | null>(null)
const isSourceMode = ref(false)
const sourceHtml = ref('')
const activeContent = computed(() => isSourceMode.value ? sourceHtml.value : editorContent.value)
const safeEditorContent = computed(() => sanitizeHtml(activeContent.value))
const showMobilePreview = ref(false)
const showDraftDrawer = ref(false)
const loadingDrafts = ref(false)
const draftList = ref<any[]>([])

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

const currentTimeText = computed(() => dayjs().format('YYYY-MM-DD HH:mm'))

const lastSavedText = computed(() => {
  if (saving.value) return '正在保存...'
  if (!lastSavedTime.value) return '未保存'
  return `已保存于 ${dayjs(lastSavedTime.value).format('HH:mm:ss')}`
})

// 计算字数、段落数、图片数
const wordCount = computed(() => {
  const plainText = activeContent.value.replace(/<[^>]+>/g, '').trim()
  return plainText.length
})

const paragraphCount = computed(() => {
  const matches = activeContent.value.match(/<p\b[^>]*>/gi)
  return matches ? matches.length : 0
})

const imageCount = computed(() => {
  const matches = activeContent.value.match(/<img\b[^>]*>/gi)
  return matches ? matches.length : 0
})

const handleContentChange = () => {
  // 内容变更响应
}

// 切换源码模式
const toggleSourceMode = () => {
  if (isSourceMode.value) {
    editorContent.value = sanitizeHtml(sourceHtml.value)
  } else {
    sourceHtml.value = editorContent.value
  }
  isSourceMode.value = !isSourceMode.value
  if (!isSourceMode.value) {
    ElMessage.success('已应用 HTML 源代码并切回可视化排版模式')
  }
}

// 打开草稿箱抽屉
const openDraftDrawer = () => {
  showDraftDrawer.value = true
  fetchDrafts()
}

// 读取草稿列表
const fetchDrafts = async () => {
  loadingDrafts.value = true
  try {
    const res = await getEditorDrafts()
    const list = res?.data || []
    draftList.value = Array.isArray(list) ? list : []
  } catch (e) {
    console.error('获取草稿失败', e)
  } finally {
    loadingDrafts.value = false
  }
}

// 载入草稿到工作区
const loadDraftToWorkbench = (item: any) => {
  currentDraftId.value = item.id
  draftTitle.value = item.title || '无标题草稿'
  editorContent.value = item.content || ''
  if (isSourceMode.value) sourceHtml.value = editorContent.value
  lastSavedTime.value = item.update_time ? item.update_time * 1000 : Date.now()
  showDraftDrawer.value = false
  ElMessage.success(`已载入草稿「${item.title}」`)
}

// 保存草稿
const handleSaveDraft = async (silent = false) => {
  if (saving.value) return
  saving.value = true
  try {
    const payload: any = {
      title: draftTitle.value || '无标题草稿',
      content: safeEditorContent.value,
      word_count: wordCount.value,
    }
    if (currentDraftId.value) {
      payload.id = currentDraftId.value
    }
    const res = await saveEditorDraft(payload)
    const saved = res?.data
    if (saved?.id) {
      currentDraftId.value = saved.id
    }
    lastSavedTime.value = Date.now()
    if (!silent) {
      ElMessage.success('草稿已成功保存！')
    }
    fetchDrafts()
  } catch (e) {
    console.error('保存草稿失败', e)
  } finally {
    saving.value = false
  }
}

// 删除草稿
const handleDeleteDraft = async (id: number) => {
  try {
    await deleteEditorDraft(id)
    ElMessage.success('草稿已删除')
    if (currentDraftId.value === id) {
      currentDraftId.value = null
    }
    fetchDrafts()
  } catch (e) {
    console.error('删除草稿失败', e)
  }
}

// 快捷模板填充
const handleApplyTemplate = (type: string) => {
  const templates: Record<string, { title: string; content: string }> = {
    release: {
      title: 'HwAdmin 1.0 官方版本发布公告',
      content: `<h2>🎉 HwAdmin 1.0 正式版发布：新一代双数据库智能后台框架</h2>
<p>我们非常自豪地宣布：<strong>HwAdmin 1.0 正式发布！</strong>历经数十个迭代与高标准企业级测试，致力于为开发者提供开箱即用的现代化极致开发体验。</p>
<hr/>
<h3>🚀 重磅特性一览</h3>
<ul>
  <li><strong>双数据库原生兼容</strong>：同一套模型与迁移代码，原生无缝适配 <code>MySQL 8.0+</code> 与 <code>PostgreSQL 16+</code>，支持一键切换。</li>
  <li><strong>零侵入插件体系</strong>：独立生命周期管理、清单隔离、动态路由分发与资产自动发布，彻底告别旧版文件覆盖式污染。</li>
  <li><strong>极速通用 CRUD 引擎</strong>：集成万能查询协议 <code>QueryParams</code>，内置字段投影、多表关联与智能防 SQL 盲注。</li>
  <li><strong>现代化前端架构</strong>：基于 Vue3 + Vite + TypeScript + Pinia，搭配精美组件与原生流畅交互。</li>
</ul>
<blockquote><p>欢迎前往官方代码库查阅最新文档并开启极速开发之旅！</p></blockquote>`,
    },
    tech: {
      title: 'HwAdmin 技术架构与底层设计白皮书',
      content: `<h2>📘 HwAdmin 技术架构与底层设计白皮书</h2>
<p class="lead">本文档旨在阐明 HwAdmin 架构分层、安全防护基准、生命周期调度及零侵入设计理念。</p>
<h3>1. 核心分层模型</h3>
<table border="1" cellpadding="8" style="width: 100%; border-collapse: collapse;">
  <thead>
    <tr style="background: #f1f5f9;">
      <th>层次</th>
      <th>命名空间 / 路径</th>
      <th>职责定位</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>框架基座</td>
      <td><code>core\\*</code></td>
      <td>统一响应协议、RBAC 鉴权基类、异常捕获、插件调度器</td>
    </tr>
    <tr>
      <td>业务应用</td>
      <td><code>app\\adminapi</code> / <code>app\\api</code></td>
      <td>后台管理端接口、前台会员开放接口</td>
    </tr>
    <tr>
      <td>独立插件</td>
      <td><code>server\\plugins\\*</code></td>
      <td>可插拔业务模块，拥有自闭环生命周期与数据表</td>
    </tr>
  </tbody>
</table>
<p><br/>遵循以上架构规范，可在保障基座稳定性的前提下，实现高度智能化的业务交付。</p>`,
    },
    notice: {
      title: '系统升级与例行维护通知',
      content: `<h2>⚠️ 关于服务器例行升级与系统优化的重要通知</h2>
<p>尊敬的各位用户与系统管理员：</p>
<p>为了进一步提升系统数据吞吐性能与网络安全防御等级，平台将于 <strong>本周六凌晨 02:00 - 05:00</strong> 进行底层数据库读写分离扩容与安全补丁更新。</p>
<h3>📌 维护期间影响事项</h3>
<ol>
  <li>维护期间管理后台接口将暂停写入服务约 15 分钟；</li>
  <li>前台只读查询服务不受影响；</li>
  <li>维护完毕后将自动恢复全部服务，无需人工介入。</li>
</ol>
<p>感谢您的理解与支持！如遇任何异常情况，请随时联系系统运维团队。</p>`,
    },
    guide: {
      title: 'HwAdmin 开发者新手入门指南',
      content: `<h2>📚 欢迎阅读 HwAdmin 开发者入门指南</h2>
<p>只需简单三步，即可在本地搭建并启动全套智能开发环境：</p>
<pre><code># 1. 检查本地环境依赖
bash scripts/wsl-dev.sh doctor

# 2. 一键配置并启动 SQLite 本地智能开发库（免装 MySQL）
bash scripts/wsl-dev.sh install-sqlite

# 3. 运行全量冒烟测试确保基线健康
bash scripts/wsl-dev.sh smoke</code></pre>
<p>祝您编码愉快，产出高效且优雅的代码！</p>`,
    },
  }

  const selected = templates[type]
  if (!selected) return

  ElMessageBox.confirm(`载入模板将替换当前编辑器的内容，确定继续吗？`, '模板载入提醒', {
    confirmButtonText: '确认替换',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(() => {
    draftTitle.value = selected.title
    editorContent.value = selected.content
    if (isSourceMode.value) sourceHtml.value = editorContent.value
    currentDraftId.value = null
    ElMessage.success(`已载入「${selected.title}」模板`)
  })
}

// 快捷键监听：Ctrl+S 保存草稿
const handleKeydown = (e: KeyboardEvent) => {
  if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
    e.preventDefault()
    handleSaveDraft(false)
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
  // 初始化默认欢迎文章
  fetchDrafts().then(() => {
    if (draftList.value.length > 0) {
      const first = draftList.value[0]
      currentDraftId.value = first.id
      draftTitle.value = first.title
      editorContent.value = first.content || ''
      if (isSourceMode.value) sourceHtml.value = editorContent.value
      lastSavedTime.value = first.update_time ? first.update_time * 1000 : Date.now()
    } else {
      handleApplyTemplate('release')
    }
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<style lang="scss" scoped>
.editor-workbench-container {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: calc(100vh - 120px);

  .header-card {
    border-radius: 8px;

    :deep(.el-card__body) {
      padding: 12px 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
      min-width: 320px;

      .title-input {
        max-width: 420px;
        font-weight: 600;
        font-size: 15px;
      }

      .draft-tag {
        font-weight: 500;
      }

      .save-status-text {
        font-size: 13px;
        color: var(--el-text-color-secondary);
        display: flex;
        align-items: center;
        gap: 4px;

        .is-loading {
          animation: rotating 2s linear infinite;
        }
      }
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 10px;

      .draft-badge {
        :deep(.el-badge__content) {
          top: 6px;
          right: 2px;
        }
      }
    }
  }

  .workbench-card {
    border-radius: 8px;
    flex: 1;
    display: flex;
    flex-direction: column;

    :deep(.el-card__body) {
      padding: 14px;
      display: flex;
      flex-direction: column;
      flex: 1;
    }

    .source-editor-wrapper {
      display: flex;
      flex-direction: column;
      gap: 10px;

      .source-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        color: var(--el-color-warning);
        background: var(--el-color-warning-light-9);
        padding: 8px 12px;
        border-radius: 4px;
      }

      .source-textarea {
        font-family: 'Fira Code', Menlo, Monaco, Consolas, Courier New, monospace;
        font-size: 13px;
        line-height: 1.5;
      }
    }

    .editor-wrapper {
      flex: 1;
      min-height: 400px;
    }

    .editor-statusbar {
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px dashed var(--el-border-color-lighter);
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
      color: var(--el-text-color-secondary);

      .status-left {
        display: flex;
        gap: 20px;

        .status-item {
          display: flex;
          align-items: center;
          gap: 4px;

          strong {
            color: var(--el-color-primary);
          }
        }
      }

      .status-hint {
        font-size: 12px;
        color: var(--el-text-color-placeholder);
      }
    }
  }

  // 移动端模拟手机外壳
  .mobile-phone-frame {
    width: 360px;
    height: 680px;
    margin: 0 auto;
    background: #111827;
    border-radius: 46px;
    padding: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), inset 0 0 2px 2px rgba(255, 255, 255, 0.2);
    position: relative;

    .phone-notch {
      width: 140px;
      height: 20px;
      background: #111827;
      position: absolute;
      top: 12px;
      left: 50%;
      transform: translateX(-50%);
      border-bottom-left-radius: 14px;
      border-bottom-right-radius: 14px;
      z-index: 100;
    }

    .phone-screen {
      width: 100%;
      height: 100%;
      background: #ffffff;
      border-radius: 36px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      position: relative;

      .phone-status-bar {
        height: 38px;
        padding: 8px 24px 0 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        color: #1f2937;

        .icons {
          display: flex;
          gap: 6px;
        }
      }

      .phone-nav-bar {
        height: 42px;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 16px;

        .title {
          font-size: 14px;
          font-weight: 600;
          color: #111827;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }

      .phone-content-scroll {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        color: #1f2937;

        .article-title {
          font-size: 19px;
          font-weight: 700;
          line-height: 1.4;
          margin: 0 0 8px 0;
          color: #111827;
        }

        .article-meta {
          display: flex;
          gap: 12px;
          font-size: 12px;
          color: #6b7280;
          margin-bottom: 16px;
          padding-bottom: 12px;
          border-bottom: 1px solid #f3f4f6;
        }

        .article-html-body {
          font-size: 14px;
          line-height: 1.7;

          :deep(img) {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
          }

          :deep(p) {
            margin: 10px 0;
          }

          :deep(blockquote) {
            margin: 10px 0;
            padding: 8px 12px;
            background: #f8fafc;
            border-left: 3px solid #3b82f6;
            color: #475569;
            font-size: 13px;
          }

          :deep(pre) {
            background: #1e293b;
            color: #f8fafc;
            padding: 12px;
            border-radius: 6px;
            font-size: 12px;
            overflow-x: auto;
          }

          :deep(table) {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;

            td, th {
              border: 1px solid #e2e8f0;
              padding: 6px 8px;
            }
          }
        }
      }
    }
  }

  // 草稿箱抽屉
  .draft-drawer-container {
    display: flex;
    flex-direction: column;
    height: 100%;

    .drawer-header-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
      font-size: 13px;
      color: var(--el-text-color-secondary);
    }

    .draft-item-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      overflow-y: auto;

      .draft-card-item {
        border: 1px solid var(--el-border-color-lighter);
        border-radius: 6px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.2s ease;

        &:hover {
          border-color: var(--el-color-primary-light-5);
          background: var(--el-color-primary-light-9);
        }

        &.is-active {
          border-color: var(--el-color-primary);
          background: var(--el-color-primary-light-9);
        }

        .item-title {
          font-weight: 600;
          font-size: 14px;
          color: var(--el-text-color-primary);
          margin-bottom: 6px;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .item-meta {
          display: flex;
          justify-content: space-between;
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin-bottom: 8px;
        }

        .item-actions {
          display: flex;
          justify-content: flex-end;
          gap: 6px;
          border-top: 1px dashed var(--el-border-color-lighter);
          padding-top: 6px;
        }
      }
    }
  }
}

@keyframes rotating {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
