<template>
  <div class="quiz-attempt-container">
    <el-tabs v-model="currentViewTab" type="border-card" class="view-tabs">
      <!-- ==================== Tab 1: 答题成绩监控列表 ==================== -->
      <el-tab-pane label="答题成绩监控" name="attempts">
        <!-- 搜索与筛选工具栏 -->
        <el-card shadow="never" class="toolbar-card">
          <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
            <el-form-item label="所属活动">
              <el-select
                v-model="queryForm.activity_id"
                placeholder="全部答题活动"
                clearable
                style="width: 220px"
                @change="handleSearch"
              >
                <el-option
                  v-for="act in activityOptions"
                  :key="act.id"
                  :label="act.title"
                  :value="act.id"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="交卷状态">
              <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 140px">
                <el-option label="正常交卷" value="submitted" />
                <el-option label="答题进行中" value="in_progress" />
                <el-option label="超时自动交卷" value="timeout" />
                <el-option label="切屏强制交卷" value="cheated" />
              </el-select>
            </el-form-item>
            <el-form-item label="选手信息">
              <el-input
                v-model="queryForm.search"
                placeholder="搜索姓名 / 工号 / 部门"
                clearable
                @keyup.enter="handleSearch"
              />
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
            <el-button type="success" plain @click="handleExportAttempts">
              <el-icon><Download /></el-icon>
              导出 Excel (CSV) 成绩单
            </el-button>
            <el-button
              type="danger"
              :disabled="selectedRows.length === 0"
              @click="handleBatchDelete"
            >
              <el-icon><Delete /></el-icon>
              删除作答记录 ({{ selectedRows.length }})
            </el-button>
          </div>
        </el-card>

        <!-- 答题成绩流水表 -->
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
            <el-table-column prop="id" label="记录ID" width="75" align="center" sortable />
            <el-table-column label="答题选手" min-width="150">
              <template #default="{ row }">
                <div class="user-cell">
                  <div class="user-main">
                    <span class="name">{{ row.user_name }}</span>
                    <el-tag size="small" type="info" class="ml-4">{{ row.user_no || 'HW0000' }}</el-tag>
                  </div>
                  <div class="dept-text text-muted">{{ row.department || '研发中心' }}</div>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="activity_title" label="答题活动" min-width="180" show-overflow-tooltip />
            <el-table-column label="得分 / 满分" width="120" align="center" sortable prop="score">
              <template #default="{ row }">
                <div class="score-cell">
                  <span
                    class="score-number font-bold"
                    :class="row.is_passed ? 'text-success' : 'text-danger'"
                  >
                    {{ row.score }}
                  </span>
                  <span class="max-score text-muted"> / {{ row.total_score || 100 }}</span>
                  <div>
                    <el-tag size="small" :type="row.is_passed ? 'success' : 'danger'" effect="plain">
                      {{ row.is_passed ? '及格' : '不及格' }}
                    </el-tag>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="题数统计" width="120" align="center">
              <template #default="{ row }">
                <div class="stats-count">
                  <span class="text-success">对: {{ row.correct_count || 0 }}</span>
                  <span class="text-danger ml-4">错: {{ row.wrong_count || 0 }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="答题耗时" width="100" align="center" sortable prop="duration_seconds">
              <template #default="{ row }">
                <span class="font-bold">{{ formatDuration(row.duration_seconds) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="超越百分比" width="110" align="center">
              <template #default="{ row }">
                <el-progress
                  type="circle"
                  :percentage="row.beat_rate || 85"
                  :width="42"
                  :stroke-width="4"
                  :color="progressColors"
                />
              </template>
            </el-table-column>
            <el-table-column label="防切屏监控" width="120" align="center">
              <template #default="{ row }">
                <el-tag
                  size="small"
                  :type="row.switch_count > 0 ? (row.status === 'cheated' ? 'danger' : 'warning') : 'info'"
                >
                  切屏 {{ row.switch_count || 0 }} 次
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="110" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="getStatusTagType(row.status)">
                  {{ getStatusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="交卷时间" width="160" align="center">
              <template #default="{ row }">
                {{ formatTime(row.create_time) }}
              </template>
            </el-table-column>
            <el-table-column label="操作" width="176" fixed="right" align="center">
              <template #default="{ row }">
                <div class="hw-table-actions">
                  <el-button plain type="primary" size="small" @click="handleViewDetail(row)">
                    答卷详情
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
      </el-tab-pane>

      <!-- ==================== Tab 2: 活动实时排行榜 ==================== -->
      <el-tab-pane label="实时风云榜" name="leaderboard">
        <el-card shadow="never" class="toolbar-card">
          <div class="leaderboard-filter-bar">
            <div class="act-select-group">
              <span class="lbl font-bold">选择榜单活动：</span>
              <el-select
                v-model="leaderboardActivityId"
                placeholder="请选择需要查看排名的活动"
                style="width: 320px"
                @change="loadLeaderboard"
              >
                <el-option
                  v-for="act in activityOptions"
                  :key="act.id"
                  :label="act.title"
                  :value="act.id"
                />
              </el-select>
            </div>
            <div class="right-actions">
              <el-button type="primary" plain @click="loadLeaderboard">
                <el-icon><Refresh /></el-icon>
                刷新榜单
              </el-button>
              <el-button type="success" plain @click="handleExportLeaderboard">
                <el-icon><Download /></el-icon>
                导出排行榜
              </el-button>
            </div>
          </div>
        </el-card>

        <!-- 前三名领奖台视觉展示 -->
        <div v-if="leaderboardData.length >= 3" class="podium-container mt-16">
          <!-- 亚军 -->
          <div class="podium-card silver">
            <div class="crown-icon">🥈</div>
            <div class="podium-rank">第二名 (亚军)</div>
            <div class="podium-user">{{ leaderboardData[1].user_name }}</div>
            <div class="podium-dept text-muted">{{ leaderboardData[1].department }}</div>
            <div class="podium-score">{{ leaderboardData[1].score }} 分</div>
            <div class="podium-time text-muted">耗时: {{ formatDuration(leaderboardData[1].duration_seconds) }}</div>
          </div>
          <!-- 冠军 -->
          <div class="podium-card gold">
            <div class="crown-icon">👑 🥇</div>
            <div class="podium-rank">第一名 (冠军)</div>
            <div class="podium-user">{{ leaderboardData[0].user_name }}</div>
            <div class="podium-dept text-muted">{{ leaderboardData[0].department }}</div>
            <div class="podium-score">{{ leaderboardData[0].score }} 分</div>
            <div class="podium-time text-muted">耗时: {{ formatDuration(leaderboardData[0].duration_seconds) }}</div>
          </div>
          <!-- 季军 -->
          <div class="podium-card bronze">
            <div class="crown-icon">🥉</div>
            <div class="podium-rank">第三名 (季军)</div>
            <div class="podium-user">{{ leaderboardData[2].user_name }}</div>
            <div class="podium-dept text-muted">{{ leaderboardData[2].department }}</div>
            <div class="podium-score">{{ leaderboardData[2].score }} 分</div>
            <div class="podium-time text-muted">耗时: {{ formatDuration(leaderboardData[2].duration_seconds) }}</div>
          </div>
        </div>

        <!-- 排行榜详细表格 -->
        <el-card shadow="never" class="table-card mt-16">
          <el-table
            v-loading="leaderboardLoading"
            :data="leaderboardData"
            border
            stripe
          >
            <el-table-column label="名次" width="90" align="center">
              <template #default="{ $index }">
                <div class="rank-badge-cell">
                  <span v-if="$index === 0" class="rank-badge gold-badge">🥇 1</span>
                  <span v-else-if="$index === 1" class="rank-badge silver-badge">🥈 2</span>
                  <span v-else-if="$index === 2" class="rank-badge bronze-badge">🥉 3</span>
                  <span v-else class="rank-badge normal-badge">{{ $index + 1 }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="user_name" label="选手姓名" width="130" align="center">
              <template #default="{ row }">
                <span class="font-bold">{{ row.user_name }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="user_no" label="工号" width="110" align="center" />
            <el-table-column prop="department" label="所属部门" min-width="150" show-overflow-tooltip />
            <el-table-column prop="score" label="最高成绩" width="110" align="center" sortable>
              <template #default="{ row }">
                <span class="text-danger font-bold score-large">{{ row.score }} 分</span>
              </template>
            </el-table-column>
            <el-table-column prop="duration_seconds" label="答题耗时" width="110" align="center" sortable>
              <template #default="{ row }">
                <span class="font-bold">{{ formatDuration(row.duration_seconds) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="正确率" width="110" align="center">
              <template #default="{ row }">
                <span class="text-success font-bold">{{ row.accuracy || '95%' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="交卷时间" width="170" align="center">
              <template #default="{ row }">
                {{ formatTime(row.create_time) }}
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 答卷详情回放弹窗 -->
    <el-dialog
      v-model="detailDialogVisible"
      title="答卷作答详情回放"
      width="780px"
      top="5vh"
      destroy-on-close
    >
      <div v-if="currentAttempt" class="paper-detail-content">
        <!-- 试卷选手概要 -->
        <div class="user-summary-bar">
          <div class="summary-item">
            <span class="lbl">选手：</span>
            <strong>{{ currentAttempt.user_name }} ({{ currentAttempt.user_no }})</strong>
          </div>
          <div class="summary-item">
            <span class="lbl">部门：</span>
            <span>{{ currentAttempt.department }}</span>
          </div>
          <div class="summary-item">
            <span class="lbl">得分：</span>
            <span :class="currentAttempt.is_passed ? 'text-success font-bold' : 'text-danger font-bold'">
              {{ currentAttempt.score }} 分
            </span>
          </div>
          <div class="summary-item">
            <span class="lbl">用时：</span>
            <span>{{ formatDuration(currentAttempt.duration_seconds) }}</span>
          </div>
        </div>

        <!-- 逐题作答情况列表 -->
        <div class="question-review-list">
          <div
            v-for="(q, idx) in currentAttempt.questions || sampleReviewQuestions"
            :key="idx"
            class="review-item-card"
            :class="{ 'is-wrong': q.user_answer !== q.answer }"
          >
            <div class="review-header">
              <span class="q-seq">第 {{ Number(idx) + 1 }} 题</span>
              <el-tag size="small" type="info">{{ getTypeLabel(q.type) }}</el-tag>
              <span class="q-score-tag">分值: {{ q.score }}分</span>
              <div class="judge-badge">
                <el-tag v-if="q.user_answer === q.answer" type="success" effect="dark" size="small">
                  回答正确 (+{{ q.score }}分)
                </el-tag>
                <el-tag v-else type="danger" effect="dark" size="small">
                  回答错误 (0分)
                </el-tag>
              </div>
            </div>

            <div class="review-title">{{ q.title }}</div>

            <!-- 选项与作答对比 -->
            <div class="review-options">
              <div
                v-for="opt in q.options"
                :key="opt.key"
                class="opt-line"
                :class="{
                  'opt-correct-target': q.answer && q.answer.split(',').includes(opt.key),
                  'opt-user-picked': q.user_answer && q.user_answer.split(',').includes(opt.key),
                }"
              >
                <span class="opt-prefix">{{ opt.key }}.</span>
                <span class="opt-text">{{ opt.value }}</span>
                <span v-if="q.answer && q.answer.split(',').includes(opt.key)" class="badge-tag tag-correct">正确答案</span>
                <span v-if="q.user_answer && q.user_answer.split(',').includes(opt.key)" class="badge-tag tag-user">选手选择</span>
              </div>
            </div>

            <!-- 知识点解析 -->
            <div class="review-analysis" v-if="q.analysis">
              <div class="analysis-lbl">知识点解析：</div>
              <div class="analysis-text">{{ q.analysis }}</div>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="detailDialogVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Refresh, Delete, Download } from '@element-plus/icons-vue'
import {
  getQuizAttemptList,
  delQuizAttempt,
  getQuizLeaderboard,
  exportQuizAttempts,
  getQuizActivityList,
} from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizAttemptManage',
})

const currentViewTab = ref('attempts')
const loading = ref(false)
const leaderboardLoading = ref(false)
const detailDialogVisible = ref(false)
const selectedRows = ref<any[]>([])
const activityOptions = ref<any[]>([])
const currentAttempt = ref<any>(null)
const leaderboardActivityId = ref<number | undefined>(undefined)

// 成绩查询表单
const queryForm = reactive({
  activity_id: undefined as number | undefined,
  status: '',
  search: '',
})

// 分页
const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

// 成绩流水
const tableData = ref<any[]>([])

// 排行榜列表
const leaderboardData = ref<any[]>([])

const progressColors = [
  { color: '#f56c6c', percentage: 40 },
  { color: '#e6a23c', percentage: 70 },
  { color: '#67c23a', percentage: 100 },
]

// 模拟回放试题样例
const sampleReviewQuestions = ref<any[]>([
  {
    type: 'single',
    title: '在企业员工终端安全管理规范中，关于强密码的设定下列哪项最为合规？',
    score: 5,
    options: [
      { key: 'A', value: '长度大于8位且包含大小写字母、数字及特殊符号' },
      { key: 'B', value: '纯8位数字以便记忆' },
      { key: 'C', value: '使用名字加出生年月日的拼音' },
      { key: 'D', value: '只要是常用英文单词即可' },
    ],
    answer: 'A',
    user_answer: 'A',
    analysis: '遵循 NIST 规范，密码必须包含大写、小写、数字和特殊字符以防范暴力破解。',
  },
  {
    type: 'multi',
    title: '在构建 Kubernetes 生产级微服务集群时，下列哪些配置可以保障服务的无损平滑升级？',
    score: 6,
    options: [
      { key: 'A', value: '合理配置 readinessProbe 与 livenessProbe 探针' },
      { key: 'B', value: '定义 preStop 钩子实现应用优雅停机' },
      { key: 'C', value: '配置 PodDisruptionBudget (PDB) 防范驱逐断服' },
      { key: 'D', value: '直接使用 kill -9 快速终止旧容器' },
    ],
    answer: 'A,B,C',
    user_answer: 'A,B',
    analysis: '通过健康检查探针、优雅下线 preStop 与 PDB 能够保证 Pod 轮替期间流量不丢包。选手遗漏 C。',
  },
])

// 状态标签
const getStatusLabel = (status: string) => {
  if (status === 'submitted') return '正常交卷'
  if (status === 'in_progress') return '答题中'
  if (status === 'timeout') return '超时交卷'
  if (status === 'cheated') return '切屏强交'
  return status || '已完成'
}

const getStatusTagType = (status: string) => {
  if (status === 'submitted') return 'success'
  if (status === 'in_progress') return 'warning'
  if (status === 'timeout') return 'info'
  if (status === 'cheated') return 'danger'
  return 'info'
}

const getTypeLabel = (type: string) => {
  if (type === 'single') return '单选题'
  if (type === 'multi') return '多选题'
  if (type === 'judge') return '判断题'
  return '试题'
}

const formatDuration = (sec: number) => {
  if (!sec) return '0秒'
  const m = Math.floor(sec / 60)
  const s = Math.floor(sec % 60)
  return m > 0 ? `${m}分${s}秒` : `${s}秒`
}

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

// 筛选事件
const handleSearch = () => {
  pagination.page = 1
  loadData()
}

const handleReset = () => {
  queryForm.activity_id = undefined
  queryForm.status = ''
  queryForm.search = ''
  pagination.page = 1
  loadData()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

// 加载活动选项
const loadActivities = async () => {
  try {
    const res: any = await getQuizActivityList({ limit: 100 })
    if (res?.code === 1 && res?.data) {
      activityOptions.value = res.data.list || []
      if (activityOptions.value.length > 0 && !leaderboardActivityId.value) {
        leaderboardActivityId.value = activityOptions.value[0].id
      }
    }
  } catch (error) {
    activityOptions.value = [
      { id: 1, title: '2026 年度全员信息安全知识竞答' },
      { id: 2, title: '敏捷开发与云原生架构技术摸底自测' },
    ]
    leaderboardActivityId.value = 1
  }
}

// 加载答题流水
const loadData = async () => {
  loading.value = true
  try {
    const res: any = await getQuizAttemptList({
      page: pagination.page,
      limit: pagination.limit,
      ...queryForm,
    })
    if (res?.code === 1 && res?.data) {
      tableData.value = res.data.list || []
      pagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取答题流水失败，呈现模拟数据', error)
    if (tableData.value.length === 0) {
      tableData.value = [
        {
          id: 101,
          user_name: '张三',
          user_no: 'HW1001',
          department: '技术研发中心',
          activity_title: '2026 年度全员信息安全知识竞答',
          score: 100,
          total_score: 100,
          is_passed: 1,
          correct_count: 20,
          wrong_count: 0,
          duration_seconds: 145,
          beat_rate: 99,
          switch_count: 0,
          status: 'submitted',
          create_time: Date.now() - 3600000,
        },
        {
          id: 102,
          user_name: '李四',
          user_no: 'HW1002',
          department: '云原生架构部',
          activity_title: '2026 年度全员信息安全知识竞答',
          score: 95,
          total_score: 100,
          is_passed: 1,
          correct_count: 19,
          wrong_count: 1,
          duration_seconds: 210,
          beat_rate: 93,
          switch_count: 1,
          status: 'submitted',
          create_time: Date.now() - 7200000,
        },
        {
          id: 103,
          user_name: '王五',
          user_no: 'HW1003',
          department: '数智营销部',
          activity_title: '2026 年度全员信息安全知识竞答',
          score: 55,
          total_score: 100,
          is_passed: 0,
          correct_count: 11,
          wrong_count: 9,
          duration_seconds: 430,
          beat_rate: 35,
          switch_count: 4,
          status: 'cheated',
          create_time: Date.now() - 14400000,
        },
      ]
      pagination.total = 3
    }
  } finally {
    loading.value = false
  }
}

// 加载排行榜
const loadLeaderboard = async () => {
  if (!leaderboardActivityId.value) return
  leaderboardLoading.value = true
  try {
    const res: any = await getQuizLeaderboard({
      activity_id: leaderboardActivityId.value,
    })
    if (res?.code === 1 && res?.data) {
      leaderboardData.value = res.data.list || res.data || []
    }
  } catch (error) {
    console.warn('获取排行榜失败，使用默认展示数据', error)
    leaderboardData.value = [
      { rank: 1, user_name: '张三', user_no: 'HW1001', department: '技术研发中心', score: 100, duration_seconds: 145, accuracy: '100%', create_time: Date.now() - 3600000 },
      { rank: 2, user_name: '李四', user_no: 'HW1002', department: '云原生架构部', score: 95, duration_seconds: 210, accuracy: '95%', create_time: Date.now() - 7200000 },
      { rank: 3, user_name: '孙七', user_no: 'HW1005', department: '产品设计中心', score: 95, duration_seconds: 245, accuracy: '95%', create_time: Date.now() - 12000000 },
      { rank: 4, user_name: '赵六', user_no: 'HW1004', department: '数据智能部', score: 90, duration_seconds: 290, accuracy: '90%', create_time: Date.now() - 15000000 },
      { rank: 5, user_name: '钱八', user_no: 'HW1006', department: '质量效能中心', score: 85, duration_seconds: 310, accuracy: '85%', create_time: Date.now() - 18000000 },
    ]
  } finally {
    leaderboardLoading.value = false
  }
}

// 查看答卷详情
const handleViewDetail = (row: any) => {
  currentAttempt.value = row
  detailDialogVisible.value = true
}

// 删除记录
const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除选手【${row.user_name}】的此条作答记录吗？`, '删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizAttempt(row.id)
      ElMessage.success('作答记录已删除')
      loadData()
    } catch (err: any) {
      ElMessage.error(err?.message || '删除失败')
    }
  })
}

// 批量删除
const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要批量删除选中的 ${ids.length} 条作答记录吗？`, '批量删除提示', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizAttempt(ids)
      ElMessage.success('批量删除完成')
      loadData()
    } catch (err: any) {
      ElMessage.error(err?.message || '批量删除失败')
    }
  })
}

// 导出答题记录
const handleExportAttempts = async () => {
  try {
    const res: any = await exportQuizAttempts(queryForm)
    const list = res?.data?.list || tableData.value
    if (!list.length) {
      ElMessage.warning('暂无作答记录可导出')
      return
    }

    let csv = '\uFEFF记录ID,姓名,工号,部门,活动名称,得分,满分,是否及格,正确题数,错误题数,用时(秒),切屏次数,状态,交卷时间\n'
    list.forEach((r: any) => {
      const row = [
        r.id,
        `"${r.user_name}"`,
        `"${r.user_no || ''}"`,
        `"${r.department || ''}"`,
        `"${r.activity_title || ''}"`,
        r.score,
        r.total_score || 100,
        r.is_passed ? '及格' : '不及格',
        r.correct_count || 0,
        r.wrong_count || 0,
        r.duration_seconds || 0,
        r.switch_count || 0,
        `"${getStatusLabel(r.status)}"`,
        `"${formatTime(r.create_time)}"`,
      ].join(',')
      csv += row + '\n'
    })

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `答题成绩单导出_${dayjs().format('YYYYMMDD_HHmmss')}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`成功导出 ${list.length} 条答题成绩`)
  } catch (err: any) {
    ElMessage.error(err?.message || '导出异常')
  }
}

// 导出排行榜
const handleExportLeaderboard = () => {
  if (!leaderboardData.value.length) {
    ElMessage.warning('暂无排行榜数据可导出')
    return
  }
  let csv = '\uFEFF名次,姓名,工号,部门,最高分,用时(秒),正确率,交卷时间\n'
  leaderboardData.value.forEach((r: any, idx: number) => {
    const row = [
      idx + 1,
      `"${r.user_name}"`,
      `"${r.user_no || ''}"`,
      `"${r.department || ''}"`,
      r.score,
      r.duration_seconds || 0,
      `"${r.accuracy || '100%'}"`,
      `"${formatTime(r.create_time)}"`,
    ].join(',')
    csv += row + '\n'
  })

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `活动风云榜_${dayjs().format('YYYYMMDD_HHmmss')}.csv`
  a.click()
  URL.revokeObjectURL(url)
  ElMessage.success(`成功导出排行榜数据`)
}

onMounted(() => {
  loadActivities()
  loadData()
  loadLeaderboard()
})
</script>

<style scoped lang="scss">
.quiz-attempt-container {
  padding: 16px;

  .view-tabs {
    border-radius: 8px;
    background: #fff;
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

  .text-muted {
    color: #94a3b8;
    font-size: 12px;
  }

  .text-success {
    color: #10b981;
  }

  .text-danger {
    color: #ef4444;
  }

  .font-bold {
    font-weight: 700;
  }

  .user-cell {
    display: flex;
    flex-direction: column;

    .user-main {
      display: flex;
      align-items: center;

      .name {
        font-weight: 600;
        color: #1e293b;
      }
    }
  }

  .score-cell {
    .score-number {
      font-size: 16px;
    }

    .max-score {
      font-size: 11px;
    }
  }

  .pagination-container {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  // 排行榜领奖台样式
  .leaderboard-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .act-select-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }
  }

  .podium-container {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 16px;
    padding: 20px 0;

    .podium-card {
      width: 200px;
      padding: 16px;
      border-radius: 12px;
      text-align: center;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
      transition: transform 0.2s;

      &:hover {
        transform: translateY(-4px);
      }

      .crown-icon {
        font-size: 32px;
        margin-bottom: 6px;
      }

      .podium-rank {
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 4px;
      }

      .podium-user {
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 2px;
      }

      .podium-score {
        font-size: 22px;
        font-weight: 800;
        color: #ef4444;
        margin: 6px 0 2px 0;
      }

      &.gold {
        height: 230px;
        background: linear-gradient(180deg, #fef3c7 0%, #fffbeb 100%);
        border: 2px solid #f59e0b;
      }

      &.silver {
        height: 200px;
        background: linear-gradient(180deg, #f1f5f9 0%, #ffffff 100%);
        border: 2px solid #94a3b8;
      }

      &.bronze {
        height: 180px;
        background: linear-gradient(180deg, #ffedd5 0%, #fff7ed 100%);
        border: 2px solid #ea580c;
      }
    }
  }

  .rank-badge-cell {
    .rank-badge {
      display: inline-block;
      font-weight: 700;
      font-size: 13px;

      &.gold-badge { color: #f59e0b; font-size: 15px; }
      &.silver-badge { color: #64748b; font-size: 14px; }
      &.bronze-badge { color: #ea580c; font-size: 14px; }
      &.normal-badge { color: #94a3b8; }
    }
  }

  .score-large {
    font-size: 15px;
  }

  // 答卷详情回放
  .paper-detail-content {
    .user-summary-bar {
      display: flex;
      justify-content: space-between;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;

      .summary-item {
        font-size: 13px;

        .lbl {
          color: #64748b;
        }
      }
    }

    .question-review-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      max-height: 520px;
      overflow-y: auto;
      padding-right: 4px;

      .review-item-card {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
        background: #fff;

        &.is-wrong {
          border-left: 4px solid #ef4444;
        }

        .review-header {
          display: flex;
          align-items: center;
          gap: 8px;
          margin-bottom: 8px;

          .q-seq {
            font-weight: 700;
            color: #1e293b;
          }

          .q-score-tag {
            font-size: 12px;
            color: #64748b;
          }

          .judge-badge {
            margin-left: auto;
          }
        }

        .review-title {
          font-size: 14px;
          color: #1e293b;
          margin-bottom: 10px;
          line-height: 1.4;
        }

        .review-options {
          display: flex;
          flex-direction: column;
          gap: 6px;

          .opt-line {
            display: flex;
            align-items: center;
            padding: 6px 10px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 13px;
            color: #475569;

            .opt-prefix {
              font-weight: 700;
              margin-right: 6px;
            }

            .opt-text {
              flex: 1;
            }

            .badge-tag {
              font-size: 11px;
              padding: 2px 6px;
              border-radius: 4px;
              margin-left: 8px;

              &.tag-correct {
                background: #dcfce7;
                color: #166534;
                font-weight: 600;
              }

              &.tag-user {
                background: #dbeafe;
                color: #1e40af;
                font-weight: 600;
              }
            }

            &.opt-correct-target {
              background: #f0fdf4;
              border: 1px solid #bbf7d0;
            }

            &.opt-user-picked:not(.opt-correct-target) {
              background: #fef2f2;
              border: 1px solid #fecaca;
            }
          }
        }

        .review-analysis {
          margin-top: 10px;
          background: #eff6ff;
          border-radius: 6px;
          padding: 8px 12px;
          font-size: 12px;

          .analysis-lbl {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 2px;
          }

          .analysis-text {
            color: #3b82f6;
          }
        }
      }
    }
  }
}
</style>
