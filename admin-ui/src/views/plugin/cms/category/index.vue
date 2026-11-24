<template>
  <div class="cms-category-container">
    <!-- 顶部适用模板过滤 Tab -->
    <el-card shadow="never" class="template-filter-card">
      <div class="filter-header">
        <span class="label">适用模板范围：</span>
        <el-radio-group v-model="templateFilter" size="default" @change="handleTemplateFilterChange">
          <el-radio-button value="all">🌟 全部栏目 (通用+全模板)</el-radio-button>
          <el-radio-button value="blog">📝 个人博客专属</el-radio-button>
          <el-radio-button value="corporate">🏢 企业官网专属</el-radio-button>
          <el-radio-button value="showcase">🛍️ 产品展厅专属</el-radio-button>
        </el-radio-group>
      </div>
    </el-card>

    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-content">
        <el-button type="primary" @click="handleAdd">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          添加 CMS 栏目分类
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
        :data="filteredTableData"
        row-key="id"
        border
      >
        <el-table-column prop="id" label="ID" width="70" align="center" sortable />
        <el-table-column label="图标" width="70" align="center">
          <template #default="{ row }">
            <el-icon v-if="row.icon" class="category-icon">
              <vab-icon :icon="row.icon" />
            </el-icon>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="栏目分类名称" min-width="160">
          <template #default="{ row }">
            <span class="name-text">{{ row.name }}</span>
            <div class="desc-text" v-if="row.description">{{ row.description }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="diyname" label="英文标识 / Slug" min-width="140">
          <template #default="{ row }">
            <code>{{ row.diyname || row.slug || '-' }}</code>
          </template>
        </el-table-column>
        <el-table-column label="适用模板" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="getScopeTagType(row.template_scope)" size="small">
              {{ getScopeLabel(row.template_scope) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="内容类型" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="getTypeTagType(row.type)" size="small" effect="plain">
              {{ getTypeLabel(row.type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="weigh" label="排序权重" width="90" align="center" sortable />
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 || row.status === 'normal' ? 'success' : 'info'" size="small">
              {{ row.status === 1 || row.status === 'normal' ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="创建时间" min-width="160" align="center">
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

    <!-- 弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑栏目分类' : '新增栏目分类'"
      width="560px"
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
        <el-form-item label="栏目分类名称" prop="name">
          <el-input v-model="form.name" placeholder="例如：业界快讯、产品展厅、企业案例" />
        </el-form-item>

        <el-form-item label="英文标识 (Slug)" prop="diyname">
          <el-input v-model="form.diyname" placeholder="例如：news、product、solution" />
        </el-form-item>

        <el-form-item label="适用模板范围" prop="template_scope">
          <el-select v-model="form.template_scope" placeholder="请选择适用模板" style="width: 100%">
            <el-option label="🌟 通用 (全部模板均适用)" value="all" />
            <el-option label="📝 个人博客模板 (blog)" value="blog" />
            <el-option label="🏢 企业官网模板 (corporate)" value="corporate" />
            <el-option label="🛍️ 购物产品展厅 (showcase)" value="showcase" />
          </el-select>
        </el-form-item>

        <el-form-item label="栏目内容模型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio-button value="article">📝 文章资讯</el-radio-button>
            <el-radio-button value="product">🛍️ 展厅商品</el-radio-button>
            <el-radio-button value="solution">🏢 方案案例</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="栏目图标" prop="icon">
          <hw-icon-selector v-model="form.icon" placeholder="点击右侧按钮选择或直接输入 RemixIcon" />
        </el-form-item>

        <el-form-item label="栏目简介说明" prop="description">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            placeholder="简要描述栏目的用途与定位"
          />
        </el-form-item>

        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="排序权重" prop="weigh">
              <el-input-number v-model="form.weigh" :min="0" :max="9999" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="显示状态" prop="status">
              <el-switch
                v-model="form.status"
                :active-value="1"
                :inactive-value="0"
                active-text="显示"
                inactive-text="隐藏"
              />
            </el-form-item>
          </el-col>
        </el-row>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getCmsCategoryList, addCmsCategory, editCmsCategory, delCmsCategory } from '/@/api/hwadmin'
import HwIconSelector from '/@/components/HwIconSelector/index.vue'
import dayjs from 'dayjs'

defineOptions({
  name: 'CmsCategoryList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const templateFilter = ref('all')

const tableData = ref<any[]>([])

const formRef = ref<FormInstance>()
const form = reactive({
  name: '',
  diyname: '',
  template_scope: 'all',
  type: 'article',
  icon: '',
  description: '',
  weigh: 100,
  status: 1,
})

const rules = reactive<FormRules>({
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
  template_scope: [{ required: true, message: '请选择适用模板', trigger: 'change' }],
})

const filteredTableData = computed(() => {
  if (templateFilter.value === 'all') {
    return tableData.value
  }
  return tableData.value.filter(
    (item) => item.template_scope === templateFilter.value || item.template_scope === 'all' || !item.template_scope
  )
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const getScopeLabel = (scope: string) => {
  const map: Record<string, string> = {
    all: '🌟 通用全站',
    blog: '📝 个人博客',
    corporate: '🏢 企业官网',
    showcase: '🛍️ 产品展厅',
  }
  return map[scope] || '通用'
}

const getScopeTagType = (scope: string): any => {
  const map: Record<string, string> = {
    all: 'warning',
    blog: 'primary',
    corporate: 'success',
    showcase: 'danger',
  }
  return map[scope] || 'info'
}

const getTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    article: '文章资讯',
    product: '展厅商品',
    solution: '方案案例',
  }
  return map[type] || '文章'
}

const getTypeTagType = (type: string): any => {
  const map: Record<string, string> = {
    article: 'primary',
    product: 'warning',
    solution: 'success',
  }
  return map[type] || 'info'
}

const handleTemplateFilterChange = () => {
  // filtered by computed
}

const fetchList = async () => {
  loading.value = true
  try {
    const res = await getCmsCategoryList()
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list) && list.length) {
      tableData.value = list
    } else {
      initMockCategories()
    }
  } catch (e) {
    console.warn('获取 CMS 分类失败，加载示范数据', e)
    initMockCategories()
  } finally {
    loading.value = false
  }
}

const initMockCategories = () => {
  tableData.value = [
    {
      id: 1,
      name: '技术架构前沿',
      diyname: 'tech',
      template_scope: 'blog',
      type: 'article',
      icon: 'code-s-slash-line',
      description: '全栈架构设计、双栈引擎实践与高并发优化探索',
      weigh: 100,
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 86400 * 3,
    },
    {
      id: 2,
      name: '企业数智解决方案',
      diyname: 'solution',
      template_scope: 'corporate',
      type: 'solution',
      icon: 'building-line',
      description: '政企与工业制造场景下的数字化中台落地标杆',
      weigh: 90,
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 86400 * 2,
    },
    {
      id: 3,
      name: '旗舰数码与算力展厅',
      diyname: 'showcase-products',
      template_scope: 'showcase',
      type: 'product',
      icon: 'shopping-bag-line',
      description: '高性能工控机、分布式算力节点与工业级网关硬件',
      weigh: 80,
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 86400,
    },
    {
      id: 4,
      name: '框架新闻与版本快讯',
      diyname: 'news',
      template_scope: 'all',
      type: 'article',
      icon: 'newspaper-line',
      description: '全站通用动态，发布最新产品发布和企业里程碑',
      weigh: 70,
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 3600,
    },
  ]
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
  form.diyname = row.diyname || row.slug || ''
  form.template_scope = row.template_scope || 'all'
  form.type = row.type || 'article'
  form.icon = row.icon || ''
  form.description = row.description || ''
  form.weigh = row.weigh ?? 100
  form.status = row.status === 'normal' || row.status === 1 ? 1 : 0
  dialogVisible.value = true
}

const resetForm = () => {
  form.name = ''
  form.diyname = ''
  form.template_scope = templateFilter.value === 'all' ? 'all' : templateFilter.value
  form.type = 'article'
  form.icon = ''
  form.description = ''
  form.weigh = 100
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
        await editCmsCategory(editId.value, { ...form })
        ElMessage.success('分类更新成功')
      } else {
        await addCmsCategory({ ...form })
        ElMessage.success('分类创建成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '操作失败')
    } finally {
      submitLoading.value = false
    }
  })
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除分类 "${row.name}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsCategory(row.id)
      ElMessage.success('分类已删除')
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '删除分类失败')
    }
  })
}

onMounted(() => {
  fetchList()
})
</script>

<style lang="scss" scoped>
.cms-category-container {
  padding: 20px;

  .template-filter-card {
    margin-bottom: 14px;
    border-radius: 8px;

    .filter-header {
      display: flex;
      align-items: center;
      gap: 12px;

      .label {
        font-size: 13px;
        color: var(--el-text-color-regular);
        font-weight: 500;
      }
    }
  }

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

    .category-icon {
      font-size: 18px;
      color: var(--el-color-primary);
    }

    .name-text {
      font-weight: 600;
      color: var(--el-text-color-primary);
    }

    .desc-text {
      font-size: 12px;
      color: var(--el-text-color-secondary);
      margin-top: 2px;
    }

    code {
      padding: 2px 6px;
      background: var(--el-fill-color-light);
      border-radius: 4px;
      font-size: 12px;
      font-family: monospace;
    }

    .text-muted {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }
  }
}
</style>
