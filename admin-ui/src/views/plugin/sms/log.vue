<template>
  <div class="sms-log-container">
    <!-- 顶部查询与操作栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="手机号码">
          <el-input
            v-model="queryForm.mobile"
            placeholder="搜索手机号"
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>

        <el-form-item label="发送场景">
          <el-select v-model="queryForm.event" placeholder="全部场景" clearable style="width: 140px">
            <el-option label="用户注册" value="register" />
            <el-option label="安全登录" value="login" />
            <el-option label="重置密码" value="reset" />
            <el-option label="测试调试" value="test" />
          </el-select>
        </el-form-item>

        <el-form-item label="发送状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="成功" :value="1" />
            <el-option label="失败" :value="0" />
          </el-select>
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
        </el-form-item>
      </el-form>

      <div class="action-buttons">
        <el-button
          type="danger"
          :disabled="selectedRows.length === 0"
          @click="handleBatchDelete"
        >
          <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
          批量删除 ({{ selectedRows.length }})
        </el-button>
      </div>
    </el-card>

    <!-- 日志数据表格 -->
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
        <el-table-column prop="mobile" label="手机号码" width="140" align="center" />
        <el-table-column prop="event" label="场景" width="120" align="center">
          <template #default="{ row }">
            <el-tag :type="getEventTagType(row.event)" size="small">
              {{ getEventText(row.event) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="template_id" label="模板 ID" width="110" align="center" />
        <el-table-column prop="sign_name" label="签名" width="120" align="center">
          <template #default="{ row }">
            <el-tag type="info" size="small">【{{ row.sign_name }}】</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="code" label="验证码/核心参数" width="130" align="center">
          <template #default="{ row }">
            <span v-if="row.code" class="code-badge">{{ row.code }}</span>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="response" label="第三方响应信息" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="viewFullResponse(row)">
              {{ row.response }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="操作 IP" width="130" align="center" />
        <el-table-column prop="create_time" label="发送时间" width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
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

    <!-- 响应报文详情弹窗 -->
    <el-dialog
      v-model="responseDialogVisible"
      title="接口原始响应报文详情"
      width="600px"
      destroy-on-close
    >
      <div class="response-detail-box">
        <div class="detail-row">
          <span class="label">手机号码：</span>
          <span class="value">{{ currentRecord?.mobile }}</span>
        </div>
        <div class="detail-row">
          <span class="label">发送时间：</span>
          <span class="value">{{ formatTime(currentRecord?.create_time) }}</span>
        </div>
        <div class="detail-row">
          <span class="label">模板参数：</span>
          <span class="value"><code>{{ currentRecord?.params || '-' }}</code></span>
        </div>
        <div class="detail-row json-row">
          <span class="label">响应明细：</span>
          <pre class="json-pre"><code>{{ formattedCurrentResponse }}</code></pre>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getSmsLogList, delSmsLog } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'SmsLogList',
})

const loading = ref(false)
const selectedRows = ref<any[]>([])
const tableData = ref<any[]>([])
const responseDialogVisible = ref(false)
const currentRecord = ref<any>(null)

const queryForm = reactive({
  mobile: '',
  event: undefined as string | undefined,
  status: undefined as number | undefined,
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const getEventText = (event: string) => {
  const map: Record<string, string> = {
    register: '用户注册',
    login: '安全登录',
    reset: '重置密码',
    test: '测试调试',
    bind: '手机绑定',
  }
  return map[event] || event || '未知'
}

const getEventTagType = (event: string) => {
  const map: Record<string, any> = {
    register: 'success',
    login: 'primary',
    reset: 'warning',
    test: 'info',
  }
  return map[event] || 'info'
}

const formattedCurrentResponse = computed(() => {
  if (!currentRecord.value?.response) return '-'
  try {
    const parsed = JSON.parse(currentRecord.value.response)
    return JSON.stringify(parsed, null, 2)
  } catch {
    return currentRecord.value.response
  }
})

const fetchList = async () => {
  loading.value = true
  try {
    const params: any = {
      page: pagination.page,
      limit: pagination.limit,
    }
    if (queryForm.mobile) params.mobile = queryForm.mobile
    if (queryForm.event) params.event = queryForm.event
    if (queryForm.status !== undefined && queryForm.status !== null) {
      params.status = queryForm.status
    }

    const res = await getSmsLogList(params)
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list)) {
      tableData.value = list
      pagination.total = res?.data?.total || list.length
    }
  } catch (e) {
    console.error('获取短信日志失败', e)
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  pagination.page = 1
  fetchList()
}

const handleReset = () => {
  queryForm.mobile = ''
  queryForm.event = undefined
  queryForm.status = undefined
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const viewFullResponse = (row: any) => {
  currentRecord.value = row
  responseDialogVisible.value = true
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除此条短信日志吗？`, '安全警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delSmsLog(row.id)
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
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 条日志吗？`, '安全警告', {
    confirmButtonText: '批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delSmsLog(ids)
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
.sms-log-container {
  padding: 16px;

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
    }

    .action-buttons {
      display: flex;
      gap: 10px;
      margin-top: 6px;
    }
  }

  .table-card {
    border-radius: 8px;

    .code-badge {
      font-family: 'Fira Code', Consolas, monospace;
      font-weight: 700;
      color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
      padding: 2px 6px;
      border-radius: 4px;
    }

    .text-muted {
      color: var(--el-text-color-secondary);
      font-size: 12px;
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 16px;
    }
  }

  .response-detail-box {
    display: flex;
    flex-direction: column;
    gap: 12px;

    .detail-row {
      display: flex;
      font-size: 13px;

      .label {
        width: 80px;
        color: var(--el-text-color-secondary);
      }

      .value {
        flex: 1;
        color: var(--el-text-color-primary);
      }

      &.json-row {
        flex-direction: column;
        gap: 6px;

        .json-pre {
          background: #1e293b;
          color: #38bdf8;
          padding: 12px;
          border-radius: 6px;
          font-family: 'Fira Code', Consolas, monospace;
          font-size: 12px;
          max-height: 240px;
          overflow-y: auto;
        }
      }
    }
  }
}
</style>
