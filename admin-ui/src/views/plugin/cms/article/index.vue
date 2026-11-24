<template>
  <div class="cms-article-container">
    <!-- 顶部模型类型筛选 Tab -->
    <el-card shadow="never" class="type-filter-card">
      <div class="type-tabs">
        <el-radio-group v-model="queryForm.content_type" size="large" @change="handleTypeTabChange">
          <el-radio-button value="">全部内容 ({{ pagination.total }})</el-radio-button>
          <el-radio-button value="article">📝 博客与新闻文章</el-radio-button>
          <el-radio-button value="product">🛍️ 展厅商品 (不含支付)</el-radio-button>
          <el-radio-button value="solution">🏢 企业方案与案例</el-radio-button>
        </el-radio-group>
      </div>
    </el-card>

    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索标题/摘要/SKU"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="所属栏目">
          <el-select v-model="queryForm.category_id" placeholder="全部栏目" clearable style="width: 160px">
            <el-option
              v-for="item in categoryOptions"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="发布状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="已发布" :value="1" />
            <el-option label="草稿" :value="0" />
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
        <el-dropdown trigger="click" @command="handleQuickAdd">
          <el-button type="primary">
            <el-icon><vab-icon icon="add-line" /></el-icon>
            新增内容发布
            <el-icon class="el-icon--right"><vab-icon icon="arrow-down-s-line" /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="article">📝 新增博客/新闻文章</el-dropdown-item>
              <el-dropdown-item command="product">🛍️ 新增展厅商品（含规格参数）</el-dropdown-item>
              <el-dropdown-item command="solution">🏢 新增企业方案案例</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>

        <el-button
          type="danger"
          plain
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
        <el-table-column type="selection" width="46" align="center" />
        <el-table-column prop="id" label="ID" width="70" align="center" sortable />
        <el-table-column label="封面" width="80" align="center">
          <template #default="{ row }">
            <el-image
              v-if="row.image"
              :src="row.image"
              fit="cover"
              class="cover-thumb"
              preview-teleported
              :preview-src-list="[row.image]"
            />
            <div v-else class="no-cover">无图</div>
          </template>
        </el-table-column>
        <el-table-column prop="title" label="标题 / 角标" min-width="260">
          <template #default="{ row }">
            <div class="title-cell">
              <div class="badge-row">
                <el-tag v-if="row.is_recommend" size="small" type="danger" effect="dark" class="mini-tag">荐</el-tag>
                <el-tag v-if="row.is_hot" size="small" type="warning" effect="dark" class="mini-tag">热</el-tag>
                <el-tag v-if="row.badge_text" size="small" type="primary" effect="plain" class="mini-tag">
                  {{ row.badge_text }}
                </el-tag>
              </div>
              <span class="main-title">{{ row.title }}</span>
              <div class="tags-row" v-if="row.tags">
                <span class="tag-chip" v-for="tag in formatTags(row.tags)" :key="tag">#{{ tag }}</span>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="模型类型" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="getTypeTag(row.content_type || 'article')" size="small">
              {{ getTypeLabel(row.content_type || 'article') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="category_name" label="所属栏目" width="120" align="center">
          <template #default="{ row }">
            <el-tag size="small" type="info">{{ row.category_name || getCatName(row.category_id) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="价格 / SKU (展厅商品)" width="150" align="center">
          <template #default="{ row }">
            <div v-if="row.content_type === 'product'" class="product-price-meta">
              <div class="price-val">{{ row.price_label || '询价' }}</div>
              <div class="sku-code" v-if="row.sku_code">型号: {{ row.sku_code }}</div>
            </div>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="author" label="作者" width="90" align="center">
          <template #default="{ row }">
            {{ row.author || '官方' }}
          </template>
        </el-table-column>
        <el-table-column label="浏览 / 获赞" width="110" align="center">
          <template #default="{ row }">
            <span class="stat-bubble">
              <el-icon><vab-icon icon="eye-line" /></el-icon> {{ row.views ?? 0 }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '已发布' : '草稿' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="发布时间" width="160" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="236" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
              <el-button plain type="info" size="small" @click="handlePreview(row)">前台预览</el-button>
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

    <!-- 新增 / 编辑内容弹窗 (三合一) -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="920px"
      top="4vh"
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
        <el-card shadow="never" class="dialog-section-card">
          <template #header>
            <div class="card-title">📌 基础核心信息</div>
          </template>
          <el-row :gutter="16">
            <el-col :span="24">
              <el-form-item label="内容模型" prop="content_type">
                <el-radio-group v-model="form.content_type" size="default">
                  <el-radio-button value="article">📝 博客/新闻文章</el-radio-button>
                  <el-radio-button value="product">🛍️ 展厅商品 (不含支付)</el-radio-button>
                  <el-radio-button value="solution">🏢 企业方案/案例</el-radio-button>
                </el-radio-group>
              </el-form-item>
            </el-col>
            <el-col :span="16">
              <el-form-item label="内容标题" prop="title">
                <el-input v-model="form.title" placeholder="请输入标题（如：产品名、方案名称或文章主旨）" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="所属栏目" prop="category_id">
                <el-select v-model="form.category_id" placeholder="选择栏目" style="width: 100%">
                  <el-option
                    v-for="item in categoryOptions"
                    :key="item.id"
                    :label="item.name"
                    :value="item.id"
                  />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="作者 / 来源" prop="author">
                <el-input v-model="form.author" placeholder="例如：官方团队 / 博主" />
              </el-form-item>
            </el-col>
            <el-col :span="16">
              <el-form-item label="标签 Tags" prop="tags">
                <el-input v-model="form.tags" placeholder="多标签以英文逗号隔开，如：全栈,高并发,硬件" />
              </el-form-item>
            </el-col>
            <el-col :span="16">
              <el-form-item label="封面图 URL" prop="image">
                <el-input v-model="form.image" placeholder="请输入封面图链接或附件地址" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="排序权重" prop="weigh">
                <el-input-number v-model="form.weigh" :min="0" :max="9999" style="width: 100%" />
              </el-form-item>
            </el-col>
            <el-col :span="24">
              <el-form-item label="推荐与标识">
                <el-checkbox v-model="form.is_recommend" :true-label="1" :false-label="0">🌟 首页推荐</el-checkbox>
                <el-checkbox v-model="form.is_hot" :true-label="1" :false-label="0">🔥 热门置顶</el-checkbox>
                <el-input
                  v-model="form.badge_text"
                  placeholder="自定义角标，如：2026旗舰 / 独家方案"
                  style="width: 220px; margin-left: 18px"
                />
              </el-form-item>
            </el-col>
          </el-row>
        </el-card>

        <!-- 展厅商品专属属性区域 -->
        <el-card
          v-if="form.content_type === 'product'"
          shadow="never"
          class="dialog-section-card product-card-highlight"
        >
          <template #header>
            <div class="card-title">
              🛍️ 展厅商品专属参数与规格表（展示型，不含在线支付）
            </div>
          </template>
          <el-row :gutter="16">
            <el-col :span="8">
              <el-form-item label="展示参考价" prop="price_label">
                <el-input v-model="form.price_label" placeholder="如：¥2,999 或 企业询价" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="划线原价" prop="market_price_label">
                <el-input v-model="form.market_price_label" placeholder="如：¥3,699" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="商品型号 SKU" prop="sku_code">
                <el-input v-model="form.sku_code" placeholder="如：HW-SRV-2026" />
              </el-form-item>
            </el-col>
            <el-col :span="24">
              <el-form-item label="外部演示/文档" prop="external_link">
                <el-input v-model="form.external_link" placeholder="产品在线说明书或白皮书外部链接（选填）" />
              </el-form-item>
            </el-col>
            <!-- 结构化多规格参数动态编辑器 -->
            <el-col :span="24">
              <el-form-item label="结构化规格参数">
                <div class="specs-editor-container">
                  <div class="specs-table-header" v-if="form.specs.length > 0">
                    <span class="col-name">规格参数名称 (如：芯片架构 / 内存规格 / 操作系统)</span>
                    <span class="col-val">参数详细数值 (如：64核双路 / 512GB ECC / Linux)</span>
                    <span class="col-act">操作</span>
                  </div>
                  <div
                    v-for="(spec, index) in form.specs"
                    :key="index"
                    class="spec-row"
                  >
                    <el-input v-model="spec.label" placeholder="参数名" class="spec-input" />
                    <el-input v-model="spec.value" placeholder="参数值" class="spec-input" />
                    <el-button type="danger" text @click="removeSpec(index)">
                      <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
                      删除
                    </el-button>
                  </div>
                  <div class="specs-actions">
                    <el-button type="primary" plain size="small" @click="addSpec">
                      <el-icon><vab-icon icon="add-line" /></el-icon>
                      添加规格参数行
                    </el-button>
                    <el-button size="small" @click="applyDefaultSpecs">
                      一键填入标准数码/服务器规格模板
                    </el-button>
                  </div>
                </div>
              </el-form-item>
            </el-col>
          </el-row>
        </el-card>

        <!-- 正文与富文本 -->
        <el-card shadow="never" class="dialog-section-card">
          <template #header>
            <div class="card-title">📝 详细正文与介绍</div>
          </template>
          <el-form-item label="内容摘要" prop="description">
            <el-input
              v-model="form.description"
              type="textarea"
              :rows="2"
              placeholder="简要概括（选填，留空则自动截取正文前 120 字）"
            />
          </el-form-item>

          <el-form-item label="详细正文" prop="content">
            <hw-editor
              v-model="form.content"
              height="360px"
              placeholder="请输入正文排版，支持富文本混排、图片嵌入与代码展示"
            />
          </el-form-item>

          <el-row :gutter="16">
            <el-col :span="8">
              <el-form-item label="初始浏览量" prop="views">
                <el-input-number v-model="form.views" :min="0" style="width: 100%" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="发布状态" prop="status">
                <el-switch
                  v-model="form.status"
                  :active-value="1"
                  :inactive-value="0"
                  active-text="立即发布"
                  inactive-text="存为草稿"
                />
              </el-form-item>
            </el-col>
          </el-row>
        </el-card>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确认提交保存</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl } from '/@/utils/platform'
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import HwEditor from '/@/components/HwEditor/index.vue'
import {
  getCmsArticleList,
  addCmsArticle,
  editCmsArticle,
  delCmsArticle,
  getCmsCategoryList,
} from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'CmsArticleList',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const selectedRows = ref<any[]>([])
const categoryOptions = ref<any[]>([])

const queryForm = reactive({
  search: '',
  content_type: '',
  category_id: undefined as number | undefined,
  status: undefined as number | undefined,
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const tableData = ref<any[]>([])

interface SpecItem {
  label: string
  value: string
}

const formRef = ref<FormInstance>()
const form = reactive({
  content_type: 'article',
  title: '',
  category_id: 1,
  author: '官方发布',
  tags: '',
  image: '',
  weigh: 100,
  is_recommend: 0,
  is_hot: 0,
  badge_text: '',

  // 展厅商品专属
  price_label: '',
  market_price_label: '',
  sku_code: '',
  external_link: '',
  specs: [] as SpecItem[],

  description: '',
  content: '',
  views: 0,
  status: 1,
})

const rules = reactive<FormRules>({
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择所属栏目', trigger: 'change' }],
})

const dialogTitle = computed(() => {
  const prefix = isEdit.value ? '编辑' : '新增'
  if (form.content_type === 'product') return `${prefix}展厅商品 (不含支付)`
  if (form.content_type === 'solution') return `${prefix}企业方案与案例`
  return `${prefix}博客/新闻文章`
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const formatTags = (tags: string) => {
  if (!tags) return []
  return tags.split(/[,，]/).map((s) => s.trim()).filter(Boolean)
}

const getTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    article: '📝 博客文章',
    product: '🛍️ 展厅商品',
    solution: '🏢 方案案例',
  }
  return map[type] || '文章'
}

const getTypeTag = (type: string): any => {
  const map: Record<string, string> = {
    article: 'primary',
    product: 'warning',
    solution: 'success',
  }
  return map[type] || 'info'
}

const getCatName = (cid: number) => {
  const match = categoryOptions.value.find((c) => c.id === cid)
  return match ? match.name : '默认栏目'
}

const loadCategories = async () => {
  try {
    const res = await getCmsCategoryList({ limit: 100 })
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list) && list.length) {
      categoryOptions.value = list
    } else {
      categoryOptions.value = [
        { id: 1, name: '业界洞察' },
        { id: 2, name: '企业解决方案' },
        { id: 3, name: '数码硬件展厅' },
      ]
    }
  } catch (e) {
    categoryOptions.value = [
      { id: 1, name: '业界洞察' },
      { id: 2, name: '企业解决方案' },
      { id: 3, name: '数码硬件展厅' },
    ]
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
    if (queryForm.content_type) params.content_type = queryForm.content_type
    if (queryForm.category_id) params.category_id = queryForm.category_id
    if (queryForm.status !== undefined && queryForm.status !== null) {
      params.status = queryForm.status
    }
    const res = await getCmsArticleList(params)
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list) && list.length) {
      tableData.value = list
      pagination.total = res?.data?.total || list.length
    } else {
      initMockArticles()
    }
  } catch (e) {
    console.warn('获取 CMS 内容失败，加载示范数据', e)
    initMockArticles()
  } finally {
    loading.value = false
  }
}

const makeCoverSvg = (c1: string, c2: string, label: string) =>
  `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="${c1}"/><stop offset="100%" stop-color="${c2}"/></linearGradient></defs><rect width="240" height="160" rx="8" fill="url(#g)"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="rgba(255,255,255,0.92)" font-family="system-ui,sans-serif" font-size="20" font-weight="700">${label}</text></svg>`
  )}`

const initMockArticles = () => {
  tableData.value = [
    {
      id: 1,
      content_type: 'article',
      title: 'HwAdmin 1.0.0 正式发布：现代化智能全栈后台与极速开发体验',
      category_id: 1,
      category_name: '业界洞察',
      author: 'HwAdmin团队',
      tags: '架构,智能,Vue3',
      image: makeCoverSvg('#2563eb', '#06b6d4', 'HwAdmin'),
      views: 2680,
      is_recommend: 1,
      is_hot: 1,
      badge_text: '重磅更新',
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 86400,
    },
    {
      id: 2,
      content_type: 'product',
      title: 'UltraBlade 9000 高性能企业级运算工作站',
      category_id: 3,
      category_name: '数码硬件展厅',
      author: '硬件展厅',
      tags: '服务器,工作站,高性能',
      image: makeCoverSvg('#ea580c', '#f59e0b', 'UltraBlade'),
      price_label: '¥39,999 起',
      market_price_label: '¥45,000',
      sku_code: 'UB-9000-X',
      badge_text: '旗舰旗舰',
      specs_json: JSON.stringify([
        { label: '处理器', value: 'AMD EPYC 9654 96核 192线程' },
        { label: '内存配置', value: '512GB DDR5 4800MHz ECC REG' },
        { label: '存储系统', value: '4 × 3.84TB NVMe PCIe 5.0 Enterprise SSD' },
      ]),
      views: 1420,
      is_recommend: 1,
      is_hot: 0,
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 43200,
    },
    {
      id: 3,
      content_type: 'solution',
      title: '智慧政企中台与混合云一站式解决方案',
      category_id: 2,
      category_name: '企业解决方案',
      author: '行业解决方案中心',
      tags: '信创,混合云,政企中台',
      image: makeCoverSvg('#0f172a', '#3b82f6', 'Solution'),
      views: 930,
      is_recommend: 0,
      is_hot: 1,
      badge_text: '客户推荐',
      status: 1,
      create_time: Math.floor(Date.now() / 1000) - 18000,
    },
  ]
  pagination.total = 3
}

const handleTypeTabChange = () => {
  pagination.page = 1
  fetchList()
}

const handleSearch = () => {
  pagination.page = 1
  fetchList()
}

const handleReset = () => {
  queryForm.search = ''
  queryForm.content_type = ''
  queryForm.category_id = undefined
  queryForm.status = undefined
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const handleQuickAdd = (type: string) => {
  isEdit.value = false
  editId.value = null
  resetForm()
  form.content_type = type
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  form.content_type = row.content_type || 'article'
  form.title = row.title || ''
  form.category_id = row.category_id || categoryOptions.value[0]?.id || 1
  form.author = row.author || '官方发布'
  form.tags = row.tags || ''
  form.image = row.image || ''
  form.weigh = row.weigh ?? 100
  form.is_recommend = row.is_recommend ?? 0
  form.is_hot = row.is_hot ?? 0
  form.badge_text = row.badge_text || ''

  form.price_label = row.price_label || ''
  form.market_price_label = row.market_price_label || ''
  form.sku_code = row.sku_code || ''
  form.external_link = row.external_link || ''

  // 解析规格参数
  let parsedSpecs: SpecItem[] = []
  if (row.specs_json) {
    try {
      parsedSpecs = typeof row.specs_json === 'string' ? JSON.parse(row.specs_json) : row.specs_json
    } catch (e) {
      parsedSpecs = []
    }
  }
  form.specs = Array.isArray(parsedSpecs) ? parsedSpecs : []

  form.description = row.description || ''
  form.content = row.content || ''
  form.views = row.views ?? 0
  form.status = row.status ?? 1

  dialogVisible.value = true
}

const handlePreview = (row: any) => {
  const url = `/#detail?id=${row.id}&type=${row.content_type || 'article'}`
  void openExternalUrl(url)
}

