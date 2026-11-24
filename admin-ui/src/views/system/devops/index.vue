<template>
  <div class="devops-container">
    <el-tabs v-model="activeTab" type="border-card" class="devops-tabs">
      <!-- Tab 1: 在线 API 接口文档与调试器 -->
      <el-tab-pane name="apiDocs">
        <template #label>
          <div class="tab-label">
            <el-icon><vab-icon icon="book-read-line" /></el-icon>
            <span>📚 在线 API 文档与调试中心</span>
          </div>
        </template>

        <div class="api-docs-layout">
          <!-- 左侧：接口树形目录 -->
          <div class="api-sidebar">
            <div class="sidebar-search">
              <el-input
                v-model="apiSearchKeyword"
                placeholder="搜索接口、路径或描述..."
                clearable
                prefix-icon="Search"
              />
            </div>

            <div v-loading="apiLoading" class="api-tree-wrapper">
              <el-empty v-if="filteredApiList.length === 0" description="未匹配到接口" />
              <div v-for="app in filteredApiList" :key="app.name" class="app-group">
                <div class="group-title">
                  <el-icon><vab-icon icon="folder-2-line" /></el-icon>
                  <span>{{ app.title }}</span>
                </div>

                <div v-for="mod in app.children" :key="mod.name" class="mod-group">
                  <div class="mod-title">
                    <el-icon><vab-icon icon="folder-3-line" /></el-icon>
                    <span>{{ mod.title }}</span>
                  </div>

                  <div
                    v-for="api in (mod.apis || [])"
                    :key="api.path"
                    class="api-endpoint-item"
                    :class="{ active: currentApi && currentApi.path === api.path }"
                    @click="handleSelectApi(api)"
                  >
                    <el-tag
                      size="small"
                      :type="api.method === 'GET' ? 'success' : 'primary'"
                      effect="dark"
                      class="method-tag"
                    >
                      {{ api.method }}
                    </el-tag>
                    <div class="endpoint-info">
                      <span class="endpoint-title">{{ api.title }}</span>
                      <span class="endpoint-path font-mono">{{ api.path }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 右侧：接口详情与在线请求调试台 -->
          <div class="api-workbench">
            <div v-if="!currentApi" class="workbench-empty">
              <el-empty description="请从左侧接口列表中点击选择一个接口发起调试" />
            </div>

            <div v-else class="workbench-content">
              <!-- 接口头部信息 -->
              <el-card shadow="never" class="endpoint-meta-card">
                <div class="meta-header">
                  <div class="meta-title-line">
                    <el-tag
                      :type="currentApi.method === 'GET' ? 'success' : 'primary'"
                      effect="dark"
                      size="large"
                    >
                      {{ currentApi.method }}
                    </el-tag>
                    <span class="meta-title">{{ currentApi.title }}</span>
                    <el-tag v-if="!currentApi.need_login" type="info" size="small">无需登录</el-tag>
                    <el-tag v-if="!currentApi.need_right" type="warning" size="small">免鉴权</el-tag>
                  </div>
                  <div class="meta-url-line">
                    <span class="meta-path font-mono">{{ currentApi.path }}</span>
                    <el-button link type="primary" size="small" @click="copyText(currentApi.path)">
                      复制路径
                    </el-button>
                  </div>
                </div>
                <div v-if="currentApi.description" class="meta-desc">
                  {{ currentApi.description }}
                </div>
              </el-card>

              <!-- 请求参数配置与发送 -->
              <el-card shadow="never" class="request-runner-card">
                <template #header>
                  <div class="runner-card-header">
                    <div class="header-left">
                      <el-icon><vab-icon icon="send-plane-line" /></el-icon>
                      <span class="font-medium">在线接口调试器</span>
                    </div>
                    <el-button
                      type="primary"
                      :loading="debugLoading"
                      @click="handleSendRequest"
                    >
                      <el-icon><vab-icon icon="flashlight-line" /></el-icon>
                      立即发送请求
                    </el-button>
                  </div>
                </template>

                <div class="request-params-section">
                  <div class="section-title">URL Query 查询参数</div>
                  <div
                    v-for="(param, idx) in queryParams"
                    :key="idx"
                    class="param-row"
                  >
                    <el-input v-model="param.key" placeholder="参数名 (Key)" class="param-key-input" />
                    <el-input v-model="param.value" placeholder="参数值 (Value)" class="param-val-input" />
                    <el-button circle size="small" type="danger" @click="handleRemoveQueryParam(idx)">
                      <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
                    </el-button>
                  </div>
                  <el-button link type="primary" size="small" @click="handleAddQueryParam">
                    <el-icon><vab-icon icon="add-line" /></el-icon>
                    添加 Query 参数
                  </el-button>
                </div>

                <div v-if="currentApi.method === 'POST'" class="request-body-section">
                  <div class="section-title">POST Body (JSON 格式)</div>
                  <el-input
                    v-model="bodyJson"
                    type="textarea"
                    :rows="5"
                    class="font-mono"
                    placeholder="请输入合法的 JSON 请求体，如: { &quot;name&quot;: &quot;test&quot; }"
                  />
                </div>
              </el-card>

              <!-- 响应结果展示 -->
              <el-card shadow="never" class="response-card">
                <template #header>
                  <div class="response-card-header">
                    <div class="response-meta">
                      <span class="font-medium">响应结果</span>
                      <template v-if="debugResponse">
                        <el-tag
                          size="small"
                          :type="debugResponse.status >= 200 && debugResponse.status < 300 ? 'success' : 'danger'"
                        >
                          Status: {{ debugResponse.status }}
                        </el-tag>
                        <el-tag size="small" type="info">耗时: {{ debugResponse.costMs }} ms</el-tag>
                      </template>
                    </div>
                    <el-button
                      v-if="debugResponse"
                      link
                      type="primary"
                      size="small"
                      @click="copyText(debugResponse.rawText)"
                    >
                      复制响应内容
                    </el-button>
                  </div>
                </template>

                <div v-if="!debugResponse" class="no-response text-muted">
                  点击上方「立即发送请求」后在此处查看响应报文
                </div>
                <div v-else class="response-terminal font-mono">
                  <pre>{{ debugResponse.formattedJson || debugResponse.rawText }}</pre>
                </div>
              </el-card>
            </div>
          </div>
        </div>
      </el-tab-pane>

      <!-- Tab 2: 🗄️ 数据库表字典与快照备份中心 -->
      <el-tab-pane name="tables">
        <template #label>
          <div class="tab-label">
            <el-icon><vab-icon icon="database-2-line" /></el-icon>
            <span>🗄️ 数据库表字典与备份中心</span>
          </div>
        </template>

        <div class="db-tables-layout">
          <!-- 统计与操作横幅 -->
          <el-card shadow="never" class="db-banner-card">
            <div class="db-banner-content">
              <div class="db-stats">
                <div class="stat-item">
                  <div class="stat-value font-mono">{{ dbDriver.toUpperCase() }}</div>
                  <div class="stat-label">当前数据库驱动引擎</div>
                </div>
                <el-divider direction="vertical" class="banner-divider" />
                <div class="stat-item">
                  <div class="stat-value font-mono">{{ tableList.length }}</div>
                  <div class="stat-label">系统数据表总数</div>
                </div>
                <el-divider direction="vertical" class="banner-divider" />
                <div class="stat-item">
                  <div class="stat-value font-mono">{{ totalRows }}</div>
                  <div class="stat-label">全库数据行总计</div>
                </div>
              </div>

              <div class="db-actions">
                <el-button type="warning" plain :loading="optimizing" @click="handleOptimizeAll">
                  <el-icon><vab-icon icon="magic-line" /></el-icon>
                  一键空间碎片整理 (VACUUM/OPTIMIZE)
                </el-button>
                <el-button type="success" :loading="backingUp" @click="handleCreateBackup">
                  <el-icon><vab-icon icon="download-cloud-2-line" /></el-icon>
                  即时全量快照备份
                </el-button>
                <el-button type="primary" plain @click="handleOpenBackupDrawer">
                  <el-icon><vab-icon icon="history-line" /></el-icon>
                  备份文件历史 ({{ backupFileList.length }})
                </el-button>
                <el-button circle @click="fetchTables">
                  <el-icon><vab-icon icon="refresh-line" /></el-icon>
                </el-button>
              </div>
            </div>
          </el-card>

          <!-- 数据表字典明细列表 -->
          <el-card shadow="never" class="table-list-card">
            <el-table
              v-loading="tableLoading"
              :data="tableList"
              row-key="name"
              stripe
              border
            >
              <el-table-column prop="name" label="数据表名" min-width="180">
                <template #default="{ row }">
                  <span class="font-mono font-medium">{{ row.name }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="comment" label="业务注释/说明" min-width="180" show-overflow-tooltip />

              <el-table-column prop="engine" label="存储引擎" width="130" align="center">
                <template #default="{ row }">
                  <el-tag size="small" type="info">{{ row.engine }}</el-tag>
                </template>
              </el-table-column>

              <el-table-column prop="rows" label="数据行数" width="110" align="right">
                <template #default="{ row }">
                  <span class="font-mono">{{ row.rows.toLocaleString() }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="data_size" label="数据大小" width="110" align="right" />
              <el-table-column prop="index_size" label="索引大小" width="110" align="right" />
              <el-table-column prop="total_size" label="总占用" width="110" align="right">
                <template #default="{ row }">
                  <strong class="font-mono text-primary">{{ row.total_size }}</strong>
                </template>
              </el-table-column>

              <el-table-column label="操作" width="130" fixed="right" align="center">
                <template #default="{ row }">
                  <div class="hw-table-actions">
                    <el-button
                      type="primary"
                      plain
                      size="small"
                      @click="handleOptimizeSingle(row.name)"
                    >
                      空间优化
                    </el-button>
                  </div>
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- 备份文件历史管理抽屉 -->
    <el-drawer
      v-model="backupDrawer.visible"
      title="🗄️ 数据库快照备份历史"
      size="min(580px, 94vw)"
      destroy-on-close
    >
      <div class="drawer-backup-summary">
        <span class="text-xs text-muted">存储于 <code>server/runtime/backups/db/</code> 目录下</span>
        <el-button size="small" type="success" :loading="backingUp" @click="handleCreateBackup">
          新建备份
        </el-button>
      </div>

      <div v-loading="backupDrawer.loading" class="backup-list-box">
        <el-empty v-if="backupFileList.length === 0" description="暂无备份历史文件" />
        <div
          v-for="file in backupFileList"
          :key="file.filename"
          class="backup-file-item"
        >
          <div class="file-info">
            <div class="file-name font-mono">{{ file.filename }}</div>
            <div class="file-meta text-muted text-xs">
              <span>大小：{{ file.size }}</span>
              <el-divider direction="vertical" />
              <span>生成时间：{{ file.created_at }}</span>
            </div>
          </div>

          <div class="file-actions">
            <el-button
              type="danger"
              size="small"
              plain
              @click="handleRestore(file.filename)"
            >
              一键还原
            </el-button>
            <el-button
              type="danger"
              circle
              size="small"
              @click="handleDeleteBackup(file.filename)"
            >
              <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
            </el-button>
          </div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '/@/utils/request'

defineOptions({
  name: 'SystemDevopsIndex',
})

const activeTab = ref('apiDocs')

// ==================== Tab 1: API 文档与在线调试 ====================
const apiLoading = ref(false)
const apiDocTree = ref<any[]>([])
const apiSearchKeyword = ref('')
const currentApi = ref<any>(null)

const queryParams = ref<{ key: string; value: string }[]>([])
const bodyJson = ref('')
const debugLoading = ref(false)
const debugResponse = ref<any>(null)

// 过滤 API 树
const filteredApiList = computed(() => {
  const kw = apiSearchKeyword.value.trim().toLowerCase()
  if (!kw) return apiDocTree.value

  const cloned: any[] = []
  for (const app of apiDocTree.value) {
    const matchedChildren: any[] = []
    for (const mod of app.children || []) {
      const matchedApis = (mod.apis || []).filter(
        (a: any) =>
          a.path.toLowerCase().includes(kw) ||
          a.title.toLowerCase().includes(kw) ||
          a.name.toLowerCase().includes(kw)
      )
      if (matchedApis.length > 0 || mod.title.toLowerCase().includes(kw)) {
        matchedChildren.push({
          ...mod,
          apis: matchedApis.length > 0 ? matchedApis : mod.apis,
        })
      }
    }
    if (matchedChildren.length > 0) {
      cloned.push({ ...app, children: matchedChildren })
    }
  }
  return cloned
})

const fetchApiDocs = async () => {
  apiLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.devops/apiDocs',
      method: 'get',
    })
    apiDocTree.value = res?.data || []
    if (apiDocTree.value.length > 0 && !currentApi.value) {
      // 默认选中第一个端点
      const firstApi = apiDocTree.value[0]?.children?.[0]?.apis?.[0]
      if (firstApi) {
        handleSelectApi(firstApi)
      }
    }
  } catch (error) {
    console.error('获取接口文档失败', error)
  } finally {
    apiLoading.value = false
  }
}

