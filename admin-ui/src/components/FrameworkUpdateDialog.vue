<template>
  <el-dialog
    :model-value="visible"
    title="升级 HwAdmin"
    width="min(680px, 94vw)"
    destroy-on-close
    class="registry-dialog"
    @update:model-value="$emit('update:visible', $event)"
  >
    <template #header>
      <div class="dialog-header-title">
        <span>升级 HwAdmin</span>
        <el-tag v-if="isBetaRelease" type="warning" effect="dark" size="small">
          内测版 (Beta)
        </el-tag>
      </div>
    </template>

    <div class="update-dialog-body">
      <el-alert
        v-if="isBetaRelease"
        title="内测版 (Beta) 风险提示"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom: 12px;"
      >
        <template #default>
          即将升级至内测版 (Beta)（v{{ frameworkInfo.latest_version }}），可能包含尚未完全稳定的试验性改动。强烈建议先在测试站验证功能与兼容性，确认稳定后再升级生产环境。
        </template>
      </el-alert>

      <el-alert
        title="升级前会自动全量备份"
        type="success"
        :closable="false"
        show-icon
        style="margin-bottom: 14px;"
      >
        <template #default>
          将先备份当前版本（v{{ frameworkInfo.current_version }}）的后端核心、<code>app/</code> 核心文件及 <code>public/admin/</code> 前端产物，再完成数据库迁移与缓存清理，有问题可随时一键回滚。
        </template>
      </el-alert>

      <el-descriptions :column="2" border size="small" style="margin-bottom: 14px;">
        <el-descriptions-item label="当前版本">v{{ frameworkInfo.current_version }}</el-descriptions-item>
        <el-descriptions-item label="新版本">
          <span>v{{ frameworkInfo.latest_version }}</span>
          <el-tag
            v-if="isBetaRelease"
            type="warning"
            effect="dark"
            size="small"
            style="margin-left: 8px;"
          >
            内测版 (Beta)
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="更新通道">
          <el-tag
            :type="frameworkInfo.update_channel === 'beta' ? 'warning' : 'success'"
            size="small"
            effect="plain"
          >
            {{ updateChannelLabel }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="发布日期">{{ frameworkInfo.release_date || '-' }}</el-descriptions-item>
        <el-descriptions-item label="前端环境识别" :span="2">
          <div class="frontend-status-cell">
            <el-tag
              :type="isCustomFrontend ? 'warning' : 'success'"
              size="small"
              effect="light"
            >
              {{ frontendStatusLabel }}
            </el-tag>
            <span v-if="frontendStatusReason" class="frontend-status-reason">
              {{ frontendStatusReason }}
            </span>
          </div>
        </el-descriptions-item>
      </el-descriptions>

      <!-- 更新范围模式选择 -->
      <div class="update-mode-box">
        <div class="update-mode-title">
          <vab-icon icon="shield-check-line" />
          <span>选择更新方式</span>
        </div>
        <el-radio-group v-model="updateMode" class="update-mode-group">
          <label
            class="update-mode-option"
            :class="{ 'is-active': updateMode === 'preserve_frontend' }"
          >
            <el-radio value="preserve_frontend">
              <span class="option-head">
                <strong>仅更新后端内核（保留当前前端 public/admin/）</strong>
                <el-tag v-if="isCustomFrontend" type="warning" size="small" effect="dark">推荐二开站点</el-tag>
              </span>
              <div class="option-desc">
                仅更新后端 <code>core/</code>、<code>app/</code> 核心代码与数据库迁移，强制跳过 <code>public/admin/</code>，完好保留您自定义编译或二开的管理后台界面。
              </div>
            </el-radio>
          </label>

          <label
            class="update-mode-option"
            :class="{ 'is-active': updateMode === 'full_sync', 'is-danger-active': isCustomFrontend && updateMode === 'full_sync' }"
          >
            <el-radio value="full_sync">
              <span class="option-head">
                <strong>同步更新前后端（含官方管理后台 UI）</strong>
                <el-tag v-if="!isCustomFrontend" type="success" size="small" effect="dark">推荐纯净站点</el-tag>
              </span>
              <div class="option-desc">
                同时升级后端内核与官方最新 <code>public/admin/</code> 界面产物（适用于未改动管理后台源码的官方纯净站点）。
              </div>
            </el-radio>
          </label>
        </el-radio-group>
      </div>

      <el-alert
        v-if="isCustomFrontend && updateMode === 'full_sync'"
        title="警告：当前站点检测为二开/自定义前端，覆盖后将替换您自己的后台界面！"
        type="error"
        :closable="false"
        show-icon
        style="margin-bottom: 14px;"
      >
        <template #default>
          您当前站点包含二开或本地重新编译的 <code>public/admin/</code> 前端产物。继续“同步更新前后端”将会把 <code>public/admin/</code> 替换为官方构建版本；系统会在覆盖前自动完整备份当前前端与后端文件，如覆盖后不符合预期可随时通过“备份与回滚”一键无损恢复。
        </template>
      </el-alert>

      <div class="changelog-box">
        <div class="changelog-title">
          <vab-icon icon="file-list-3-line" />
          <span>更新内容</span>
        </div>
        <ul class="changelog-list">
          <li v-for="(item, idx) in frameworkInfo.changelog" :key="idx">{{ item }}</li>
        </ul>
      </div>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <el-button @click="$emit('update:visible', false)">稍后</el-button>
        <el-button :type="confirmButtonType" :loading="updating" @click="handleConfirm">
          <vab-icon icon="upload-cloud-line" />
          {{ confirmButtonText }}
        </el-button>
      </span>
    </template>
  </el-dialog>
</template>

<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { updateSystemFramework } from '/@/api/hwadmin'

export interface FrontendStatusInfo {
  mode: 'official' | 'custom' | 'legacy_custom_suspected' | 'legacy_clean' | string
  is_custom: boolean
  preserve_frontend_default: boolean
  label: string
  reason: string
}

export interface FrameworkReleaseInfo {
  name?: string
  current_version: string
  latest_version: string
  has_update: boolean
  update_channel?: 'stable' | 'beta'
  release_channel?: 'stable' | 'beta'
  release_date?: string
  changelog: string[]
  download_url?: string
  patch_sha256?: string
  core_download_url?: string
  core_patch_sha256?: string
  full_download_url?: string
  full_patch_sha256?: string
  frontend_status?: FrontendStatusInfo
  min_php?: string
}

const props = defineProps<{
  visible: boolean
  frameworkInfo: FrameworkReleaseInfo
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'updated'): void
}>()

