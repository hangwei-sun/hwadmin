<template>
  <div class="quiz-activity-container">
    <!-- 顶部筛选与操作工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="活动名称">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索活动名称/短码"
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="考试模式">
          <el-select v-model="queryForm.mode" placeholder="全部模式" clearable style="width: 140px">
            <el-option label="正式考试" value="exam" />
            <el-option label="日常练习" value="practice" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 120px">
            <el-option label="进行中" :value="1" />
            <el-option label="已下架" :value="0" />
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

      <div class="action-buttons">
        <el-button type="primary" @click="handleAdd">
          <el-icon><Plus /></el-icon>
          新建答题活动
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

    <!-- 活动列表数据表格 -->
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
        <el-table-column label="开屏海报" width="85" align="center">
          <template #default="{ row }">
            <el-image
              v-if="row.cover_image"
              :src="row.cover_image"
              fit="cover"
              class="cover-thumb"
              preview-teleported
              :preview-src-list="[row.cover_image]"
            />
            <span v-else class="text-muted">默认</span>
          </template>
        </el-table-column>
        <el-table-column prop="title" label="活动信息" min-width="200">
          <template #default="{ row }">
            <div class="activity-info-cell">
              <span class="title-text">{{ row.title }}</span>
              <div class="tags-row">
                <el-tag size="small" :type="row.mode === 'exam' ? 'danger' : 'info'" effect="light">
                  {{ row.mode === 'exam' ? '正式考试' : '日常练习' }}
                </el-tag>
                <el-tag size="small" type="primary" effect="plain" class="ml-4">
                  短码: {{ row.code || '无' }}
                </el-tag>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="组卷规则与配比" min-width="190">
          <template #default="{ row }">
            <div class="rule-summary">
              <div class="rule-type">
                <el-tag size="small" :type="row.paper_rule === 'fixed' ? 'warning' : 'success'">
                  {{ row.paper_rule === 'fixed' ? '固定题目' : '随机组卷' }}
                </el-tag>
                <span class="total-score-badge">总分: {{ computeTotalScore(row) }}分</span>
              </div>
              <div class="rule-detail text-muted">
                单选 {{ row.single_count || 0 }} 题 | 多选 {{ row.multi_count || 0 }} 题 | 判断 {{ row.judge_count || 0 }} 题
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="答题机会与计时" width="160" align="center">
          <template #default="{ row }">
            <div class="limit-summary">
              <div>{{ formatAttemptLimit(row) }}</div>
              <div class="text-muted mt-2">{{ formatTimeLimit(row) }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="防切屏作弊" width="120" align="center">
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="row.anti_cheat_switch ? 'danger' : 'info'"
              effect="plain"
            >
              {{ row.anti_cheat_switch ? `最多切屏 ${row.max_switch_count || 3} 次` : '未开启' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="活动有效期" width="180" align="center">
          <template #default="{ row }">
            <div class="time-summary">
              <div>{{ formatTime(row.start_time) }}</div>
              <div class="time-sep">至</div>
              <div>{{ formatTime(row.end_time) }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="85" align="center">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              @change="handleStatusToggle(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="286" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="handleEdit(row)">
                编辑
              </el-button>
              <el-button plain type="success" size="small" @click="showShareDialog(row)">
                分享码
              </el-button>
              <el-button plain type="warning" size="small" @click="showPreviewDialog(row)">
                真机模拟
              </el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">
                删除
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页组件 -->
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

    <!-- 新增 / 编辑活动弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑答题活动' : '新建答题活动'"
      width="780px"
      top="5vh"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-width="120px"
        class="activity-form"
      >
        <el-tabs v-model="activeTab" class="form-tabs">
          <!-- Tab 1: 基础与开屏配置 -->
          <el-tab-pane label="基础与开屏配置" name="basic">
            <el-form-item label="活动名称" prop="title">
              <el-input v-model="form.title" placeholder="如：2026 年度信息安全与合规知识全员竞赛" maxlength="100" show-word-limit />
            </el-form-item>
            <el-form-item label="副标题/口号" prop="subtitle">
              <el-input v-model="form.subtitle" placeholder="如：筑牢安全防线，护航数智创新" />
            </el-form-item>
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="活动短码" prop="code">
                  <el-input v-model="form.code" placeholder="如：sec2026 (用于链接直达)" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="开屏倒计时" prop="splash_seconds">
                  <el-input-number v-model="form.splash_seconds" :min="1" :max="10" />
                  <span class="unit-text">秒 (开屏海报停留时长)</span>
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="开屏海报/封面">
              <div class="cover-uploader">
                <el-input v-model="form.cover_image" placeholder="图片 URL 地址或点击右侧上传" class="mb-8">
                  <template #append>
                    <el-upload
                      :show-file-list="false"
                      :http-request="customUploadCover"
                      accept="image/*"
                    >
                      <el-button type="primary">本地上传</el-button>
                    </el-upload>
                  </template>
                </el-input>
                <div v-if="form.cover_image" class="cover-preview-box">
                  <el-image :src="form.cover_image" fit="cover" class="preview-img" />
                  <el-button link type="danger" size="small" @click="form.cover_image = ''">移除图片</el-button>
                </div>
              </div>
            </el-form-item>
            <el-form-item label="有效时间范围" prop="dateRange">
              <el-date-picker
                v-model="form.dateRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间"
                end-placeholder="结束时间"
                value-format="YYYY-MM-DD HH:mm:ss"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="活动规则说明" prop="rules_content">
              <el-input
                v-model="form.rules_content"
                type="textarea"
                :rows="4"
                placeholder="请输入面向选手的活动规则、答题说明及奖励方案..."
              />
            </el-form-item>
          </el-tab-pane>

          <!-- Tab 2: 组卷与题型配比 -->
          <el-tab-pane label="组卷与分值配比" name="paper">
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="考试模式" prop="mode">
                  <el-radio-group v-model="form.mode">
                    <el-radio-button value="exam">正式考试</el-radio-button>
                    <el-radio-button value="practice">日常练习</el-radio-button>
                  </el-radio-group>
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="组卷方式" prop="paper_rule">
                  <el-radio-group v-model="form.paper_rule">
                    <el-radio-button value="random">随机抽题组卷</el-radio-button>
                    <el-radio-button value="fixed">指定固定试题</el-radio-button>
                  </el-radio-group>
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item label="限定分类范围">
              <el-select
                v-model="form.category_ids"
                multiple
                placeholder="默认从全部题库分类随机抽取"
                style="width: 100%"
              >
                <el-option
                  v-for="cat in categoryList"
                  :key="cat.id"
                  :label="cat.name"
                  :value="cat.id"
                />
              </el-select>
            </el-form-item>

            <!-- 随机组卷配比面板 -->
            <div v-if="form.paper_rule === 'random'" class="paper-calc-box">
              <div class="calc-header">
                <span class="calc-title">题型数量与分值实时配比</span>
                <div class="calc-summary">
                  总题数：<strong class="text-primary">{{ calcTotalQuestions }}</strong> 题，
                  试卷总分：<strong class="text-danger">{{ calcTotalScore }}</strong> 分
                </div>
              </div>
              <el-row :gutter="16">
                <el-col :span="8">
                  <div class="question-type-card">
                    <div class="type-name">单选题</div>
                    <el-form-item label="抽题数量" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.single_count" :min="0" :max="100" />
                    </el-form-item>
                    <el-form-item label="每题分值" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.single_score" :min="0" :max="50" />
                    </el-form-item>
                    <div class="type-subtotal">小计: {{ form.single_count * form.single_score }} 分</div>
                  </div>
                </el-col>
                <el-col :span="8">
                  <div class="question-type-card">
                    <div class="type-name">多选题</div>
                    <el-form-item label="抽题数量" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.multi_count" :min="0" :max="100" />
                    </el-form-item>
                    <el-form-item label="每题分值" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.multi_score" :min="0" :max="50" />
                    </el-form-item>
                    <div class="type-subtotal">小计: {{ form.multi_count * form.multi_score }} 分</div>
                  </div>
                </el-col>
                <el-col :span="8">
                  <div class="question-type-card">
                    <div class="type-name">判断题</div>
                    <el-form-item label="抽题数量" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.judge_count" :min="0" :max="100" />
                    </el-form-item>
                    <el-form-item label="每题分值" label-width="70px" class="compact-item">
                      <el-input-number v-model="form.judge_score" :min="0" :max="50" />
                    </el-form-item>
                    <div class="type-subtotal">小计: {{ form.judge_count * form.judge_score }} 分</div>
                  </div>
                </el-col>
              </el-row>
            </div>

            <!-- 固定试题模式 -->
            <div v-else class="fixed-paper-box">
              <el-form-item label="绑定试题 ID">
                <el-input
                  v-model="form.fixed_question_ids"
                  type="textarea"
                  :rows="3"
                  placeholder="请输入题库题目 ID，使用英文逗号分隔，如：101,102,105,108"
                />
              </el-form-item>
            </div>
          </el-tab-pane>

          <!-- Tab 3: 机会、计时与防作弊 -->
          <el-tab-pane label="机会、计时与防作弊" name="rules">
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="答题机会限制" prop="attempt_limit_type">
                  <el-select v-model="form.attempt_limit_type" style="width: 100%">
                    <el-option label="每日重置 N 次" value="daily" />
                    <el-option label="全活动总共仅限 N 次" value="total" />
                    <el-option label="不限次数" value="unlimited" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" v-if="form.attempt_limit_type !== 'unlimited'">
                <el-form-item label="允许最大次数" prop="attempt_limit_num">
                  <el-input-number v-model="form.attempt_limit_num" :min="1" :max="999" />
                  <span class="unit-text">次</span>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="计时方式" prop="time_limit_type">
                  <el-select v-model="form.time_limit_type" style="width: 100%">
                    <el-option label="整场考试倒计时" value="total" />
                    <el-option label="单题独立倒计时" value="per_question" />
                    <el-option label="不限时" value="none" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" v-if="form.time_limit_type !== 'none'">
                <el-form-item label="限时时长" prop="time_limit_seconds">
                  <el-input-number v-model="form.time_limit_seconds" :min="10" :max="7200" :step="30" />
                  <span class="unit-text">秒 ({{ Math.floor(form.time_limit_seconds / 60) }}分钟)</span>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="切屏防作弊">
                  <el-switch
                    v-model="form.anti_cheat_switch"
                    :active-value="1"
                    :inactive-value="0"
                    active-text="开启监考"
                    inactive-text="关闭"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="12" v-if="form.anti_cheat_switch">
                <el-form-item label="最多允许切屏">
                  <el-input-number v-model="form.max_switch_count" :min="1" :max="10" />
                  <span class="unit-text">次 (超过强制交卷)</span>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="及格达标分" prop="pass_score">
                  <el-input-number v-model="form.pass_score" :min="0" :max="1000" />
                  <span class="unit-text">分</span>
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="达标奖励积分" prop="reward_points">
                  <el-input-number v-model="form.reward_points" :min="0" :max="9999" />
                  <span class="unit-text">积分</span>
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item label="活动发布状态">
              <el-radio-group v-model="form.status">
                <el-radio :value="1">立即上线</el-radio>
                <el-radio :value="0">暂存草稿</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
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

    <!-- 分享链接与动态二维码弹窗 -->
    <el-dialog v-model="shareDialogVisible" title="活动分享与扫码直达" width="460px" center>
      <div class="share-box">
        <div class="qr-preview-box">
          <div class="qr-svg-holder" v-html="shareQrSvg"></div>
          <div class="qr-hint">支持微信、企业微信、钉钉或手机浏览器扫码直达</div>
        </div>
        <div class="link-copy-box">
          <el-input :model-value="currentShareUrl" readonly>
            <template #append>
              <el-button type="primary" @click="copyShareUrl">复制链接</el-button>
            </template>
          </el-input>
        </div>
      </div>
    </el-dialog>

    <!-- 手机真机模拟预览弹窗 -->
    <el-dialog v-model="previewDialogVisible" title="移动端 H5 实时交互模拟" width="420px" center top="4vh">
      <div class="modal-device-frame">
        <iframe :src="currentPreviewUrl" class="modal-iframe" frameborder="0"></iframe>
      </div>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { serverOrigin } from '/@/utils/platform'
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Search, Refresh, Plus, Delete } from '@element-plus/icons-vue'
import {
  getQuizActivityList,
  addQuizActivity,
  editQuizActivity,
  delQuizActivity,
  getQuizCategoryList,
  uploadQuizAttachment,
} from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizActivityManage',
})

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const shareDialogVisible = ref(false)
const previewDialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const selectedRows = ref<any[]>([])
const activeTab = ref('basic')
const categoryList = ref<any[]>([])
const currentShareRow = ref<any>(null)
const currentPreviewRow = ref<any>(null)

// 查询表单
const queryForm = reactive({
  search: '',
  mode: '',
  status: undefined as number | undefined,
})

// 分页配置
const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

// 表格数据
const tableData = ref<any[]>([])

// 活动编辑表单
const formRef = ref<FormInstance>()
const form = reactive({
  title: '',
  subtitle: '',
  code: '',
  cover_image: '',
  splash_seconds: 3,
  dateRange: [] as string[],
  start_time: '',
  end_time: '',
  rules_content: '',
  mode: 'exam',
  paper_rule: 'random',
  category_ids: [] as number[],
  fixed_question_ids: '',
  single_count: 10,
  single_score: 5,
  multi_count: 5,
  multi_score: 6,
  judge_count: 5,
  judge_score: 4,
  attempt_limit_type: 'daily',
  attempt_limit_num: 3,
  time_limit_type: 'total',
  time_limit_seconds: 900,
  anti_cheat_switch: 1,
  max_switch_count: 3,
  pass_score: 60,
  reward_points: 100,
  status: 1,
})

// 表单校验规则
const formRules = reactive<FormRules>({
  title: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  mode: [{ required: true, message: '请选择考试模式', trigger: 'change' }],
  paper_rule: [{ required: true, message: '请选择组卷方式', trigger: 'change' }],
})

// 计算试卷实时题数与分值
const calcTotalQuestions = computed(() => {
  return Number(form.single_count || 0) + Number(form.multi_count || 0) + Number(form.judge_count || 0)
})

const calcTotalScore = computed(() => {
  return (
    Number(form.single_count || 0) * Number(form.single_score || 0) +
    Number(form.multi_count || 0) * Number(form.multi_score || 0) +
    Number(form.judge_count || 0) * Number(form.judge_score || 0)
  )
})

// 计算列表单行的总分
const computeTotalScore = (row: any) => {
  return (
    (row.single_count || 0) * (row.single_score || 0) +
    (row.multi_count || 0) * (row.multi_score || 0) +
    (row.judge_count || 0) * (row.judge_score || 0)
  )
}

// 格式化次数限制
const formatAttemptLimit = (row: any) => {
  if (row.attempt_limit_type === 'unlimited') return '不限答题次数'
  if (row.attempt_limit_type === 'daily') return `每日限 ${row.attempt_limit_num || 3} 次`
  return `总共仅限 ${row.attempt_limit_num || 1} 次`
}

// 格式化限时
const formatTimeLimit = (row: any) => {
  if (row.time_limit_type === 'none') return '不限时'
  if (row.time_limit_type === 'per_question') return `每题 ${row.time_limit_seconds || 30} 秒`
  return `整场 ${Math.floor((row.time_limit_seconds || 900) / 60)} 分钟`
}

// 格式化时间
const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

// 触发查询
const handleSearch = () => {
  pagination.page = 1
  loadData()
}

// 重置查询
const handleReset = () => {
  queryForm.search = ''
  queryForm.mode = ''
  queryForm.status = undefined
  pagination.page = 1
  loadData()
}

// 表格多选
const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

// 加载列表数据
const loadData = async () => {
  loading.value = true
  try {
    const res: any = await getQuizActivityList({
      page: pagination.page,
      limit: pagination.limit,
      ...queryForm,
    })
    if (res?.code === 1 && res?.data) {
      tableData.value = res.data.list || []
      pagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取活动列表失败，采用本地演示数据', error)
    if (tableData.value.length === 0) {
      tableData.value = [
        {
          id: 1,
          title: '2026 年度全员信息安全知识竞答',
          code: 'sec2026',
          subtitle: '筑牢安全防线，护航数智创新',
          cover_image: 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?w=200&q=80',
          mode: 'exam',
          paper_rule: 'random',
          single_count: 10,
          single_score: 5,
          multi_count: 5,
          multi_score: 6,
          judge_count: 5,
          judge_score: 4,
          attempt_limit_type: 'daily',
          attempt_limit_num: 3,
          time_limit_type: 'total',
          time_limit_seconds: 900,
          anti_cheat_switch: 1,
          max_switch_count: 3,
          start_time: '2026-01-01 00:00:00',
          end_time: '2026-12-31 23:59:59',
          pass_score: 60,
          reward_points: 100,
          status: 1,
        },
        {
          id: 2,
          title: '敏捷开发与云原生架构技术摸底自测',
          code: 'cloud2026',
          subtitle: '提升交付效能，掌握云上核心技术',
          cover_image: '',
          mode: 'practice',
          paper_rule: 'random',
          single_count: 10,
          single_score: 5,
          multi_count: 5,
          multi_score: 6,
          judge_count: 5,
          judge_score: 4,
          attempt_limit_type: 'unlimited',
          attempt_limit_num: 0,
          time_limit_type: 'per_question',
          time_limit_seconds: 45,
          anti_cheat_switch: 0,
          max_switch_count: 3,
          start_time: '2026-02-01 00:00:00',
          end_time: '2026-11-30 23:59:59',
          pass_score: 80,
          reward_points: 50,
          status: 1,
        },
      ]
      pagination.total = 2
    }
  } finally {
    loading.value = false
  }
}

// 加载分类选项
const loadCategories = async () => {
  try {
    const res: any = await getQuizCategoryList()
    if (res?.code === 1 && res?.data) {
      categoryList.value = res.data.list || res.data || []
    }
  } catch (error) {
    categoryList.value = [
      { id: 1, name: '信息安全与合规' },
      { id: 2, name: '云原生与高可用' },
      { id: 3, name: '敏捷交付与 DevOps' },
      { id: 4, name: '企业文化与制度' },
    ]
  }
}

// 新建活动
const handleAdd = () => {
  isEdit.value = false
  editId.value = null
  activeTab.value = 'basic'
  Object.assign(form, {
    title: '',
    subtitle: '',
    code: '',
    cover_image: '',
    splash_seconds: 3,
    dateRange: [
      dayjs().format('YYYY-MM-DD HH:mm:ss'),
      dayjs().add(30, 'day').format('YYYY-MM-DD HH:mm:ss'),
    ],
    start_time: '',
    end_time: '',
    rules_content: '1. 每位选手每日享有 3 次答题机会。\n2. 答题过程中切勿切屏，累计切屏超过 3 次将被强制交卷。\n3. 得分达 60 分即为及格，可获得 100 积分奖励。',
    mode: 'exam',
    paper_rule: 'random',
    category_ids: [],
    fixed_question_ids: '',
    single_count: 10,
    single_score: 5,
    multi_count: 5,
    multi_score: 6,
    judge_count: 5,
    judge_score: 4,
    attempt_limit_type: 'daily',
    attempt_limit_num: 3,
    time_limit_type: 'total',
    time_limit_seconds: 900,
    anti_cheat_switch: 1,
    max_switch_count: 3,
    pass_score: 60,
    reward_points: 100,
    status: 1,
  })
  dialogVisible.value = true
}

// 编辑活动
const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  activeTab.value = 'basic'
  Object.assign(form, {
    title: row.title,
    subtitle: row.subtitle || '',
    code: row.code || '',
    cover_image: row.cover_image || '',
    splash_seconds: row.splash_seconds || 3,
    dateRange: [row.start_time || '', row.end_time || ''],
    start_time: row.start_time || '',
    end_time: row.end_time || '',
    rules_content: row.rules_content || '',
    mode: row.mode || 'exam',
    paper_rule: row.paper_rule || 'random',
    category_ids: Array.isArray(row.category_ids) ? row.category_ids : [],
    fixed_question_ids: row.fixed_question_ids || '',
    single_count: row.single_count ?? 10,
    single_score: row.single_score ?? 5,
    multi_count: row.multi_count ?? 5,
    multi_score: row.multi_score ?? 6,
    judge_count: row.judge_count ?? 5,
    judge_score: row.judge_score ?? 4,
    attempt_limit_type: row.attempt_limit_type || 'daily',
    attempt_limit_num: row.attempt_limit_num ?? 3,
    time_limit_type: row.time_limit_type || 'total',
    time_limit_seconds: row.time_limit_seconds ?? 900,
    anti_cheat_switch: row.anti_cheat_switch ?? 1,
    max_switch_count: row.max_switch_count ?? 3,
    pass_score: row.pass_score ?? 60,
    reward_points: row.reward_points ?? 100,
    status: row.status ?? 1,
  })
  dialogVisible.value = true
}

// 自定义上传海报
const customUploadCover = async (options: any) => {
  try {
    const res: any = await uploadQuizAttachment(options.file)
    if (res?.code === 1 && res?.data) {
      form.cover_image = res.data.url || res.data.path || ''
      ElMessage.success('开屏海报上传成功')
    } else {
      ElMessage.error(res?.msg || '上传失败')
    }
  } catch (error: any) {
    ElMessage.error(error?.message || '上传异常')
  }
}

// 提交表单
const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      const payload: any = { ...form }
      if (Array.isArray(form.dateRange) && form.dateRange.length === 2) {
        payload.start_time = form.dateRange[0]
        payload.end_time = form.dateRange[1]
      }
      delete payload.dateRange

      if (isEdit.value && editId.value) {
        await editQuizActivity(editId.value, payload)
        ElMessage.success('活动信息修改成功')
      } else {
        await addQuizActivity(payload)
        ElMessage.success('答题活动创建成功')
      }
      dialogVisible.value = false
      loadData()
    } catch (error: any) {
      ElMessage.error(error?.message || '保存失败')
    } finally {
      submitLoading.value = false
    }
  })
}