const handleSelectApi = (api: any) => {
  currentApi.value = api
  queryParams.value = []
  bodyJson.value = '{}'
  debugResponse.value = null
}

const handleAddQueryParam = () => {
  queryParams.value.push({ key: '', value: '' })
}

const handleRemoveQueryParam = (idx: number) => {
  queryParams.value.splice(idx, 1)
}

const handleSendRequest = async () => {
  if (!currentApi.value) return
  debugLoading.value = true
  const startTime = Date.now()

  try {
    const params: Record<string, any> = {}
    for (const p of queryParams.value) {
      if (p.key.trim() !== '') {
        params[p.key.trim()] = p.value
      }
    }

    let postData: any = undefined
    if (currentApi.value.method === 'POST') {
      try {
        postData = bodyJson.value.trim() ? JSON.parse(bodyJson.value) : {}
      } catch {
        ElMessage.error('POST Body 必须是合法的 JSON 格式！')
        debugLoading.value = false
        return
      }
    }

    const res: any = await request({
      url: currentApi.value.path,
      method: currentApi.value.method.toLowerCase(),
      params,
      data: postData,
    })

    const cost = Date.now() - startTime
    debugResponse.value = {
      status: 200,
      costMs: cost,
      rawText: JSON.stringify(res, null, 2),
      formattedJson: JSON.stringify(res, null, 2),
    }
    ElMessage.success('请求成功完成')
  } catch (error: any) {
    const cost = Date.now() - startTime
    const errData = error?.response?.data || error?.message || '请求发生异常'
    debugResponse.value = {
      status: error?.response?.status || 500,
      costMs: cost,
      rawText: typeof errData === 'object' ? JSON.stringify(errData, null, 2) : String(errData),
      formattedJson: typeof errData === 'object' ? JSON.stringify(errData, null, 2) : String(errData),
    }
    ElMessage.warning('接口返回异常状态')
  } finally {
    debugLoading.value = false
  }
}

