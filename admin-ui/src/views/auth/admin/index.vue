<template>
  <div class="admin-management-container">
    <!-- 顶部查询与操作工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索用户名/昵称/邮箱/手机"
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="正常" :value="1" />
            <el-option label="禁用" :value="0" />
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
        <el-button type="primary" @click="handleAdd">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          添加管理员
        </el-button>
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

    <!-- 数据表格 -->
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
        <el-table-column prop="username" label="用户名" min-width="120" />
        <el-table-column prop="nickname" label="昵称" min-width="120" />
        <el-table-column label="所属角色组" min-width="150">
          <template #default="{ row }">
            <div class="group-tags">
              <el-tag
                v-for="(name, index) in row.group_names || []"
                :key="index"
                size="small"
                type="info"
                effect="light"
              >
                {{ name }}
              </el-tag>
              <span v-if="!row.group_names || row.group_names.length === 0" class="text-muted">未分配</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="email" label="邮箱" min-width="160" />
        <el-table-column prop="mobile" label="手机号" min-width="130" />
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">
              {{ row.status === 1 ? '正常' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="创建时间" min-width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="168" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页栏 -->
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

    <!-- 新增 / 编辑管理员弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑管理员' : '新增管理员'"
      width="560px"
      destroy-on-close
      @closed="resetForm"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        status-icon
      >
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="请输入字母数字下划线" :disabled="isEdit" />
        </el-form-item>

        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="form.nickname" placeholder="管理员显示昵称" />
        </el-form-item>

        <el-form-item label="登录密码" :prop="isEdit ? '' : 'password'">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="isEdit ? '留空则保持原密码不变' : '请输入至少 8 位密码'"
          />
        </el-form-item>

        <el-form-item label="所属角色" prop="groups">
          <el-select
            v-model="form.groups"
            multiple
            placeholder="请选择所属角色组"
            style="width: 100%"
          >
            <el-option
              v-for="item in groupOptions"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="电子邮箱" prop="email">
          <el-input v-model="form.email" placeholder="admin@example.com" />
        </el-form-item>

        <el-form-item label="手机号码" prop="mobile">
          <el-input v-model="form.mobile" placeholder="请输入手机号" />
        </el-form-item>

        <el-form-item label="启用状态" prop="status">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="启用"
            inactive-text="禁用"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确认提交</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getAdminList, addAdmin, editAdmin, delAdmin, getGroupList } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'AdminList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const selectedRows = ref<any[]>([])
const groupOptions = ref<any[]>([])

const queryForm = reactive({
  search: '',
  status: undefined as number | undefined,
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const tableData = ref<any[]>([])

const formRef = ref<FormInstance>()
const form = reactive({
  username: '',
  nickname: '',
  password: '',
  email: '',
  mobile: '',
  groups: [] as number[],
  status: 1,
})

const rules = reactive<FormRules>({
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 30, message: '长度需在 3 到 30 个字符', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码长度至少 8 位', trigger: 'blur' },
  ],
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const loadGroups = async () => {
  try {
    const res = await getGroupList({ limit: 100 })
    groupOptions.value = res?.data?.list || []
  } catch (e) {
    console.error('加载角色组失败', e)
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
    if (queryForm.status !== undefined && queryForm.status !== null) {
      params.status = queryForm.status
    }
    const res = await getAdminList(params)
    tableData.value = res?.data?.list || []
    pagination.total = res?.data?.total || 0
  } catch (e) {
    console.error('获取管理员列表失败', e)
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
  queryForm.status = undefined
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const handleAdd = () => {
  isEdit.value = false
  editId.value = null
  resetForm()
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  form.username = row.username
  form.nickname = row.nickname || ''
  form.password = ''
  form.email = row.email || ''
  form.mobile = row.mobile || ''
  form.groups = row.groups ? [...row.groups] : []
  form.status = row.status ?? 1
  dialogVisible.value = true
}

const resetForm = () => {
  form.username = ''
  form.nickname = ''
  form.password = ''
  form.email = ''
  form.mobile = ''
  form.groups = []
  form.status = 1
  if (formRef.value) formRef.value.clearValidate()
}

const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      const payload: any = { ...form }
      if (isEdit.value && !payload.password) {
        delete payload.password
      }
      if (isEdit.value && editId.value) {
        await editAdmin(editId.value, payload)
        ElMessage.success('管理员更新成功')
      } else {
        await addAdmin(payload)
        ElMessage.success('管理员创建成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch (e) {
      console.error(e)
    } finally {
      submitLoading.value = false
    }
  })
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除管理员 "${row.username}" 吗？此操作不可逆！`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAdmin(row.id)
      ElMessage.success('管理员删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 名管理员吗？`, '安全警告', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delAdmin(ids)
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

onMounted(() => {
  loadGroups()
  fetchList()
})
</script>

<style lang="scss" scoped>
.admin-management-container {
  padding: 20px;

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

    .group-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
    }

    .text-muted {
      font-size: 13px;
      color: var(--el-text-color-secondary);
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }
}
</style>