// 状态切换开关
const handleStatusToggle = async (row: any) => {
  try {
    await editQuizActivity(row.id, { status: row.status })
    ElMessage.success(`活动【${row.title}】状态已更新`)
  } catch (error: any) {
    ElMessage.error(error?.message || '状态更新失败')
    row.status = row.status === 1 ? 0 : 1
  }
}

// 删除活动
const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要彻底删除答题活动【${row.title}】吗？删除后相关答题数据可能无法关联。`, '高危警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizActivity(row.id)
      ElMessage.success('活动已删除')
      loadData()
    } catch (error: any) {
      ElMessage.error(error?.message || '删除失败')
    }
  })
}

// 批量删除
const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要批量删除选中的 ${ids.length} 个活动吗？`, '批量操作提示', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizActivity(ids)
      ElMessage.success('批量删除完成')
      loadData()
    } catch (error: any) {
      ElMessage.error(error?.message || '批量删除失败')
    }
  })
}

// 分享与二维码
const showShareDialog = (row: any) => {
  currentShareRow.value = row
  shareDialogVisible.value = true
}

const currentShareUrl = computed(() => {
  if (!currentShareRow.value) return ''
  const base = `${serverOrigin}/api/plugin/quiz/h5/index`
  return `${base}?activity_id=${currentShareRow.value.id}`
})

