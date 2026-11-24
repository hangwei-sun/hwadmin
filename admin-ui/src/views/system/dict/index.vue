<template>
  <div class="dict-container">
    <el-row :gutter="16" class="dict-row">
      <!-- 左侧：字典分类列表 -->
      <el-col :xs="24" :sm="24" :md="9" :lg="8" :xl="7" class="dict-col-left">
        <el-card shadow="never" class="dict-card left-card">
          <template #header>
            <div class="card-header">
              <div class="header-title">
                <el-icon><vab-icon icon="book-open-line" /></el-icon>
                <span>数据字典分类</span>
              </div>
              <div class="header-btns">
                <el-button type="primary" size="small" @click="handleOpenDictDialog('add')">
                  <el-icon><vab-icon icon="add-line" /></el-icon>
                  新增
                </el-button>
                <el-button size="small" circle @click="fetchDictList">
                  <el-icon><vab-icon icon="refresh-line" /></el-icon>
                </el-button>
              </div>
            </div>
          </template>

          <div class="left-search">
            <el-input
              v-model="dictSearch"
              placeholder="搜索字典名称或编码..."
              clearable
              prefix-icon="Search"
            />
          </div>

          <div v-loading="dictLoading" class="dict-list-wrapper">
            <el-empty v-if="filteredDictList.length === 0" description="暂无字典分类" />
            <div
              v-for="item in filteredDictList"
              :key="item.id"
              class="dict-item-box"
              :class="{ active: currentDict && currentDict.id === item.id }"
              @click="handleSelectDict(item)"
            >
              <div class="dict-item-header">
                <span class="dict-item-name">{{ item.name }}</span>
                <el-tag size="small" type="info" effect="plain">{{ item.code }}</el-tag>
              </div>
              <div class="dict-item-desc">
                {{ item.remark || '暂无描述' }}
              </div>
              <div class="dict-item-footer">
                <el-tag size="small" :type="item.status === 1 ? 'success' : 'danger'">
                  {{ item.status === 1 ? '启用' : '禁用' }}
                </el-tag>
                <div class="item-actions" @click.stop>
                  <el-button
                    type="primary"
                    link
                    size="small"
                    @click="handleOpenDictDialog('edit', item)"
                  >
                    编辑
                  </el-button>
                  <el-button
                    type="danger"
                    link
                    size="small"
                    @click="handleDeleteDict(item)"
                  >
                    删除
                  </el-button>
                </div>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧：字典明细项管理 -->
      <el-col :xs="24" :sm="24" :md="15" :lg="16" :xl="17">
        <el-card shadow="never" class="dict-card right-card">
          <template #header>
            <div class="card-header">
              <div class="header-title">
                <el-icon><vab-icon icon="list-check-2" /></el-icon>
                <span v-if="currentDict">
                  字典项配置：<strong class="text-primary">{{ currentDict.name }}</strong> ({{ currentDict.code }})
                </span>
                <span v-else>字典明细项</span>
              </div>
              <div class="header-btns">
                <el-button
                  type="primary"
                  size="small"
                  :disabled="!currentDict"
                  @click="handleOpenItemDialog('add')"
                >
                  <el-icon><vab-icon icon="add-line" /></el-icon>
                  新增字典项
                </el-button>
                <el-button
                  size="small"
                  circle
                  :disabled="!currentDict"
                  @click="fetchItemList"
                >
                  <el-icon><vab-icon icon="refresh-line" /></el-icon>
                </el-button>
              </div>
            </div>
          </template>

          <div v-if="!currentDict" class="empty-tip">
            <el-empty description="请在左侧选择一个数据字典以管理其键值项" />
          </div>

          <div v-else v-loading="itemLoading" class="item-content-wrapper">
            <el-table :data="itemList" row-key="id" stripe border>
              <el-table-column prop="label" label="显示标签" min-width="130" show-overflow-tooltip>
                <template #default="{ row }">
                  <span class="font-medium">{{ row.label }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="value" label="字典键值" min-width="120" show-overflow-tooltip>
                <template #default="{ row }">
                  <el-tag effect="plain" type="info" class="font-mono">{{ row.value }}</el-tag>
                </template>
              </el-table-column>

              <el-table-column label="标签样式效果" width="130" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.tag_type || 'primary'">{{ row.label }}</el-tag>
                </template>
              </el-table-column>

              <el-table-column prop="sort" label="排序" width="80" align="center" />

              <el-table-column label="状态" width="100" align="center">
                <template #default="{ row }">
                  <el-switch
                    v-model="row.status"
                    :active-value="1"
                    :inactive-value="0"
                    @change="handleStatusChange(row)"
                  />
                </template>
              </el-table-column>

              <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />

              <el-table-column label="操作" width="168" fixed="right" align="center">
                <template #default="{ row }">
                  <div class="hw-table-actions">
                    <el-button type="primary" plain size="small" @click="handleOpenItemDialog('edit', row)">
                      编辑
                    </el-button>
                    <el-button type="danger" plain size="small" @click="handleDeleteItem(row)">
                      删除
                    </el-button>
                  </div>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 字典分类 弹窗 -->
    <el-dialog
      v-model="dictDialog.visible"
      :title="dictDialog.type === 'add' ? '新增字典分类' : '编辑字典分类'"
      width="min(520px, 92vw)"
      destroy-on-close
    >
      <el-form ref="dictFormRef" :model="dictForm" :rules="dictRules" label-width="90px">
        <el-form-item label="字典名称" prop="name">
          <el-input v-model="dictForm.name" placeholder="例如：用户性别、订单状态" />
        </el-form-item>
        <el-form-item label="字典编码" prop="code">
          <el-input
            v-model="dictForm.code"
            placeholder="例如：user_gender、order_status"
            :disabled="dictDialog.type === 'edit'"
          />
        </el-form-item>
        <el-form-item label="排序权重" prop="sort">
          <el-input-number v-model="dictForm.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>
        <el-form-item label="启用状态" prop="status">
          <el-radio-group v-model="dictForm.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注说明" prop="remark">
          <el-input v-model="dictForm.remark" type="textarea" :rows="3" placeholder="请输入分类说明" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dictDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="dictDialog.submitting" @click="handleSubmitDict">确定</el-button>
      </template>
    </el-dialog>

    <!-- 字典项 弹窗 -->
    <el-dialog
      v-model="itemDialog.visible"
      :title="itemDialog.type === 'add' ? '新增字典项' : '编辑字典项'"
      width="min(540px, 92vw)"
      destroy-on-close
    >
      <el-form ref="itemFormRef" :model="itemForm" :rules="itemRules" label-width="90px">
        <el-form-item label="显示标签" prop="label">
          <el-input v-model="itemForm.label" placeholder="例如：男、进行中、已发货" />
        </el-form-item>
        <el-form-item label="字典键值" prop="value">
          <el-input v-model="itemForm.value" placeholder="例如：1、processing、shipped" />
        </el-form-item>
        <el-form-item label="标签样式" prop="tag_type">
          <el-select v-model="itemForm.tag_type" placeholder="选择在表格中的展示效果" style="width: 100%">
            <el-option label="主色 (Primary)" value="primary">
              <el-tag type="primary">Primary</el-tag>
            </el-option>
            <el-option label="成功 (Success)" value="success">
              <el-tag type="success">Success</el-tag>
            </el-option>
            <el-option label="警告 (Warning)" value="warning">
              <el-tag type="warning">Warning</el-tag>
            </el-option>
            <el-option label="危险 (Danger)" value="danger">
              <el-tag type="danger">Danger</el-tag>
            </el-option>
            <el-option label="信息 (Info)" value="info">
              <el-tag type="info">Info</el-tag>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="排序权重" prop="sort">
          <el-input-number v-model="itemForm.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>
        <el-form-item label="启用状态" prop="status">
          <el-radio-group v-model="itemForm.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注说明" prop="remark">
          <el-input v-model="itemForm.remark" type="textarea" :rows="2" placeholder="选填，如键值说明" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="itemDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="itemDialog.submitting" @click="handleSubmitItem">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import request from '/@/utils/request'

defineOptions({
  name: 'SystemDictIndex',
})

// 左侧字典分类状态
const dictLoading = ref(false)
const dictList = ref<any[]>([])
const dictSearch = ref('')
const currentDict = ref<any>(null)

// 右侧字典项状态
const itemLoading = ref(false)
const itemList = ref<any[]>([])

// 弹窗状态
const dictFormRef = ref<FormInstance>()
const dictDialog = reactive({
  visible: false,
  type: 'add',
  submitting: false,
})
const dictForm = reactive({
  id: 0,
  name: '',
  code: '',
  sort: 0,
  status: 1,
  remark: '',
})
const dictRules: FormRules = {
  name: [{ required: true, message: '请输入字典名称', trigger: 'blur' }],
  code: [{ required: true, message: '请输入字典编码', trigger: 'blur' }],
}

const itemFormRef = ref<FormInstance>()
const itemDialog = reactive({
  visible: false,
  type: 'add',
  submitting: false,
})
const itemForm = reactive({
  id: 0,
  dict_id: 0,
  label: '',
  value: '',
  tag_type: 'primary',
  sort: 0,
  status: 1,
  remark: '',
})
const itemRules: FormRules = {
  label: [{ required: true, message: '请输入显示标签', trigger: 'blur' }],
  value: [{ required: true, message: '请输入字典键值', trigger: 'blur' }],
}

// 字典列表搜索过滤
const filteredDictList = computed(() => {
  const keyword = dictSearch.value.trim().toLowerCase()
  if (!keyword) return dictList.value
  return dictList.value.filter(
    (item) =>
      item.name?.toLowerCase().includes(keyword) ||
      item.code?.toLowerCase().includes(keyword)
  )
})

// 获取字典分类列表
const fetchDictList = async () => {
  dictLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.dict/index',
      method: 'get',
      params: { limit: 1000 },
    })
    const list = res?.data?.list || res?.data || []
    dictList.value = Array.isArray(list) ? list : []

    // 维持选中状态或默认选中第一项
    if (dictList.value.length > 0) {
      if (currentDict.value) {
        const found = dictList.value.find((d) => d.id === currentDict.value.id)
        currentDict.value = found || dictList.value[0]
      } else {
        currentDict.value = dictList.value[0]
      }
      fetchItemList()
    } else {
      currentDict.value = null
      itemList.value = []
    }
  } catch (error) {
    console.error('获取字典列表失败', error)
  } finally {
    dictLoading.value = false
  }
}

