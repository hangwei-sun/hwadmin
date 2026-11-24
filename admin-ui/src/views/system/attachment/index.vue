<template>
  <div class="attachment-management-container">
    <!-- 筛选与上传工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-row">
        <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
          <el-form-item label="文件名">
            <el-input
              v-model="queryForm.search"
              placeholder="搜索附件文件名/后缀"
              clearable
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">
              <el-icon><vab-icon icon="search-line" /></el-icon>
              查询
            </el-button>
            <el-button @click="handleReset">
              <el-icon><vab-icon icon="refresh-line" /></el-icon>
              重置
            </el-button>
            <el-upload
              class="inline-upload-btn"
              action="#"
              :http-request="customUpload"
              :before-upload="handleBeforeUpload"
              :show-file-list="false"
              multiple
            >
              <el-button type="success">
                <el-icon><vab-icon icon="upload-cloud-2-line" /></el-icon>
                上传附件
              </el-button>
            </el-upload>
            <el-button
              type="danger"
              plain
              :disabled="selectedRows.length === 0"
              @click="handleBatchDelete"
            >
              <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
              批量删除 ({{ selectedRows.length }})
            </el-button>
          </el-form-item>
        </el-form>
      </div>
      <div class="upload-limit-tip">
        支持 {{ uploadConfig.allowExts }}，单文件最大 {{ uploadConfig.maxSizeMB }}MB，系统自动执行 SHA1 去重与安全校验
      </div>
    </el-card>

    <!-- 附件数据表格 -->
    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" align="center" />
        <el-table-column prop="id" label="ID" width="80" align="center" sortable />
        <el-table-column label="预览" width="90" align="center">
          <template #default="{ row }">
            <!-- 1. 图片类型 -->
            <el-image
              v-if="isImage(row)"
              :src="resolveUrl(row.fullurl || row.url)"
              :preview-src-list="[resolveUrl(row.fullurl || row.url)]"
              preview-teleported
              fit="cover"
              class="thumb-img"
            >
              <template #error>
                <div class="thumb-fallback image-error">
                  <el-icon><vab-icon icon="image-line" /></el-icon>
                </div>
              </template>
            </el-image>

            <!-- 2. 视频类型 -->
            <div
              v-else-if="isVideo(row)"
              class="thumb-box video-box"
              title="点击播放视频"
              @click="playVideo(row)"
            >
              <el-icon class="play-icon"><vab-icon icon="play-circle-line" /></el-icon>
              <span class="type-tag">{{ (row.imagetype || row.ext || 'VIDEO').toUpperCase() }}</span>
            </div>

            <!-- 3. 音频类型 -->
            <div v-else-if="isAudio(row)" class="thumb-box audio-box">
              <el-icon><vab-icon icon="music-2-line" /></el-icon>
              <span class="type-tag">{{ (row.imagetype || row.ext || 'AUDIO').toUpperCase() }}</span>
            </div>

            <!-- 4. 文档类型 -->
            <div v-else-if="isDoc(row)" class="thumb-box doc-box">
              <el-icon><vab-icon icon="file-text-line" /></el-icon>
              <span class="type-tag">{{ (row.imagetype || row.ext || 'DOC').toUpperCase() }}</span>
            </div>

            <!-- 5. 压缩包类型 -->
            <div v-else-if="isArchive(row)" class="thumb-box archive-box">
              <el-icon><vab-icon icon="folder-zip-line" /></el-icon>
              <span class="type-tag">{{ (row.imagetype || row.ext || 'ZIP').toUpperCase() }}</span>
            </div>

            <!-- 6. 兜底通用文件 -->
            <div v-else class="thumb-box file-box">
              <el-icon><vab-icon icon="file-line" /></el-icon>
              <span class="type-tag">{{ (row.imagetype || row.ext || 'FILE').toUpperCase() }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="filename" label="文件名" min-width="180" show-overflow-tooltip />
        <el-table-column prop="filesize" label="大小" width="100" align="center">
          <template #default="{ row }">
            {{ formatBytes(row.filesize) }}
          </template>
        </el-table-column>
        <el-table-column prop="imagetype" label="格式" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small" type="info">{{ row.imagetype || 'file' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sha1" label="SHA1 哈希" min-width="170" show-overflow-tooltip>
          <template #default="{ row }">
            <code>{{ row.sha1 || '-' }}</code>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="上传时间" width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="186" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="copyUrl(row.fullurl || row.url)">复制链接</el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.limit"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <!-- 视频播放弹窗 -->
    <el-dialog
      v-model="videoDialogVisible"
      :title="currentVideo.filename || currentVideo.name || '视频播放预览'"
      width="720px"
      destroy-on-close
      @closed="currentVideo = {}"
    >
      <div class="video-player-container">
        <video
          :src="resolveUrl(currentVideo.fullurl || currentVideo.url)"
          controls
          autoplay
          class="video-player-element"
        ></video>
      </div>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getAttachmentList, uploadAttachment, delAttachment, getConfigList } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'AttachmentManagement',
})