const updating = ref(false)
const updateMode = ref<'preserve_frontend' | 'full_sync'>('full_sync')

const isBetaRelease = computed(() => props.frameworkInfo.release_channel === 'beta')
const updateChannelLabel = computed(() =>
  props.frameworkInfo.update_channel === 'beta' ? '内测版 (Beta)' : '稳定版 (Stable)'
)

const isCustomFrontend = computed(() => {
  const status = props.frameworkInfo.frontend_status
  if (!status) return false
  return Boolean(status.preserve_frontend_default || status.is_custom)
})

const frontendStatusLabel = computed(() => {
  return props.frameworkInfo.frontend_status?.label || '未检测（默认按纯净站处理）'
})

const frontendStatusReason = computed(() => {
  return props.frameworkInfo.frontend_status?.reason || ''
})

const syncDefaultUpdateMode = () => {
  updateMode.value = props.frameworkInfo.frontend_status?.preserve_frontend_default
    ? 'preserve_frontend'
    : 'full_sync'
}

watch(
  () => [props.visible, props.frameworkInfo.frontend_status?.preserve_frontend_default],
  ([visible]) => {
    if (visible) {
      syncDefaultUpdateMode()
    }
  },
  { immediate: true }
)

const confirmButtonType = computed(() => {
  if (isCustomFrontend.value && updateMode.value === 'full_sync') return 'danger'
  if (isBetaRelease.value) return 'warning'
  return updateMode.value === 'preserve_frontend' ? 'primary' : 'danger'
})