// 选中字典分类
const handleSelectDict = (dict: any) => {
  currentDict.value = dict
  fetchItemList()
}

// 获取字典项明细
const fetchItemList = async () => {
  if (!currentDict.value) return
  itemLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.dict/items',
      method: 'get',
      params: { dict_id: currentDict.value.id },
    })
    const list = res?.data?.list || res?.data || []
    itemList.value = Array.isArray(list) ? list : []
  } catch (error) {
    console.error('获取字典项失败', error)
  } finally {
    itemLoading.value = false
  }
}

// 打开字典分类弹窗
const handleOpenDictDialog = (type: 'add' | 'edit', row?: any) => {
  dictDialog.type = type
  if (type === 'edit' && row) {
    dictForm.id = row.id
    dictForm.name = row.name
    dictForm.code = row.code
    dictForm.sort = row.sort || 0
    dictForm.status = row.status ?? 1
    dictForm.remark = row.remark || ''
  } else {
    dictForm.id = 0
    dictForm.name = ''
    dictForm.code = ''
    dictForm.sort = 0
    dictForm.status = 1
    dictForm.remark = ''
  }
  dictDialog.visible = true
}

// 提交字典分类表单
const handleSubmitDict = async () => {
  if (!dictFormRef.value) return
  await dictFormRef.value.validate(async (valid) => {
    if (!valid) return
    dictDialog.submitting = true
    try {
      if (dictDialog.type === 'add') {
        await request({
          url: '/adminapi/system.dict/add',
          method: 'post',
          data: dictForm,
        })
        ElMessage.success('添加成功')
      } else {
        await request({
          url: `/adminapi/system.dict/edit?id=${dictForm.id}`,
          method: 'post',
          data: dictForm,
        })
        ElMessage.success('更新成功')
      }
      dictDialog.visible = false
      await fetchDictList()
    } catch (error: any) {
      ElMessage.error(error?.message || '操作失败')
    } finally {
      dictDialog.submitting = false
    }
  })
}

