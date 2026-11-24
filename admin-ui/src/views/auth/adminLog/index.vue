<template>
  <div class="log-management-container">
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索用户名/标题/URL/IP"
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
          <el-button
            type="danger"
            :disabled="selectedRows.length === 0"
            @click="handleBatchDelete"
          >
            <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
            批量删除 ({{ selectedRows.length }})
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

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
        <el-table-column prop="username" label="操作人" width="120" align="center">
          <template #default="{ row }">
            <el-tag size="small">{{ row.username || '系统' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="title" label="操作标题" min-width="160" />
        <el-table-column prop="url" label="请求 URL" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <code>{{ row.url }}</code>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="操作 IP" width="140" align="center" />
        <el-table-column prop="useragent" label="User-Agent" min-width="180" show-overflow-tooltip />
        <el-table-column prop="create_time" label="操作时间" width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="168" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="info" size="small" @click="handleViewDetail(row)">详情</el-button>
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
          :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <!-- 日志详情弹窗 -->
    <el-dialog v-model="detailVisible" title="操作日志详情" width="650px" destroy-on-close>
      <el-descriptions v-if="currentDetail" :column="1" border>
        <el-descriptions-item label="日志 ID">{{ currentDetail.id }}</el-descriptions-item>
        <el-descriptions-item label="管理员 ID / 用户名">
          {{ currentDetail.admin_id }} ({{ currentDetail.username }})
        </el-descriptions-item>
        <el-descriptions-item label="操作标题">{{ currentDetail.title }}</el-descriptions-item>
        <el-descriptions-item label="请求 URL">{{ currentDetail.url }}</el-descriptions-item>
        <el-descriptions-item label="IP 地址">{{ currentDetail.ip }}</el-descriptions-item>
        <el-descriptions-item label="记录时间">{{ formatTime(currentDetail.create_time) }}</el-descriptions-item>
        <el-descriptions-item label="客户端 UA">{{ currentDetail.useragent }}</el-descriptions-item>
        <el-descriptions-item label="请求参数 (Content)">
          <div class="json-code-block">
            <pre>{{ formatJson(currentDetail.content) }}</pre>
          </div>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="primary" @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getAdminLogList, delAdminLog } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'AdminLogList',
})

const loading = ref(false)
const detailVisible = ref(false)
const currentDetail = ref<any>(null)
const selectedRows = ref<any[]>([])

const queryForm = reactive({
  search: '',
})

const pagination = reactive({
  page: 1,
  limit: 15,
  total: 0,
})

const tableData = ref<any[]>([])

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const formatJson = (content: any) => {
  if (!content) return '无请求参数'
  try {
    if (typeof content === 'string') {
      const parsed = JSON.parse(content)
      return JSON.stringify(parsed, null, 2)
    }
    return JSON.stringify(content, null, 2)
  } catch {
    return String(content)
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
    const res = await getAdminLogList(params)
    tableData.value = res?.data?.list || []
    pagination.total = res?.data?.total || 0
  } catch (e) {
    console.error('获取日志列表失败', e)
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

const handleViewDetail = (row: any) => {
  currentDetail.value = row
  detailVisible.value = true
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除此条日志吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAdminLog(row.id)
      ElMessage.success('日志已删除')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条日志吗？`, '安全警告', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAdminLog(ids)
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

onMounted(() => {
  fetchList()
})
</script>

<style lang="scss" scoped>
.log-management-container {
  padding: 20px;

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
    }
  }

  .table-card {
    border-radius: 8px;

    code {
      padding: 2px 6px;
      background: var(--el-fill-color-light);
      border-radius: 4px;
      font-size: 12px;
      color: var(--el-text-color-regular);
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }

  .json-code-block {
    max-height: 240px;
    overflow-y: auto;
    background: var(--el-fill-color-lighter);
    border-radius: 6px;
    padding: 10px;

    pre {
      margin: 0;
      font-family: Consolas, Monaco, monospace;
      font-size: 12px;
      white-space: pre-wrap;
      word-break: break-all;
    }
  }
}
</style>
