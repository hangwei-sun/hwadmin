<template>
  <div class="rule-management-container">
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-content">
        <el-button type="primary" @click="handleAddRoot">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          添加顶级规则
        </el-button>
        <el-button type="success" plain :disabled="pluginRootRules.length === 0" @click="handleOpenRegroup">
          <el-icon><vab-icon icon="folder-transfer-line" /></el-icon>
          归集插件菜单 ({{ pluginRootRules.length }})
        </el-button>
        <el-button @click="toggleExpandAll">
          <el-icon><vab-icon icon="arrow-up-down-line" /></el-icon>
          {{ isExpandAll ? '折叠全部' : '展开全部' }}
        </el-button>
        <el-button @click="fetchList">
          <el-icon><vab-icon icon="refresh-line" /></el-icon>
          刷新
        </el-button>
      </div>
      <el-alert
        class="menu-guideline-alert"
        type="info"
        :closable="false"
        show-icon
        title="菜单规范与插件归集：1 级目录建议 3 个汉字以内、2 级菜单建议 6 个汉字以内以免左侧栏遮挡；手动调整后的菜单层级与标题在后续更新插件/框架时将自动保留。"
      />
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table
        v-if="refreshTable"
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
        :default-expand-all="isExpandAll"
      >
        <el-table-column prop="title" label="规则标题" min-width="180">
          <template #default="{ row }">
            <span class="rule-title-cell">
              <el-icon v-if="row.icon" class="menu-icon"><vab-icon :icon="row.icon" /></el-icon>
              <span>{{ row.title }}</span>
            </span>
          </template>
        </el-table-column>

        <el-table-column prop="type" label="类型" width="90" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.type === 'dir'" type="warning" size="small">目录</el-tag>
            <el-tag v-else-if="row.type === 'menu'" type="primary" size="small">菜单</el-tag>
            <el-tag v-else type="info" size="small">按钮</el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="plugin" label="归属" width="110" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.plugin" type="success" effect="plain" size="small">
              {{ row.plugin }}
            </el-tag>
            <el-tag v-else type="info" effect="plain" size="small">核心</el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="name" label="权限节点 / 名称" min-width="170">
          <template #default="{ row }">
            <code>{{ row.name }}</code>
          </template>
        </el-table-column>

        <el-table-column prop="path" label="路由地址" min-width="130" />
        <el-table-column prop="component" label="前端组件路径" min-width="180" show-overflow-tooltip />

        <el-table-column prop="weight" label="权重" width="80" align="center" sortable />

        <el-table-column prop="status" label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '正常' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="260" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="success" size="small" @click="handleAddChild(row)">新增子规则</el-button>
              <el-button plain type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑规则弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑菜单规则' : '新增菜单规则'"
      width="620px"
      destroy-on-close
      @closed="resetForm"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="110px"
        status-icon
      >
        <el-form-item label="上级规则" prop="pid">
          <el-tree-select
            v-model="form.pid"
            :data="parentOptions"
            :props="{ label: 'title', children: 'children' }"
            node-key="id"
            check-strictly
            placeholder="请选择上级规则（顶级无上级）"
            style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="规则类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio-button value="dir">目录 (Dir)</el-radio-button>
            <el-radio-button value="menu">菜单 (Menu)</el-radio-button>
            <el-radio-button value="button">按钮 (Button)</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="规则标题" prop="title">
          <el-input v-model="form.title" placeholder="1级目录 ≤3 汉字 / 2级菜单 ≤6 汉字" />
          <div v-if="titleLengthTip" class="title-length-tip" :class="{ 'is-warn': titleLengthTip.warn }">
            {{ titleLengthTip.text }}
          </div>
        </el-form-item>

        <el-form-item label="权限节点" prop="name">
          <el-input v-model="form.name" placeholder="例如：user/user、user/user/add" />
        </el-form-item>

        <el-form-item v-if="form.type !== 'button'" label="路由 Path" prop="path">
          <el-input v-model="form.path" placeholder="例如：user、adminLog" />
        </el-form-item>

        <el-form-item v-if="form.type === 'menu'" label="前端组件" prop="component">
          <el-input v-model="form.component" placeholder="例如：@/views/user/user/index.vue" />
        </el-form-item>

        <el-form-item v-if="form.type !== 'button'" label="菜单图标" prop="icon">
          <hw-icon-selector v-model="form.icon" placeholder="点击右侧按钮选择或直接输入 RemixIcon" />
        </el-form-item>

        <el-form-item label="排序权重" prop="weight">
          <el-input-number v-model="form.weight" :min="0" :max="99999" style="width: 160px" />
          <span class="form-tip">数字越大越靠前</span>
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
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确认保存</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 一键归集插件菜单弹窗 -->
    <el-dialog
      v-model="regroupDialogVisible"
      title="归集插件菜单"
      width="560px"
      destroy-on-close
    >
      <el-alert
        type="info"
        :closable="false"
        style="margin-bottom: 16px"
        title="将多个插件的顶级菜单统一收拢到指定目录（如「插件」或新建 ≤3 字目录）下，后续更新插件或系统将保留此处调整后的层级。"
      />
      <el-form label-width="110px">
        <el-form-item label="待归集菜单">
          <el-checkbox-group v-model="regroupForm.ruleIds">
            <el-checkbox v-for="item in pluginRootRules" :key="item.id" :label="item.id">
              {{ item.title }}
              <el-tag v-if="item.plugin" size="small" type="success" effect="plain" style="margin-left: 4px">
                {{ item.plugin }}
              </el-tag>
            </el-checkbox>
          </el-checkbox-group>
        </el-form-item>

        <el-form-item label="目标上级目录">
          <el-radio-group v-model="regroupForm.mode">
            <el-radio value="existing">归入已有目录</el-radio>
            <el-radio value="new">新建归集目录</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="regroupForm.mode === 'existing'" label="选择已有目录">
          <el-select v-model="regroupForm.targetDirId" placeholder="请选择目标 1 级目录" style="width: 100%">
            <el-option
              v-for="dir in existingDirOptions"
              :key="dir.id"
              :label="`${dir.title} (${dir.name})`"
              :value="dir.id"
            />
          </el-select>
        </el-form-item>

        <el-form-item v-else label="新目录标题">
          <el-input v-model="regroupForm.newDirTitle" placeholder="建议 ≤ 3 个汉字，例如：扩展" maxlength="12" />
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="regroupDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="regroupLoading" @click="handleConfirmRegroup">确认归集</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getRuleList, addRule, editRule, delRule } from '/@/api/hwadmin'
import HwIconSelector from '/@/components/HwIconSelector/index.vue'

