<template>
  <div class="quiz-question-container">
    <el-row :gutter="16">
      <!-- 左侧：题库分类面板 -->
      <el-col :xs="24" :md="6" :lg="5">
        <el-card shadow="never" class="category-card">
          <template #header>
            <div class="card-header-flex">
              <span class="category-header-title">试题分类</span>
              <el-button link type="primary" size="small" @click="openCategoryDialog(null)">
                <el-icon><Plus /></el-icon>
                新建分类
              </el-button>
            </div>
          </template>

          <div class="category-list">
            <div
              class="category-item"
              :class="{ active: currentCategoryId === undefined }"
              @click="selectCategory(undefined)"
            >
              <div class="cat-left">
                <el-icon><Folder /></el-icon>
                <span class="cat-name">全部分类试题</span>
              </div>
              <el-tag size="small" type="info" round>{{ totalQuestionsCount }}</el-tag>
            </div>

            <div
              v-for="cat in categories"
              :key="cat.id"
              class="category-item"
              :class="{ active: currentCategoryId === cat.id }"
              @click="selectCategory(cat.id)"
            >
              <div class="cat-left">
                <el-icon><FolderOpened /></el-icon>
                <span class="cat-name" :title="cat.name">{{ cat.name }}</span>
              </div>
              <div class="cat-right" @click.stop>
                <el-tag size="small" type="primary" round class="count-tag">{{ cat.question_count || 0 }}</el-tag>
                <el-dropdown trigger="hover" size="small">
                  <el-button link type="info" size="small" :icon="MoreFilled" />
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item @click="openCategoryDialog(cat)">编辑分类</el-dropdown-item>
                      <el-dropdown-item divided @click="handleDeleteCategory(cat)">删除分类</el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧：试题列表与操作 -->
      <el-col :xs="24" :md="18" :lg="19">
        <!-- 顶部搜索工具栏 -->
        <el-card shadow="never" class="toolbar-card">
          <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
            <el-form-item label="题干关键词">
              <el-input
                v-model="queryForm.search"
                placeholder="搜索题干内容/解析"
                clearable
                @keyup.enter="handleSearch"
              />
            </el-form-item>
            <el-form-item label="试题类型">
              <el-select v-model="queryForm.type" placeholder="全部题型" clearable style="width: 130px">
                <el-option label="单选题" value="single" />
                <el-option label="多选题" value="multi" />
                <el-option label="判断题" value="judge" />
              </el-select>
            </el-form-item>
            <el-form-item label="试题难度">
              <el-select v-model="queryForm.difficulty" placeholder="全部难度" clearable style="width: 120px">
                <el-option label="简单 (★)" :value="1" />
                <el-option label="中等 (★★)" :value="2" />
                <el-option label="困难 (★★★)" :value="3" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleSearch">
                <el-icon><Search /></el-icon>
                查询
              </el-button>
              <el-button @click="handleReset">
                <el-icon><Refresh /></el-icon>
                重置
              </el-button>
            </el-form-item>
          </el-form>

          <!-- 批量操作与导入导出 -->
          <div class="action-buttons">
            <el-button type="primary" @click="handleAdd">
              <el-icon><Plus /></el-icon>
              新增试题
            </el-button>
            <el-button type="success" plain @click="importDialogVisible = true">
              <el-icon><Upload /></el-icon>
              批量导入
            </el-button>
            <el-button type="warning" plain @click="handleExport">
              <el-icon><Download /></el-icon>
              导出试题
            </el-button>
            <el-button
              type="danger"
              :disabled="selectedRows.length === 0"
              @click="handleBatchDelete"
            >
              <el-icon><Delete /></el-icon>
              批量删除 ({{ selectedRows.length }})
            </el-button>
          </div>
        </el-card>

        <!-- 试题表格 -->
        <el-card shadow="never" class="table-card mt-16">
          <el-table
            v-loading="loading"
            :data="tableData"
            row-key="id"
            border
            stripe
            @selection-change="handleSelectionChange"
          >
            <el-table-column type="selection" width="50" align="center" />
            <el-table-column prop="id" label="ID" width="70" align="center" sortable />
            <el-table-column prop="type" label="题型" width="90" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="getTypeTagType(row.type)">
                  {{ getTypeLabel(row.type) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="title" label="试题题干与选项" min-width="260">
              <template #default="{ row }">
                <div class="question-preview">
                  <div class="question-title-row">
                    <span class="q-title">{{ row.title }}</span>
                    <el-tag v-if="row.image" size="small" type="info" effect="plain" class="ml-4">图文题</el-tag>
                  </div>
                  <div v-if="row.options && row.options.length" class="options-preview">
                    <span
                      v-for="opt in row.options"
                      :key="opt.key"
                      class="option-pill"
                      :class="{ 'is-correct': isOptionCorrect(row, opt.key) }"
                    >
                      <strong>{{ opt.key }}:</strong> {{ opt.value }}
                    </span>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="category_name" label="所属分类" width="130" align="center">
              <template #default="{ row }">
                <el-tag size="small" type="info">{{ row.category_name || getCatName(row.category_id) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="answer" label="正确答案" width="100" align="center">
              <template #default="{ row }">
                <el-tag size="small" type="success" effect="dark">{{ row.answer }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="score" label="分值" width="70" align="center" sortable>
              <template #default="{ row }">
                <span class="font-bold text-orange">{{ row.score || 5 }}分</span>
              </template>
            </el-table-column>
            <el-table-column prop="difficulty" label="难度" width="90" align="center">
              <template #default="{ row }">
                <span v-if="row.difficulty === 1" class="diff-tag text-green">简单 ★</span>
                <span v-else-if="row.difficulty === 2" class="diff-tag text-blue">中等 ★★</span>
                <span v-else class="diff-tag text-red">困难 ★★★</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="168" fixed="right" align="center">
              <template #default="{ row }">
                <div class="hw-table-actions">
                  <el-button plain type="primary" size="small" @click="handleEdit(row)">
                    编辑
                  </el-button>
                  <el-button plain type="danger" size="small" @click="handleDelete(row)">
                    删除
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <!-- 分页器 -->
          <div class="pagination-container">
            <el-pagination
              v-model:current-page="pagination.page"
              v-model:page-size="pagination.limit"
              :total="pagination.total"
              :page-sizes="[10, 20, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="loadData"
              @current-change="loadData"
            />
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 新增 / 编辑试题弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑试题' : '新增试题'"
      width="720px"
      top="6vh"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-width="100px"
        class="question-edit-form"
      >
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="所属分类" prop="category_id">
              <el-select v-model="form.category_id" placeholder="请选择试题分类" style="width: 100%">
                <el-option
                  v-for="cat in categories"
                  :key="cat.id"
                  :label="cat.name"
                  :value="cat.id"
                />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="试题类型" prop="type">
              <el-radio-group v-model="form.type" @change="handleTypeChange">
                <el-radio-button value="single">单选题</el-radio-button>
                <el-radio-button value="multi">多选题</el-radio-button>
                <el-radio-button value="judge">判断题</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="题干内容" prop="title">
          <el-input
            v-model="form.title"
            type="textarea"
            :rows="3"
            placeholder="请输入试题题目描述..."
            maxlength="500"
            show-word-limit
          />
        </el-form-item>

        <el-form-item label="题干配图">
          <div class="image-uploader-line">
            <el-input v-model="form.image" placeholder="图片 URL 或点击右侧本地上传" class="mb-4">
              <template #append>
                <el-upload
                  :show-file-list="false"
                  :http-request="customUploadQuestionImage"
                  accept="image/*"
                >
                  <el-button type="primary">上传图片</el-button>
                </el-upload>
              </template>
            </el-input>
            <div v-if="form.image" class="img-preview-box">
              <el-image :src="form.image" fit="contain" class="preview-thumb" />
              <el-button link type="danger" size="small" @click="form.image = ''">删除配图</el-button>
            </div>
          </div>
        </el-form-item>

        <!-- 选项编辑区域 -->
        <el-form-item label="选项设置" required>
          <div class="options-container">
            <div
              v-for="(opt, idx) in form.options"
              :key="idx"
              class="option-row"
            >
              <div class="opt-key-tag">{{ opt.key }}</div>
              <el-input
                v-model="opt.value"
                :disabled="form.type === 'judge'"
                placeholder="请输入选项描述"
                class="opt-input"
              />
              <el-button
                v-if="form.type !== 'judge' && form.options.length > 2"
                link
                type="danger"
                :icon="Delete"
                @click="removeOption(idx)"
              />
            </div>
            <div v-if="form.type !== 'judge' && form.options.length < 8" class="add-option-btn">
              <el-button link type="primary" size="small" @click="addOption">
                <el-icon><Plus /></el-icon>
                添加选项 (最多8项)
              </el-button>
            </div>
          </div>
        </el-form-item>

        <!-- 正确答案选择器 -->
        <el-form-item label="正确答案" required>
          <div class="answer-selector-box">
            <!-- 单选或判断题 -->
            <el-radio-group
              v-if="form.type === 'single' || form.type === 'judge'"
              v-model="form.answer"
            >
              <el-radio
                v-for="opt in form.options"
                :key="opt.key"
                :value="opt.key"
              >
                选项 {{ opt.key }} ({{ opt.value }})
              </el-radio>
            </el-radio-group>

            <!-- 多选题 -->
            <el-checkbox-group
              v-else
              v-model="multiAnswerArray"
              @change="handleMultiAnswerChange"
            >
              <el-checkbox
                v-for="opt in form.options"
                :key="opt.key"
                :label="opt.key"
              >
                选项 {{ opt.key }} ({{ opt.value }})
              </el-checkbox>
            </el-checkbox-group>

            <span class="answer-tip">
              当前设定的答案：<strong class="text-success">{{ form.answer || '尚未指定' }}</strong>
            </span>
          </div>
        </el-form-item>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="默认分值" prop="score">
              <el-input-number v-model="form.score" :min="1" :max="100" />
              <span class="unit-text">分</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="难度等级" prop="difficulty">
              <el-rate
                v-model="form.difficulty"
                :max="3"
                :texts="['简单', '中等', '困难']"
                show-text
              />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="知识点解析">
          <el-input
            v-model="form.analysis"
            type="textarea"
            :rows="3"
            placeholder="请输入试题答案的详细解析与知识点拓展（答题后向选手展示）"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">
            确认保存
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 分类新增/编辑弹窗 -->
    <el-dialog
      v-model="categoryDialogVisible"
      :title="categoryForm.id ? '编辑题库分类' : '新建题库分类'"
      width="440px"
      destroy-on-close
    >
      <el-form :model="categoryForm" label-width="90px">
        <el-form-item label="分类名称" required>
          <el-input v-model="categoryForm.name" placeholder="如：信息安全与合规" />
        </el-form-item>
        <el-form-item label="排序权重">
          <el-input-number v-model="categoryForm.sort" :min="0" :max="999" />
          <span class="unit-text">越小越靠前</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="categoryDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="categoryLoading" @click="handleSaveCategory">
          保存分类
        </el-button>
      </template>
    </el-dialog>

    <!-- 批量导入试题弹窗 -->
    <el-dialog v-model="importDialogVisible" title="批量导入试题" width="680px" destroy-on-close>
      <div class="import-modal-content">
        <el-alert
          title="支持 CSV、Excel、JSON 结构化格式导入；系统自动执行试题去重与校验"
          type="info"
          :closable="false"
          show-icon
          class="mb-16"
        />

        <div class="template-download-bar mb-16">
          <el-button size="small" type="primary" plain @click="downloadCsvTemplate">
            <el-icon><Download /></el-icon>
            下载标准 CSV 模板
          </el-button>
          <el-button size="small" type="success" plain @click="downloadJsonTemplate">
            <el-icon><Download /></el-icon>
            下载标准 JSON 模板
          </el-button>
        </div>

        <el-tabs v-model="importActiveTab">
          <el-tab-pane label="文件上传导入" name="file">
            <el-upload
              drag
              :auto-upload="false"
              :limit="1"
              accept=".csv,.json,.txt"
              :on-change="handleFileSelected"
            >
              <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
              <div class="el-upload__text">将 CSV 或 JSON 文件拖拽到此处，或 <em>点击上传</em></div>
              <template #tip>
                <div class="el-upload__tip">单个文件不超过 10MB，编码推荐 UTF-8</div>
              </template>
            </el-upload>
          </el-tab-pane>

          <el-tab-pane label="粘贴 JSON 导入" name="json">
            <el-input
              v-model="importJsonText"
              type="textarea"
              :rows="8"
              placeholder='[{"category_id":1,"type":"single","title":"在企业内网中，关于强密码策略下列哪项是正确的？","options":[{"key":"A","value":"至少8位且包含大小写字母与数字"},{"key":"B","value":"纯数字即可"}],"answer":"A","score":5,"difficulty":1,"analysis":"强密码应具备复杂度要求。"}]'
            />
          </el-tab-pane>
        </el-tabs>
      </div>

      <template #footer>
        <el-button @click="importDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="importLoading" @click="handleExecuteImport">
          执行批量导入
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  Search,
  Refresh,
  Plus,
  Delete,
  Folder,
  FolderOpened,
  MoreFilled,
  Upload,
  Download,
  UploadFilled,
} from '@element-plus/icons-vue'
import {
  getQuizQuestionList,
  addQuizQuestion,
  editQuizQuestion,
  delQuizQuestion,
  getQuizCategoryList,
  saveQuizCategory,
  delQuizCategory,
  importQuizQuestions,
  exportQuizQuestions,
  uploadQuizAttachment,
} from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizQuestionManage',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const categoryDialogVisible = ref(false)
const categoryLoading = ref(false)
const importDialogVisible = ref(false)
const importLoading = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const selectedRows = ref<any[]>([])
const currentCategoryId = ref<number | undefined>(undefined)
const importActiveTab = ref('file')
const importJsonText = ref('')
const selectedFile = ref<File | null>(null)

// 试题分类数据
const categories = ref<any[]>([])

// 查询过滤
const queryForm = reactive({
  search: '',
  type: '',
  difficulty: undefined as number | undefined,
})

// 分页
const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

// 试题列表
const tableData = ref<any[]>([])

// 分类编辑表单
const categoryForm = reactive({
  id: undefined as number | undefined,
  name: '',
  sort: 10,
})

// 试题编辑表单
const formRef = ref<FormInstance>()
const form = reactive({
  category_id: 1,
  type: 'single',
  title: '',
  image: '',
  options: [
    { key: 'A', value: '' },
    { key: 'B', value: '' },
    { key: 'C', value: '' },
    { key: 'D', value: '' },
  ],
  answer: 'A',
  score: 5,
  difficulty: 1,
  analysis: '',
})

const multiAnswerArray = ref<string[]>([])

// 表单校验
const formRules = reactive<FormRules>({
  category_id: [{ required: true, message: '请选择所属试题分类', trigger: 'change' }],
  type: [{ required: true, message: '请选择试题类型', trigger: 'change' }],
  title: [{ required: true, message: '请输入试题题目描述', trigger: 'blur' }],
})

// 计算试题总数
const totalQuestionsCount = computed(() => {
  return categories.value.reduce((sum, item) => sum + (item.question_count || 0), pagination.total)
})

// 题型标签
const getTypeLabel = (type: string) => {
  if (type === 'single') return '单选题'
  if (type === 'multi') return '多选题'
  if (type === 'judge') return '判断题'
  return '未知'
}

const getTypeTagType = (type: string) => {
  if (type === 'single') return 'primary'
  if (type === 'multi') return 'warning'
  if (type === 'judge') return 'info'
  return 'info'
}

// 检查是否为正确答案
const isOptionCorrect = (row: any, key: string) => {
  if (!row.answer) return false
  return row.answer.split(',').includes(key)
}

// 获取分类名称
const getCatName = (cid: number) => {
  const match = categories.value.find((c) => c.id === cid)
  return match ? match.name : '未分类'
}

// 筛选分类
const selectCategory = (cid?: number) => {
  currentCategoryId.value = cid
  pagination.page = 1
  loadData()
}

// 搜索与重置
const handleSearch = () => {
  pagination.page = 1
  loadData()
}

const handleReset = () => {
  queryForm.search = ''
  queryForm.type = ''
  queryForm.difficulty = undefined
  pagination.page = 1
  loadData()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

// 加载分类
const loadCategories = async () => {
  try {
    const res: any = await getQuizCategoryList()
    if (res?.code === 1 && res?.data) {
      categories.value = Array.isArray(res.data) ? res.data : res.data.list || []
    }
  } catch (error) {
    categories.value = [
      { id: 1, name: '信息安全与合规', question_count: 120, sort: 1 },
      { id: 2, name: '云原生与高可用', question_count: 85, sort: 2 },
      { id: 3, name: '敏捷交付与 DevOps', question_count: 50, sort: 3 },
      { id: 4, name: '企业文化与制度', question_count: 25, sort: 4 },
    ]
  }
}

// 加载试题
const loadData = async () => {
  loading.value = true
  try {
    const res: any = await getQuizQuestionList({
      page: pagination.page,
      limit: pagination.limit,
      category_id: currentCategoryId.value,
      ...queryForm,
    })
    if (res?.code === 1 && res?.data) {
      tableData.value = res.data.list || []
      pagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取试题列表失败，加载默认模拟数据', error)
    if (tableData.value.length === 0) {
      tableData.value = [
        {
          id: 1,
          category_id: 1,
          category_name: '信息安全与合规',
          type: 'single',
          title: '在企业员工终端安全管理规范中，关于强密码的设定下列哪项最为合规？',
          options: [
            { key: 'A', value: '长度大于8位且包含大小写字母、数字及特殊符号' },
            { key: 'B', value: '纯8位数字以便记忆' },
            { key: 'C', value: '使用名字加出生年月日的拼音' },
            { key: 'D', value: '只要是常用英文单词即可' },
          ],
          answer: 'A',
          score: 5,
          difficulty: 1,
          analysis: '遵循 NIST 规范，密码必须包含大写、小写、数字和特殊字符以防范暴力破解。',
        },
        {
          id: 2,
          category_id: 2,
          category_name: '云原生与高可用',
          type: 'multi',
          title: '在构建 Kubernetes 生产级微服务集群时，下列哪些配置可以保障服务的无损平滑升级？',
          options: [
            { key: 'A', value: '合理配置 readinessProbe 与 livenessProbe 探针' },
            { key: 'B', value: '定义 preStop 钩子实现应用优雅停机' },
            { key: 'C', value: '配置 PodDisruptionBudget (PDB) 防范驱逐断服' },
            { key: 'D', value: '直接使用 kill -9 快速终止旧容器' },
          ],
          answer: 'A,B,C',
          score: 6,
          difficulty: 2,
          analysis: '通过健康检查探针、优雅下线 preStop 与 PDB 能够保证 Pod 轮替期间流量不丢包。',
        },
        {
          id: 3,
          category_id: 1,
          category_name: '信息安全与合规',
          type: 'judge',
          title: '收到来自未知外部邮箱发送的带压缩包附件的“年终奖评定通知”邮件，可以直接下载解压查看。',
          options: [
            { key: 'A', value: '正确' },
            { key: 'B', value: '错误' },
          ],
          answer: 'B',
          score: 4,
          difficulty: 1,
          analysis: '属于高度疑似钓鱼邮件与恶意木马传播，应一键上报安全运营中心进行沙箱分析。',
        },
      ]
      pagination.total = 3
    }
  } finally {
    loading.value = false
  }
}

// 题型切换事件
const handleTypeChange = (val: string | number | boolean | undefined) => {
  const typeStr = String(val)
  if (typeStr === 'judge') {
    form.options = [
      { key: 'A', value: '正确' },
      { key: 'B', value: '错误' },
    ]
    form.answer = 'A'
  } else if (typeStr === 'multi') {
    if (form.options.length < 4) {
      form.options = [
        { key: 'A', value: '' },
        { key: 'B', value: '' },
        { key: 'C', value: '' },
        { key: 'D', value: '' },
      ]
    }
    multiAnswerArray.value = ['A']
    form.answer = 'A'
  } else {
    if (form.options.length < 4) {
      form.options = [
        { key: 'A', value: '' },
        { key: 'B', value: '' },
        { key: 'C', value: '' },
        { key: 'D', value: '' },
      ]
    }
    form.answer = 'A'
  }
}

// 添加选项
const addOption = () => {
  const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
  const nextKey = letters[form.options.length]
  if (nextKey) {
    form.options.push({ key: nextKey, value: '' })
  }
}

// 移除选项
const removeOption = (idx: number) => {
  form.options.splice(idx, 1)
  // 重新对齐 key
  const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
  form.options.forEach((opt, i) => {
    opt.key = letters[i]
  })
}

// 多选答案变更
const handleMultiAnswerChange = (val: any[]) => {
  // 排序并格式化为逗号隔开
  const sorted = [...val].sort()
  form.answer = sorted.join(',')
}

// 上传配图
const customUploadQuestionImage = async (options: any) => {
  try {
    const res: any = await uploadQuizAttachment(options.file)
    if (res?.code === 1 && res?.data) {
      form.image = res.data.url || res.data.path || ''
      ElMessage.success('配图上传成功')
    } else {
      ElMessage.error(res?.msg || '上传失败')
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '上传异常')
  }
}

// 新增试题
const handleAdd = () => {
  isEdit.value = false
  editId.value = null
  Object.assign(form, {
    category_id: currentCategoryId.value || (categories.value[0]?.id || 1),
    type: 'single',
    title: '',
    image: '',
    options: [
      { key: 'A', value: '' },
      { key: 'B', value: '' },
      { key: 'C', value: '' },
      { key: 'D', value: '' },
    ],
    answer: 'A',
    score: 5,
    difficulty: 1,
    analysis: '',
  })
  multiAnswerArray.value = ['A']
  dialogVisible.value = true
}

// 编辑试题
const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  Object.assign(form, {
    category_id: row.category_id,
    type: row.type || 'single',
    title: row.title,
    image: row.image || '',
    options: Array.isArray(row.options) ? JSON.parse(JSON.stringify(row.options)) : [],
    answer: row.answer || 'A',
    score: row.score || 5,
    difficulty: row.difficulty || 1,
    analysis: row.analysis || '',
  })
  if (row.type === 'multi') {
    multiAnswerArray.value = row.answer ? row.answer.split(',') : []
  }
  dialogVisible.value = true
}

// 保存试题
const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.answer) {
      ElMessage.warning('请设置正确答案')
      return
    }
    submitLoading.value = true
    try {
      if (isEdit.value && editId.value) {
        await editQuizQuestion(editId.value, form)
        ElMessage.success('试题修改成功')
      } else {
        await addQuizQuestion(form)
        ElMessage.success('试题录入成功')
      }
      dialogVisible.value = false
      loadData()
      loadCategories()
    } catch (err: any) {
      ElMessage.error(err?.message || '保存失败')
    } finally {
      submitLoading.value = false
    }
  })
}

// 删除试题
const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要彻底删除试题【${row.title.slice(0, 30)}...】吗？`, '删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizQuestion(row.id)
      ElMessage.success('试题已删除')
      loadData()
      loadCategories()
    } catch (err: any) {
      ElMessage.error(err?.message || '删除失败')
    }
  })
}

// 批量删除试题
const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 道试题吗？`, '批量删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizQuestion(ids)
      ElMessage.success('批量删除完成')
      loadData()
      loadCategories()
    } catch (err: any) {
      ElMessage.error(err?.message || '批量删除失败')
    }
  })
}