const confirmButtonText = computed(() => {
  const betaPrefix = isBetaRelease.value ? '内测版 · ' : ''
  return updateMode.value === 'preserve_frontend'
    ? `${betaPrefix}仅更新后端内核`
    : `${betaPrefix}同步更新前后端`
})

const handleConfirm = async () => {
  updating.value = true
  try {
    const preserveFrontend = updateMode.value === 'preserve_frontend'
    let targetUrl: string | undefined
    let targetSha256: string | undefined

    if (preserveFrontend) {
      const useCorePackage = Boolean(props.frameworkInfo.core_download_url)
      targetUrl = useCorePackage
        ? props.frameworkInfo.core_download_url
        : props.frameworkInfo.download_url
      targetSha256 = useCorePackage
        ? props.frameworkInfo.core_patch_sha256 || props.frameworkInfo.patch_sha256
        : props.frameworkInfo.patch_sha256
    } else {
      const useFullPackage = Boolean(props.frameworkInfo.full_download_url)
      targetUrl = useFullPackage
        ? props.frameworkInfo.full_download_url
        : props.frameworkInfo.download_url
      targetSha256 = useFullPackage
        ? props.frameworkInfo.full_patch_sha256
        : props.frameworkInfo.patch_sha256
    }

    const payload: {
      download_url?: string
      patch_sha256?: string
      preserve_frontend: boolean
    } = {
      download_url: targetUrl,
      preserve_frontend: preserveFrontend,
    }
    if (targetSha256) {
      payload.patch_sha256 = targetSha256
    }

    const res: any = await updateSystemFramework(payload)
    if (res?.code === 1) {
      ElMessage.success(res.msg || '框架升级成功，正在刷新页面...')
      emit('update:visible', false)
      emit('updated')
      window.setTimeout(async () => {
        try {
          if (typeof caches !== 'undefined' && caches?.keys) {
            const keys = await caches.keys()
            await Promise.all(keys.map((k) => caches.delete(k)))
          }
        } catch {
          // 忽略浏览器 CacheStorage 清理异常
        }
        window.location.reload()
      }, 800)
    } else {
      ElMessage.error(res?.msg || '升级失败')
    }
  } catch (e: any) {
    ElMessage.error('升级失败：' + (e?.message || '网络错误'))
  } finally {
    updating.value = false
  }
}
</script>

<style scoped lang="scss">
.dialog-header-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.update-dialog-body {
  .frontend-status-cell {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;

    .frontend-status-reason {
      font-size: 12px;
      color: #64748b;
      line-height: 1.5;
    }
  }

  .update-mode-box {
    margin-bottom: 14px;

    .update-mode-title {
      display: flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      color: #1e293b;
      font-size: 13px;
      margin-bottom: 8px;
    }

    .update-mode-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
      width: 100%;
    }

    .update-mode-option {
      display: block;
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #ffffff;
      cursor: pointer;
      transition: all 0.2s ease;

      &.is-active {
        border-color: var(--el-color-primary);
        background: #f0f7ff;
      }

      &.is-danger-active {
        border-color: var(--el-color-danger);
        background: #fef2f2;
      }

      :deep(.el-radio) {
        align-items: flex-start;
        height: auto;
        width: 100%;
        white-space: normal;
      }

      :deep(.el-radio__input) {
        margin-top: 2px;
      }

      :deep(.el-radio__label) {
        width: 100%;
        white-space: normal;
      }

      .option-head {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #0f172a;
      }

      .option-desc {
        margin-top: 4px;
        font-size: 12px;
        color: #475569;
        line-height: 1.5;
      }
    }
  }

  .changelog-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 16px;

    .changelog-title {
      display: flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 8px;
      font-size: 13px;
    }

    .changelog-list {
      margin: 0;
      padding-left: 20px;
      font-size: 12px;
      color: #475569;
      line-height: 1.8;
    }
  }
}
</style>
