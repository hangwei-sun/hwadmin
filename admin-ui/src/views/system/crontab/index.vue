<template>
  <div class="crontab-management-container">
    <!-- 筛选工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="任务名称/目标">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索任务标题或目标..."
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="任务类型">
          <el-select v-model="queryForm.type" placeholder="全部类型" clearable style="width: 140px">
            <el-option label="控制台指令" value="command" />
            <el-option label="HTTP 请求" value="url" />
            <el-option label="SQL 脚本" value="sql" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
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
          <el-button type="success" @click="handleOpenDialog('add')">
            <el-icon><vab-icon icon="add-line" /></el-icon>
            新建定时任务
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 任务数据表格 -->
    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        stripe
        border
      >
        <el-table-column prop="id" label="ID" width="64" align="center" />

        <el-table-column prop="title" label="任务标题" min-width="210">
          <template #default="{ row }">
            <div class="task-title-cell">
              <span class="font-medium text-dark task-title-text" :title="row.title">{{ row.title }}</span>
              <span v-if="row.remark" class="text-muted text-xs task-remark-text" :title="row.remark">
                {{ row.remark }}
              </span>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="type" label="类型" width="115" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.type === 'command'" type="primary" effect="light">CLI 指令</el-tag>
            <el-tag v-else-if="row.type === 'url'" type="warning" effect="light">HTTP 请求</el-tag>
            <el-tag v-else-if="row.type === 'sql'" type="success" effect="light">SQL 维护</el-tag>
            <el-tag v-else type="info">{{ row.type }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="target" label="执行目标" min-width="190" show-overflow-tooltip>
          <template #default="{ row }">
            <code class="code-badge">{{ row.target }}</code>
          </template>
        </el-table-column>

        <el-table-column prop="cron" label="Cron 表达式" min-width="155" align="center">
          <template #default="{ row }">
            <el-tag effect="plain" type="info" class="font-mono cron-tag">{{ row.cron }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="启用状态" width="95" align="center">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              @change="handleStatusChange(row)"
            />
          </template>
        </el-table-column>

        <el-table-column label="上次运行情况" min-width="195">
          <template #default="{ row }">
            <div v-if="row.last_run_time > 0" class="run-info-cell">
              <div class="run-time">{{ formatTime(row.last_run_time) }}</div>
              <div class="run-status">
                <el-badge
                  is-dot
                  :type="row.last_run_status === 1 ? 'success' : 'danger'"
                  style="margin-right: 6px"
                />
                <span :class="row.last_run_status === 1 ? 'text-success' : 'text-danger'">
                  {{ row.last_run_status === 1 ? '执行成功' : '执行失败' }}
                </span>
                <span class="text-muted text-xs">({{ row.last_run_cost }}ms)</span>
              </div>
            </div>
            <span v-else class="text-muted text-xs">尚未运行</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="320" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button
                type="primary"
                plain
                size="small"
                :loading="runningIds.includes(row.id)"
                @click="handleRunOnce(row)"
              >
                <el-icon><vab-icon icon="play-circle-line" /></el-icon>
                立即执行
              </el-button>
              <el-button
                type="info"
                plain
                size="small"
                @click="handleOpenLogs(row)"
              >
                <el-icon><vab-icon icon="file-list-line" /></el-icon>
                日志
              </el-button>
              <el-button type="primary" plain size="small" @click="handleOpenDialog('edit', row)">
                编辑
              </el-button>
              <el-button type="danger" plain size="small" @click="handleDelete(row)">
                删除
              </el-button>
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

    <!-- 新建/编辑任务 弹窗 -->
    <el-dialog
      v-model="dialog.visible"
      :title="dialog.type === 'add' ? '新建定时任务' : '编辑定时任务'"
      width="min(640px, 94vw)"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="任务标题" prop="title">
          <el-input v-model="form.title" placeholder="如：清理过期验证码缓存、备份数据库" />
        </el-form-item>

        <el-form-item label="任务类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio value="command">CLI 控制台指令</el-radio>
            <el-radio value="url">HTTP 请求</el-radio>
            <el-radio value="sql">SQL 语句</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="执行目标" prop="target">
          <el-input
            v-model="form.target"
            :type="form.type === 'sql' ? 'textarea' : 'text'"
            :rows="3"
            :placeholder="targetPlaceholder"
          />
          <div class="form-tip text-muted text-xs">
            {{ targetTip }}
          </div>
        </el-form-item>

        <el-form-item label="Cron 表达式" prop="cron">
          <el-input v-model="form.cron" placeholder="分 时 日 月 周，如 0 2 * * *">
            <template #prepend>
              <el-icon><vab-icon icon="time-line" /></el-icon>
            </template>
          </el-input>

          <!-- 快捷预设按钮组 -->
          <div class="cron-presets">
            <span class="text-xs text-muted">快捷预设：</span>
            <el-button-group size="small">
              <el-button link type="primary" @click="form.cron = '* * * * *'">每分钟</el-button>
              <el-button link type="primary" @click="form.cron = '*/5 * * * *'">每5分钟</el-button>
              <el-button link type="primary" @click="form.cron = '*/15 * * * *'">每15分钟</el-button>
              <el-button link type="primary" @click="form.cron = '0 * * * *'">每小时整点</el-button>
              <el-button link type="primary" @click="form.cron = '0 2 * * *'">每天凌晨2点</el-button>
              <el-button link type="primary" @click="form.cron = '0 3 * * 1'">每周一凌晨3点</el-button>
            </el-button-group>
          </div>
        </el-form-item>

        <el-form-item label="启用状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="排序权重" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>

        <el-form-item label="任务备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填，关于此任务的运维备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="dialog.submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 执行日志抽屉 -->
    <el-drawer
      v-model="drawer.visible"
      :title="`执行日志 - ${drawer.currentTask?.title || ''}`"
      size="min(680px, 94vw)"
      destroy-on-close
    >
      <div class="drawer-header-actions">
        <div class="task-summary">
          <span class="text-xs text-muted">目标：<code>{{ drawer.currentTask?.target }}</code></span>
        </div>
        <el-button type="danger" size="small" plain @click="handleClearLogs">
          <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
          清空此任务日志
        </el-button>
      </div>

      <div v-loading="drawer.loading" class="logs-list-wrapper">
        <el-empty v-if="drawer.list.length === 0" description="暂无执行日志记录" />
        <div
          v-for="log in drawer.list"
          :key="log.id"
          class="log-card"
          :class="log.status === 1 ? 'log-success' : 'log-danger'"
        >
          <div class="log-card-header">
            <div class="log-time">
              <el-icon><vab-icon icon="calendar-line" /></el-icon>
              <span>{{ formatTime(log.create_time) }}</span>
            </div>
            <div class="log-badges">
              <el-tag size="small" :type="log.status === 1 ? 'success' : 'danger'">
                {{ log.status === 1 ? '成功' : '失败' }}
              </el-tag>
              <el-tag size="small" type="info">{{ log.duration_ms }} ms</el-tag>
            </div>
          </div>
          <div class="log-output-box">
            <pre>{{ log.output || '(无控制台输出)' }}</pre>
          </div>
        </div>
      </div>

      <div class="drawer-pagination">
        <el-pagination
          v-model:current-page="drawer.page"
          v-model:page-size="drawer.limit"
          :total="drawer.total"
          layout="total, prev, pager, next"
          small
          @current-change="fetchLogs"
        />
      </div>
    </el-drawer>

    <!-- 运行结果即时展示弹窗 -->
    <el-dialog
      v-model="runResultDialog.visible"
      title="任务立即执行结果"
      width="600px"
      destroy-on-close
    >
      <div class="run-result-summary">
        <el-alert
          :type="runResultDialog.status === 1 ? 'success' : 'error'"
          :title="runResultDialog.status === 1 ? '执行成功' : '执行失败或抛出异常'"
          :description="`耗时：${runResultDialog.duration_ms} ms | 触发时间：${runResultDialog.run_time}`"
          show-icon
          :closable="false"
        />
      </div>
      <div class="run-output-terminal">
        <div class="terminal-title">输出控制台结果：</div>
        <pre>{{ runResultDialog.output || '(无返回输出)' }}</pre>
      </div>
      <template #footer>
        <el-button type="primary" @click="runResultDialog.visible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import request from '/@/utils/request'
import dayjs from 'dayjs'

defineOptions({
  name: 'SystemCrontabIndex',
})

const loading = ref(false)
const tableData = ref<any[]>([])
const runningIds = ref<number[]>([])

const pagination = reactive({
  page: 1,
  limit: 20,
  total: 0,
})

const queryForm = reactive({
  search: '',
  type: '',
  status: '',
})

// 表单弹窗
const formRef = ref<FormInstance>()
const dialog = reactive({
  visible: false,
  type: 'add',
  submitting: false,
})

const form = reactive({
  id: 0,
  title: '',
  type: 'command',
  target: '',
  cron: '0 2 * * *',
  status: 1,
  remark: '',
  sort: 0,
})

const rules: FormRules = {
  title: [{ required: true, message: '请输入任务标题', trigger: 'blur' }],
  type: [{ required: true, message: '请选择任务类型', trigger: 'change' }],
  target: [{ required: true, message: '请输入执行目标', trigger: 'blur' }],
  cron: [{ required: true, message: '请输入 Cron 表达式', trigger: 'blur' }],
}

// 抽屉日志
const drawer = reactive({
  visible: false,
  loading: false,
  currentTask: null as any,
  list: [] as any[],
  page: 1,
  limit: 20,
  total: 0,
})

// 立即运行结果弹窗
const runResultDialog = reactive({
  visible: false,
  status: 1,
  duration_ms: 0,
  run_time: '',
  output: '',
})

const targetPlaceholder = computed(() => {
  if (form.type === 'command') return '例如：cache:clear 或 devops:health-check'
  if (form.type === 'url') return '例如：https://api.example.com/crontab/sync'
  if (form.type === 'sql') return '例如：DELETE FROM hw_admin_log WHERE create_time < 1700000000;'
  return ''
})

const targetTip = computed(() => {
  if (form.type === 'command') return '支持 ThinkPHP 原生控制台命令（如 cache:clear, version 等）'
  if (form.type === 'url') return '系统将自动发起 HTTP GET/POST 请求，超时时间为 30 秒'
  if (form.type === 'sql') return '可执行定期清理过期数据或维护脚本，直接写入原生 SQL'
  return ''
})

const formatTime = (ts: number) => {
  if (!ts) return '-'
  return dayjs(ts * 1000).format('YYYY-MM-DD HH:mm:ss')
}

// 获取列表
const fetchList = async () => {
  loading.value = true
  try {
    const params: any = {
      page: pagination.page,
      limit: pagination.limit,
    }
    if (queryForm.search) params.search = queryForm.search
    if (queryForm.type) params.type = queryForm.type
    if (queryForm.status !== '') params.status = queryForm.status

    const res: any = await request({
      url: '/adminapi/system.crontab/index',
      method: 'get',
      params,
    })

    tableData.value = res?.data?.list || []
    pagination.total = res?.data?.total || 0
  } catch (error) {
    console.error('获取定时任务失败', error)
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
  queryForm.type = ''
  queryForm.status = ''
  pagination.page = 1
  fetchList()
}

// 快速状态切换
const handleStatusChange = async (row: any) => {
  try {
    await request({
      url: `/adminapi/system.crontab/edit?id=${row.id}`,
      method: 'post',
      data: { status: row.status },
    })
    ElMessage.success('任务状态已更新')
  } catch (error: any) {
    row.status = row.status === 1 ? 0 : 1
    ElMessage.error(error?.message || '更新状态失败')
  }
}

// 打开新增/编辑弹窗
const handleOpenDialog = (type: 'add' | 'edit', row?: any) => {
  dialog.type = type
  if (type === 'edit' && row) {
    form.id = row.id
    form.title = row.title
    form.type = row.type || 'command'
    form.target = row.target
    form.cron = row.cron
    form.status = row.status ?? 1
    form.remark = row.remark || ''
    form.sort = row.sort || 0
  } else {
    form.id = 0
    form.title = ''
    form.type = 'command'
    form.target = ''
    form.cron = '0 2 * * *'
    form.status = 1
    form.remark = ''
    form.sort = 0
  }
  dialog.visible = true
}

// 保存表单
const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    dialog.submitting = true
    try {
      if (dialog.type === 'add') {
        await request({
          url: '/adminapi/system.crontab/add',
          method: 'post',
          data: form,
        })
        ElMessage.success('创建定时任务成功')
      } else {
        await request({
          url: `/adminapi/system.crontab/edit?id=${form.id}`,
          method: 'post',
          data: form,
        })
        ElMessage.success('更新定时任务成功')
      }
      dialog.visible = false
      fetchList()
    } catch (error: any) {
      ElMessage.error(error?.message || '保存失败')
    } finally {
      dialog.submitting = false
    }
  })
}