const resetForm = () => {
  form.content_type = 'article'
  form.title = ''
  form.category_id = categoryOptions.value[0]?.id || 1
  form.author = '官方发布'
  form.tags = ''
  form.image = ''
  form.weigh = 100
  form.is_recommend = 0
  form.is_hot = 0
  form.badge_text = ''

  form.price_label = ''
  form.market_price_label = ''
  form.sku_code = ''
  form.external_link = ''
  form.specs = []

  form.description = ''
  form.content = ''
  form.views = 0
  form.status = 1
  if (formRef.value) formRef.value.clearValidate()
}

const addSpec = () => {
  form.specs.push({ label: '', value: '' })
}

const removeSpec = (index: number) => {
  form.specs.splice(index, 1)
}

const applyDefaultSpecs = () => {
  form.specs = [
    { label: '核心架构', value: '新一代多核心高性能集群计算单元' },
    { label: '标准内存', value: '64GB 高速高带宽低延迟内存' },
    { label: '网络接口', value: '双万兆 SFP+ 光纤端口 + 4×千兆 RJ45' },
    { label: '认证标准', value: '企业级 80PLUS Platinum 钛金级能效认证' },
  ]
}

const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      const payload: any = {
        ...form,
        specs_json: JSON.stringify(form.specs),
      }
      if (isEdit.value && editId.value) {
        await editCmsArticle(editId.value, payload)
        ElMessage.success('内容已更新')
      } else {
        await addCmsArticle(payload)
        ElMessage.success('内容发布成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '提交失败')
    } finally {
      submitLoading.value = false
    }
  })
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除 "${row.title}" 吗？`, '安全警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsArticle(row.id)
      ElMessage.success('删除成功')
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '删除失败')
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 项内容吗？`, '批量删除提醒', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsArticle(ids)
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '批量删除失败')
    }
  })
}