const loading = ref(false)
const selectedRows = ref<any[]>([])
const videoDialogVisible = ref(false)
const currentVideo = ref<any>({})

const uploadConfig = reactive({
  allowExts: 'jpg,jpeg,png,gif,webp,bmp,ico,mp4,webm,mov,m4v,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv,md',
  maxSizeMB: 50,
})

const fetchUploadConfig = async () => {
  try {
    const res = await getConfigList()
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list)) {
      const extCfg = list.find((item: any) => item.name === 'upload_allow_ext')
      const sizeCfg = list.find((item: any) => item.name === 'upload_max_size')
      if (extCfg?.value) {
        uploadConfig.allowExts = String(extCfg.value)
      }
      if (sizeCfg?.value) {
        const val = Number(sizeCfg.value)
        if (val > 0) {
          uploadConfig.maxSizeMB = val <= 1024 ? val : Math.round(val / 1024 / 1024)
        }
      }
    }
  } catch {
    // 忽略加载错误
  }
}

const playVideo = (row: any) => {
  currentVideo.value = row
  videoDialogVisible.value = true
}

const resolveUrl = (url: string) => {
  if (!url) return ''
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:') || url.startsWith('blob:')) {
    return url
  }
  return url.startsWith('/') ? url : `/${url}`
}

const isImage = (row: any) => {
  const mime = (row?.mimetype || row?.mime || '').toLowerCase()
  const ext = (row?.imagetype || row?.ext || '').toLowerCase()
  const url = (row?.url || '').toLowerCase()
  return (
    mime.startsWith('image/') ||
    ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'svg'].includes(ext) ||
    /\.(jpg|jpeg|png|gif|bmp|webp|ico|svg)(\?.*)?$/i.test(url)
  )
}

const isVideo = (row: any) => {
  const mime = (row?.mimetype || row?.mime || '').toLowerCase()
  const ext = (row?.imagetype || row?.ext || '').toLowerCase()
  const url = (row?.url || '').toLowerCase()
  return (
    mime.startsWith('video/') ||
    ['mp4', 'webm', 'mov', 'm4v', 'ogg', 'avi', 'mkv', 'flv'].includes(ext) ||
    /\.(mp4|webm|mov|m4v|ogg|avi|mkv|flv)(\?.*)?$/i.test(url)
  )
}

const isAudio = (row: any) => {
  const mime = (row?.mimetype || row?.mime || '').toLowerCase()
  const ext = (row?.imagetype || row?.ext || '').toLowerCase()
  const url = (row?.url || '').toLowerCase()
  return (
    mime.startsWith('audio/') ||
    ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'].includes(ext) ||
    /\.(mp3|wav|ogg|aac|flac|m4a)(\?.*)?$/i.test(url)
  )
}

const isDoc = (row: any) => {
  const ext = (row?.imagetype || row?.ext || '').toLowerCase()
  return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md'].includes(ext)
}