const copyText = (text: string) => {
  if (!text) return
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('已成功复制到剪贴板')
  })
}

// ==================== Tab 2: 数据库表空间与备份 ====================
const tableLoading = ref(false)
const dbDriver = ref('mysql')
const tableList = ref<any[]>([])
const optimizing = ref(false)
const backingUp = ref(false)

const backupDrawer = reactive({
  visible: false,
  loading: false,
})
const backupFileList = ref<any[]>([])

const totalRows = computed(() => {
  return tableList.value.reduce((acc, cur) => acc + (cur.rows || 0), 0)
})

const fetchTables = async () => {
  tableLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.devops/tables',
      method: 'get',
    })
    dbDriver.value = res?.data?.driver || 'mysql'
    tableList.value = res?.data?.tables || []
  } catch (error) {
    console.error('获取数据库表失败', error)
  } finally {
    tableLoading.value = false
  }
}

const handleOptimizeAll = async () => {
  optimizing.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.devops/optimize',
      method: 'post',
    })
    ElMessage.success(res?.msg || '表空间整理优化完成')
    fetchTables()
  } catch (error: any) {
    ElMessage.error(error?.message || '优化失败')
  } finally {
    optimizing.value = false
  }
}

const handleOptimizeSingle = async (tableName: string) => {
  try {
    await request({
      url: '/adminapi/system.devops/optimize',
      method: 'post',
      data: { table: tableName },
    })
    ElMessage.success(`表 [${tableName}] 优化完成`)
    fetchTables()
  } catch (error: any) {
    ElMessage.error(error?.message || '优化失败')
  }
}

