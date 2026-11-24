<template>
  <div class="oss-workbench-container">
    <!-- 顶部状态概览与指标看板 -->
    <el-row :gutter="16" class="overview-row">
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">全局默认存储驱动</div>
            <div class="stat-value">
              <el-tag :type="getDriverTagType(activeDriver)" size="large" effect="dark">
                {{ formatStorageLabel(activeDriver) }}
              </el-tag>
            </div>
            <div class="stat-tip">当前系统 Attachment 默认分发链路</div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">沙盒免密钥模拟</div>
            <div class="stat-value">
              <el-switch
                v-model="configData.mock_mode"
                :active-value="1"
                :inactive-value="0"
                active-text="已开启"
                inactive-text="真实直传"
                @change="handleMockModeChange"
              />
            </div>
            <div class="stat-tip">
              {{ configData.mock_mode ? '免真实云账号，真算法签名+本地可预览' : '直传云厂商 API 生产 Bucket' }}
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">累计云存储对象</div>
            <div class="stat-value text-primary">
              {{ stats.total_count }} <span class="unit">个</span>
            </div>
            <div class="stat-tip">
              COS: <strong>{{ stats.cos_count }}</strong> | OSS: <strong>{{ stats.oss_count }}</strong> | 七牛: <strong>{{ stats.qiniu_count }}</strong>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">云存储流水总体积</div>
            <div class="stat-value text-success">
              {{ formatBytes(stats.total_size) }}
            </div>
            <div class="stat-tip">经由云插件驱动同步的总数据量</div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 全局存储路由快速切换卡片 -->
    <el-card shadow="never" class="router-switcher-card">
      <template #header>
        <div class="card-header">
          <span class="card-title">
            <el-icon><vab-icon icon="route-line" /></el-icon>
            系统全局存储引擎一键路由
          </span>
          <span class="card-subtitle">点击即时热插拔切换底层驱动，无缝影响系统附件与富文本上传</span>
        </div>
      </template>

      <div class="storage-routes-grid">
        <div
          v-for="(label, key) in storageOptions"
          :key="key"
          class="storage-route-item"
          :class="{ active: activeDriver === key }"
          @click="switchGlobalDriver(String(key))"
        >
          <div class="route-icon">
            <el-icon v-if="key === 'local'"><vab-icon icon="hard-drive-2-line" /></el-icon>
            <el-icon v-else-if="key === 'private'"><vab-icon icon="shield-keyhole-line" /></el-icon>
            <el-icon v-else-if="key === 'cos'"><vab-icon icon="cloud-line" /></el-icon>
            <el-icon v-else-if="key === 'oss'"><vab-icon icon="server-line" /></el-icon>
            <el-icon v-else><vab-icon icon="database-2-line" /></el-icon>
          </div>
          <div class="route-info">
            <div class="route-name">{{ label }}</div>
            <div class="route-key">driver: {{ key }}</div>
          </div>
          <div v-if="activeDriver === key" class="active-badge">
            <el-icon><vab-icon icon="check-line" /></el-icon> 当前生效
          </div>
        </div>
      </div>
    </el-card>

    <!-- 中部三大云厂商配置与连通性测试体验区 -->
    <el-row :gutter="16" class="main-cloud-row">
      <el-col :xs="24" :lg="14">
        <el-card shadow="never" class="vendor-config-card">
          <template #header>
            <div class="card-header">
              <span class="card-title">
                <el-icon><vab-icon icon="settings-3-line" /></el-icon>
                三大云存储驱动参数矩阵
              </span>
              <el-button type="primary" :loading="saving" @click="handleSaveCurrentConfig">
                <el-icon><vab-icon icon="save-line" /></el-icon> 保存配置
              </el-button>
            </div>
          </template>

          <el-tabs v-model="activeTab" type="border-card" class="vendor-tabs">
            <!-- 腾讯云 COS -->
            <el-tab-pane name="cos">
              <template #label>
                <span class="tab-label">
                  <el-icon><vab-icon icon="cloud-line" /></el-icon> 腾讯云 COS
                </span>
              </template>
              <el-form :model="configData.cos" label-width="120px" class="config-form">
                <el-alert
                  title="腾讯云对象存储 (Cloud Object Storage, COS)"
                  type="info"
                  :closable="false"
                  show-icon
                  description="支持标准 COS XML V5 规范签名，直传 ap-guangzhou / ap-beijing / ap-shanghai 等全球可用区"
                  style="margin-bottom: 18px"
                />
                <el-form-item label="SecretId">
                  <el-input
                    v-model="configData.cos.secret_id"
                    placeholder="例如 AKIDxxxxxxxxxxxxxxxxxxxxxx"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="SecretKey">
                  <el-input
                    v-model="configData.cos.secret_key"
                    type="password"
                    show-password
                    placeholder="腾讯云 API 密钥 SecretKey"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="Bucket 名称">
                  <el-input
                    v-model="configData.cos.bucket"
                    placeholder="例如 hwadmin-1258888888 (需包含 AppId 后缀)"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="所属地域 Region">
                  <el-input
                    v-model="configData.cos.region"
                    placeholder="例如 ap-guangzhou、ap-beijing、ap-shanghai"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="访问自定义域名">
                  <el-input
                    v-model="configData.cos.domain"
                    placeholder="例如 https://cos.hwadmin.example.com (留空则使用 Bucket 默认域名)"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="存储前缀 Prefix">
                  <el-input
                    v-model="configData.cos.prefix"
                    placeholder="例如 uploads/"
                    clearable
                  />
                </el-form-item>
              </el-form>
            </el-tab-pane>

            <!-- 阿里云 OSS -->
            <el-tab-pane name="oss">
              <template #label>
                <span class="tab-label">
                  <el-icon><vab-icon icon="server-line" /></el-icon> 阿里云 OSS
                </span>
              </template>
              <el-form :model="configData.oss" label-width="120px" class="config-form">
                <el-alert
                  title="阿里云对象存储 (Object Storage Service, OSS)"
                  type="warning"
                  :closable="false"
                  show-icon
                  description="基于 RFC 原生 HMAC-SHA1 Authorization 规范，支持内网与外网 Endpoint 极速直传"
                  style="margin-bottom: 18px"
                />
                <el-form-item label="AccessKey ID">
                  <el-input
                    v-model="configData.oss.access_key_id"
                    placeholder="例如 LTAIxxxxxxxxxxxxxxxx"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="AccessKey Secret">
                  <el-input
                    v-model="configData.oss.access_key_secret"
                    type="password"
                    show-password
                    placeholder="阿里云 AccessKey Secret"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="Bucket 名称">
                  <el-input
                    v-model="configData.oss.bucket"
                    placeholder="例如 hwadmin-oss-prod"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="Endpoint 节点">
                  <el-input
                    v-model="configData.oss.endpoint"
                    placeholder="例如 oss-cn-hangzhou.aliyuncs.com"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="访问自定义域名">
                  <el-input
                    v-model="configData.oss.domain"
                    placeholder="例如 https://oss.hwadmin.example.com"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="存储前缀 Prefix">
                  <el-input
                    v-model="configData.oss.prefix"
                    placeholder="例如 uploads/"
                    clearable
                  />
                </el-form-item>
              </el-form>
            </el-tab-pane>

            <!-- 七牛云 Kodo -->
            <el-tab-pane name="qiniu">
              <template #label>
                <span class="tab-label">
                  <el-icon><vab-icon icon="database-2-line" /></el-icon> 七牛云 Kodo
                </span>
              </template>
              <el-form :model="configData.qiniu" label-width="120px" class="config-form">
                <el-alert
                  title="七牛云海量存储系统 (Kodo Object Storage)"
                  type="success"
                  :closable="false"
                  show-icon
                  description="支持安全 PutPolicy 授权签发 UpToken，内置多机房智能就近上传加速"
                  style="margin-bottom: 18px"
                />
                <el-form-item label="AccessKey (AK)">
                  <el-input
                    v-model="configData.qiniu.access_key"
                    placeholder="七牛云个人中心 API 密钥 AccessKey"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="SecretKey (SK)">
                  <el-input
                    v-model="configData.qiniu.secret_key"
                    type="password"
                    show-password
                    placeholder="七牛云 SecretKey"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="Bucket 空间名称">
                  <el-input
                    v-model="configData.qiniu.bucket"
                    placeholder="例如 hwadmin-qiniu"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="存储区域 Zone">
                  <el-select v-model="configData.qiniu.zone" style="width: 100%">
                    <el-option label="华东-浙江 (z0: up.qiniup.com)" value="z0" />
                    <el-option label="华北-河北 (z1: up-z1.qiniup.com)" value="z1" />
                    <el-option label="华南-广东 (z2: up-z2.qiniup.com)" value="z2" />
                    <el-option label="北美-洛杉矶 (na0: up-na0.qiniup.com)" value="na0" />
                    <el-option label="亚太-新加坡 (as0: up-as0.qiniup.com)" value="as0" />
                    <el-option label="亚太-首尔 (ap-northeast-1)" value="ap-northeast-1" />
                  </el-select>
                </el-form-item>
                <el-form-item label="CDN 加速域名">
                  <el-input
                    v-model="configData.qiniu.domain"
                    placeholder="例如 https://qiniu.hwadmin.example.com"
                    clearable
                  />
                </el-form-item>
                <el-form-item label="存储前缀 Prefix">
                  <el-input
                    v-model="configData.qiniu.prefix"
                    placeholder="例如 uploads/"
                    clearable
                  />
                </el-form-item>
              </el-form>
            </el-tab-pane>
          </el-tabs>
        </el-card>
      </el-col>

      <!-- 右侧：连通性探针与在线直传实测沙盒 -->
      <el-col :xs="24" :lg="10">
        <el-card shadow="never" class="probe-test-card">
          <template #header>
            <div class="card-header">
              <span class="card-title">
                <el-icon><vab-icon icon="shield-check-line" /></el-icon>
                连通性探针与在线云直传实测
              </span>
              <el-tag :type="getDriverTagType(activeTab)" size="small">
                当前探针：{{ formatStorageLabel(activeTab) }}
              </el-tag>
            </div>
          </template>

          <div class="probe-action-box">
            <el-button
              type="warning"
              plain
              :loading="testing"
              class="probe-btn"
              @click="handleTestConnection"
            >
              <el-icon><vab-icon icon="flashlight-line" /></el-icon>
              ⚡ 立即测试连接与验算签名
            </el-button>

            <!-- 探针结果展示框 -->
            <transition name="el-fade-in-linear">
              <div v-if="testResult" class="probe-result-box">
                <div class="result-title">
                  <el-icon class="text-success"><vab-icon icon="checkbox-circle-line" /></el-icon>
                  <strong>{{ testResult.message }}</strong>
                </div>
                <div class="result-details">
                  <div class="detail-line">
                    <span class="label">运行模式：</span>
                    <el-tag size="small" :type="configData.mock_mode ? 'success' : 'danger'">
                      {{ testResult.mode_label }}
                    </el-tag>
                  </div>
                  <div class="detail-line">
                    <span class="label">目标端点：</span>
                    <code>{{ testResult.endpoint_url }}</code>
                  </div>
                  <div class="detail-line">
                    <span class="label">计算耗时：</span>
                    <span class="cost">{{ testResult.cost_ms }} ms</span>
                  </div>
                  <div class="detail-line signature-line">
                    <span class="label">规范签名预览：</span>
                    <el-input
                      type="textarea"
                      :rows="2"
                      readonly
                      :model-value="testResult.auth_signature_preview"
                      class="sign-preview-input"
                    />
                  </div>
                </div>
              </div>
            </transition>
          </div>

          <el-divider content-position="left">
            <span class="divider-title">
              <el-icon><vab-icon icon="upload-cloud-2-line" /></el-icon>
              📤 在线云直传实测
            </span>
          </el-divider>

          <div class="upload-sandbox-box">
            <el-upload
              action="#"
              drag
              :show-file-list="false"
              :http-request="handleSandboxUpload"
              accept="image/*,.pdf,.txt,.zip"
            >
              <el-icon class="el-icon--upload"><vab-icon icon="upload-cloud-line" /></el-icon>
              <div class="el-upload__text">
                将测试文件拖至此处，或 <em>点击选取文件上传</em>
              </div>
              <template #tip>
                <div class="el-upload__tip">
                  将携带 <code>storage: {{ activeTab }}</code> 经由 <code>/adminapi/system.attachment/upload</code> 驱动并生成云端流水
                </div>
              </template>
            </el-upload>

            <div v-if="uploadSuccessData" class="upload-feedback-card">
              <div class="feedback-header">
                <el-icon class="text-success"><vab-icon icon="checkbox-circle-fill" /></el-icon>
                <span>直传验证成功</span>
                <el-tag size="small" type="success" effect="plain">
                  {{ uploadSuccessData.fast_upload ? '秒传命中' : '全新同步' }}
                </el-tag>
              </div>
              <div class="feedback-body">
                <div class="feedback-item">
                  <span class="fb-label">文件名：</span>
                  <span class="fb-val">{{ uploadSuccessData.name || uploadSuccessData.filename }}</span>
                </div>
                <div class="feedback-item">
                  <span class="fb-label">访问链接：</span>
                  <el-link
                    type="primary"
                    :href="uploadSuccessData.fullurl || uploadSuccessData.url"
                    target="_blank"
                    class="fb-link"
                  >
                    {{ uploadSuccessData.fullurl || uploadSuccessData.url }}
                  </el-link>
                </div>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 底部云存储上传流水日志 -->
    <el-card shadow="never" class="logs-card">
      <template #header>
        <div class="card-header">
          <div class="header-left">
            <span class="card-title">
              <el-icon><vab-icon icon="history-line" /></el-icon>
              云存储同步流水日志
            </span>
            <el-radio-group v-model="logFilterDriver" size="small" @change="handleSearchLogs">
              <el-radio-button value="">全部厂商</el-radio-button>
              <el-radio-button value="cos">腾讯云 COS</el-radio-button>
              <el-radio-button value="oss">阿里云 OSS</el-radio-button>
              <el-radio-button value="qiniu">七牛云 Kodo</el-radio-button>
            </el-radio-group>
          </div>

          <div class="header-right">
            <el-input
              v-model="logSearch"
              placeholder="搜索 Object Key / 文件名 / 桶"
              clearable
              style="width: 260px; margin-right: 10px"
              @keyup.enter="handleSearchLogs"
              @clear="handleSearchLogs"
            >
              <template #prefix>
                <el-icon><vab-icon icon="search-line" /></el-icon>
              </template>
            </el-input>
            <el-button type="primary" plain @click="handleSearchLogs">
              <el-icon><vab-icon icon="refresh-line" /></el-icon> 刷新
            </el-button>
            <el-button
              type="danger"
              plain
              :disabled="selectedLogIds.length === 0"
              @click="handleBatchDeleteLogs"
            >
              <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
              批量删除 ({{ selectedLogIds.length }})
            </el-button>
          </div>
        </div>
      </template>

      <el-table
        v-loading="logsLoading"
        :data="logList"
        row-key="id"
        border
        style="width: 100%"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" align="center" />
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="driver" label="存储引擎" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="getDriverTagType(row.driver)" effect="dark" size="small">
              {{ formatStorageLabel(row.driver) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="bucket" label="存储空间 Bucket" width="160" show-overflow-tooltip />
        <el-table-column prop="object_key" label="对象键 (Object Key)" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <code>{{ row.object_key }}</code>
          </template>
        </el-table-column>
        <el-table-column prop="original_name" label="原始文件名" min-width="150" show-overflow-tooltip />
        <el-table-column prop="size" label="文件大小" width="110" align="right">
          <template #default="{ row }">
            {{ formatBytes(row.size) }}
          </template>
        </el-table-column>
        <el-table-column prop="is_mock" label="传输模式" width="110" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.is_mock" type="success" size="small" effect="plain">沙盒模拟</el-tag>
            <el-tag v-else type="primary" size="small" effect="plain">真实直传</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="cost_ms" label="耗时" width="90" align="center">
          <template #default="{ row }">
            <span class="cost-badge">{{ row.cost_ms }} ms</span>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="上传时间" width="160" align="center">
          <template #default="{ row }">
            {{ formatDateTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="218" align="center" fixed="right">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="copyUrl(row.url)">
                复制链接
              </el-button>
              <el-button plain type="success" size="small" @click="openUrl(row.url)">
                打开
              </el-button>
              <el-popconfirm title="确定要删除此条上传记录吗？" @confirm="handleDeleteSingleLog(row.id)">
                <template #reference>
                  <el-button plain type="danger" size="small">删除</el-button>
                </template>
              </el-popconfirm>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-footer">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="limit"
          :page-sizes="[10, 15, 30, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="handleSearchLogs"
          @current-change="handleSearchLogs"
        />
      </div>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl } from '/@/utils/platform'
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getOssConfig,
  saveOssConfig,
  testOssConnection,
  getOssLogs,
  delOssLogs,
  type OssConfigData,
  type OssLogItem,
} from '/@/api/oss'
import { uploadAttachment } from '/@/api/hwadmin'

defineOptions({
  name: 'OssConfigWorkbench',
})

const activeDriver = ref('local')
const activeTab = ref<'cos' | 'oss' | 'qiniu'>('cos')
const saving = ref(false)
const testing = ref(false)
const logsLoading = ref(false)

const storageOptions = ref<Record<string, string>>({
  local: '本地存储 (Local)',
  private: '私有受控存储 (Private)',
  cos: '腾讯云 COS 对象存储',
  oss: '阿里云 OSS 对象存储',
  qiniu: '七牛云 Kodo 对象存储',
})

const stats = reactive({
  total_count: 0,
  cos_count: 0,
  oss_count: 0,
  qiniu_count: 0,
  total_size: 0,
})

const configData = reactive<OssConfigData>({
  mock_mode: 1,
  cos: {
    secret_id: '',
    secret_key: '',
    bucket: '',
    region: 'ap-guangzhou',
    domain: '',
    prefix: 'uploads/',
  },
  oss: {
    access_key_id: '',
    access_key_secret: '',
    bucket: '',
    endpoint: 'oss-cn-hangzhou.aliyuncs.com',
    domain: '',
    prefix: 'uploads/',
  },
  qiniu: {
    access_key: '',
    secret_key: '',
    bucket: '',
    zone: 'z0',
    domain: '',
    prefix: 'uploads/',
  },
})

const testResult = ref<any>(null)
const uploadSuccessData = ref<any>(null)

// 日志表格
const logList = ref<OssLogItem[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(15)
const logFilterDriver = ref('')
const logSearch = ref('')
const selectedLogIds = ref<number[]>([])

const getDriverTagType = (driver: string): 'primary' | 'success' | 'warning' | 'info' | 'danger' => {
  switch (driver) {
    case 'cos':
      return 'primary'
    case 'oss':
      return 'warning'
    case 'qiniu':
      return 'success'
    case 'private':
      return 'danger'
    default:
      return 'info'
  }
}

const formatStorageLabel = (driver: string): string => {
  return storageOptions.value[driver] || driver
}

const formatBytes = (bytes: number): string => {
  if (!bytes || bytes <= 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}

const formatDateTime = (ts: number): string => {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(
    d.getHours()
  ).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`
}

// 加载整体配置
const fetchAllData = async () => {
  try {
    const res = await getOssConfig()
    if (res?.data) {
      activeDriver.value = res.data.upload_storage || 'local'
      if (res.data.storage_options) {
        storageOptions.value = res.data.storage_options
      }
      if (res.data.config) {
        configData.mock_mode = res.data.config.mock_mode ?? 1
        if (res.data.config.cos) Object.assign(configData.cos, res.data.config.cos)
        if (res.data.config.oss) Object.assign(configData.oss, res.data.config.oss)
        if (res.data.config.qiniu) Object.assign(configData.qiniu, res.data.config.qiniu)
      }
      if (res.data.stats) {
        Object.assign(stats, res.data.stats)
      }
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '获取云存储配置失败')
  }
}

// 快速切换全局系统存储路由
const switchGlobalDriver = async (targetDriver: string) => {
  if (activeDriver.value === targetDriver) return
  try {
    await saveOssConfig({ upload_storage: targetDriver })
    activeDriver.value = targetDriver
    ElMessage.success(`全局默认存储已切换为：${formatStorageLabel(targetDriver)}`)
    if (['cos', 'oss', 'qiniu'].includes(targetDriver)) {
      activeTab.value = targetDriver as 'cos' | 'oss' | 'qiniu'
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '切换存储路由失败')
  }
}

// 切换沙盒模拟开关
const handleMockModeChange = async (val: any) => {
  try {
    await saveOssConfig({
      config: { mock_mode: Number(val) } as any,
    })
    ElMessage.success(Number(val) === 1 ? '已开启沙盒免密钥模拟模式' : '已切换为真实云端直传模式')
  } catch (err: any) {
    ElMessage.error(err?.message || '更新模式失败')
  }
}

// 保存当前表单配置
const handleSaveCurrentConfig = async () => {
  saving.value = true
  try {
    await saveOssConfig({
      upload_storage: activeDriver.value,
      config: {
        mock_mode: configData.mock_mode,
        cos: configData.cos,
        oss: configData.oss,
        qiniu: configData.qiniu,
      },
    })
    ElMessage.success('三大云厂商配置保存成功！')
    await fetchAllData()
  } catch (err: any) {
    ElMessage.error(err?.message || '保存配置失败')
  } finally {
    saving.value = false
  }
}

// 连通性与签名探针
const handleTestConnection = async () => {
  testing.value = true
  testResult.value = null
  try {
    const driver = activeTab.value
    const driverConfig = configData[driver]
    const res = await testOssConnection({
      driver,
      driver_config: driverConfig,
      mock_mode: configData.mock_mode,
    })
    testResult.value = res.data
    ElMessage.success((res.data as any)?.message || '连通性测试通过！')
  } catch (err: any) {
    ElMessage.error(err?.message || '连通性测试失败')
  } finally {
    testing.value = false
  }
}

// 在线云直传体验
const handleSandboxUpload = async (options: any) => {
  const { file } = options
  try {
    const driver = activeTab.value
    const fd = new FormData()
    fd.append('file', file)
    fd.append('storage', driver)
    const res = await uploadAttachment(fd)
    uploadSuccessData.value = res.data
    ElMessage.success(`文件通过【${formatStorageLabel(driver)}】直传成功！`)
    await fetchLogs()
    await fetchAllData()
  } catch (err: any) {
    ElMessage.error(err?.message || '文件直传失败')
  }
}

// 查询流水日志
const fetchLogs = async () => {
  logsLoading.value = true
  try {
    const res = await getOssLogs({
      driver: logFilterDriver.value || undefined,
      search: logSearch.value || undefined,
      page: page.value,
      limit: limit.value,
    })
    logList.value = res?.data?.list || []
    total.value = res?.data?.total || 0
  } catch (err: any) {
    ElMessage.error(err?.message || '获取流水日志失败')
  } finally {
    logsLoading.value = false
  }
}

const handleSearchLogs = () => {
  page.value = 1
  fetchLogs()
}

const handleSelectionChange = (rows: OssLogItem[]) => {
  selectedLogIds.value = rows.map((r) => r.id)
}

const handleDeleteSingleLog = async (id: number) => {
  try {
    await delOssLogs(id)
    ElMessage.success('日志记录已删除')
    await fetchLogs()
    await fetchAllData()
  } catch (err: any) {
    ElMessage.error(err?.message || '删除日志失败')
  }
}

const handleBatchDeleteLogs = async () => {
  if (selectedLogIds.value.length === 0) return
  try {
    await delOssLogs(selectedLogIds.value)
    ElMessage.success('批量删除成功')
    selectedLogIds.value = []
    await fetchLogs()
    await fetchAllData()
  } catch (err: any) {
    ElMessage.error(err?.message || '批量删除失败')
  }
}

const copyUrl = (url: string) => {
  if (!url) return
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      ElMessage.success('访问链接已复制到剪贴板')
    })
  } else {
    ElMessage.info(`链接地址: ${url}`)
  }
}

const openUrl = (url: string) => {
  if (!url) return
  void openExternalUrl(url)
}

onMounted(() => {
  fetchAllData()
  fetchLogs()
})
</script>

<style lang="scss" scoped>
.oss-workbench-container {
  padding: 16px;

  .overview-row {
    margin-bottom: 16px;

    .stat-card {
      border-radius: 8px;
      .stat-content {
        .stat-label {
          font-size: 13px;
          color: var(--el-text-color-secondary);
          margin-bottom: 8px;
        }
        .stat-value {
          font-size: 24px;
          font-weight: 600;
          line-height: 1.2;
          margin-bottom: 8px;
          display: flex;
          align-items: center;

          .unit {
            font-size: 14px;
            font-weight: normal;
            margin-left: 4px;
            color: var(--el-text-color-secondary);
          }
        }
        .stat-tip {
          font-size: 12px;
          color: var(--el-text-color-placeholder);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
      }
    }
  }

  .router-switcher-card {
    border-radius: 8px;
    margin-bottom: 16px;

    .card-header {
      display: flex;
      align-items: center;
      gap: 12px;
      .card-title {
        font-size: 16px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .card-subtitle {
        font-size: 13px;
        color: var(--el-text-color-secondary);
      }
    }

    .storage-routes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px;

      .storage-route-item {
        position: relative;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border: 1px solid var(--el-border-color-lighter);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.25s ease;
        background-color: var(--el-fill-color-blank);

        &:hover {
          border-color: var(--el-color-primary-light-3);
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        &.active {
          border-color: var(--el-color-primary);
          background-color: var(--el-color-primary-light-9);

          .route-icon {
            color: var(--el-color-primary);
            background-color: var(--el-color-primary-light-8);
          }
          .route-name {
            color: var(--el-color-primary);
            font-weight: 600;
          }
        }

        .route-icon {
          width: 40px;
          height: 40px;
          border-radius: 8px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 20px;
          background-color: var(--el-fill-color-light);
          color: var(--el-text-color-regular);
          transition: all 0.2s ease;
        }

        .route-info {
          flex: 1;
          .route-name {
            font-size: 14px;
            margin-bottom: 2px;
          }
          .route-key {
            font-size: 12px;
            color: var(--el-text-color-placeholder);
          }
        }

        .active-badge {
          position: absolute;
          top: 6px;
          right: 8px;
          font-size: 11px;
          color: var(--el-color-primary);
          display: flex;
          align-items: center;
          gap: 2px;
        }
      }
    }
  }

  .main-cloud-row {
    margin-bottom: 16px;

    .vendor-config-card {
      border-radius: 8px;
      height: 100%;

      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        .card-title {
          font-size: 16px;
          font-weight: 600;
          display: flex;
          align-items: center;
          gap: 6px;
        }
      }

      .vendor-tabs {
        border-radius: 6px;
        .tab-label {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 14px;
        }
        .config-form {
          padding-top: 10px;
        }
      }
    }

    .probe-test-card {
      border-radius: 8px;
      height: 100%;

      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        .card-title {
          font-size: 15px;
          font-weight: 600;
          display: flex;
          align-items: center;
          gap: 6px;
        }
      }

      .probe-action-box {
        .probe-btn {
          width: 100%;
          font-weight: 600;
          margin-bottom: 12px;
        }

        .probe-result-box {
          background-color: var(--el-fill-color-light);
          border: 1px solid var(--el-border-color-lighter);
          border-radius: 6px;
          padding: 12px;
          margin-bottom: 16px;

          .result-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            margin-bottom: 8px;
          }

          .result-details {
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;

            .detail-line {
              display: flex;
              align-items: center;
              .label {
                width: 90px;
                color: var(--el-text-color-secondary);
              }
              code {
                background-color: var(--el-fill-color-blank);
                padding: 2px 6px;
                border-radius: 4px;
                color: var(--el-color-primary);
              }
              .cost {
                color: var(--el-color-success);
                font-weight: 600;
              }
            }

            .signature-line {
              flex-direction: column;
              align-items: flex-start;
              gap: 4px;
              .sign-preview-input {
                font-family: monospace;
                font-size: 11px;
              }
            }
          }
        }
      }

      .divider-title {
        font-size: 13px;
        color: var(--el-text-color-regular);
        display: flex;
        align-items: center;
        gap: 4px;
      }

      .upload-sandbox-box {
        margin-top: 12px;

        .upload-feedback-card {
          margin-top: 12px;
          padding: 12px;
          background-color: var(--el-color-success-light-9);
          border: 1px solid var(--el-color-success-light-5);
          border-radius: 6px;

          .feedback-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
          }

          .feedback-body {
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            .feedback-item {
              display: flex;
              .fb-label {
                color: var(--el-text-color-secondary);
                width: 70px;
              }
              .fb-val {
                word-break: break-all;
              }
              .fb-link {
                word-break: break-all;
                font-size: 12px;
              }
            }
          }
        }
      }
    }
  }

  .logs-card {
    border-radius: 8px;

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;

      .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
        .card-title {
          font-size: 16px;
          font-weight: 600;
          display: flex;
          align-items: center;
          gap: 6px;
        }
      }

      .header-right {
        display: flex;
        align-items: center;
      }
    }

    .cost-badge {
      display: inline-block;
      padding: 2px 6px;
      font-size: 11px;
      border-radius: 4px;
      background-color: var(--el-fill-color-light);
      color: var(--el-color-primary);
      font-family: monospace;
    }

    .pagination-footer {
      display: flex;
      justify-content: flex-end;
      margin-top: 16px;
    }
  }
}
</style>
