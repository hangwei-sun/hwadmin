<template>
  <div class="group-management-container">
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-content">
        <el-button type="primary" @click="handleAdd">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          添加角色组
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
        default-expand-all
      >
        <el-table-column prop="id" label="ID" width="80" align="center" />
        <el-table-column prop="name" label="角色组名称" min-width="180" />
        <el-table-column prop="rules" label="权限规则节点" min-width="200">
          <template #default="{ row }">
            <el-tag v-if="row.rules === '*'" type="danger" effect="dark">
              全部超级权限 (*)
            </el-tag>
            <span v-else class="rules-summary">
              已分配 {{ (row.rules ? row.rules.split(',').filter(Boolean) : []).length }} 项权限
            </span>
          </template>
        </el-table-column>
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
              <el-button
                v-if="row.id !== 1 && row.rules !== '*'"
                plain
                type="danger"
                size="small"
                @click="handleDelete(row)"
              >
                删除
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑角色组' : '新增角色组'"
      width="580px"
      destroy-on-close
      @closed="resetForm"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
        status-icon
      >
        <el-form-item label="上级角色" prop="pid">
          <el-tree-select
            v-model="form.pid"
            :data="parentOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            placeholder="请选择上级角色（无则为顶级）"
            style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="角色组名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入角色组名称" />
        </el-form-item>

        <el-form-item label="权限分配">
          <div v-if="isSuperAdminGroup" class="super-admin-tip">
            <el-alert
              title="超级管理员组拥有全局通配权限 (*)，不可修改规则节点"
              type="warning"
              :closable="false"
              show-icon
            />
          </div>
          <div v-else class="permission-tree-box">
            <div class="tree-tools">
              <el-button size="small" @click="handleCheckAll(true)">全选</el-button>
              <el-button size="small" @click="handleCheckAll(false)">清空</el-button>
              <el-button size="small" @click="handleExpandAll(true)">全部展开</el-button>
              <el-button size="small" @click="handleExpandAll(false)">全部折叠</el-button>
            </div>
            <el-scrollbar max-height="280px">
              <el-tree
                ref="treeRef"
                :data="ruleTree"
                :props="{ label: 'title', children: 'children' }"
                show-checkbox
                node-key="id"
                default-expand-all
              />
            </el-scrollbar>
          </div>
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
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type ElTree } from 'element-plus'
import { getGroupList, addGroup, editGroup, delGroup, getRuleList } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'GroupList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const isSuperAdminGroup = ref(false)

const tableData = ref<any[]>([])
const ruleTree = ref<any[]>([])
const treeRef = ref<InstanceType<typeof ElTree>>()

const formRef = ref<FormInstance>()
const form = reactive({
  pid: 0,
  name: '',
  rules: '',
  status: 1,
})

const rules = reactive<FormRules>({
  name: [{ required: true, message: '请输入角色组名称', trigger: 'blur' }],
})

const parentOptions = computed(() => {
  return [{ id: 0, name: '顶级角色组', children: tableData.value }]
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const loadRules = async () => {
  try {
    const res = await getRuleList()
    ruleTree.value = res?.data?.list || []
  } catch (e) {
    console.error('获取规则树失败', e)
  }
}

const fetchList = async () => {
  loading.value = true
  try {
    const res = await getGroupList()
    tableData.value = res?.data?.list || []
  } catch (e) {
    console.error('获取角色组列表失败', e)
  } finally {
    loading.value = false
  }
}

const handleAdd = () => {
  isEdit.value = false
  editId.value = null
  isSuperAdminGroup.value = false
  resetForm()
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  isSuperAdminGroup.value = row.rules === '*' || row.id === 1
  form.pid = row.pid || 0
  form.name = row.name
  form.rules = row.rules || ''
  form.status = row.status ?? 1
  dialogVisible.value = true

  nextTick(() => {
    if (treeRef.value && !isSuperAdminGroup.value) {
      const ids = (row.rules ? row.rules.split(',') : [])
        .map((id: string) => Number(id.trim()))
        .filter((n: number) => !isNaN(n) && n > 0)
      treeRef.value.setCheckedKeys(ids, false)
    }
  })
}

const resetForm = () => {
  form.pid = 0
  form.name = ''
  form.rules = ''
  form.status = 1
  if (treeRef.value) {
    treeRef.value.setCheckedKeys([])
  }
  if (formRef.value) formRef.value.clearValidate()
}

const handleCheckAll = (checked: boolean) => {
  if (!treeRef.value) return
  if (checked) {
    const allKeys: number[] = []
    const traverse = (nodes: any[]) => {
      nodes.forEach((n) => {
        allKeys.push(n.id)
        if (n.children && n.children.length) traverse(n.children)
      })
    }
    traverse(ruleTree.value)
    treeRef.value.setCheckedKeys(allKeys)
  } else {
    treeRef.value.setCheckedKeys([])
  }
}

const handleExpandAll = (expand: boolean) => {
  if (!treeRef.value) return
  const nodes = (treeRef.value as any).store?.nodesMap
  for (const key in nodes) {
    nodes[key].expanded = expand
  }
}

const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      const payload: any = { ...form }
      if (!isSuperAdminGroup.value && treeRef.value) {
        // 包含已勾选与半选节点
        const checkedKeys = treeRef.value.getCheckedKeys()
        const halfCheckedKeys = treeRef.value.getHalfCheckedKeys()
        payload.rules = [...checkedKeys, ...halfCheckedKeys].join(',')
      }
      if (isEdit.value && editId.value) {
        await editGroup(editId.value, payload)
        ElMessage.success('角色组更新成功')
      } else {
        await addGroup(payload)
        ElMessage.success('角色组创建成功')
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
  ElMessageBox.confirm(`确定要删除角色组 "${row.name}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delGroup(row.id)
      ElMessage.success('角色组删除成功')
      fetchList()
    } catch (e) {
      console.error(e)
    }
  })
}

onMounted(() => {
  loadRules()
  fetchList()
})
</script>

<style lang="scss" scoped>
.group-management-container {
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

    .rules-summary {
      font-size: 13px;
      color: var(--el-text-color-regular);
    }
  }

  .permission-tree-box {
    width: 100%;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    padding: 10px;

    .tree-tools {
      display: flex;
      gap: 6px;
      margin-bottom: 8px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--el-border-color-lighter);
    }
  }

  .super-admin-tip {
    width: 100%;
  }
}
</style>