// 删除
const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除定时任务「${row.title}」吗？历史执行日志也将被清除！`, '警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: '/adminapi/system.crontab/del',
        method: 'post',
        data: { ids: [row.id] },
      })
      ElMessage.success('删除成功')
      fetchList()
    } catch (error: any) {
      ElMessage.error(error?.message || '删除失败')
    }
  })
}

// 立即执行一次
const handleRunOnce = async (row: any) => {
  if (runningIds.value.includes(row.id)) return
  runningIds.value.push(row.id)
  try {
    const res: any = await request({
      url: '/adminapi/system.crontab/runOnce',
      method: 'post',
      data: { id: row.id },
    })

    const data = res?.data || {}
    runResultDialog.status = data.status ?? 1
    runResultDialog.duration_ms = data.duration_ms ?? 0
    runResultDialog.run_time = data.run_time || dayjs().format('YYYY-MM-DD HH:mm:ss')
    runResultDialog.output = data.output || ''
    runResultDialog.visible = true

    ElMessage.success('任务已触发并执行完毕')
    fetchList()
  } catch (error: any) {
    ElMessage.error(error?.message || '执行失败')
  } finally {
    runningIds.value = runningIds.value.filter((id) => id !== row.id)
  }
}

// 打开日志抽屉
const handleOpenLogs = (row: any) => {
  drawer.currentTask = row
  drawer.page = 1
  drawer.visible = true
  fetchLogs()
}

// 获取日志
const fetchLogs = async () => {
  if (!drawer.currentTask) return
  drawer.loading = true
  try {
    const res: any = await request({
      url: '/adminapi/system.crontab/logs',
      method: 'get',
      params: {
        crontab_id: drawer.currentTask.id,
        page: drawer.page,
        limit: drawer.limit,
      },
    })
    drawer.list = res?.data?.list || []
    drawer.total = res?.data?.total || 0
  } catch (error) {
    console.error('获取日志失败', error)
  } finally {
    drawer.loading = false
  }
}

// 清空日志
const handleClearLogs = () => {
  if (!drawer.currentTask) return
  ElMessageBox.confirm('确定要清空该任务的所有历史执行日志吗？', '提示', {
    confirmButtonText: '确定清空',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: '/adminapi/system.crontab/clearLogs',
        method: 'post',
        data: { crontab_id: drawer.currentTask.id },
      })
      ElMessage.success('日志已清空')
      fetchLogs()
    } catch (error: any) {
      ElMessage.error(error?.message || '清空失败')
    }
  })
}

onMounted(() => {
  fetchList()
})
</script>

<style scoped lang="scss">
.crontab-management-container {
  padding: 16px;

  .toolbar-card {
    margin-bottom: 16px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px 16px;

      :deep(.el-form-item) {
        margin-bottom: 0;
        margin-right: 0;
      }
    }
  }

  .table-card {
    .task-title-cell {
      display: flex;
      flex-direction: column;
      gap: 3px;
      min-width: 0;

      .task-title-text,
      .task-remark-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .code-badge {
      display: inline-block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      vertical-align: middle;
      background-color: var(--el-fill-color-light);
      padding: 3px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 12px;
      color: var(--el-text-color-regular);
    }

    .cron-tag {
      white-space: nowrap;
      letter-spacing: 0.3px;
    }

    .run-info-cell {
      display: flex;
      flex-direction: column;
      gap: 2px;
      font-size: 13px;

      .run-status {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
      }
    }

    .pagination-wrapper {
      margin-top: 16px;
      display: flex;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 8px;
    }
  }

  .cron-presets {
    margin-top: 8px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
  }

  .drawer-header-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .logs-list-wrapper {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: calc(100vh - 180px);
    overflow-y: auto;
    padding-right: 4px;
  }

  .log-card {
    border-radius: 8px;
    border: 1px solid var(--el-border-color-light);
    background-color: var(--el-fill-color-blank);
    padding: 12px;

    &.log-success {
      border-left: 4px solid var(--el-color-success);
    }

    &.log-danger {
      border-left: 4px solid var(--el-color-danger);
    }

    .log-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 8px;

      .log-time {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: var(--el-text-color-secondary);
      }

      .log-badges {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
      }
    }

    .log-output-box {
      background-color: #1e1e1e;
      color: #d4d4d4;
      border-radius: 4px;
      padding: 8px 12px;
      font-family: monospace;
      font-size: 12px;
      max-height: 180px;
      overflow-y: auto;

      pre {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-all;
      }
    }
  }

  @media (max-width: 768px) {
    padding: 12px;

    .toolbar-card {
      .query-form {
        :deep(.el-form-item) {
          width: 100%;
        }

        :deep(.el-input),
        :deep(.el-select) {
          width: 100% !important;
        }
      }
    }

    .table-card .pagination-wrapper {
      justify-content: center;
    }
  }

  .drawer-pagination {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  .run-result-summary {
    margin-bottom: 16px;
  }

  .run-output-terminal {
    background-color: #181818;
    color: #e0e0e0;
    border-radius: 6px;
    padding: 12px;

    .terminal-title {
      font-size: 12px;
      color: #9e9e9e;
      margin-bottom: 8px;
    }

    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-all;
      font-family: monospace;
      font-size: 13px;
      line-height: 1.5;
      max-height: 320px;
      overflow-y: auto;
    }
  }

  .text-muted {
    color: var(--el-text-color-secondary);
  }

  .text-xs {
    font-size: 12px;
  }

  .text-success {
    color: var(--el-color-success);
  }

  .text-danger {
    color: var(--el-color-danger);
  }

  .font-mono {
    font-family: monospace;
  }
}
</style>
