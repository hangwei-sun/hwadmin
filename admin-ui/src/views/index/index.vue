<template>
  <div class="hw-dashboard-container">
    <!-- 顶部主框架版本与在线更新中枢 -->
    <div class="welcome-header">
      <div class="welcome-info">
        <div class="title-row">
          <div class="framework-icon-wrap">
            <vab-icon icon="cloud-line" />
          </div>
          <h2>HwAdmin 智能管理后台</h2>
          <el-tag effect="plain" type="info" class="version-tag">
            当前版本 v{{ displayCurrentVersion }}
          </el-tag>
          <el-tag
            v-if="frameworkInfo.has_update"
            :type="frameworkInfo.release_channel === 'beta' ? 'warning' : 'danger'"
            effect="dark"
            class="status-tag pulse-tag"
          >
            新版本 v{{ frameworkInfo.latest_version }}
          </el-tag>
          <el-tag
            v-if="frameworkInfo.has_update && frameworkInfo.release_channel === 'beta'"
            type="warning"
            effect="dark"
            class="status-tag beta-badge-tag"
          >
            内测版 (Beta)
          </el-tag>
          <el-tag v-else-if="!frameworkInfo.has_update" effect="plain" type="success" class="status-tag is-latest">
            已是最新
          </el-tag>
        </div>
        <p class="subtitle">
          让 AI 智能体读得懂、改得快的业务框架 · PHP 8+ + ThinkORM 4 + Vue 3 + MySQL 8.0+ / PG 16+ / SQLite
        </p>
        <div class="update-channel-bar">
          <span class="channel-label">
            <vab-icon icon="git-branch-line" />
            更新通道
          </span>
          <el-radio-group
            v-model="selectedUpdateChannel"
            size="small"
            :disabled="switchingChannel || checkingUpdates"
            class="channel-radio-group"
            @change="handleUpdateChannelChange"
          >
            <el-radio-button value="stable">稳定版 (Stable)</el-radio-button>
            <el-radio-button value="beta">内测版 (Beta)</el-radio-button>
          </el-radio-group>
          <span
            v-if="frameworkInfo.has_update && frameworkInfo.release_channel === 'beta'"
            class="beta-risk-banner"
          >
            <vab-icon icon="alert-line" />
            检测到内测版 (Beta)，建议先在测试站验证后再升级生产环境
          </span>
        </div>
      </div>
      <div class="header-actions">
        <el-button :loading="checkingUpdates" class="banner-btn banner-btn-check" @click="handleCheckUpdates(true)">
          <el-icon><vab-icon icon="scan-line" /></el-icon>
          检查更新
        </el-button>
        <el-button
          v-if="frameworkInfo.has_update"
          type="danger"
          class="banner-btn-upgrade"
          @click="frameworkUpdateDialogVisible = true"
        >
          <el-icon><vab-icon icon="upload-cloud-line" /></el-icon>
          立即升级
        </el-button>
        <el-button class="banner-btn banner-btn-backup" @click="openFrameworkBackupDialog">
          <el-icon><vab-icon icon="history-line" /></el-icon>
          备份与回滚 ({{ frameworkBackupsList.length }})
        </el-button>
        <el-button :loading="loading" class="banner-btn banner-btn-refresh" @click="fetchData">
          <el-icon><vab-icon icon="refresh-line" /></el-icon>
          刷新
        </el-button>
        <el-button :loading="clearing" class="banner-btn banner-btn-clear" @click="handleQuickClearCache">
          <el-icon><vab-icon icon="brush-line" /></el-icon>
          清理缓存
        </el-button>
      </div>
    </div>

    <FrameworkUpdateDialog
      v-model:visible="frameworkUpdateDialogVisible"
      :framework-info="frameworkInfo"
      @updated="handleFrameworkUpdated"
    />

    <!-- 主框架历史备份与回滚弹窗 -->
    <el-dialog
      v-model="frameworkBackupDialogVisible"
      title="框架备份与回滚"
      width="min(700px, 94vw)"
      destroy-on-close
    >
      <div v-if="!frameworkBackupsList.length" style="padding: 24px 0; text-align: center;">
        <el-empty description="还没有主框架备份快照" />
      </div>

      <el-table v-else :data="frameworkBackupsList" stripe style="width: 100%">
        <el-table-column prop="id" label="备份编号" min-width="200">
          <template #default="{ row }">
            <code class="mono-id-badge">{{ row.id }}</code>
          </template>
        </el-table-column>
        <el-table-column prop="version" label="版本" width="100">
          <template #default="{ row }">
            <el-tag size="small" type="info">v{{ row.version }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="备份时间" width="165" />
        <el-table-column prop="size" label="体积" width="90" />
        <el-table-column label="操作" width="105" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button
                type="danger"
                plain
                size="small"
                :loading="rollingBackId === row.id"
                @click="handleFrameworkRollback(row)"
              >
                回滚
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="frameworkBackupDialogVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 第一排：6 大核心指标卡片 (18) + 系统安全态势环图卡片 (6) -->
    <el-row :gutter="16" class="top-overview-row">
      <el-col :xs="24" :lg="18">
        <div class="metrics-grid">
          <!-- 1. 管理员总数 -->
          <div class="metric-card card-blue" @click="router.push('/auth/admin')">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">安全管理员</span>
                <span class="metric-value">{{ counts.admin }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="shield-user-line" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">受控账户数</span>
              <span class="status-pill status-blue">RBAC 防护</span>
            </div>
          </div>

          <!-- 2. 角色组 -->
          <div class="metric-card card-cyan" @click="router.push('/auth/group')">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">角色权限组</span>
                <span class="metric-value">{{ counts.role }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="team-line" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">按岗位授权</span>
              <span class="status-pill status-cyan">细粒度隔离</span>
            </div>
          </div>

          <!-- 3. 权限规则 -->
          <div class="metric-card card-purple" @click="router.push('/auth/rule')">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">菜单与规则节点</span>
                <span class="metric-value">{{ counts.rule }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="node-tree" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">动态路由鉴权</span>
              <span class="status-pill status-purple">自动构建</span>
            </div>
          </div>

          <!-- 4. 媒体附件资源 -->
          <div class="metric-card card-orange" @click="router.push('/system/attachment')">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">媒体附件资源</span>
                <span class="metric-value">{{ counts.attachment }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="attachment-line" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">统一资源管理</span>
              <span class="status-pill status-orange">SHA1 去重</span>
            </div>
          </div>

          <!-- 5. 操作审计日志 -->
          <div class="metric-card card-green" @click="router.push('/auth/adminLog')">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">操作审计日志</span>
                <span class="metric-value">{{ counts.admin_log }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="file-list-3-line" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">行为安全追踪</span>
              <span class="status-pill status-green">自动留痕</span>
            </div>
          </div>

          <!-- 6. 个人安全中心 -->
          <div class="metric-card card-pink" @click="handleNavPersonalCenter">
            <div class="metric-body">
              <div class="metric-info">
                <span class="metric-label">个人安全中心</span>
                <span class="metric-value metric-value-text">{{ userStore.username || 'Admin' }}</span>
              </div>
              <div class="metric-icon">
                <vab-icon icon="user-follow-line" />
              </div>
            </div>
            <div class="metric-footer">
              <span class="footer-tip">资料与锁屏密码</span>
              <span class="status-pill status-pink">安全守卫</span>
            </div>
          </div>
        </div>
      </el-col>

      <!-- 系统安全态势健康评分卡片 -->
      <el-col :xs="24" :lg="6">
        <el-card shadow="hover" class="security-card">
          <template #header>
            <div class="card-header">
              <span class="title">
                <el-icon><vab-icon icon="shield-check-line" /></el-icon>
                系统安全态势评分
              </span>
              <el-tag size="small" :type="securityScore.score >= 80 ? 'success' : 'warning'">
                {{ securityScore.level }}
              </el-tag>
            </div>
          </template>

          <div class="security-ring-container">
            <div class="gauge-ring">
              <svg viewBox="0 0 120 120" class="gauge-svg">
                <circle cx="60" cy="60" r="50" class="gauge-track" />
                <circle
                  cx="60"
                  cy="60"
                  r="50"
                  class="gauge-progress"
                  :stroke-dasharray="314"
                  :stroke-dashoffset="314 - (314 * (securityScore.score || 0)) / 100"
                />
              </svg>
              <div class="gauge-value">
                <span class="num">{{ securityScore.score }}</span>
                <span class="unit">分</span>
              </div>
            </div>
            <div class="security-meta">
              <div class="sec-item" v-for="(chk, idx) in securityScore.checks.slice(0, 2)" :key="idx">
                <el-icon :color="chk.status ? '#52c41a' : '#faad14'">
                  <vab-icon :icon="chk.status ? 'checkbox-circle-fill' : 'error-warning-fill'" />
                </el-icon>
                <span class="chk-tip">{{ chk.name }}</span>
              </div>
            </div>
          </div>

          <div class="security-action">
            <el-button type="primary" link size="small" @click="router.push('/system/config')">
              前往系统配置加固 &rarr;
            </el-button>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 第二排（左 16 : 右 8）：近 7 天时序趋势大盘 + 智能研发直通车 -->
    <el-row :gutter="16" class="mid-section-row">
      <!-- 左侧：近 7 天双轴请求与安全趋势大盘 -->
      <el-col :xs="24" :lg="16">
        <el-card shadow="hover" class="trend-card">
          <template #header>
            <div class="card-header">
              <div class="title-with-legend">
                <span class="title">
                  <el-icon><vab-icon icon="line-chart-line" /></el-icon>
                  近 7 日请求趋势
                </span>
                <div class="chart-legend">
                  <span class="legend-item"><i class="dot blue"></i> API 请求</span>
                  <span class="legend-item"><i class="dot purple"></i> 安全事件</span>
                </div>
              </div>
              <el-radio-group v-model="trendViewMode" size="small">
                <el-radio-button value="7d">近 7 日</el-radio-button>
                <el-radio-button value="realtime">实时流</el-radio-button>
              </el-radio-group>
            </div>
          </template>

          <!-- 原生 SVG 高帧率自适应平滑面积折线图 -->
          <div ref="chartContainerRef" class="svg-chart-container" @mouseleave="hoverIndex = -1">
            <svg
              :viewBox="`0 0 ${chartSize.width} ${chartSize.height}`"
              class="trend-svg"
              @mousemove="handleSvgMouseMove"
            >
              <defs>
                <linearGradient id="gradReq" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#1890ff" stop-opacity="0.32" />
                  <stop offset="100%" stop-color="#1890ff" stop-opacity="0.0" />
                </linearGradient>
                <linearGradient id="gradLog" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#722ed1" stop-opacity="0.25" />
                  <stop offset="100%" stop-color="#722ed1" stop-opacity="0.0" />
                </linearGradient>
              </defs>

              <!-- 水平参考虚线 -->
              <line x1="44" y1="26" :x2="chartSize.width - 16" y2="26" class="grid-line" />
              <line x1="44" y1="78" :x2="chartSize.width - 16" y2="78" class="grid-line" />
              <line x1="44" y1="131" :x2="chartSize.width - 16" y2="131" class="grid-line" />
              <line
                x1="44"
                :y1="chartBounds.botY"
                :x2="chartSize.width - 16"
                :y2="chartBounds.botY"
                class="grid-line axis-line"
              />

              <!-- Y 轴刻度文本 -->
              <text x="36" y="30" class="axis-text" text-anchor="end">{{ maxScale }}</text>
              <text x="36" y="82" class="axis-text" text-anchor="end">{{ Math.round(maxScale * 0.66) }}</text>
              <text x="36" y="135" class="axis-text" text-anchor="end">{{ Math.round(maxScale * 0.33) }}</text>
              <text x="36" :y="chartBounds.botY + 4" class="axis-text" text-anchor="end">0</text>

              <!-- 请求面积图与折线 -->
              <path :d="svgPaths.areaReq" fill="url(#gradReq)" />
              <path :d="svgPaths.lineReq" fill="none" stroke="#1890ff" stroke-width="2.5" class="svg-line" />

              <!-- 登录面积图与折线 -->
              <path :d="svgPaths.areaLog" fill="url(#gradLog)" />
              <path :d="svgPaths.lineLog" fill="none" stroke="#722ed1" stroke-width="2.5" class="svg-line" />

              <!-- X 轴刻度文本与数据节点圆圈 -->
              <g v-for="(p, idx) in chartPoints.reqPoints" :key="'pt-' + idx">
                <text :x="p.x" :y="chartBounds.botY + 22" class="axis-text" text-anchor="middle">
                  {{ trendDates[idx] || '' }}
                </text>
                <!-- 悬浮指示线 -->
                <line
                  v-if="hoverIndex === idx"
                  :x1="p.x"
                  y1="22"
                  :x2="p.x"
                  :y2="chartBounds.botY"
                  class="hover-guide-line"
                />
                <!-- 请求圆点 -->
                <circle
                  :cx="p.x"
                  :cy="p.y"
                  :r="hoverIndex === idx ? 5.5 : 4"
                  class="chart-circle circle-req"
                />
                <!-- 登录圆点 -->
                <circle
                  :cx="chartPoints.logPoints[idx]?.x || p.x"
                  :cy="chartPoints.logPoints[idx]?.y || chartBounds.botY"
                  :r="hoverIndex === idx ? 5.5 : 4"
                  class="chart-circle circle-log"
                />
              </g>
            </svg>

            <!-- 悬浮互动 Tooltip -->
            <transition name="el-fade-in-linear">
              <div
                v-if="hoverIndex >= 0 && trendDates[hoverIndex]"
                class="chart-tooltip"
                :style="tooltipStyle"
              >
                <div class="tooltip-header">{{ trendDates[hoverIndex] }} 审计指标</div>
                <div class="tooltip-row">
                  <span class="dot blue"></span>
                  <span>API 操作请求：</span>
                  <strong>{{ trendRequests[hoverIndex] }}</strong>
                </div>
                <div class="tooltip-row">
                  <span class="dot purple"></span>
                  <span>安全鉴权登录：</span>
                  <strong>{{ trendLogins[hoverIndex] }}</strong>
                </div>
              </div>
            </transition>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧：智能研发与运维 6 宫格 -->
      <el-col :xs="24" :lg="8">
        <el-card shadow="hover" class="quick-nav-matrix-card">
          <template #header>
            <div class="card-header">
              <span class="title">
                <el-icon><vab-icon icon="flashlight-line" /></el-icon>
                智能研发与运维
              </span>
              <el-tag size="small" type="primary" effect="light">企业套件</el-tag>
            </div>
          </template>

          <div class="quick-matrix-grid">
            <div class="matrix-item" @click="handleNav('/plugin/crud')">
              <div class="matrix-icon-box bg-blue">
                <vab-icon icon="table-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">CRUD 生成器</span>
                <span class="matrix-desc">秒级建表生成代码</span>
              </div>
            </div>

            <div class="matrix-item" @click="handleNav('/system/plugin')">
              <div class="matrix-icon-box bg-purple">
                <vab-icon icon="shopping-bag-3-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">应用商城</span>
                <span class="matrix-desc">一键安装扩展</span>
              </div>
            </div>

            <div class="matrix-item" @click="handleNav('/plugin/dict')">
              <div class="matrix-icon-box bg-green">
                <vab-icon icon="book-2-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">数据字典中台</span>
                <span class="matrix-desc">全局枚举状态治理</span>
              </div>
            </div>

            <div class="matrix-item" @click="handleNav('/plugin/crontab')">
              <div class="matrix-icon-box bg-pink">
                <vab-icon icon="alarm-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">定时任务调度</span>
                <span class="matrix-desc">毫秒级守护任务进程</span>
              </div>
            </div>

            <div class="matrix-item" @click="handleNav('/system/config')">
              <div class="matrix-icon-box bg-orange">
                <vab-icon icon="database-2-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">数据库快照备份</span>
                <span class="matrix-desc">冷备热备防灾还原</span>
              </div>
            </div>

            <div class="matrix-item" @click="handleNav('/system/config')">
              <div class="matrix-icon-box bg-teal">
                <vab-icon icon="shield-keyhole-line" />
              </div>
              <div class="matrix-content">
                <span class="matrix-name">系统安全加固</span>
                <span class="matrix-desc">后台隐藏与安全配置</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 第三排（左 12 : 右 12）：实时操作审计流水 + 运行环境探针 -->
    <el-row :gutter="16" class="bottom-section-row">
      <!-- 左侧：实时操作审计流水 -->
      <el-col :xs="24" :lg="12">
        <el-card shadow="hover" class="logs-card">
          <template #header>
            <div class="card-header">
              <span class="title">
                <el-icon><vab-icon icon="history-line" /></el-icon>
                实时管理员操作审计流水
              </span>
              <el-button text type="primary" size="small" @click="router.push('/auth/adminLog')">
                查看全部流水
              </el-button>
            </div>
          </template>

          <div class="audit-timeline-wrap">
            <el-empty v-if="!recentLogs.length" description="暂无操作日志记录" :image-size="60" />
            <el-timeline v-else class="audit-timeline">
              <el-timeline-item
                v-for="log in recentLogs"
                :key="log.id"
                :timestamp="formatTime(log.create_time)"
                placement="top"
                type="primary"
                size="normal"
              >
                <div class="audit-log-row">
                  <div class="audit-main">
                    <span class="operator-badge">{{ log.username || 'Admin' }}</span>
                    <span class="log-action">{{ log.title || '执行操作' }}</span>
                  </div>
                  <div class="audit-details">
                    <span class="ip-tag">{{ log.ip }}</span>
                    <code class="url-code">{{ log.url }}</code>
                  </div>
                </div>
              </el-timeline-item>
            </el-timeline>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧：运行环境与双库引擎探针 -->
      <el-col :xs="24" :lg="12">
        <el-card shadow="hover" class="system-probe-card">
          <template #header>
            <div class="card-header">
              <span class="title">
                <el-icon><vab-icon icon="cpu-line" /></el-icon>
                运行环境与双库引擎探针
              </span>
              <el-tag type="success" effect="plain" class="dual-badge">
                <vab-icon icon="checkbox-circle-fill" /> 双引擎就绪
              </el-tag>
            </div>
          </template>

          <el-descriptions :column="2" border class="probe-descriptions">
            <el-descriptions-item label="主框架版本">
              <strong>HwAdmin {{ systemInfo.hw_version }}</strong>
            </el-descriptions-item>
            <el-descriptions-item label="PHP 内核版本">
              <span class="tech-tag">{{ systemInfo.php_version }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="ThinkPHP 内核">
              <span class="tech-tag">TP {{ systemInfo.tp_version }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="服务器架构">
              {{ systemInfo.os }}
            </el-descriptions-item>
            <el-descriptions-item label="当前数据库驱动">
              <el-tag
                size="small"
                :type="systemInfo.db_driver.includes('pgsql') ? 'success' : 'primary'"
                effect="dark"
              >
                {{ systemInfo.db_driver.toUpperCase() }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="原生兼容引擎">
              <span class="engine-check">MySQL 8.0+ / PG 16+</span>
            </el-descriptions-item>
            <el-descriptions-item label="后台安全入口">
              <code>/{{ systemInfo.admin_entry || 'admin' }}</code>
            </el-descriptions-item>
            <el-descriptions-item label="沙箱保护机制">
              <el-tag size="small" type="info">演示防篡改隔离就绪</el-tag>
            </el-descriptions-item>
          </el-descriptions>

          <div class="probe-footer-actions">
            <el-button type="primary" plain size="small" @click="fetchData">
              <el-icon><vab-icon icon="refresh-line" /></el-icon>
              刷新探针
            </el-button>
            <el-button type="danger" plain size="small" :loading="clearing" @click="handleQuickClearCache">
              <el-icon><vab-icon icon="brush-line" /></el-icon>
              一键清理全栈系统缓存
            </el-button>
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script lang="ts" setup>
import { reactive, ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { getDashboard, clearCache, checkSystemUpdates, setSystemUpdateChannel, getSystemBackups, rollbackSystemUpdate } from '/@/api/hwadmin'
import { useUserStore } from '/@/store/modules/user'
import FrameworkUpdateDialog, { type FrameworkReleaseInfo } from '/@/components/FrameworkUpdateDialog.vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'

defineOptions({
  name: 'Index',
})

const router = useRouter()
const userStore = useUserStore()
const loading = ref(false)
const clearing = ref(false)
const checkingUpdates = ref(false)
const switchingChannel = ref(false)
const selectedUpdateChannel = ref<'stable' | 'beta'>('stable')
const frameworkUpdateDialogVisible = ref(false)
const frameworkBackupDialogVisible = ref(false)
const frameworkBackupsList = ref<any[]>([])
const rollingBackId = ref('')
const trendViewMode = ref<'7d' | 'realtime'>('7d')

const frameworkInfo = reactive<FrameworkReleaseInfo>({
  name: 'hwadmin',
  current_version: '',
  latest_version: '1.6.4',
  has_update: false,
  update_channel: 'stable',
  release_channel: 'stable',
  release_date: '2026-11-24',
  changelog: [],
  download_url: '',
  patch_sha256: '',
  min_php: '8.1',
})

// 核心计数器
const counts = reactive({
  admin: 1,
  user: 0,
  plugin: 0,
  attachment: 0,
  admin_log: 0,
  role: 1,
  rule: 36,
  dict_count: 0,
  crontab_count: 0,
})

// 系统探针
const systemInfo = reactive({
  hw_version: '1.0.0',
  tp_version: '8.1.x',
  php_version: '8.2.x',
  os: 'Linux/Darwin',
  db_driver: 'mysql',
  admin_entry: 'admin',
})

// 安全态势
const securityScore = reactive({
  score: 95,
  level: '极佳',
  checks: [
    { name: '后台入口地址加固', status: true, score: 25 },
    { name: '图形验证码防暴力破解', status: true, score: 25 },
    { name: '生产环境调试隔离', status: true, score: 20 },
    { name: '双引擎原生兼容架构', status: true, score: 15 },
  ],
})

// 时序统计数据
const trendDates = ref<string[]>(['11-05', '11-06', '11-07', '11-08', '11-09', '11-10', '11-11'])
const trendRequests = ref<number[]>([32, 45, 68, 89, 74, 105, 128])
const trendLogins = ref<number[]>([4, 6, 9, 14, 11, 16, 19])

// 最近日志
const recentLogs = ref<any[]>([])

// 格式化时间戳
const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

// 导航跳转辅助
const handleNav = (path: string) => {
  router.push(path)
}

const handleNavPersonalCenter = () => {
  const target = router.resolve('/system/personalCenter').matched.length > 0 ? '/system/personalCenter' : '/setting/personalCenter'
  router.push(target)
}

// 一键清理缓存
const handleQuickClearCache = async () => {
  if (clearing.value) return
  clearing.value = true
  try {
    const res: any = await clearCache('all')
    const msg = res?.msg || '全栈系统缓存与模板编译清理成功'
    if (typeof $baseMessage === 'function') {
      $baseMessage(msg, 'success', 'hey')
    } else {
      ElMessage.success(msg)
    }
  } catch (e: any) {
    const err = e?.message || '清理缓存失败'
    if (typeof $baseMessage === 'function') {
      $baseMessage(err, 'error', 'hey')
    } else {
      ElMessage.error(err)
    }
  } finally {
    clearing.value = false
  }
}

// ==================== SVG 平滑曲线算法 ====================
const chartContainerRef = ref<HTMLElement | null>(null)
const chartSize = reactive({ width: 700, height: 220 })
const chartBounds = computed(() => ({
  startX: 56,
  endX: Math.max(120, chartSize.width - 28),
  topY: 26,
  botY: Math.max(96, chartSize.height - 36),
}))
let chartResizeObserver: ResizeObserver | null = null

const hoverIndex = ref(-1)
const hoverSvgX = ref(0)
const hoverSvgY = ref(0)

const maxScale = computed(() => {
  const maxReq = Math.max(...trendRequests.value, 10)
  return Math.ceil(maxReq / 20) * 20
})

// 计算每个点坐标 (与实际容器像素 1:1 对齐，避免 SVG 拉伸变形)
const chartPoints = computed(() => {
  const reqVals = trendRequests.value
  const logVals = trendLogins.value
  const count = reqVals.length
  const { startX, endX, topY, botY } = chartBounds.value
  const stepX = count > 1 ? (endX - startX) / (count - 1) : 0
  const max = maxScale.value

  const reqPoints = reqVals.map((val, idx) => {
    const x = startX + idx * stepX
    const y = botY - (val / max) * (botY - topY)
    return { x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 }
  })

  const logPoints = logVals.map((val, idx) => {
    const x = startX + idx * stepX
    const y = botY - (val / max) * (botY - topY)
    return { x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 }
  })

  return { reqPoints, logPoints }
})

// 生成三次贝塞尔平滑路径
const buildSmoothPath = (pts: { x: number; y: number }[], isArea = false, botY = 184) => {
  if (!pts || pts.length === 0) return ''
  let d = `M ${pts[0].x} ${pts[0].y}`
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = i > 0 ? pts[i - 1] : pts[i]
    const p1 = pts[i]
    const p2 = pts[i + 1]
    const p3 = i < pts.length - 2 ? pts[i + 2] : p2

    const cp1x = p1.x + (p2.x - p0.x) / 6
    const cp1y = p1.y + (p2.y - p0.y) / 6
    const cp2x = p2.x - (p3.x - p1.x) / 6
    const cp2y = p2.y - (p3.y - p1.y) / 6

    d += ` C ${cp1x.toFixed(1)} ${cp1y.toFixed(1)}, ${cp2x.toFixed(1)} ${cp2y.toFixed(1)}, ${p2.x.toFixed(1)} ${p2.y.toFixed(1)}`
  }
  if (isArea) {
    const lastX = pts[pts.length - 1].x
    d += ` L ${lastX} ${botY} L ${pts[0].x} ${botY} Z`
  }
  return d
}

const svgPaths = computed(() => {
  const { reqPoints, logPoints } = chartPoints.value
  const botY = chartBounds.value.botY
  return {
    lineReq: buildSmoothPath(reqPoints, false, botY),
    areaReq: buildSmoothPath(reqPoints, true, botY),
    lineLog: buildSmoothPath(logPoints, false, botY),
    areaLog: buildSmoothPath(logPoints, true, botY),
  }
})

const handleSvgMouseMove = (event: MouseEvent) => {
  const svgEl = event.currentTarget as SVGElement
  const rect = svgEl.getBoundingClientRect()
  if (!rect.width) return
  const mouseX = event.clientX - rect.left
  const svgX = (mouseX / rect.width) * chartSize.width

  // 寻找最近的 index
  const pts = chartPoints.value.reqPoints
  let closestIdx = 0
  let minDist = 9999
  pts.forEach((pt, idx) => {
    const dist = Math.abs(pt.x - svgX)
    if (dist < minDist) {
      minDist = dist
      closestIdx = idx
    }
  })

  hoverIndex.value = closestIdx
  hoverSvgX.value = (mouseX / rect.width) * 100
  hoverSvgY.value = event.clientY - rect.top
}

const tooltipStyle = computed(() => {
  const leftPercent = hoverSvgX.value
  return {
    left: `clamp(10%, ${leftPercent}%, 85%)`,
    top: '30px',
  }
})

const displayCurrentVersion = computed(() => {
  return frameworkInfo.current_version || systemInfo.hw_version || '1.6.4'
})

const openFrameworkBackupDialog = async () => {
  frameworkBackupDialogVisible.value = true
  try {
    const res: any = await getSystemBackups()
    if (res?.code === 1 && Array.isArray(res.data)) {
      frameworkBackupsList.value = res.data.filter((b: any) => b?.type === 'framework')
    }
  } catch {
  }
}

const handleFrameworkRollback = async (backup: any) => {
  try {
    await ElMessageBox.confirm(
      `确定将 HwAdmin 主框架回滚到 v${backup.version}（${backup.id}）吗？核心代码和配置将恢复到该快照。`,
      '框架回滚确认',
      {
        confirmButtonText: '确认回滚',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  rollingBackId.value = backup.id
  try {
    const res: any = await rollbackSystemUpdate({ backup_id: backup.id })
    if (res?.code === 1) {
      ElMessage.success(res.msg || '回滚成功，正在自动刷新页面...')
      frameworkBackupDialogVisible.value = false
      window.setTimeout(() => {
        window.location.reload()
      }, 800)
    } else {
      ElMessage.error(res?.msg || '回滚失败')
    }
  } catch (e: any) {
    ElMessage.error('回滚失败：' + (e?.message || '网络错误'))
  } finally {
    rollingBackId.value = ''
  }
}

const handleCheckUpdates = async (showToast = true) => {
  if (checkingUpdates.value) return
  checkingUpdates.value = true
  try {
    const res: any = await checkSystemUpdates()
    if (res?.code === 1 && res?.data?.framework) {
      Object.assign(frameworkInfo, res.data.framework)
      selectedUpdateChannel.value =
        res.data.framework.update_channel === 'beta' ? 'beta' : 'stable'
      if (Array.isArray(res.data.backups)) {
        frameworkBackupsList.value = res.data.backups.filter((b: any) => b?.type === 'framework')
      }
      const betaSuffix = frameworkInfo.release_channel === 'beta' ? '（内测版 Beta）' : ''
      if (showToast || frameworkInfo.has_update) {
        ElMessage.success(
          frameworkInfo.has_update
            ? `发现新版本 v${frameworkInfo.latest_version}${betaSuffix}`
            : '已是最新版本'
        )
      }
    } else if (showToast) {
      ElMessage.warning(res?.msg || '暂时无法检查更新，请稍后再试')
    }
  } catch {
    if (showToast) {
      ElMessage.warning('暂时无法检查更新，请稍后再试')
    }
  } finally {
    checkingUpdates.value = false
  }
}

const handleUpdateChannelChange = async (val: string | number | boolean | undefined) => {
  const targetChannel: 'stable' | 'beta' = val === 'beta' ? 'beta' : 'stable'
  const prevChannel: 'stable' | 'beta' =
    frameworkInfo.update_channel === 'beta' ? 'beta' : 'stable'
  if (targetChannel === prevChannel) {
    return
  }
  switchingChannel.value = true
  try {
    const res: any = await setSystemUpdateChannel({ channel: targetChannel })
    if (res?.code === 1) {
      frameworkInfo.update_channel = targetChannel
      selectedUpdateChannel.value = targetChannel
      ElMessage.success(
        res?.msg ||
          (targetChannel === 'beta'
            ? '已切换至内测版 (Beta) 通道，正在重新检测更新...'
            : '已切换至稳定版 (Stable) 通道，正在重新检测更新...')
      )
      await handleCheckUpdates(true)
    } else {
      selectedUpdateChannel.value = prevChannel
      ElMessage.error(res?.msg || '切换更新通道失败')
    }
  } catch (e: any) {
    selectedUpdateChannel.value = prevChannel
    ElMessage.error(e?.msg || e?.message || '切换更新通道失败')
  } finally {
    switchingChannel.value = false
  }
}

const handleFrameworkUpdated = async () => {
  await Promise.all([fetchData(), handleCheckUpdates(false)])
}

// ==================== 拉取控制台数据 ====================
const fetchData = async () => {
  loading.value = true
  try {
    const res: any = await getDashboard()
    if (res && res.data) {
      if (res.data.counts) {
        Object.assign(counts, res.data.counts)
      }
      if (res.data.system) {
        Object.assign(systemInfo, res.data.system)
        if (!frameworkInfo.current_version && res.data.system.hw_version) {
          frameworkInfo.current_version = res.data.system.hw_version
        }
      }
      if (res.data.security_score) {
        Object.assign(securityScore, res.data.security_score)
      }
      if (res.data.trend_7d) {
        if (Array.isArray(res.data.trend_7d.dates)) {
          trendDates.value = res.data.trend_7d.dates
        }
        if (Array.isArray(res.data.trend_7d.requests)) {
          trendRequests.value = res.data.trend_7d.requests
        }
        if (Array.isArray(res.data.trend_7d.logins)) {
          trendLogins.value = res.data.trend_7d.logins
        }
      }
      if (Array.isArray(res.data.recent_logs)) {
        recentLogs.value = res.data.recent_logs
      }
    }
  } catch (e) {
    console.warn('Dashboard 接口请求降级展示', e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (chartContainerRef.value && typeof ResizeObserver !== 'undefined') {
    const updateChartSize = (w: number, h: number) => {
      if (w > 0) chartSize.width = Math.max(320, Math.round(w))
      if (h > 0) chartSize.height = Math.max(180, Math.round(h))
    }
    updateChartSize(chartContainerRef.value.clientWidth, chartContainerRef.value.clientHeight)
    chartResizeObserver = new ResizeObserver((entries) => {
      for (const entry of entries) {
        updateChartSize(entry.contentRect.width, entry.contentRect.height)
      }
    })
    chartResizeObserver.observe(chartContainerRef.value)
  }
  void Promise.all([fetchData(), handleCheckUpdates(false)])
})

onBeforeUnmount(() => {
  chartResizeObserver?.disconnect()
  chartResizeObserver = null
})
</script>

<style lang="scss" scoped>
.hw-dashboard-container {
  padding: 16px;

  // 欢迎头部
  .welcome-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 24px;
    margin-bottom: 16px;
    background: linear-gradient(135deg, #1890ff 0%, #36cfc9 100%);
    border-radius: 8px;
    color: #fff;
    box-shadow: 0 4px 12px rgba(24, 144, 255, 0.15);

    .welcome-info {
      min-width: 0;
      flex: 1;

      .title-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 12px;
        margin-bottom: 6px;

        .framework-icon-wrap {
          width: 34px;
          height: 34px;
          border-radius: 9px;
          background: rgba(255, 255, 255, 0.22);
          border: 1px solid rgba(255, 255, 255, 0.35);
          display: inline-flex;
          align-items: center;
          justify-content: center;
          font-size: 18px;
          color: #ffffff;
          flex-shrink: 0;
        }

        h2 {
          margin: 0;
          font-size: 20px;
          font-weight: 600;
          letter-spacing: -0.2px;
        }

        .version-tag {
          background: rgba(255, 255, 255, 0.2);
          color: #fff;
          border-color: rgba(255, 255, 255, 0.38);
          font-weight: 500;
          font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
        }

        .status-tag {
          font-weight: 500;

          &.is-latest {
            background: rgba(240, 253, 244, 0.96);
            border-color: rgba(187, 247, 208, 0.96);
            color: #15803d;
          }

          &.pulse-tag {
            animation: hwPulseGlow 2s infinite ease-in-out;
          }

          &.beta-badge-tag {
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.38);
          }
        }
      }

      @keyframes hwPulseGlow {
        0% {
          box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.55);
        }
        70% {
          box-shadow: 0 0 0 8px rgba(239, 68, 68, 0);
        }
        100% {
          box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
      }

      .subtitle {
        margin: 0;
        font-size: 13px;
        opacity: 0.92;
        line-height: 1.5;
      }

      .update-channel-bar {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;

        .channel-label {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          font-size: 12px;
          font-weight: 600;
          color: rgba(255, 255, 255, 0.95);
        }

        .channel-radio-group {
          :deep(.el-radio-button__inner) {
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(255, 255, 255, 0.34);
            color: #ffffff;
            font-weight: 500;
            padding: 5px 11px;
            box-shadow: none;
          }

          :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
            background: #ffffff;
            border-color: #ffffff;
            color: #1d4ed8;
            font-weight: 600;
          }
        }

        .beta-risk-banner {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          padding: 3px 10px;
          border-radius: 999px;
          background: rgba(254, 243, 199, 0.96);
          border: 1px solid rgba(251, 191, 36, 0.9);
          color: #92400e;
          font-size: 12px;
          font-weight: 600;
        }
      }
    }

    .header-actions {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      flex-shrink: 0;

      .banner-btn {
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(255, 255, 255, 0.96);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
        font-weight: 500;
        transition: all 0.2s ease;

        &:hover,
        &:focus {
          background: #ffffff;
          border-color: #ffffff;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(15, 23, 42, 0.16);
        }

        &.banner-btn-check,
        &.banner-btn-backup {
          color: #1d4ed8;
        }

        &.banner-btn-refresh {
          color: #0958d9;
        }

        &.banner-btn-clear {
          color: #d46b08;
        }
      }

      .banner-btn-upgrade {
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
      }
    }
  }

  // 第一排布局
  .top-overview-row {
    margin-bottom: 16px;
    align-items: stretch;

    > :deep(.el-col) {
      display: flex;
      flex-direction: column;
    }

    .metrics-grid {
      flex: 1;
      height: 100%;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 14px;

      @media (max-width: 768px) {
        grid-template-columns: repeat(2, 1fr);
        grid-template-rows: auto;
      }
    }

    .metric-card {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background: var(--el-bg-color-overlay);
      border: 1px solid var(--el-border-color-lighter);
      border-radius: 8px;
      padding: 14px 16px;
      cursor: pointer;
      transition: all 0.25s ease;

      &:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
      }

      .metric-body {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        gap: 10px;

        .metric-info {
          display: flex;
          flex-direction: column;
          min-width: 0;

          .metric-label {
            font-size: 13px;
            color: var(--el-text-color-secondary);
            margin-bottom: 4px;
          }

          .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--el-text-color-primary);
            line-height: 1.2;

            &.metric-value-text {
              font-size: 18px;
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }
          }
        }

        .metric-icon {
          display: flex;
          flex-shrink: 0;
          align-items: center;
          justify-content: center;
          width: 44px;
          height: 44px;
          border-radius: 10px;
          font-size: 22px;
        }
      }

      .metric-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 8px;
        border-top: 1px dashed var(--el-border-color-lighter);
        font-size: 12px;
        color: var(--el-text-color-secondary);

        .status-pill {
          padding: 1px 7px;
          border-radius: 10px;
          font-size: 11px;
          font-weight: 500;
        }
      }

      // 颜色分支
      &.card-blue {
        .metric-icon {
          background: rgba(24, 144, 255, 0.12);
          color: #1890ff;
        }
        .status-blue {
          background: #e6f7ff;
          color: #1890ff;
        }
      }

      &.card-cyan {
        .metric-icon {
          background: rgba(19, 194, 194, 0.12);
          color: #13c2c2;
        }
        .status-cyan {
          background: #e6fffb;
          color: #13c2c2;
        }
      }

      &.card-purple {
        .metric-icon {
          background: rgba(114, 46, 209, 0.12);
          color: #722ed1;
        }
        .status-purple {
          background: #f9f0ff;
          color: #722ed1;
        }
      }

      &.card-orange {
        .metric-icon {
          background: rgba(250, 140, 22, 0.12);
          color: #fa8c16;
        }
        .status-orange {
          background: #fff7e6;
          color: #fa8c16;
        }
      }

      &.card-green {
        .metric-icon {
          background: rgba(82, 196, 26, 0.12);
          color: #52c41a;
        }
        .status-green {
          background: #f6ffed;
          color: #52c41a;
        }
      }

      &.card-pink {
        .metric-icon {
          background: rgba(235, 47, 150, 0.12);
          color: #eb2f96;
        }
        .status-pink {
          background: #fff0f6;
          color: #eb2f96;
        }
      }
    }

    // 安全态势卡片
    .security-card {
      flex: 1;
      height: 100%;
      margin-bottom: 0;
      border-radius: 8px;
      display: flex;
      flex-direction: column;

      :deep(.el-card__body) {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
      }

      .card-header {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px 12px;

        .title {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          font-size: 14px;
          font-weight: 600;
        }
      }

      .security-ring-container {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-around;
        gap: 12px;
        padding: 8px 0;

        .gauge-ring {
          position: relative;
          width: 90px;
          height: 90px;

          .gauge-svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;

            .gauge-track {
              fill: none;
              stroke: var(--el-fill-color-darker);
              stroke-width: 8;
            }

            .gauge-progress {
              fill: none;
              stroke: #52c41a;
              stroke-width: 8;
              stroke-linecap: round;
              transition: stroke-dashoffset 0.6s ease;
            }
          }

          .gauge-value {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: baseline;
            justify-content: center;
            padding-top: 30px;

            .num {
              font-size: 26px;
              font-weight: 700;
              color: var(--el-text-color-primary);
            }

            .unit {
              font-size: 12px;
              color: var(--el-text-color-secondary);
              margin-left: 2px;
            }
          }
        }

        .security-meta {
          display: flex;
          flex-direction: column;
          gap: 8px;

          .sec-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--el-text-color-regular);

            .chk-tip {
              max-width: 130px;
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }
          }
        }
      }

      .security-action {
        display: flex;
        justify-content: flex-end;
        padding-top: 4px;
      }
    }
  }

  // 第二排布局
  .mid-section-row {
    margin-bottom: 16px;
    align-items: stretch;

    > :deep(.el-col) {
      display: flex;
      flex-direction: column;
    }

    .trend-card {
      flex: 1;
      height: 100%;
      margin-bottom: 0;
      border-radius: 8px;
      display: flex;
      flex-direction: column;

      :deep(.el-card__body) {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      .card-header {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px 12px;

        .title-with-legend {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          gap: 8px 14px;
          min-width: 0;

          .title {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 15px;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
          }

          .chart-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 14px;

            .legend-item {
              display: inline-flex;
              align-items: center;
              gap: 6px;
              font-size: 12px;
              color: var(--el-text-color-secondary);
              white-space: nowrap;

              .dot {
                position: static;
                flex-shrink: 0;
                margin: 0;
                width: 8px;
                height: 8px;
                border-radius: 50%;

                &.blue {
                  background-color: #1890ff;
                }
                &.purple {
                  background-color: #722ed1;
                }
              }
            }
          }
        }
      }

      .svg-chart-container {
        position: relative;
        width: 100%;
        height: 220px;

        .trend-svg {
          width: 100%;
          height: 100%;
          cursor: crosshair;

          .grid-line {
            stroke: var(--el-border-color-lighter);
            stroke-width: 1;
            stroke-dasharray: 4 4;
          }

          .axis-line {
            stroke: var(--el-border-color);
            stroke-dasharray: none;
          }

          .axis-text {
            font-size: 11px;
            fill: var(--el-text-color-placeholder);
          }

          .svg-line {
            stroke-linecap: round;
            stroke-linejoin: round;
          }

          .chart-circle {
            stroke-width: 2;
            transition: r 0.2s;

            &.circle-req {
              fill: #fff;
              stroke: #1890ff;
            }

            &.circle-log {
              fill: #fff;
              stroke: #722ed1;
            }
          }

          .hover-guide-line {
            stroke: #1890ff;
            stroke-width: 1;
            stroke-dasharray: 3 3;
            opacity: 0.6;
          }
        }

        .chart-tooltip {
          position: absolute;
          transform: translateX(-50%);
          padding: 8px 12px;
          background: rgba(0, 0, 0, 0.82);
          backdrop-filter: blur(4px);
          border-radius: 6px;
          color: #fff;
          font-size: 12px;
          pointer-events: none;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
          z-index: 10;

          .tooltip-header {
            font-weight: 600;
            margin-bottom: 4px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 2px;
          }

          .tooltip-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;

            .dot {
              width: 6px;
              height: 6px;
              border-radius: 50%;
              &.blue {
                background: #1890ff;
              }
              &.purple {
                background: #722ed1;
              }
            }

            strong {
              color: #fffae6;
            }
          }
        }
      }
    }

    // 智能研发 6 宫格卡片
    .quick-nav-matrix-card {
      border-radius: 8px;
      flex: 1;
      height: 100%;
      margin-bottom: 0;
      display: flex;
      flex-direction: column;

      :deep(.el-card__body) {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      .card-header {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px 12px;

        .title {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          font-size: 15px;
          font-weight: 600;
        }
      }

      .quick-matrix-grid {
        flex: 1;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        align-content: space-between;
        gap: 12px;
        padding: 4px 0;

        .matrix-item {
          display: flex;
          align-items: center;
          gap: 10px;
          padding: 10px 12px;
          background: var(--el-fill-color-light);
          border-radius: 8px;
          cursor: pointer;
          transition: all 0.25s ease;

          &:hover {
            background: var(--el-color-primary-light-9);
            transform: translateY(-2px);
          }

          .matrix-icon-box {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            font-size: 20px;
            flex-shrink: 0;

            &.bg-blue {
              background: #e6f7ff;
              color: #1890ff;
            }
            &.bg-purple {
              background: #f9f0ff;
              color: #722ed1;
            }
            &.bg-green {
              background: #f6ffed;
              color: #52c41a;
            }
            &.bg-pink {
              background: #fff0f6;
              color: #eb2f96;
            }
            &.bg-orange {
              background: #fff7e6;
              color: #fa8c16;
            }
            &.bg-teal {
              background: #e6fffb;
              color: #13c2c2;
            }
          }

          .matrix-content {
            display: flex;
            flex-direction: column;
            min-width: 0;

            .matrix-name {
              font-size: 13px;
              font-weight: 600;
              color: var(--el-text-color-primary);
              margin-bottom: 2px;
            }

            .matrix-desc {
              font-size: 11px;
              color: var(--el-text-color-secondary);
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }
          }
        }
      }
    }
  }

  // 第三排布局
  .bottom-section-row {
    align-items: stretch;

    > :deep(.el-col) {
      display: flex;
      flex-direction: column;
    }

    .logs-card,
    .system-probe-card {
      border-radius: 8px;
      flex: 1;
      height: 100%;
      margin-bottom: 0;
      display: flex;
      flex-direction: column;

      :deep(.el-card__body) {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
      }

      .card-header {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px 12px;

        .title {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          font-size: 15px;
          font-weight: 600;
        }

        .dual-badge {
          display: inline-flex;
          align-items: center;
          gap: 4px;
        }
      }
    }

    .audit-timeline-wrap {
      max-height: 250px;
      overflow-y: auto;
      padding: 6px 12px;

      .audit-timeline {
        padding-left: 4px;

        .audit-log-row {
          .audit-main {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;

            .operator-badge {
              font-size: 12px;
              font-weight: 600;
              color: var(--el-color-primary);
            }

            .log-action {
              font-size: 13px;
              font-weight: 500;
              color: var(--el-text-color-primary);
            }
          }

          .audit-details {
            display: flex;
            align-items: center;
            gap: 8px;

            .ip-tag {
              font-size: 11px;
              color: var(--el-text-color-placeholder);
            }

            .url-code {
              font-size: 11px;
              background: var(--el-fill-color);
              padding: 1px 6px;
              border-radius: 4px;
              color: var(--el-text-color-secondary);
            }
          }
        }
      }
    }

    .system-probe-card {
      .probe-descriptions {
        margin-bottom: 14px;

        .tech-tag {
          font-family: monospace;
          font-size: 12px;
          color: var(--el-color-primary);
        }

        .engine-check {
          color: #52c41a;
          font-weight: 500;
          font-size: 12px;
        }
      }

      .probe-footer-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 10px 12px;
        padding-top: 10px;
        border-top: 1px solid var(--el-border-color-lighter);
      }
    }
  }

  .tooltip-row .dot {
    position: static;
    flex-shrink: 0;
    margin: 0;
  }

  @media (max-width: 1199px) {
    .top-overview-row,
    .mid-section-row,
    .bottom-section-row {
      row-gap: 16px;
    }
  }

  @media (max-width: 768px) {
    padding: 12px;

    .welcome-header {
      padding: 16px;
    }

    .bottom-section-row .audit-timeline-wrap .audit-timeline .audit-log-row {
      .audit-main,
      .audit-details {
        flex-wrap: wrap;
      }

      .audit-details .url-code {
        word-break: break-all;
      }
    }
  }

  @media (max-width: 520px) {
    .top-overview-row .metrics-grid {
      grid-template-columns: 1fr;
    }
  }
}
</style>
