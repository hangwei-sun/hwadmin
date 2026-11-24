<template>
  <el-dialog
    :model-value="visible"
    title="升级 HwAdmin"
    width="min(640px, 94vw)"
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
        title="升级前会自动备份"
        type="success"
        :closable="false"
        show-icon
        style="margin-bottom: 16px;"
      >
        <template #default>
          将先备份当前版本（v{{ frameworkInfo.current_version }}），再自动完成数据库迁移与缓存清理。有问题可随时回滚。
        </template>
      </el-alert>

      <el-descriptions :column="2" border size="small" style="margin-bottom: 16px;">
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
        <el-descriptions-item label="更新通道" :span="2">
          <el-tag
            :type="frameworkInfo.update_channel === 'beta' ? 'warning' : 'success'"
            size="small"
            effect="plain"
          >
            {{ updateChannelLabel }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="发布日期">{{ frameworkInfo.release_date || '-' }}</el-descriptions-item>
        <el-descriptions-item label="最低 PHP 版本">&gt;= {{ frameworkInfo.min_php || '8.1' }}</el-descriptions-item>
      </el-descriptions>

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
        <el-button :type="isBetaRelease ? 'warning' : 'danger'" :loading="updating" @click="handleConfirm">
          <vab-icon icon="upload-cloud-line" />
          {{ isBetaRelease ? '确认升级内测版' : '开始升级' }}
        </el-button>
      </span>
    </template>
  </el-dialog>
</template>

<script lang="ts" setup>
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { updateSystemFramework } from '/@/api/hwadmin'

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
const isBetaRelease = computed(() => props.frameworkInfo.release_channel === 'beta')
const updateChannelLabel = computed(() =>
  props.frameworkInfo.update_channel === 'beta' ? '内测版 (Beta)' : '稳定版 (Stable)'
)

const handleConfirm = async () => {
  updating.value = true
  try {
    const res: any = await updateSystemFramework({ download_url: props.frameworkInfo.download_url })
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
