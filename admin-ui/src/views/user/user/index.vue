<template>
  <div class="user-management-container">
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索用户名/昵称/手机/邮箱"
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="所属分组">
          <el-select v-model="queryForm.group_id" placeholder="全部分组" clearable style="width: 140px">
            <el-option
              v-for="item in groupOptions"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="正常" :value="1" />
            <el-option label="锁定" :value="0" />
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
          添加会员
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
        <el-table-column label="会员组" width="120" align="center">
          <template #default="{ row }">
            <el-tag size="small" type="info">{{ row.group_name || '默认组' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="money" label="余额 (元)" width="110" align="right">
          <template #default="{ row }">
            <span class="money-text">￥{{ Number(row.money || 0).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="score" label="积分" width="90" align="center" />
        <el-table-column prop="mobile" label="手机号码" min-width="130" />
        <el-table-column prop="email" label="电子邮箱" min-width="160" />
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '正常' : '锁定' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="注册时间" width="170" align="center">
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

    <!-- 新增 / 编辑会员弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑会员资料' : '新增会员'"
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
          <el-input v-model="form.username" placeholder="请输入会员用户名" :disabled="isEdit" />
        </el-form-item>

        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="form.nickname" placeholder="会员昵称" />
        </el-form-item>

        <el-form-item label="登录密码" :prop="isEdit ? '' : 'password'">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="isEdit ? '留空则保持原密码不变' : '请输入登录密码'"
          />
        </el-form-item>

        <el-form-item label="所属分组" prop="group_id">
          <el-select v-model="form.group_id" placeholder="请选择会员分组" style="width: 100%">
            <el-option
              v-for="item in groupOptions"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="账户余额" prop="money">
          <el-input-number v-model="form.money" :precision="2" :step="10" :min="0" style="width: 160px" />
        </el-form-item>

        <el-form-item label="账户积分" prop="score">
          <el-input-number v-model="form.score" :step="100" :min="0" style="width: 160px" />
        </el-form-item>

        <el-form-item label="手机号码" prop="mobile">
          <el-input v-model="form.mobile" placeholder="请输入手机号码" />
        </el-form-item>

        <el-form-item label="电子邮箱" prop="email">
          <el-input v-model="form.email" placeholder="user@example.com" />
        </el-form-item>

        <el-form-item label="会员状态" prop="status">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="锁定"
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
import { getUserList, addUser, editUser, delUser, getUserGroupList } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'UserList',
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
  group_id: undefined as number | undefined,
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
  group_id: 1,
  money: 0,
  score: 0,
  mobile: '',
  email: '',
  status: 1,
})

const rules = reactive<FormRules>({
  username: [{ required: true, message: '请输入会员用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入登录密码', trigger: 'blur' }],
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const loadGroups = async () => {
  try {
    const res = await getUserGroupList({ limit: 100 })
    groupOptions.value = res?.data?.list || []
  } catch (e) {
    console.error('获取会员组列表失败', e)
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
    if (queryForm.group_id) params.group_id = queryForm.group_id
    if (queryForm.status !== undefined && queryForm.status !== null) {
      params.status = queryForm.status
    }
    const res = await getUserList(params)
    tableData.value = res?.data?.list || []
    pagination.total = res?.data?.total || 0
  } catch (e) {
    console.error('获取会员列表失败', e)
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
  queryForm.group_id = undefined
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
  form.group_id = row.group_id || 1
  form.money = Number(row.money || 0)
  form.score = Number(row.score || 0)
  form.mobile = row.mobile || ''
  form.email = row.email || ''
  form.status = row.status ?? 1
  dialogVisible.value = true
}

const resetForm = () => {
  form.username = ''
  form.nickname = ''
  form.password = ''
  form.group_id = 1
  form.money = 0
  form.score = 0
  form.mobile = ''
  form.email = ''
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
        await editUser(editId.value, payload)
        ElMessage.success('会员资料更新成功')
      } else {
        await addUser(payload)
        ElMessage.success('会员创建成功')
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
  ElMessageBox.confirm(`确定要删除会员 "${row.username}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delUser(row.id)
      ElMessage.success('会员删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 名会员吗？`, '安全警告', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delUser(ids)
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
.user-management-container {
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

    .money-text {
      color: #f56c6c;
      font-weight: 600;
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }
}
</style>
