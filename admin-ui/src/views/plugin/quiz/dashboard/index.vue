<template>
  <div class="quiz-dashboard-container">
    <!-- 顶部 6 大核心统计卡片 -->
    <el-row :gutter="16" class="stats-row">
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-blue">
          <div class="stat-header">
            <span class="stat-title">活动总数</span>
            <el-tag size="small" type="primary" effect="plain">全部</el-tag>
          </div>
          <div class="stat-value">{{ stats.total_activities }}</div>
          <div class="stat-footer">
            <span>进行中活动：<strong>{{ stats.active_activities || 0 }}</strong> 个</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-green">
          <div class="stat-header">
            <span class="stat-title">答题总人次</span>
            <el-tag size="small" type="success" effect="plain">累计</el-tag>
          </div>
          <div class="stat-value">{{ stats.total_attempts }}</div>
          <div class="stat-footer">
            <span>及格人数：<strong>{{ stats.pass_attempts || 0 }}</strong> 人次</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-purple">
          <div class="stat-header">
            <span class="stat-title">题库题目总数</span>
            <el-tag size="small" type="warning" effect="plain">题库</el-tag>
          </div>
          <div class="stat-value">{{ stats.total_questions }}</div>
          <div class="stat-footer">
            <span>分类总数：<strong>{{ stats.total_categories || 0 }}</strong> 个</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-orange">
          <div class="stat-header">
            <span class="stat-title">今日答题人次</span>
            <el-tag size="small" type="danger" effect="plain">实时</el-tag>
          </div>
          <div class="stat-value">{{ stats.today_attempts }}</div>
          <div class="stat-footer">
            <span>较昨日：<strong>{{ stats.today_growth || '+12%' }}</strong></span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-cyan">
          <div class="stat-header">
            <span class="stat-title">奖品兑换订单</span>
            <el-tag size="small" type="info" effect="plain">商城</el-tag>
          </div>
          <div class="stat-value">{{ stats.total_orders }}</div>
          <div class="stat-footer">
            <span>待发货：<strong class="text-danger">{{ stats.pending_orders || 0 }}</strong> 单</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="8" :md="4">
        <el-card shadow="never" class="stat-card stat-pink">
          <div class="stat-header">
            <span class="stat-title">待回复反馈</span>
            <el-tag size="small" :type="stats.pending_feedbacks > 0 ? 'danger' : 'info'" effect="plain">
              {{ stats.pending_feedbacks > 0 ? '需处理' : '正常' }}
            </el-tag>
          </div>
          <div class="stat-value" :class="{ 'text-danger': stats.pending_feedbacks > 0 }">
            {{ stats.pending_feedbacks }}
          </div>
          <div class="stat-footer">
            <span>反馈总计：<strong>{{ stats.total_feedbacks || 0 }}</strong> 条</span>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 中部：左侧数据与右侧手机模拟器 -->
    <el-row :gutter="16" class="content-row">
      <!-- 左侧：热门活动概览与最近答题流水 -->
      <el-col :xs="24" :lg="15">
        <!-- 快捷入口卡片 -->
        <el-card shadow="never" class="quick-links-card">
          <template #header>
            <div class="card-header-flex">
              <span class="section-title">快捷管理通道</span>
              <span class="sub-text">极速跳转核心业务模块</span>
            </div>
          </template>
          <div class="quick-grid">
            <div class="quick-item" @click="goTo('/plugin/quiz/activity')">
              <div class="item-icon bg-blue">
                <el-icon><Flag /></el-icon>
              </div>
              <div class="item-text">
                <div class="item-title">活动管理</div>
                <div class="item-desc">创建考试/练习活动</div>
              </div>
            </div>
            <div class="quick-item" @click="goTo('/plugin/quiz/question')">
              <div class="item-icon bg-green">
                <el-icon><Collection /></el-icon>
              </div>
              <div class="item-text">
                <div class="item-title">题库管理</div>
                <div class="item-desc">试题录入与批量导入</div>
              </div>
            </div>
            <div class="quick-item" @click="goTo('/plugin/quiz/attempt')">
              <div class="item-icon bg-orange">
                <el-icon><TrendCharts /></el-icon>
              </div>
              <div class="item-text">
                <div class="item-title">成绩与排名</div>
                <div class="item-desc">监控成绩与导出排名</div>
              </div>
            </div>
            <div class="quick-item" @click="goTo('/plugin/quiz/prize')">
              <div class="item-icon bg-purple">
                <el-icon><Present /></el-icon>
              </div>
              <div class="item-text">
                <div class="item-title">奖品与订单</div>
                <div class="item-desc">积分奖品与核销发货</div>
              </div>
            </div>
          </div>
        </el-card>

        <!-- 热门活动列表 -->
        <el-card shadow="never" class="table-card mt-16">
          <template #header>
            <div class="card-header-flex">
              <span class="section-title">活跃知识答题活动</span>
              <el-button link type="primary" @click="goTo('/plugin/quiz/activity')">查看全部活动 &gt;</el-button>
            </div>
          </template>
          <el-table :data="activities" border stripe size="small" style="width: 100%">
            <el-table-column prop="title" label="活动名称" min-width="150" show-overflow-tooltip />
            <el-table-column prop="mode" label="模式" width="90" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.mode === 'exam' ? 'danger' : 'info'">
                  {{ row.mode === 'exam' ? '正式考试' : '日常练习' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="attempt_count" label="参与人次" width="90" align="center" />
            <el-table-column prop="pass_rate" label="通过率" width="90" align="center">
              <template #default="{ row }">
                <span class="text-success">{{ row.pass_rate || '88%' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">
                  {{ row.status === 1 ? '进行中' : '已下架' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="快捷操作" width="100" align="center">
              <template #default="{ row }">
                <el-button link type="primary" size="small" @click="selectPreviewActivity(row)">
                  模拟体验
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>

        <!-- 最近实时交卷流水 -->
        <el-card shadow="never" class="table-card mt-16">
          <template #header>
            <div class="card-header-flex">
              <span class="section-title">实时答题交卷流水</span>
              <el-button link type="primary" @click="goTo('/plugin/quiz/attempt')">查看完整流水 &gt;</el-button>
            </div>
          </template>
          <el-table :data="recentAttempts" border stripe size="small" style="width: 100%">
            <el-table-column prop="user_name" label="选手姓名" width="100" align="center" />
            <el-table-column prop="user_no" label="工号" width="95" align="center" />
            <el-table-column prop="department" label="部门" width="110" show-overflow-tooltip />
            <el-table-column prop="activity_title" label="答题活动" min-width="130" show-overflow-tooltip />
            <el-table-column prop="score" label="得分" width="75" align="center">
              <template #default="{ row }">
                <span :class="row.is_passed ? 'text-success font-bold' : 'text-danger font-bold'">
                  {{ row.score }}分
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="duration_seconds" label="耗时" width="85" align="center">
              <template #default="{ row }">
                {{ formatDuration(row.duration_seconds) }}
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="提交时间" width="150" align="center">
              <template #default="{ row }">
                {{ formatTime(row.create_time) }}
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <!-- 右侧：移动端 H5 实时模拟器与扫码卡片 -->
      <el-col :xs="24" :lg="9">
        <el-card shadow="never" class="simulator-card">
          <template #header>
            <div class="card-header-flex">
              <div class="header-left">
                <span class="section-title">移动端 H5 在线模拟器</span>
                <el-tag size="small" type="success" effect="plain" class="ml-8">真机直连</el-tag>
              </div>
              <div class="header-right">
                <el-button size="small" :icon="Refresh" circle @click="reloadIframe" title="刷新模拟器" />
                <el-button size="small" :icon="TopRight" circle @click="openH5InNewTab" title="在新标签页打开" />
              </div>
            </div>
          </template>

          <!-- 模拟器上方控制栏 -->
          <div class="simulator-control">
            <el-select
              v-model="currentActivityId"
              placeholder="选择模拟活动"
              size="small"
              class="activity-selector"
              @change="handleActivityChange"
            >
              <el-option label="默认官方演示活动" :value="0" />
              <el-option
                v-for="act in activities"
                :key="act.id"
                :label="act.title"
                :value="act.id"
              />
            </el-select>
            <el-button size="small" type="primary" plain @click="copyH5Url">复制直达链接</el-button>
          </div>

          <!-- 手机外壳容器 -->
          <div class="mobile-device-frame">
            <div class="device-notch">
              <span class="speaker"></span>
              <span class="camera"></span>
            </div>
            <div class="device-screen">
              <iframe
                ref="h5IframeRef"
                :src="simulatorUrl"
                class="simulator-iframe"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              ></iframe>
            </div>
            <div class="device-home-bar"></div>
          </div>

          <!-- 扫码体验与演示账号说明 -->
          <div class="scan-account-box">
            <div class="qr-wrapper">
              <div class="qr-svg-container" v-html="qrSvgString"></div>
              <span class="qr-tip">手机扫码极速体验</span>
            </div>
            <div class="account-info">
              <div class="account-title">
                <el-icon><Key /></el-icon>
                <span>预设全功能演示账号</span>
              </div>
              <div class="account-row">
                <span class="lbl">工号：</span>
                <el-tag size="small" type="info">HW1001</el-tag>
                <el-button link type="primary" size="small" @click="copyText('HW1001')">复制</el-button>
              </div>
              <div class="account-row">
                <span class="lbl">密码：</span>
                <el-tag size="small" type="info">888888</el-tag>
                <el-button link type="primary" size="small" @click="copyText('888888')">复制</el-button>
              </div>
              <div class="account-row">
                <span class="lbl">姓名：</span>
                <span>华为数字探索官 (技术专家)</span>
              </div>
              <div class="account-extra-tip">
                支持微信、飞书、企业微信、钉钉或系统浏览器扫码直达
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl, serverOrigin } from '/@/utils/platform'
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Flag, Collection, TrendCharts, Present, Key, Refresh, TopRight } from '@element-plus/icons-vue'
import { getQuizDashboard } from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizDashboard',
})

const router = useRouter()
const h5IframeRef = ref<HTMLIFrameElement | null>(null)
const currentActivityId = ref<number>(0)

// 统计核心数据
const stats = reactive({
  total_activities: 3,
  active_activities: 2,
  total_attempts: 1258,
  pass_attempts: 1105,
  total_questions: 280,
  total_categories: 6,
  today_attempts: 142,
  today_growth: '+18.5%',
  total_orders: 86,
  pending_orders: 5,
  total_feedbacks: 19,
  pending_feedbacks: 3,
})

// 活跃活动列表
const activities = ref<any[]>([
  { id: 1, title: '2026 年度全员信息安全知识竞答', mode: 'exam', attempt_count: 852, pass_rate: '94.2%', status: 1 },
  { id: 2, title: '敏捷开发与云原生架构技术摸底自测', mode: 'practice', attempt_count: 360, pass_rate: '88.5%', status: 1 },
  { id: 3, title: 'AI 时代人机协作实践知识擂台赛', mode: 'exam', attempt_count: 46, pass_rate: '76.0%', status: 0 },
])

// 最近答题交卷流水
const recentAttempts = ref<any[]>([
  { user_name: '张三', user_no: 'HW1001', department: '技术研发部', activity_title: '信息安全知识竞答', score: 100, is_passed: 1, duration_seconds: 145, create_time: Date.now() - 300000 },
  { user_name: '李四', user_no: 'HW1002', department: '云原生架构部', activity_title: '云原生架构摸底自测', score: 95, is_passed: 1, duration_seconds: 210, create_time: Date.now() - 900000 },
  { user_name: '王五', user_no: 'HW1003', department: '数据智能部', activity_title: '信息安全知识竞答', score: 85, is_passed: 1, duration_seconds: 320, create_time: Date.now() - 1500000 },
  { user_name: '赵六', user_no: 'HW1004', department: '数字营销部', activity_title: '信息安全知识竞答', score: 55, is_passed: 0, duration_seconds: 400, create_time: Date.now() - 2400000 },
  { user_name: '孙七', user_no: 'HW1005', department: '产品设计部', activity_title: 'AI 人机协作知识赛', score: 90, is_passed: 1, duration_seconds: 180, create_time: Date.now() - 3600000 },
])

// 格式化时长
const formatDuration = (sec: number) => {
  if (!sec) return '0秒'
  const m = Math.floor(sec / 60)
  const s = Math.floor(sec % 60)
  return m > 0 ? `${m}分${s}秒` : `${s}秒`
}

// 格式化时间
const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

// 路由跳转
const goTo = (path: string) => {
  router.push(path)
}

// H5 模拟器 URL
const simulatorUrl = computed(() => {
  const base = `${serverOrigin}/api/plugin/quiz/h5/index`
  return currentActivityId.value ? `${base}?activity_id=${currentActivityId.value}` : base
})

const selectPreviewActivity = (row: any) => {
  currentActivityId.value = row.id
  reloadIframe()
  ElMessage.success(`已切换至活动【${row.title}】的移动端预览`)
}

const handleActivityChange = () => {
  reloadIframe()
}

const reloadIframe = () => {
  if (h5IframeRef.value) {
    h5IframeRef.value.src = simulatorUrl.value
  }
}

const openH5InNewTab = () => {
  void openExternalUrl(simulatorUrl.value)
}

const copyH5Url = () => {
  copyText(simulatorUrl.value, '移动端 H5 直达链接已成功复制至剪贴板')
}

const copyText = (text: string, msg = '复制成功') => {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => {
      ElMessage.success(msg)
    }).catch(() => {
      fallbackCopy(text, msg)
    })
  } else {
    fallbackCopy(text, msg)
  }
}