// 分类管理弹窗
const openCategoryDialog = (cat: any) => {
  if (cat) {
    categoryForm.id = cat.id
    categoryForm.name = cat.name
    categoryForm.sort = cat.sort || 10
  } else {
    categoryForm.id = undefined
    categoryForm.name = ''
    categoryForm.sort = 10
  }
  categoryDialogVisible.value = true
}

const handleSaveCategory = async () => {
  if (!categoryForm.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  categoryLoading.value = true
  try {
    await saveQuizCategory(categoryForm)
    ElMessage.success('分类保存成功')
    categoryDialogVisible.value = false
    loadCategories()
  } catch (err: any) {
    ElMessage.error(err?.message || '分类保存失败')
  } finally {
    categoryLoading.value = false
  }
}

const handleDeleteCategory = (cat: any) => {
  ElMessageBox.confirm(`确定要删除分类【${cat.name}】吗？删除后属于该分类的试题将变为未分类。`, '分类删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizCategory(cat.id)
      ElMessage.success('分类已删除')
      if (currentCategoryId.value === cat.id) {
        currentCategoryId.value = undefined
      }
      loadCategories()
      loadData()
    } catch (err: any) {
      ElMessage.error(err?.message || '分类删除失败')
    }
  })
}

// 批量导入
const handleFileSelected = (file: any) => {
  selectedFile.value = file.raw
}