// 删除字典分类
const handleDeleteDict = (dict: any) => {
  ElMessageBox.confirm(
    `确定要删除字典分类「${dict.name} (${dict.code})」吗？其下所有字典项也将被删除！`,
    '警告',
    {
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      await request({
        url: '/adminapi/system.dict/del',
        method: 'post',
        data: { ids: [dict.id] },
      })
      ElMessage.success('删除成功')
      if (currentDict.value?.id === dict.id) {
        currentDict.value = null
      }
      fetchDictList()
    } catch (error: any) {
      ElMessage.error(error?.message || '删除失败')
    }
  })
}

// 打开字典项弹窗
const handleOpenItemDialog = (type: 'add' | 'edit', row?: any) => {
  if (!currentDict.value) return
  itemDialog.type = type
  if (type === 'edit' && row) {
    itemForm.id = row.id
    itemForm.dict_id = row.dict_id
    itemForm.label = row.label
    itemForm.value = row.value
    itemForm.tag_type = row.tag_type || 'primary'
    itemForm.sort = row.sort || 0
    itemForm.status = row.status ?? 1
    itemForm.remark = row.remark || ''
  } else {
    itemForm.id = 0
    itemForm.dict_id = currentDict.value.id
    itemForm.label = ''
    itemForm.value = ''
    itemForm.tag_type = 'primary'
    itemForm.sort = itemList.value.length + 1
    itemForm.status = 1
    itemForm.remark = ''
  }
  itemDialog.visible = true
}

