<template>
  <div class="user-group-container">
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-content">
        <el-button type="primary" @click="handleAdd">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          添加会员组
        </el-button>
        <el-button @click="fetchList">
          <el-icon><vab-icon icon="refresh-line" /></el-icon>
          刷新
        </el-button>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
      >
        <el-table-column prop="id" label="ID" width="80" align="center" sortable />
        <el-table-column prop="name" label="分组名称" min-width="180" />
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
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑会员分组' : '新增会员分组'"
      width="480px"
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
        <el-form-item label="分组名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入会员分组名称" />
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
import { getUserGroupList, addUserGroup, editUserGroup, delUserGroup } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'UserGroupList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)

const tableData = ref<any[]>([])

const formRef = ref<FormInstance>()
const form = reactive({
  name: '',
  status: 1,
})

const rules = reactive<FormRules>({
  name: [{ required: true, message: '请输入会员分组名称', trigger: 'blur' }],
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const fetchList = async () => {
  loading.value = true
  try {
    const res = await getUserGroupList()
    tableData.value = res?.data?.list || []
  } catch (e) {
    console.error('获取会员组列表失败', e)
  } finally {
    loading.value = false
  }
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
  form.name = row.name
  form.status = row.status ?? 1
  dialogVisible.value = true
}

const resetForm = () => {
  form.name = ''
  form.status = 1
  if (formRef.value) formRef.value.clearValidate()
}

const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      if (isEdit.value && editId.value) {
        await editUserGroup(editId.value, { ...form })
        ElMessage.success('会员分组更新成功')
      } else {
        await addUserGroup({ ...form })
        ElMessage.success('会员分组创建成功')
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
  ElMessageBox.confirm(`确定要删除会员组 "${row.name}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delUserGroup(row.id)
      ElMessage.success('会员分组删除成功')
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
.user-group-container {
  padding: 20px;

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .toolbar-content {
      display: flex;
      gap: 12px;
    }
  }

  .table-card {
    border-radius: 8px;
  }
}
</style>