onMounted(() => {
  loadCategories()
  fetchList()
})
</script>

<style lang="scss" scoped>
.cms-article-container {
  padding: 20px;

  .type-filter-card {
    margin-bottom: 14px;
    border-radius: 8px;

    .type-tabs {
      display: flex;
      align-items: center;
    }
  }

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
    }

    .action-buttons {
      display: flex;
      gap: 12px;
      margin-top: 6px;
    }
  }

  .table-card {
    border-radius: 8px;

    .cover-thumb {
      width: 48px;
      height: 48px;
      border-radius: 6px;
    }

    .no-cover {
      width: 48px;
      height: 48px;
      border-radius: 6px;
      background: var(--el-fill-color-light);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      color: var(--el-text-color-secondary);
      margin: 0 auto;
    }

    .title-cell {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .badge-row {
        display: flex;
        gap: 4px;
        align-items: center;

        .mini-tag {
          font-size: 11px;
          height: 18px;
          line-height: 16px;
          padding: 0 4px;
        }
      }

      .main-title {
        font-weight: 500;
        color: var(--el-text-color-primary);
        line-height: 1.4;
      }

      .tags-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;

        .tag-chip {
          font-size: 11px;
          color: var(--el-color-primary);
          background: rgba(64, 158, 255, 0.08);
          padding: 1px 6px;
          border-radius: 4px;
        }
      }
    }

    .product-price-meta {
      .price-val {
        font-weight: 700;
        color: #f56c6c;
        font-size: 13px;
      }
      .sku-code {
        font-size: 11px;
        color: var(--el-text-color-secondary);
        font-family: monospace;
      }
    }

    .stat-bubble {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }

    .text-muted {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }

  .dialog-section-card {
    margin-bottom: 16px;
    border-radius: 6px;

    .card-title {
      font-size: 14px;
      font-weight: 600;
    }

    &.product-card-highlight {
      border: 1px solid #e6a23c;
      background: rgba(230, 162, 60, 0.02);
    }

    .specs-editor-container {
      width: 100%;
      background: var(--el-fill-color-light);
      border-radius: 6px;
      padding: 12px;

      .specs-table-header {
        display: flex;
        gap: 12px;
        font-size: 12px;
        color: var(--el-text-color-secondary);
        margin-bottom: 8px;

        .col-name {
          flex: 1;
        }
        .col-val {
          flex: 1.5;
        }
        .col-act {
          width: 60px;
          text-align: center;
        }
      }

      .spec-row {
        display: flex;
        gap: 12px;
        margin-bottom: 8px;
        align-items: center;

        .spec-input {
          flex: 1;
          &:nth-child(2) {
            flex: 1.5;
          }
        }
      }

      .specs-actions {
        display: flex;
        gap: 10px;
        margin-top: 10px;
      }
    }
  }
}
</style>