const isArchive = (row: any) => {
  const ext = (row?.imagetype || row?.ext || '').toLowerCase()
  return ['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)
}

const queryForm = reactive({
  search: '',
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const tableData = ref<any[]>([])

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const formatBytes = (bytes: number) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}

const handleBeforeUpload = (rawFile: File) => {
  if (uploadConfig.allowExts) {
    const fileName = rawFile.name || ''
    const ext = fileName.includes('.') ? fileName.split('.').pop()?.toLowerCase() || '' : ''
    const allowed = uploadConfig.allowExts
      .split(',')
      .map((s) => s.trim().toLowerCase())
      .filter(Boolean)
    if (ext && allowed.length > 0 && !allowed.includes(ext)) {
      ElMessage.error(`不支持的文件格式 .${ext}，仅支持: ${uploadConfig.allowExts}`)
      return false
    }
  }

  const maxBytes = (uploadConfig.maxSizeMB || 50) * 1024 * 1024
  if (rawFile.size > maxBytes) {
    ElMessage.error(`文件大小 (${formatBytes(rawFile.size)}) 超出限制，单文件最大支持 ${uploadConfig.maxSizeMB}MB`)
    return false
  }

  return true
}

const customUpload = async (options: any) => {
  const { file } = options
  try {
    const res = await uploadAttachment(file)
    ElMessage.success('附件上传成功')
    fetchList()
    return res
  } catch (e: any) {
    const errorMsg = e?.msg || e?.message || e?.response?.data?.msg || '上传失败，请检查网络或后端配置'
    ElMessage.error(errorMsg)
  }
}

const copyUrl = async (url: string) => {
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    ElMessage.success('附件链接已复制到剪贴板')
  } catch {
    ElMessage.info(`链接: ${url}`)
  }
}

const fetchList = async () => {
  loading.value = true
  try {
    const params: any = {
      page: pagination.page,
      limit: pagination.limit,
    }
    if (queryForm.search) params.search = queryForm.search
    const res = await getAttachmentList(params)
    tableData.value = res?.data?.list || []
    pagination.total = res?.data?.total || 0
  } catch (e) {
    console.error('获取附件列表失败', e)
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  pagination.page = 1
  fetchList()
}

const handleReset = () => {
  queryForm.search = ''
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除附件 "${row.filename}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAttachment(row.id)
      ElMessage.success('附件已删除')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 个附件吗？`, '安全警告', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAttachment(ids)
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

onMounted(() => {
  fetchList()
  fetchUploadConfig()
})
</script>

<style lang="scss" scoped>
.attachment-management-container {
  padding: 20px;

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .toolbar-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .query-form {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 12px;

      :deep(.el-form-item) {
        margin-bottom: 0;
        margin-right: 0;
      }

      :deep(.el-form-item__content) {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
      }

      .inline-upload-btn {
        display: inline-flex;
        align-items: center;
      }
    }

    .upload-limit-tip {
      margin-top: 10px;
      padding-top: 8px;
      border-top: 1px dashed var(--el-border-color-lighter);
      font-size: 12px;
      line-height: 1.5;
      color: var(--el-text-color-secondary);
      word-break: break-all;
    }
  }

  .table-card {
    border-radius: 8px;

    .thumb-img {
      width: 44px;
      height: 44px;
      border-radius: 4px;
      border: 1px solid var(--el-border-color-lighter);
      vertical-align: middle;
    }

    .thumb-fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      background: var(--el-fill-color-light);
      color: var(--el-text-color-secondary);
      font-size: 18px;
    }

    .thumb-box {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      margin: 0 auto;
      border-radius: 4px;
      font-size: 18px;
      cursor: default;
      transition: all 0.2s;

      .type-tag {
        font-size: 9px;
        line-height: 1;
        margin-top: 2px;
        font-weight: 600;
        letter-spacing: -0.5px;
      }

      &.video-box {
        background: #fdf6ec;
        color: #e6a23c;
        border: 1px solid #faecd8;
        cursor: pointer;

        &:hover {
          background: #faecd8;
          transform: scale(1.05);

          .play-icon {
            transform: scale(1.15);
          }
        }

        .play-icon {
          font-size: 20px;
          transition: transform 0.2s;
        }
      }

      &.audio-box {
        background: #f0f9eb;
        color: #67c23a;
        border: 1px solid #e1f3d8;
      }

      &.doc-box {
        background: #ecf5ff;
        color: #409eff;
        border: 1px solid #d9ecff;
      }

      &.archive-box {
        background: #f4f4f5;
        color: #909399;
        border: 1px solid #e9e9eb;
      }

      &.file-box {
        background: var(--el-fill-color-light);
        color: var(--el-text-color-secondary);
        border: 1px solid var(--el-border-color-lighter);
      }
    }

    code {
      font-size: 11px;
      color: var(--el-text-color-secondary);
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }

  .video-player-container {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #000;
    border-radius: 6px;
    overflow: hidden;
    min-height: 320px;

    .video-player-element {
      width: 100%;
      max-height: 520px;
      outline: none;
    }
  }
}
</style>
