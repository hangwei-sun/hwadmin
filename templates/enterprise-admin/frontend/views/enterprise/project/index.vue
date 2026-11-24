<template>
  <div class="crud-page-container enterprise-project-page">
    <el-card shadow="never" class="search-card">
      <el-form :inline="true" :model="queryForm" class="search-form" aria-label="项目筛选表单">
        <el-form-item label="项目名称">
          <el-input v-model="queryForm.title" placeholder="请输入项目名称" clearable aria-label="项目名称搜索" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable aria-label="项目状态筛选">
            <el-option label="待启动" :value="1" />
            <el-option label="进行中" :value="2" />
            <el-option label="已归档" :value="3" />
          </el-select>
        </el-form-item>
        <!-- @hw-custom-start: enterprise-project-filters -->
        <!-- @hw-custom-end: enterprise-project-filters -->
        <el-form-item>
          <el-button type="primary" aria-label="查询项目列表" @click="handleSearch">查询</el-button>
          <el-button aria-label="重置项目筛选" @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card" style="margin-top: 16px">
      <HwTable
        ref="tableRef"
        :columns="columns"
        :data="tableData"
        :loading="loading"
        :pagination="pagination"
        @selection-change="handleSelectionChange"
        @page-change="fetchList"
      >
        <template #toolbar>
          <div class="toolbar" role="toolbar" aria-label="项目管理操作工具栏">
            <el-button v-auth="'enterprise/project/add'" type="primary" aria-label="新建项目" @click="openCreate">
              新建项目
            </el-button>
            <el-button v-auth="'enterprise/project/export'" type="success" plain aria-label="导出项目台账" @click="handleExport">
              导出项目
            </el-button>
          </div>
        </template>

        <template #cell-priority="{ row }">
          <el-tag :type="priorityTag(row.priority)">P{{ row.priority }}</el-tag>
        </template>

        <template #cell-status="{ row }">
          <el-tag :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag>
        </template>

        <template #actions="{ row }">
          <el-button
            v-auth="'enterprise/project/detail'"
            link
            type="info"
            aria-label="查看项目主从详情"
            @click="openDetail(asRow(row))"
          >
            主从详情
          </el-button>
          <el-button v-auth="'enterprise/project/edit'" link type="primary" aria-label="编辑项目" @click="openEdit(asRow(row))">
            编辑
          </el-button>
          <el-button v-auth="'enterprise/task/add'" link type="success" aria-label="拆解任务" @click="addTask(asRow(row))">
            拆解任务
          </el-button>
          <el-button v-auth="'enterprise/project/del'" link type="danger" aria-label="删除项目" @click="handleDelete(asRow(row), asRow(row).title)">
            删除
          </el-button>
        </template>
      </HwTable>

      <el-empty v-if="!loading && tableData.length === 0" description="暂无项目台账，请点击新建项目" />
    </el-card>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑项目' : '新建项目'" width="min(640px, 92vw)">
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="96px" aria-label="项目编辑表单">
        <el-form-item label="项目名称" prop="title">
          <el-input v-model="form.title" placeholder="请输入项目名称" maxlength="120" aria-label="项目名称" />
        </el-form-item>
        <el-form-item label="优先级" prop="priority">
          <el-select v-model="form.priority" style="width: 100%" aria-label="项目优先级">
            <el-option label="P1（紧急）" :value="1" />
            <el-option label="P2（常规）" :value="2" />
            <el-option label="P3（低优）" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item label="预算 (元)" prop="budget">
          <el-input v-model="form.budget" placeholder="例如 128000.00（最多两位小数）" aria-label="项目预算" />
        </el-form-item>
        <el-form-item label="项目状态" prop="status">
          <el-select v-model="form.status" style="width: 100%" aria-label="项目状态">
            <el-option label="待启动" :value="1" />
            <el-option label="进行中" :value="2" />
            <el-option label="已归档" :value="3" />
          </el-select>
        </el-form-item>
        <!-- @hw-custom-start: enterprise-project-form-fields -->
        <!-- @hw-custom-end: enterprise-project-form-fields -->
      </el-form>
      <template #footer>
        <el-button aria-label="取消保存" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" aria-label="保存项目" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="taskDialogVisible" title="拆解子任务" width="min(520px, 92vw)">
      <el-form :model="taskForm" label-width="96px" aria-label="拆解任务表单">
        <el-form-item label="所属项目">
          <el-input :model-value="taskForm.projectTitle" disabled aria-label="所属项目" />
        </el-form-item>
        <el-form-item label="任务标题" required>
          <el-input v-model="taskForm.title" placeholder="请输入子任务标题" maxlength="120" aria-label="子任务标题" />
        </el-form-item>
        <el-form-item label="执行人">
          <el-input v-model="taskForm.assignee" placeholder="请输入执行人姓名（选填）" maxlength="64" aria-label="任务执行人" />
        </el-form-item>
        <el-form-item label="截止日期">
          <el-date-picker
            v-model="taskForm.deadline"
            type="date"
            value-format="YYYY-MM-DD"
            placeholder="选择截止日期（选填）"
            style="width: 100%"
            aria-label="任务截止日期"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消拆解任务" @click="taskDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="taskSubmitting" aria-label="确认创建子任务" @click="submitTask">确认创建</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="attachmentDialogVisible" title="绑定附件记录" width="min(540px, 92vw)">
      <el-form :model="attachmentForm" label-width="100px" aria-label="绑定项目附件表单">
        <el-form-item label="文件名" required>
          <el-input
            v-model="attachmentForm.file_name"
            placeholder="例如：架构设计说明书.pdf"
            maxlength="255"
            aria-label="附件文件名"
          />
        </el-form-item>
        <el-form-item label="文件地址" required>
          <el-input
            v-model="attachmentForm.file_url"
            placeholder="例如：/uploads/202611/arch.pdf"
            maxlength="500"
            aria-label="附件存储URL"
          />
        </el-form-item>
        <el-form-item label="文件大小 (B)" required>
          <el-input-number
            v-model="attachmentForm.file_size"
            :min="1"
            :max="10485760"
            style="width: 100%"
            aria-label="附件字节大小"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消绑定附件" @click="attachmentDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="attachmentSubmitting" aria-label="确认绑定附件" @click="submitAttachment">
          确认绑定
        </el-button>
      </template>
    </el-dialog>

    <el-drawer
      v-model="detailVisible"
      title="项目详情与子任务协同"
      size="min(840px, 94vw)"
      destroy-on-close
      aria-label="项目主从详情抽屉"
    >
      <div v-loading="detailLoading" class="detail-drawer-body">
        <template v-if="detailProject">
          <el-descriptions :column="2" border class="project-summary">
            <el-descriptions-item label="项目编号">{{ detailProject.project_no }}</el-descriptions-item>
            <el-descriptions-item label="项目名称">{{ detailProject.title }}</el-descriptions-item>
            <el-descriptions-item label="优先级">
              <el-tag :type="priorityTag(detailProject.priority)">P{{ detailProject.priority }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="项目预算">¥{{ detailProject.budget }}</el-descriptions-item>
            <el-descriptions-item label="项目状态">
              <el-tag :type="statusTag(detailProject.status)">{{ statusLabel(detailProject.status) }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="子任务 / 附件">
              {{ detailTasks.length }} 项任务 · {{ detailAttachments.length }} 份附件
            </el-descriptions-item>
          </el-descriptions>

          <div class="detail-section-header">
            <span class="detail-section-title">子任务协同列表</span>
            <div class="detail-section-actions">
              <el-button
                v-auth="'enterprise/task/add'"
                type="primary"
                size="small"
                aria-label="在详情中拆解新任务"
                @click="addTask(detailProject)"
              >
                拆解新任务
              </el-button>
              <el-button
                v-auth="'enterprise/project/addAttachment'"
                type="success"
                plain
                size="small"
                aria-label="在详情中绑定附件记录"
                @click="openAttachmentDialog"
              >
                绑定附件记录
              </el-button>
            </div>
          </div>

          <el-table :data="detailTasks" border stripe empty-text="暂无子任务，请点击右上角「拆解新任务」">
            <el-table-column prop="title" label="任务标题" min-width="180" />
            <el-table-column prop="assignee" label="执行人" width="110">
              <template #default="{ row }">{{ row.assignee || '-' }}</template>
            </el-table-column>
            <el-table-column prop="deadline" label="截止日期" width="120">
              <template #default="{ row }">{{ row.deadline || '-' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <el-tag :type="taskStatusTag(row.status)">{{ taskStatusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="190" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-auth="'enterprise/task/edit'"
                  link
                  type="primary"
                  size="small"
                  aria-label="一键切换子任务状态"
                  @click="toggleTaskStatus(asTaskRow(row))"
                >
                  {{ nextTaskActionLabel(row.status) }}
                </el-button>
                <el-button
                  v-auth="'enterprise/task/del'"
                  link
                  type="danger"
                  size="small"
                  aria-label="删除子任务"
                  @click="deleteTask(asTaskRow(row))"
                >
                  删除
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="detail-section-header" style="margin-top: 22px">
            <span class="detail-section-title">项目附件记录</span>
          </div>

          <el-table :data="detailAttachments" border stripe empty-text="暂无项目附件记录">
            <el-table-column prop="file_name" label="文件名" min-width="180" />
            <el-table-column prop="file_ext" label="类型" width="80" />
            <el-table-column label="大小" width="110">
              <template #default="{ row }">{{ formatFileSize(row.file_size) }}</template>
            </el-table-column>
            <el-table-column prop="file_url" label="存储路径" min-width="200" show-overflow-tooltip />
          </el-table>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import HwTable from '/@/components/HwTable/index.vue'
import type { HwTableColumn } from '/@/components/HwTable/types'
import { useCrudTable } from '/@/hooks/useCrudTable'
import request from '/@/utils/request'

defineOptions({ name: 'EnterpriseProjectIndex' })

interface ProjectRow {
  id: number
  project_no: string
  title: string
  priority: number
  budget: string
  status: number
  create_time: string
  [key: string]: unknown
}

interface TaskRow {
  id: number
  project_id: number
  title: string
  assignee: string
  deadline: string
  status: number
  [key: string]: unknown
}

interface AttachmentRow {
  id: number
  target_type: string
  target_id: number
  file_name: string
  file_url: string
  file_size: number
  file_ext: string
  [key: string]: unknown
}

type ElTagType = 'primary' | 'success' | 'warning' | 'info' | 'danger'

const columns: HwTableColumn<ProjectRow>[] = [
  { prop: 'project_no', label: '项目编号', width: 160 },
  { prop: 'title', label: '项目名称', minWidth: 200 },
  // @hw-custom-start: enterprise-project-columns-config
  // @hw-custom-end: enterprise-project-columns-config
  { prop: 'priority', label: '优先级', width: 90 },
  { prop: 'budget', label: '预算 (元)', width: 130 },
  { prop: 'status', label: '状态', width: 100 },
  { prop: 'create_time', label: '创建时间', width: 170 },
]

const {
  loading,
  tableData,
  pagination,
  queryForm,
  fetchList,
  handleSearch,
  handleReset,
  handleSelectionChange,
  handleDelete,
  asRow,
} = useCrudTable<ProjectRow, { title: string; status?: number }>({
  fetchApi: (params) => request({ url: '/adminapi/enterprise.project/index', method: 'get', params }),
  delApi: (ids) => request({ url: '/adminapi/enterprise.project/del', method: 'post', data: { ids } }),
  defaultQuery: () => ({ title: '', status: undefined }),
  rowKey: 'id',
})

const dialogVisible = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()

const form = reactive<{
  id: number
  title: string
  priority: number
  budget: string
  status: number
  [key: string]: unknown
}>({
  id: 0,
  title: '',
  priority: 2,
  budget: '0.00',
  status: 1,
})

const formRules: FormRules = {
  title: [{ required: true, message: '请输入项目名称', trigger: 'blur' }],
  budget: [
    {
      pattern: /^\d{1,10}(\.\d{1,2})?$/,
      message: '预算须为非负数字，最多保留两位小数 (decimal(12,2))',
      trigger: 'blur',
    },
  ],
}

const taskDialogVisible = ref(false)
const taskSubmitting = ref(false)
const taskForm = reactive({
  project_id: 0,
  projectTitle: '',
  title: '',
  assignee: '',
  deadline: '',
})

const detailVisible = ref(false)
const detailLoading = ref(false)
const detailProject = ref<ProjectRow | null>(null)
const detailTasks = ref<TaskRow[]>([])
const detailAttachments = ref<AttachmentRow[]>([])

const attachmentDialogVisible = ref(false)
const attachmentSubmitting = ref(false)
const attachmentForm = reactive({
  file_name: '',
  file_url: '',
  file_size: 102400,
})

const asTaskRow = (row: unknown): TaskRow => row as TaskRow
const statusLabel = (s: number): string => ({ 1: '待启动', 2: '进行中', 3: '已归档' }[s] || '未知')
const statusTag = (s: number): ElTagType => ({ 1: 'info', 2: 'primary', 3: 'success' }[s] as ElTagType) || 'info'
const priorityTag = (p: number): ElTagType => ({ 1: 'danger', 2: 'warning', 3: 'info' }[p] as ElTagType) || 'info'

const taskStatusLabel = (s: number): string => ({ 0: '待办', 1: '进行中', 2: '已完成' }[Number(s)] || '未知')
const taskStatusTag = (s: number): ElTagType => ({ 0: 'info', 1: 'primary', 2: 'success' }[Number(s)] as ElTagType) || 'info'
const nextTaskStatus = (s: number): number => ({ 0: 1, 1: 2, 2: 0 }[Number(s)] ?? 1)
const nextTaskActionLabel = (s: number): string =>
  ({ 0: '设为进行中', 1: '标记完成', 2: '重开待办' }[Number(s)] || '切换状态')

const formatFileSize = (bytes: number): string => {
  const n = Number(bytes || 0)
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(2)} MB`
}

const loadProjectDetail = async (projectId: number) => {
  detailLoading.value = true
  try {
    const res = await request({
      url: '/adminapi/enterprise.project/detail',
      method: 'get',
      params: { id: projectId },
    })
    const data = res?.data ?? {}
    detailProject.value = data
    detailTasks.value = Array.isArray(data.tasks) ? data.tasks : []
    detailAttachments.value = Array.isArray(data.attachments) ? data.attachments : []
  } catch (err: any) {
    ElMessage.error(err?.message || '加载项目主从详情失败')
  } finally {
    detailLoading.value = false
  }
}

const openDetail = async (row: ProjectRow) => {
  detailProject.value = row
  detailTasks.value = []
  detailAttachments.value = []
  detailVisible.value = true
  await loadProjectDetail(row.id)
}

const openCreate = () => {
  form.id = 0
  form.title = ''
  form.priority = 2
  form.budget = '0.00'
  form.status = 1
  dialogVisible.value = true
}

const openEdit = (row: ProjectRow) => {
  form.id = row.id
  form.title = row.title
  form.priority = Number(row.priority || 2)
  form.budget = String(row.budget ?? '0.00')
  form.status = Number(row.status || 1)
  dialogVisible.value = true
}

const submitForm = async () => {
  if (!formRef.value) return
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const url = form.id ? '/adminapi/enterprise.project/edit' : '/adminapi/enterprise.project/add'
    await request({ url, method: 'post', data: { ...form } })
    ElMessage.success(form.id ? '项目更新成功' : '项目创建成功')
    dialogVisible.value = false
    await fetchList()
  } catch (err: any) {
    ElMessage.error(err?.message || '项目保存失败')
  } finally {
    submitting.value = false
  }
}

const addTask = (row: ProjectRow) => {
  taskForm.project_id = row.id
  taskForm.projectTitle = `${row.project_no} · ${row.title}`
  taskForm.title = ''
  taskForm.assignee = ''
  taskForm.deadline = ''
  taskDialogVisible.value = true
}

const submitTask = async () => {
  if (!taskForm.title.trim()) {
    ElMessage.warning('请输入子任务标题')
    return
  }
  taskSubmitting.value = true
  try {
    await request({
      url: '/adminapi/enterprise.task/add',
      method: 'post',
      data: {
        project_id: taskForm.project_id,
        title: taskForm.title.trim(),
        assignee: taskForm.assignee.trim(),
        deadline: taskForm.deadline || '',
        status: 0,
      },
    })
    ElMessage.success('子任务创建成功')
    taskDialogVisible.value = false
    if (detailVisible.value && detailProject.value?.id === taskForm.project_id) {
      await loadProjectDetail(taskForm.project_id)
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '子任务创建失败')
  } finally {
    taskSubmitting.value = false
  }
}

const toggleTaskStatus = async (task: TaskRow) => {
  const targetStatus = nextTaskStatus(task.status)
  try {
    await request({
      url: '/adminapi/enterprise.task/edit',
      method: 'post',
      params: { id: task.id },
      data: { id: task.id, status: targetStatus },
    })
    ElMessage.success(`任务状态已更新为「${taskStatusLabel(targetStatus)}」`)
    if (detailProject.value) {
      await loadProjectDetail(detailProject.value.id)
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '更新子任务状态失败')
  }
}

const deleteTask = async (task: TaskRow) => {
  try {
    await ElMessageBox.confirm(`确认删除子任务「${task.title}」吗？`, '删除确认', {
      type: 'warning',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    })
    await request({
      url: '/adminapi/enterprise.task/del',
      method: 'post',
      data: { ids: [task.id] },
    })
    ElMessage.success('子任务已删除')
    if (detailProject.value) {
      await loadProjectDetail(detailProject.value.id)
    }
  } catch (err: any) {
    if (err !== 'cancel' && err !== 'close') {
      ElMessage.error(err?.message || '删除子任务失败')
    }
  }
}

const openAttachmentDialog = () => {
  attachmentForm.file_name = ''
  attachmentForm.file_url = ''
  attachmentForm.file_size = 102400
  attachmentDialogVisible.value = true
}

const submitAttachment = async () => {
  if (!detailProject.value) return
  if (!attachmentForm.file_name.trim() || !attachmentForm.file_url.trim()) {
    ElMessage.warning('请填写附件文件名与存储地址')
    return
  }
  attachmentSubmitting.value = true
  try {
    await request({
      url: '/adminapi/enterprise.project/addAttachment',
      method: 'post',
      data: {
        target_type: 'project',
        target_id: detailProject.value.id,
        file_name: attachmentForm.file_name.trim(),
        file_url: attachmentForm.file_url.trim(),
        file_size: Number(attachmentForm.file_size || 1024),
      },
    })
    ElMessage.success('附件记录绑定成功')
    attachmentDialogVisible.value = false
    await loadProjectDetail(detailProject.value.id)
  } catch (err: any) {
    ElMessage.error(err?.message || '附件绑定失败')
  } finally {
    attachmentSubmitting.value = false
  }
}

const handleExport = async () => {
  try {
    const res = await request({ url: '/adminapi/enterprise.project/export', method: 'get', params: queryForm })
    const csv = res?.data?.csv || ''
    if (!csv) {
      ElMessage.warning('当前筛选无可导出数据')
      return
    }
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `projects-${Date.now()}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('项目数据已导出')
  } catch (err: any) {
    ElMessage.error(err?.message || '项目导出失败')
  }
}
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.detail-drawer-body {
  min-height: 240px;
}

.project-summary {
  margin-bottom: 20px;
}

.detail-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.detail-section-title {
  font-size: 15px;
  font-weight: 600;
}

.detail-section-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

@media (max-width: 768px) {
  .search-form :deep(.el-form-item) {
    width: 100%;
    margin-right: 0;
  }
}
</style>