// 提交字典项表单
const handleSubmitItem = async () => {
  if (!itemFormRef.value) return
  await itemFormRef.value.validate(async (valid) => {
    if (!valid) return
    itemDialog.submitting = true
    try {
      if (itemDialog.type === 'add') {
        await request({
          url: '/adminapi/system.dict/itemAdd',
          method: 'post',
          data: itemForm,
        })
        ElMessage.success('添加字典项成功')
      } else {
        await request({
          url: `/adminapi/system.dict/itemEdit?id=${itemForm.id}`,
          method: 'post',
          data: itemForm,
        })
        ElMessage.success('更新字典项成功')
      }
      itemDialog.visible = false
      await fetchItemList()
    } catch (error: any) {
      ElMessage.error(error?.message || '操作失败')
    } finally {
      itemDialog.submitting = false
    }
  })
}

// 字典项快速状态切换
const handleStatusChange = async (row: any) => {
  try {
    await request({
      url: `/adminapi/system.dict/itemEdit?id=${row.id}`,
      method: 'post',
      data: { status: row.status },
    })
    ElMessage.success('状态已更新')
  } catch (error: any) {
    row.status = row.status === 1 ? 0 : 1
    ElMessage.error(error?.message || '更新状态失败')
  }
}

// 删除字典项
const handleDeleteItem = (row: any) => {
  ElMessageBox.confirm(`确定要删除字典项「${row.label}」吗？`, '提示', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: '/adminapi/system.dict/itemDel',
        method: 'post',
        data: { ids: [row.id] },
      })
      ElMessage.success('删除成功')
      fetchItemList()
    } catch (error: any) {
      ElMessage.error(error?.message || '删除失败')
    }
  })
}

onMounted(() => {
  fetchDictList()
})
</script>

<style scoped lang="scss">
.dict-container {
  padding: 16px;
  min-height: calc(100vh - 120px);
  box-sizing: border-box;

  .dict-row {
    min-height: 100%;
  }

  .dict-card {
    height: 100%;
    display: flex;
    flex-direction: column;

    :deep(.el-card__header) {
      padding: 14px 16px;
      border-bottom: 1px solid var(--el-border-color-lighter);
    }

    :deep(.el-card__body) {
      flex: 1;
      padding: 16px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
  }

  .card-header {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 12px;

    .header-title {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px 8px;
      font-weight: 600;
      font-size: 15px;
      color: var(--el-text-color-primary);
      word-break: break-word;
    }

    .header-btns {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      flex-shrink: 0;
    }
  }

  .left-search {
    margin-bottom: 12px;
  }

  .dict-list-wrapper {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-right: 4px;
  }

  .dict-item-box {
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--el-border-color-light);
    background-color: var(--el-fill-color-blank);
    cursor: pointer;
    transition: all 0.2s ease-in-out;

    &:hover {
      border-color: var(--el-color-primary-light-5);
      background-color: var(--el-fill-color-light);
    }

    &.active {
      border-color: var(--el-color-primary);
      background-color: var(--el-color-primary-light-9);
    }

    .dict-item-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 6px;

      .dict-item-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--el-text-color-primary);
        word-break: break-word;
      }
    }

    .dict-item-desc {
      font-size: 12px;
      color: var(--el-text-color-secondary);
      line-height: 1.4;
      margin-bottom: 8px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .dict-item-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;

      .item-actions {
        display: flex;
        gap: 6px;
      }
    }
  }

  @media (min-width: 992px) {
    height: calc(100vh - 120px);

    .dict-row {
      height: 100%;
    }
  }

  @media (max-width: 991px) {
    padding: 12px;

    .dict-col-left {
      margin-bottom: 16px;
    }

    .left-card {
      max-height: 420px;
    }
  }

  .empty-tip {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 260px;
    height: 100%;
  }

  .item-content-wrapper {
    flex: 1;
    overflow-y: auto;
  }

  .text-primary {
    color: var(--el-color-primary);
  }

  .font-mono {
    font-family: monospace;
  }
}
</style>