defineOptions({
  name: 'RuleList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const isExpandAll = ref(true)
const refreshTable = ref(true)

const tableData = ref<any[]>([])

const formRef = ref<FormInstance>()
const form = reactive({
  pid: 0,
  type: 'menu' as 'dir' | 'menu' | 'button',
  name: '',
  title: '',
  path: '',
  component: '',
  icon: '',
  weight: 0,
  status: 1,
})

const rules = reactive<FormRules>({
  title: [{ required: true, message: '请输入规则标题', trigger: 'blur' }],
  name: [{ required: true, message: '请输入权限节点标识', trigger: 'blur' }],
})

const mapTreeOptions = (nodes: any[], currentId: number | null, currentType: string, inSelfBranch = false): any[] => {
  return nodes.map((node) => {
    const isSelf = currentId !== null && Number(node.id) === Number(currentId)
    const blockedBranch = inSelfBranch || isSelf
    const invalidParentType =
      node.type === 'button' || (currentType !== 'button' && node.type !== 'dir')
    const children = Array.isArray(node.children)
      ? mapTreeOptions(node.children, currentId, currentType, blockedBranch)
      : []
    return {
      ...node,
      disabled: blockedBranch || invalidParentType,
      children,
    }
  })
}

const parentOptions = computed(() => {
  return [
    {
      id: 0,
      title: '顶级规则 (Root)',
      disabled: false,
      children: mapTreeOptions(tableData.value, editId.value, form.type),
    },
  ]
})

const titleLengthTip = computed(() => {
  if (form.type === 'button') return null
  const len = Array.from(form.title.trim()).length
  const maxLen = Number(form.pid) === 0 ? 3 : 6
  const levelLabel = Number(form.pid) === 0 ? '1 级目录' : '2 级菜单'
  if (len === 0) {
    return { warn: false, text: `${levelLabel}建议不超过 ${maxLen} 个汉字，防止左侧边栏遮挡` }
  }
  if (len > maxLen) {
    return { warn: true, text: `当前 ${len} 字已超过${levelLabel}建议上限（${maxLen} 字），左侧栏可能产生截断或遮挡` }
  }
  return { warn: false, text: `当前 ${len}/${maxLen} 字，符合分栏显示规范` }
})

const pluginRootRules = computed(() => {
  return tableData.value.filter(
    (item: any) => item.plugin && item.plugin !== '' && Number(item.pid || 0) === 0
  )
})

const existingDirOptions = computed(() => {
  return tableData.value.filter((item: any) => item.type === 'dir')
})

const regroupDialogVisible = ref(false)
const regroupLoading = ref(false)
const regroupForm = reactive({
  ruleIds: [] as number[],
  mode: 'existing' as 'existing' | 'new',
  targetDirId: undefined as number | undefined,
  newDirTitle: '扩展',
})

const handleOpenRegroup = () => {
  regroupForm.ruleIds = pluginRootRules.value.map((item: any) => Number(item.id))
  const defaultDir =
    existingDirOptions.value.find((item: any) => item.name === 'plugin') ||
    existingDirOptions.value[0]
  regroupForm.mode = defaultDir ? 'existing' : 'new'
  regroupForm.targetDirId = defaultDir ? Number(defaultDir.id) : undefined
  regroupForm.newDirTitle = '扩展'
  regroupDialogVisible.value = true
}

const handleConfirmRegroup = async () => {
  if (!regroupForm.ruleIds.length) {
    ElMessage.warning('请至少选择一个待归集的插件菜单')
    return
  }
  regroupLoading.value = true
  try {
    let targetPid = regroupForm.targetDirId
    if (regroupForm.mode === 'new') {
      const title = regroupForm.newDirTitle.trim()
      if (!title) {
        ElMessage.warning('请输入新目录标题')
        return
      }
      const slug = `group_${Date.now()}`
      const res = await addRule({
        pid: 0,
        type: 'dir',
        name: slug,
        title,
        path: slug,
        component: '',
        icon: 'apps-2-line',
        weight: 640,
        status: 1,
      })
      targetPid = Number(res?.data?.id || 0)
    }
    if (!targetPid) {
      ElMessage.warning('请选择有效的目标上级目录')
      return
    }
    const targetMap = new Map(pluginRootRules.value.map((item: any) => [Number(item.id), item]))
    let moved = 0
    for (const id of regroupForm.ruleIds) {
      const row = targetMap.get(Number(id))
      if (!row || Number(row.id) === Number(targetPid)) continue
      await editRule(row.id, {
        pid: targetPid,
        type: row.type || 'menu',
        name: row.name,
        title: row.title,
        path: row.path || '',
        component: row.component || '',
        icon: row.icon || '',
        weight: row.weight ?? 0,
        status: row.status ?? 1,
      })
      moved++
    }
    ElMessage.success(`已成功归集 ${moved} 个插件顶级菜单`)
    regroupDialogVisible.value = false
    await fetchList()
  } catch (e) {
    console.error('归集插件菜单失败', e)
  } finally {
    regroupLoading.value = false
  }
}

const toggleExpandAll = () => {
  refreshTable.value = false
  isExpandAll.value = !isExpandAll.value
  nextTick(() => {
    refreshTable.value = true
  })
}

const fetchList = async () => {
  loading.value = true
  try {
    const res = await getRuleList()
    tableData.value = res?.data?.list || []
  } catch (e) {
    console.error('获取菜单规则失败', e)
  } finally {
    loading.value = false
  }
}

const handleAddRoot = () => {
  isEdit.value = false
  editId.value = null
  resetForm()
  form.pid = 0
  dialogVisible.value = true
}

const handleAddChild = (row: any) => {
  isEdit.value = false
  editId.value = null
  resetForm()
  form.pid = row.id
  form.type = row.type === 'dir' ? 'menu' : 'button'
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  form.pid = row.pid || 0
  form.type = row.type || 'menu'
  form.name = row.name || ''
  form.title = row.title || ''
  form.path = row.path || ''
  form.component = row.component || ''
  form.icon = row.icon || ''
  form.weight = row.weight ?? 0
  form.status = row.status ?? 1
  dialogVisible.value = true
}

const resetForm = () => {
  form.pid = 0
  form.type = 'menu'
  form.name = ''
  form.title = ''
  form.path = ''
  form.component = ''
  form.icon = ''
  form.weight = 0
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
      if (isEdit.value && editId.value) {
        await editRule(editId.value, payload)
        ElMessage.success('规则更新成功')
      } else {
        await addRule(payload)
        ElMessage.success('规则创建成功')
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
  ElMessageBox.confirm(`确定要删除规则 "${row.title}" 吗？子规则将一并级联删除！`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delRule(row.id)
      ElMessage.success('规则删除成功')
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
.rule-management-container {
  padding: 20px;

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .toolbar-content {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .menu-guideline-alert {
      margin-top: 12px;
    }
  }

  .table-card {
    border-radius: 8px;

    .rule-title-cell {
      display: inline-flex;
      align-items: center;
      gap: 6px;

      .menu-icon {
        font-size: 16px;
        color: var(--el-color-primary);
      }
    }

    code {
      padding: 2px 6px;
      background: var(--el-fill-color-light);
      border-radius: 4px;
      font-size: 12px;
      color: var(--el-color-primary);
    }
  }

  .form-tip {
    margin-left: 10px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .title-length-tip {
    width: 100%;
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.4;
    color: var(--el-text-color-secondary);

    &.is-warn {
      color: var(--el-color-warning);
    }
  }
}
</style>