const handleCreateBackup = async () => {
  backingUp.value = true
  try {
    const res: any = await request({
      url: '/adminapi/system.devops/backup',
      method: 'post',
    })
    ElMessage.success(`备份成功生成：${res?.data?.filename} (${res?.data?.size})`)
    fetchBackupList()
  } catch (error: any) {
    ElMessage.error(error?.message || '生成备份失败')
  } finally {
    backingUp.value = false
  }
}

const handleOpenBackupDrawer = () => {
  backupDrawer.visible = true
  fetchBackupList()
}

const fetchBackupList = async () => {
  backupDrawer.loading = true
  try {
    const res: any = await request({
      url: '/adminapi/system.devops/backupList',
      method: 'get',
    })
    backupFileList.value = res?.data || []
  } catch (error) {
    console.error('获取备份列表失败', error)
  } finally {
    backupDrawer.loading = false
  }
}

const handleDeleteBackup = (filename: string) => {
  ElMessageBox.confirm(`确定要彻底删除备份文件「${filename}」吗？`, '提示', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: '/adminapi/system.devops/delBackup',
        method: 'post',
        data: { filename },
      })
      ElMessage.success('备份已删除')
      fetchBackupList()
    } catch (error: any) {
      ElMessage.error(error?.message || '删除失败')
    }
  })
}