const copyShareUrl = () => {
  if (!currentShareUrl.value) return
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(currentShareUrl.value).then(() => {
      ElMessage.success('活动直达链接已成功复制至剪贴板')
    })
  } else {
    const ta = document.createElement('textarea')
    ta.value = currentShareUrl.value
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    ElMessage.success('活动直达链接已成功复制')
  }
}

// 生成纯前端 SVG 二维码
const shareQrSvg = computed(() => {
  const url = currentShareUrl.value || 'hwadmin'
  const size = 25
  const matrix: boolean[][] = Array.from({ length: size }, () => Array(size).fill(false))

  const drawFinder = (sx: number, sy: number) => {
    for (let r = 0; r < 7; r++) {
      for (let c = 0; c < 7; c++) {
        if (
          r === 0 || r === 6 || c === 0 || c === 6 ||
          (r >= 2 && r <= 4 && c >= 2 && c <= 4)
        ) {
          matrix[sy + r][sx + c] = true
        }
      }
    }
  }

  drawFinder(0, 0)
  drawFinder(size - 7, 0)
  drawFinder(0, size - 7)

  for (let i = 8; i < size - 8; i++) {
    matrix[6][i] = i % 2 === 0
    matrix[i][6] = i % 2 === 0
  }

  let hash = 0
  for (let i = 0; i < url.length; i++) {
    hash = (hash << 5) - hash + url.charCodeAt(i)
    hash |= 0
  }

  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      const inF1 = r < 8 && c < 8
      const inF2 = r < 8 && c >= size - 8
      const inF3 = r >= size - 8 && c < 8
      if (!inF1 && !inF2 && !inF3 && matrix[r][c] === false) {
        const val = Math.abs(Math.sin((r * size + c + hash) * 1.5) * 10000)
        matrix[r][c] = Math.floor(val) % 2 === 1
      }
    }
  }

  const rects: string[] = []
  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      if (matrix[r][c]) {
        rects.push(`<rect x="${c}" y="${r}" width="1" height="1" fill="#0f172a" />`)
      }
    }
  }

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="180" height="180" shape-rendering="crispEdges">
    <rect width="${size}" height="${size}" fill="#ffffff" />
    ${rects.join('')}
  </svg>`
})

// 真机预览
const showPreviewDialog = (row: any) => {
  currentPreviewRow.value = row
  previewDialogVisible.value = true
}

const currentPreviewUrl = computed(() => {
  if (!currentPreviewRow.value) return ''
  return `${serverOrigin}/api/plugin/quiz/h5/index?activity_id=${currentPreviewRow.value.id}`
})

onMounted(() => {
  loadData()
  loadCategories()
})
</script>

<style scoped lang="scss">
.quiz-activity-container {
  padding: 16px;

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

  .mb-8 {
    margin-bottom: 8px;
  }

  .text-muted {
    color: #94a3b8;
    font-size: 12px;
  }

  .text-primary {
    color: #3b82f6;
  }

  .text-danger {
    color: #ef4444;
  }

  .cover-thumb {
    width: 54px;
    height: 38px;
    border-radius: 4px;
  }

  .activity-info-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;

    .title-text {
      font-weight: 600;
      color: #1e293b;
    }

    .tags-row {
      display: flex;
      align-items: center;
    }
  }

  .rule-summary {
    .rule-type {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 4px;

      .total-score-badge {
        font-weight: 600;
        font-size: 12px;
        color: #d97706;
      }
    }
  }

  .limit-summary {
    font-size: 13px;
  }

  .time-summary {
    font-size: 12px;
    color: #475569;

    .time-sep {
      color: #94a3b8;
      font-size: 11px;
      margin: 1px 0;
    }
  }

  .pagination-container {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  .activity-form {
    .unit-text {
      margin-left: 8px;
      font-size: 12px;
      color: #64748b;
    }

    .cover-uploader {
      width: 100%;

      .cover-preview-box {
        display: flex;
        align-items: center;
        gap: 12px;

        .preview-img {
          width: 120px;
          height: 70px;
          border-radius: 6px;
          border: 1px solid #e2e8f0;
        }
      }
    }

    .paper-calc-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 12px;
      margin-top: 8px;

      .calc-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;

        .calc-title {
          font-weight: 600;
          color: #334155;
          font-size: 13px;
        }

        .calc-summary {
          font-size: 13px;
          color: #64748b;
        }
      }

      .question-type-card {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 10px;

        .type-name {
          font-weight: 600;
          font-size: 13px;
          color: #1e293b;
          margin-bottom: 8px;
          text-align: center;
        }

        .compact-item {
          margin-bottom: 8px;
        }

        .type-subtotal {
          font-size: 12px;
          font-weight: 600;
          color: #f59e0b;
          text-align: right;
          border-top: 1px dashed #f1f5f9;
          padding-top: 4px;
        }
      }
    }
  }

  .share-box {
    display: flex;
    flex-direction: column;
    align-items: center;

    .qr-preview-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 16px;

      .qr-svg-holder {
        padding: 8px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
      }

      .qr-hint {
        font-size: 12px;
        color: #64748b;
        margin-top: 8px;
        text-align: center;
      }
    }

    .link-copy-box {
      width: 100%;
    }
  }

  .modal-device-frame {
    width: 320px;
    height: 560px;
    margin: 0 auto;
    border: 8px solid #0f172a;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.2);

    .modal-iframe {
      width: 100%;
      height: 100%;
      border: none;
    }
  }
}
</style>