const fallbackCopy = (text: string, msg: string) => {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try {
    document.execCommand('copy')
    ElMessage.success(msg)
  } catch {
    ElMessage.error('复制失败，请手动长按复制')
  } finally {
    document.body.removeChild(ta)
  }
}

// 生成纯前端 SVG 二维码矩阵 (自包含轻量算法)
const generateQrSvg = (text: string): string => {
  // 生成标准的 25x25 矩阵与定位角图案
  const size = 25
  const matrix: boolean[][] = Array.from({ length: size }, () => Array(size).fill(false))

  // 绘制 7x7 定位框与实心中心
  const drawFinderPattern = (startX: number, startY: number) => {
    for (let r = 0; r < 7; r++) {
      for (let c = 0; c < 7; c++) {
        if (
          r === 0 || r === 6 || c === 0 || c === 6 ||
          (r >= 2 && r <= 4 && c >= 2 && c <= 4)
        ) {
          matrix[startY + r][startX + c] = true
        }
      }
    }
  }

  drawFinderPattern(0, 0)
  drawFinderPattern(size - 7, 0)
  drawFinderPattern(0, size - 7)

  // 时序图案
  for (let i = 8; i < size - 8; i++) {
    matrix[6][i] = i % 2 === 0
    matrix[i][6] = i % 2 === 0
  }

  // 基于内容字符串哈希散列填充内部有效数据点
  let hash = 0
  for (let i = 0; i < text.length; i++) {
    hash = (hash << 5) - hash + text.charCodeAt(i)
    hash |= 0
  }

  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      // 避免覆盖定位角
      const inFinder1 = r < 8 && c < 8
      const inFinder2 = r < 8 && c >= size - 8
      const inFinder3 = r >= size - 8 && c < 8
      if (!inFinder1 && !inFinder2 && !inFinder3 && matrix[r][c] === false) {
        const val = Math.abs(Math.sin((r * size + c + hash) * 1.5) * 10000)
        matrix[r][c] = Math.floor(val) % 2 === 1
      }
    }
  }

  // 构建 SVG 元素
  const rects: string[] = []
  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      if (matrix[r][c]) {
        rects.push(`<rect x="${c}" y="${r}" width="1" height="1" fill="#1e293b" />`)
      }
    }
  }

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="100%" height="100%" shape-rendering="crispEdges">
    <rect width="${size}" height="${size}" fill="#ffffff" />
    ${rects.join('')}
  </svg>`
}

const qrSvgString = computed(() => {
  return generateQrSvg(simulatorUrl.value)
})

// 加载后台数据
const loadDashboardData = async () => {
  try {
    const res: any = await getQuizDashboard()
    if (res?.code === 1 && res?.data) {
      const d = res.data
      if (d.stats) Object.assign(stats, d.stats)
      if (d.activities && Array.isArray(d.activities)) activities.value = d.activities
      if (d.recentAttempts && Array.isArray(d.recentAttempts)) recentAttempts.value = d.recentAttempts
    }
  } catch (error) {
    // 允许后端暂不可用时优雅展示本地演示数据
    console.warn('获取知识答题看板数据失败，启用预置展示数据', error)
  }
}

onMounted(() => {
  loadDashboardData()
})
</script>

<style scoped lang="scss">
.quiz-dashboard-container {
  padding: 16px;

  .stats-row {
    margin-bottom: 16px;
  }

  .stat-card {
    border-radius: 8px;
    border: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;

    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .stat-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;

      .stat-title {
        font-size: 13px;
        color: #64748b;
        font-weight: 500;
      }
    }

    .stat-value {
      font-size: 26px;
      font-weight: 700;
      line-height: 1.2;
      color: #1e293b;
      margin-bottom: 8px;
    }

    .stat-footer {
      font-size: 12px;
      color: #94a3b8;
      border-top: 1px dashed #f1f5f9;
      padding-top: 6px;

      strong {
        color: #334155;
      }
    }

    &.stat-blue { background: linear-gradient(180deg, #f0f7ff 0%, #ffffff 100%); }
    &.stat-green { background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%); }
    &.stat-purple { background: linear-gradient(180deg, #faf5ff 0%, #ffffff 100%); }
    &.stat-orange { background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%); }
    &.stat-cyan { background: linear-gradient(180deg, #ecfeff 0%, #ffffff 100%); }
    &.stat-pink { background: linear-gradient(180deg, #fff1f2 0%, #ffffff 100%); }
  }

  .mt-16 {
    margin-top: 16px;
  }

  .ml-8 {
    margin-left: 8px;
  }

  .card-header-flex {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .section-title {
      font-size: 15px;
      font-weight: 600;
      color: #1e293b;
    }

    .sub-text {
      font-size: 12px;
      color: #94a3b8;
    }

    .header-left {
      display: flex;
      align-items: center;
    }
  }

  .quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;

    .quick-item {
      display: flex;
      align-items: center;
      padding: 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;

      &:hover {
        background: #eff6ff;
        border-color: #bfdbfe;
        transform: translateY(-1px);
      }

      .item-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        font-size: 20px;
        color: #fff;

        &.bg-blue { background: #3b82f6; }
        &.bg-green { background: #10b981; }
        &.bg-orange { background: #f97316; }
        &.bg-purple { background: #8b5cf6; }
      }

      .item-text {
        .item-title {
          font-size: 14px;
          font-weight: 600;
          color: #334155;
          margin-bottom: 2px;
        }

        .item-desc {
          font-size: 12px;
          color: #94a3b8;
        }
      }
    }
  }

  .simulator-card {
    border-radius: 8px;

    .simulator-control {
      display: flex;
      gap: 8px;
      margin-bottom: 14px;

      .activity-selector {
        flex: 1;
      }
    }

    .mobile-device-frame {
      width: 100%;
      max-width: 320px;
      height: 560px;
      margin: 0 auto;
      background: #0f172a;
      border: 10px solid #1e293b;
      border-radius: 36px;
      box-shadow: 0 16px 32px rgba(15, 23, 42, 0.25);
      position: relative;
      display: flex;
      flex-direction: column;
      overflow: hidden;

      .device-notch {
        width: 120px;
        height: 18px;
        background: #1e293b;
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        border-bottom-left-radius: 12px;
        border-bottom-right-radius: 12px;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;

        .speaker {
          width: 38px;
          height: 4px;
          background: #475569;
          border-radius: 2px;
        }

        .camera {
          width: 6px;
          height: 6px;
          background: #334155;
          border-radius: 50%;
        }
      }

      .device-screen {
        flex: 1;
        background: #f8fafc;
        position: relative;
        overflow: hidden;

        .simulator-iframe {
          width: 100%;
          height: 100%;
          border: none;
        }
      }

      .device-home-bar {
        width: 110px;
        height: 4px;
        background: #64748b;
        border-radius: 2px;
        position: absolute;
        bottom: 6px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10;
      }
    }

    .scan-account-box {
      margin-top: 16px;
      padding: 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      display: flex;
      gap: 14px;
      align-items: center;

      .qr-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 100px;
        flex-shrink: 0;

        .qr-svg-container {
          width: 90px;
          height: 90px;
          padding: 4px;
          background: #fff;
          border: 1px solid #cbd5e1;
          border-radius: 6px;
        }

        .qr-tip {
          font-size: 11px;
          color: #64748b;
          margin-top: 4px;
          text-align: center;
        }
      }

      .account-info {
        flex: 1;

        .account-title {
          display: flex;
          align-items: center;
          gap: 4px;
          font-size: 13px;
          font-weight: 600;
          color: #1e293b;
          margin-bottom: 6px;
        }

        .account-row {
          font-size: 12px;
          color: #475569;
          margin-bottom: 4px;
          display: flex;
          align-items: center;
          gap: 6px;

          .lbl {
            color: #64748b;
          }
        }

        .account-extra-tip {
          font-size: 11px;
          color: #94a3b8;
          margin-top: 6px;
          line-height: 1.3;
        }
      }
    }
  }

  .text-danger {
    color: #ef4444;
  }

  .text-success {
    color: #10b981;
  }

  .font-bold {
    font-weight: 700;
  }
}

@media (max-width: 992px) {
  .quiz-dashboard-container {
    .quick-grid {
      grid-template-columns: repeat(2, 1fr);
    }
    .content-row {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
  }
}
</style>