const handleRestore = (filename: string) => {
  ElMessageBox.confirm(
    `警告：数据还原将用备份快照「${filename}」覆盖当前数据库对应的全部数据表！此操作不可逆，请务必确认已做好当前数据备份！`,
    '高风险操作警告',
    {
      confirmButtonText: '我已知晓风险，开始还原',
      cancelButtonText: '取消',
      type: 'error',
    }
  ).then(async () => {
    const loadingInstance = ElMessage.info({
      message: '正在还原数据，请勿关闭页面...',
      duration: 0,
    })
    try {
      const res: any = await request({
        url: '/adminapi/system.devops/restore',
        method: 'post',
        data: { filename },
      })
      loadingInstance.close()
      ElMessage.success(res?.msg || '数据还原成功！')
      fetchTables()
    } catch (error: any) {
      loadingInstance.close()
      ElMessage.error(error?.message || '还原失败')
    }
  })
}

onMounted(() => {
  fetchApiDocs()
  fetchTables()
  fetchBackupList()
})
</script>

<style scoped lang="scss">
.devops-container {
  padding: 16px;

  .devops-tabs {
    min-height: calc(100vh - 120px);

    :deep(.el-tabs__content) {
      padding: 16px;
    }
  }

  .tab-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
  }

  // ================= Tab 1 样式 =================
  .api-docs-layout {
    display: flex;
    gap: 16px;
    min-height: calc(100vh - 200px);
    height: calc(100vh - 200px);
  }

  .api-sidebar {
    width: clamp(260px, 26vw, 330px);
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--el-border-color-lighter);
    padding-right: 12px;

    .sidebar-search {
      margin-bottom: 12px;
    }

    .api-tree-wrapper {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding-right: 4px;
    }

    .group-title {
      display: flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      font-size: 13px;
      color: var(--el-text-color-primary);
      margin-bottom: 6px;
    }

    .mod-group {
      padding-left: 8px;
      margin-bottom: 8px;

      .mod-title {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: var(--el-text-color-secondary);
        margin-bottom: 4px;
      }
    }

    .api-endpoint-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 8px;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 2px;

      &:hover {
        background-color: var(--el-fill-color-light);
      }

      &.active {
        background-color: var(--el-color-primary-light-9);
      }

      .method-tag {
        font-size: 11px;
        font-weight: bold;
        width: 48px;
        flex-shrink: 0;
        text-align: center;
      }

      .endpoint-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;

        .endpoint-title {
          font-size: 13px;
          color: var(--el-text-color-primary);
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .endpoint-path {
          font-size: 11px;
          color: var(--el-text-color-secondary);
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }
  }

  .api-workbench {
    flex: 1;
    min-width: 0;
    overflow-y: auto;
    padding-left: 4px;

    .workbench-empty {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 320px;
      height: 100%;
    }

    .workbench-content {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .endpoint-meta-card {
      .meta-header {
        display: flex;
        flex-direction: column;
        gap: 8px;

        .meta-title-line {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px 10px;

          .meta-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--el-text-color-primary);
            word-break: break-word;
          }
        }

        .meta-url-line {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px;

          .meta-path {
            font-size: 14px;
            background-color: var(--el-fill-color-light);
            padding: 4px 8px;
            border-radius: 4px;
            color: var(--el-text-color-primary);
            word-break: break-all;
          }
        }
      }

      .meta-desc {
        margin-top: 10px;
        font-size: 13px;
        color: var(--el-text-color-secondary);
        line-height: 1.5;
        white-space: pre-wrap;
      }
    }

    .request-runner-card {
      .runner-card-header {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;

        .header-left {
          display: flex;
          align-items: center;
          gap: 6px;
        }
      }

      .section-title {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--el-text-color-regular);
      }

      .request-params-section {
        margin-bottom: 16px;

        .param-row {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px;
          margin-bottom: 8px;

          .param-key-input {
            flex: 0 0 180px;
          }

          .param-val-input {
            flex: 1;
            min-width: 160px;
          }
        }
      }

      .request-body-section {
        margin-top: 12px;
      }
    }

    .response-card {
      .response-card-header {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;

        .response-meta {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px 10px;
        }
      }

      .no-response {
        padding: 30px;
        text-align: center;
        font-size: 13px;
      }

      .response-terminal {
        background-color: #1e1e1e;
        color: #d4d4d4;
        border-radius: 6px;
        padding: 12px;
        max-height: 360px;
        overflow-y: auto;

        pre {
          margin: 0;
          white-space: pre-wrap;
          word-break: break-all;
          font-size: 13px;
          line-height: 1.5;
        }
      }
    }
  }

  // ================= Tab 2 样式 =================
  .db-tables-layout {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .db-banner-card {
    .db-banner-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;

      .db-stats {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 14px 20px;

        .stat-item {
          display: flex;
          flex-direction: column;
          gap: 4px;

          .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--el-color-primary);
          }

          .stat-label {
            font-size: 12px;
            color: var(--el-text-color-secondary);
          }
        }

        .banner-divider {
          height: 36px;
        }
      }

      .db-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
      }
    }
  }

  .drawer-backup-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .backup-list-box {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: calc(100vh - 180px);
    overflow-y: auto;
  }

  .backup-file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--el-border-color-light);
    background-color: var(--el-fill-color-blank);

    .file-info {
      min-width: 0;
      flex: 1;
    }

    .file-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--el-text-color-primary);
      margin-bottom: 4px;
      word-break: break-all;
    }

    .file-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
  }

  @media (max-width: 1024px) {
    .api-docs-layout {
      flex-direction: column;
      height: auto;
      min-height: 0;
    }

    .api-sidebar {
      width: 100%;
      border-right: none;
      border-bottom: 1px solid var(--el-border-color-lighter);
      padding-right: 0;
      padding-bottom: 12px;
      max-height: 340px;
    }

    .api-workbench {
      padding-left: 0;
      overflow-y: visible;
    }
  }

  @media (max-width: 768px) {
    padding: 12px;

    .devops-tabs :deep(.el-tabs__content) {
      padding: 12px;
    }

    .api-workbench .request-runner-card .request-params-section .param-row {
      .param-key-input,
      .param-val-input {
        flex: 1 1 100%;
        min-width: 0;
      }
    }

    .db-banner-card .db-banner-content .db-stats .banner-divider {
      display: none;
    }
  }

  .font-mono {
    font-family: monospace;
  }

  .font-medium {
    font-weight: 500;
  }

  .text-primary {
    color: var(--el-color-primary);
  }

  .text-muted {
    color: var(--el-text-color-secondary);
  }

  .text-xs {
    font-size: 12px;
  }
}
</style>