const downloadCsvTemplate = () => {
  const headers = '分类ID,题型(single/multi/judge),题干内容,选项A,选项B,选项C,选项D,正确答案,分值,难度(1-3),解析\n'
  const exampleRow = '1,single,在强密码策略中哪项符合标准？,大于8位且含字符数字,纯数字,姓名全拼,常用英文,A,5,1,必须包含多字符类型。\n'
  const blob = new Blob(['\uFEFF' + headers + exampleRow], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = '试题批量导入标准模板.csv'
  a.click()
  URL.revokeObjectURL(url)
  ElMessage.success('CSV 标准模板下载成功')
}

const downloadJsonTemplate = () => {
  const jsonSample = [
    {
      category_id: 1,
      type: 'single',
      title: '在企业内网中，关于强密码策略下列哪项是正确的？',
      options: [
        { key: 'A', value: '至少8位且包含大小写字母与数字' },
        { key: 'B', value: '纯数字以便快速输入' },
      ],
      answer: 'A',
      score: 5,
      difficulty: 1,
      analysis: '强密码应具备复杂度要求以抵御暴力破解。',
    },
  ]
  const blob = new Blob([JSON.stringify(jsonSample, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = '试题导入模板.json'
  a.click()
  URL.revokeObjectURL(url)
  ElMessage.success('JSON 标准模板下载成功')
}

const handleExecuteImport = async () => {
  let itemsToImport: any[] = []
  if (importActiveTab.value === 'json') {
    if (!importJsonText.value.trim()) {
      ElMessage.warning('请输入待导入的 JSON 试题数据')
      return
    }
    try {
      const parsed = JSON.parse(importJsonText.value)
      if (Array.isArray(parsed)) {
        itemsToImport = parsed
      } else {
        throw new Error('JSON 最外层必须是数组')
      }
    } catch (e: any) {
      ElMessage.error(`JSON 语法解析失败: ${e.message}`)
      return
    }
  } else {
    if (!selectedFile.value) {
      ElMessage.warning('请选择需要上传的 CSV 或 JSON 文件')
      return
    }
    const text = await selectedFile.value.text()
    if (selectedFile.value.name.endsWith('.json')) {
      try {
        itemsToImport = JSON.parse(text)
      } catch (e: any) {
        ElMessage.error('JSON 文件解析失败')
        return
      }
    } else {
      // 解析 CSV
      const lines = text.split(/\r?\n/).filter((l) => l.trim().length > 0)
      if (lines.length <= 1) {
        ElMessage.warning('CSV 文件内容为空或仅包含表头')
        return
      }
      for (let i = 1; i < lines.length; i++) {
        const cols = lines[i].split(',')
        if (cols.length >= 8) {
          itemsToImport.push({
            category_id: Number(cols[0]) || 1,
            type: cols[1]?.trim() || 'single',
            title: cols[2]?.trim(),
            options: [
              { key: 'A', value: cols[3]?.trim() || '' },
              { key: 'B', value: cols[4]?.trim() || '' },
              { key: 'C', value: cols[5]?.trim() || '' },
              { key: 'D', value: cols[6]?.trim() || '' },
            ].filter((o) => o.value),
            answer: cols[7]?.trim() || 'A',
            score: Number(cols[8]) || 5,
            difficulty: Number(cols[9]) || 1,
            analysis: cols[10]?.trim() || '',
          })
        }
      }
    }
  }

  if (itemsToImport.length === 0) {
    ElMessage.warning('未识别到有效的试题记录')
    return
  }

  importLoading.value = true
  try {
    await importQuizQuestions(itemsToImport)
    ElMessage.success(`成功批量导入 ${itemsToImport.length} 道试题`)
    importDialogVisible.value = false
    importJsonText.value = ''
    selectedFile.value = null
    loadData()
    loadCategories()
  } catch (err: any) {
    ElMessage.error(err?.message || '导入试题失败')
  } finally {
    importLoading.value = false
  }
}

// 导出试题为 CSV 文件
const handleExport = async () => {
  try {
    const res: any = await exportQuizQuestions({
      category_id: currentCategoryId.value,
      ...queryForm,
    })
    const list = res?.data?.list || tableData.value
    if (!list.length) {
      ElMessage.warning('暂无符合条件的试题可导出')
      return
    }

    let csvContent = '\uFEFFID,分类名称,题型,题干,选项,正确答案,分值,难度,解析\n'
    list.forEach((item: any) => {
      const opts = (item.options || []).map((o: any) => `${o.key}:${o.value}`).join(';')
      const row = [
        item.id,
        `"${item.category_name || getCatName(item.category_id)}"`,
        `"${getTypeLabel(item.type)}"`,
        `"${(item.title || '').replace(/"/g, '""')}"`,
        `"${opts.replace(/"/g, '""')}"`,
        `"${item.answer}"`,
        item.score,
        item.difficulty,
        `"${(item.analysis || '').replace(/"/g, '""')}"`,
      ].join(',')
      csvContent += row + '\n'
    })

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `题库试题导出_${dayjs().format('YYYYMMDD_HHmmss')}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`成功导出 ${list.length} 道试题`)
  } catch (err: any) {
    ElMessage.error(err?.message || '导出异常')
  }
}

onMounted(() => {
  loadCategories()
  loadData()
})
</script>

<style scoped lang="scss">
.quiz-question-container {
  padding: 16px;

  .category-card {
    border-radius: 8px;
    height: 100%;

    .card-header-flex {
      display: flex;
      justify-content: space-between;
      align-items: center;

      .category-header-title {
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
      }
    }

    .category-list {
      display: flex;
      flex-direction: column;
      gap: 6px;

      .category-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        color: #475569;

        &:hover {
          background: #f1f5f9;
        }

        &.active {
          background: #eff6ff;
          color: #3b82f6;
          font-weight: 600;
        }

        .cat-left {
          display: flex;
          align-items: center;
          gap: 6px;
          overflow: hidden;

          .cat-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
          }
        }

        .cat-right {
          display: flex;
          align-items: center;
          gap: 4px;

          .count-tag {
            font-size: 11px;
          }
        }
      }
    }
  }

  .toolbar-card {
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .action-buttons {
      margin-top: 12px;
      display: flex;
      gap: 10px;
    }
  }

  .mt-16 {
    margin-top: 16px;
  }

  .ml-4 {
    margin-left: 4px;
  }

  .mb-4 {
    margin-bottom: 4px;
  }

  .mb-16 {
    margin-bottom: 16px;
  }

  .text-orange {
    color: #f97316;
  }

  .text-success {
    color: #10b981;
  }

  .font-bold {
    font-weight: 700;
  }

  .diff-tag {
    font-size: 12px;
    font-weight: 600;

    &.text-green { color: #10b981; }
    &.text-blue { color: #3b82f6; }
    &.text-red { color: #ef4444; }
  }

  .question-preview {
    .question-title-row {
      display: flex;
      align-items: baseline;
      margin-bottom: 6px;

      .q-title {
        font-weight: 600;
        color: #1e293b;
        line-height: 1.4;
      }
    }

    .options-preview {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;

      .option-pill {
        font-size: 11px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 2px 6px;
        color: #64748b;

        &.is-correct {
          background: #ecfdf5;
          border-color: #a7f3d0;
          color: #065f46;
          font-weight: 600;
        }
      }
    }
  }

  .pagination-container {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  .question-edit-form {
    .image-uploader-line {
      width: 100%;

      .img-preview-box {
        display: flex;
        align-items: center;
        gap: 8px;

        .preview-thumb {
          width: 80px;
          height: 50px;
          border-radius: 4px;
          border: 1px solid #e2e8f0;
        }
      }
    }

    .options-container {
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 8px;

      .option-row {
        display: flex;
        align-items: center;
        gap: 8px;

        .opt-key-tag {
          width: 28px;
          height: 28px;
          background: #f1f5f9;
          border-radius: 4px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-weight: 700;
          color: #475569;
        }

        .opt-input {
          flex: 1;
        }
      }

      .add-option-btn {
        margin-top: 4px;
      }
    }

    .answer-selector-box {
      display: flex;
      flex-direction: column;
      gap: 8px;

      .answer-tip {
        font-size: 12px;
        color: #64748b;
      }
    }

    .unit-text {
      margin-left: 8px;
      font-size: 12px;
      color: #94a3b8;
    }
  }

  .import-modal-content {
    .template-download-bar {
      display: flex;
      gap: 12px;
    }
  }
}
</style>
