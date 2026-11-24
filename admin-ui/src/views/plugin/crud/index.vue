<template>
  <div class="crud-generator-container">
    <!-- 主工作区：左侧表列表 + 右侧配置面板 -->
    <div class="crud-layout">
      <!-- 左侧：数据库表列表面板 -->
      <div class="table-sidebar">
        <div class="sidebar-header">
          <div class="header-title">
            <span class="title-text">数据库表</span>
            <el-tag size="small" type="info">{{ filteredTables.length }} 张</el-tag>
          </div>
          <el-button type="primary" size="small" class="create-table-btn" @click="openCreateTableDialog">
            <el-icon><vab-icon icon="add-line" /></el-icon>
            可视化建表
          </el-button>
        </div>

        <div class="search-box">
          <el-input
            v-model="searchTable"
            placeholder="搜索表名或注释..."
            clearable
            prefix-icon="Search"
            size="default"
          />
        </div>

        <div v-loading="tablesLoading" class="table-list-scroll">
          <div
            v-for="item in filteredTables"
            :key="item.name"
            class="table-item"
            :class="{ active: currentTable?.name === item.name }"
            @click="handleSelectTable(item)"
          >
            <div class="item-top">
              <span class="table-name" :title="item.name">{{ item.clean_name || item.name }}</span>
              <el-tag v-if="item.is_system" size="small" type="danger" effect="plain">系统核心</el-tag>
              <el-tag v-else-if="item.is_plugin" size="small" type="warning" effect="plain">插件表</el-tag>
              <el-tag v-else size="small" type="success" effect="plain">业务表</el-tag>
            </div>
            <div class="item-desc">
              <span class="table-comment">{{ item.comment || '无注释' }}</span>
              <span class="table-meta">{{ item.columns_count }} 列 · {{ item.rows }} 行</span>
            </div>
          </div>
          <el-empty v-if="filteredTables.length === 0" description="未找到符合条件的数据表" />
        </div>
      </div>

      <!-- 右侧：CRUD 配置与生成工作区 -->
      <div class="main-workspace">
        <template v-if="currentTable">
          <!-- 顶部生成参数配置卡片 -->
          <el-card shadow="never" class="config-card">
            <template #header>
              <div class="card-header-bar">
                <div class="active-table-info">
                  <span class="active-badge">当前数据表:</span>
                  <span class="active-name">{{ currentTable.name }}</span>
                  <span class="active-comment">({{ currentTable.comment || '无注释' }})</span>
                  <el-tag size="small" type="info">{{ currentTable.engine }}</el-tag>
                </div>
                <div class="action-btn-group">
                  <el-button @click="openHistoryDrawer">
                    <el-icon><vab-icon icon="history-line" /></el-icon>
                    生成历史
                  </el-button>
                  <el-button type="info" plain :loading="previewLoading" @click="handlePreview">
                    <el-icon><vab-icon icon="code-box-line" /></el-icon>
                    实时预览代码
                  </el-button>
                  <el-button type="primary" :loading="generateLoading" @click="handleGenerate">
                    <el-icon><vab-icon icon="flashlight-line" /></el-icon>
                    一键全栈生成
                  </el-button>
                </div>
              </div>
            </template>

            <!-- 基础生成参数表单 -->
            <el-form :model="genForm" label-position="top" class="gen-form-grid">
              <div class="gen-form-fields">
                <el-form-item label="生成目标模块">
                  <el-select v-model="genForm.module" style="width: 100%">
                    <el-option label="管理后台应用 (app/adminapi)" value="adminapi" />
                    <el-option label="CMS内容插件 (plugins/cms)" value="cms" />
                  </el-select>
                </el-form-item>
                <el-form-item label="控制器类名">
                  <el-input v-model="genForm.controller" placeholder="如 Goods 或 shop/Goods" />
                </el-form-item>
                <el-form-item label="模型类名">
                  <el-input v-model="genForm.model" placeholder="如 Goods" />
                </el-form-item>
                <el-form-item label="菜单标题">
                  <el-input v-model="genForm.menu_title" placeholder="如 商品管理" />
                </el-form-item>
                <el-form-item label="上级菜单ID">
                  <el-input-number v-model="genForm.parent_menu_id" :min="0" style="width: 100%" />
                </el-form-item>
                <el-form-item label="自动注册菜单">
                  <div class="switch-control-row">
                    <el-switch v-model="genForm.auto_menu" inline-prompt active-text="开" inactive-text="关" />
                    <span class="switch-hint">{{ genForm.auto_menu ? '自动挂载菜单' : '不自动注册' }}</span>
                  </div>
                </el-form-item>
                <el-form-item label="数据归属隔离">
                  <div class="switch-control-row">
                    <el-switch v-model="genForm.data_scope" inline-prompt active-text="开" inactive-text="关" />
                    <span class="switch-hint">{{ genForm.data_scope ? '按 admin_id 隔离' : '全局数据可见' }}</span>
                  </div>
                </el-form-item>
                <el-form-item label="强制覆盖已有">
                  <div class="switch-control-row">
                    <el-switch v-model="genForm.force" inline-prompt active-text="覆" inactive-text="否" />
                    <span class="switch-hint">{{ genForm.force ? '覆盖已有代码' : '存在时阻止写入' }}</span>
                  </div>
                </el-form-item>
              </div>
            </el-form>
          </el-card>

          <!-- 字段可视化配置大表 -->
          <el-card shadow="never" class="columns-card">
            <template #header>
              <div class="columns-header-bar">
                <div class="col-title">
                  <el-icon><vab-icon icon="list-settings-line" /></el-icon>
                  <span>字段可视化配置映射</span>
                  <span class="sub-text">（智能推导已就绪，可实时调整列表展示、表单控件与搜索规则）</span>
                </div>
                <div class="col-actions">
                  <el-button size="small" @click="loadColumns(currentTable.name)">
                    <el-icon><vab-icon icon="refresh-line" /></el-icon>
                    重置推导配置
                  </el-button>
                </div>
              </div>
            </template>

            <el-table
              v-loading="columnsLoading"
              :data="columns"
              border
              stripe
              style="width: 100%"
              row-key="name"
            >
              <el-table-column prop="name" label="字段名称" min-width="180">
                <template #default="{ row }">
                  <div class="field-name-cell">
                    <div class="field-name-top">
                      <span class="raw-name">{{ row.name }}</span>
                      <el-tag v-if="row.is_pk" size="small" type="danger" effect="dark">PK</el-tag>
                    </div>
                    <span class="raw-type">{{ row.type }}</span>
                  </div>
                </template>
              </el-table-column>

              <el-table-column label="中文标题" min-width="140">
                <template #default="{ row }">
                  <el-input v-model="row.title" placeholder="字段中文名称" size="small" />
                </template>
              </el-table-column>

              <el-table-column label="列表展示" width="85" align="center">
                <template #default="{ row }">
                  <el-switch v-model="row.show_in_list" size="small" />
                </template>
              </el-table-column>

              <el-table-column label="表单录入" width="85" align="center">
                <template #default="{ row }">
                  <el-switch v-model="row.show_in_form" size="small" :disabled="row.is_pk" />
                </template>
              </el-table-column>

              <el-table-column label="必填校验" width="85" align="center">
                <template #default="{ row }">
                  <el-switch v-model="row.required" size="small" :disabled="!row.show_in_form" />
                </template>
              </el-table-column>

              <el-table-column label="搜索匹配方式" min-width="130">
                <template #default="{ row }">
                  <el-select v-model="row.query_type" size="small" style="width: 100%">
                    <el-option label="不参与搜索" value="NONE" />
                    <el-option label="模糊匹配 (LIKE)" value="LIKE" />
                    <el-option label="精确匹配 (=)" value="=" />
                    <el-option label="区间范围 (BETWEEN)" value="BETWEEN" />
                  </el-select>
                </template>
              </el-table-column>

              <el-table-column label="表单控件类型" min-width="150">
                <template #default="{ row }">
                  <el-select
                    v-model="row.control_type"
                    size="small"
                    style="width: 100%"
                    :disabled="!row.show_in_form"
                  >
                    <el-option label="单行文本 (Input)" value="input" />
                    <el-option label="数值计数 (Number)" value="number" />
                    <el-option label="下拉选择 (Select)" value="select" />
                    <el-option label="状态开关 (Switch)" value="switch" />
                    <el-option label="多行文本 (Textarea)" value="textarea" />
                    <el-option label="图片上传 (Image)" value="image" />
                    <el-option label="富文本编辑 (Editor)" value="editor" />
                    <el-option label="日期时间 (DateTime)" value="datetime" />
                    <el-option label="单日期 (Date)" value="date" />
                    <el-option label="密码凭证 (Password)" value="password" />
                  </el-select>
                </template>
              </el-table-column>

              <el-table-column label="关联字典" min-width="120">
                <template #default="{ row }">
                  <el-input
                    v-model="row.dict_code"
                    placeholder="如 system_status"
                    size="small"
                    :disabled="!row.show_in_form"
                  />
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </template>

        <!-- 未选择表时的占位状态 -->
        <div v-else class="empty-placeholder">
          <el-empty description="请从左侧选择一张数据表开始配置，或点击「可视化建表」快速创建">
            <el-button type="primary" @click="openCreateTableDialog">
              <el-icon><vab-icon icon="add-line" /></el-icon>
              立即可视化建表
            </el-button>
          </el-empty>
        </div>
      </div>
    </div>

    <!-- 弹窗 1：零 SQL 可视化在线建表 -->
    <el-dialog
      v-model="createTableDialogVisible"
      title="在线可视化建表（支持零代码添加字段与常用预设模板）"
      width="min(820px, 94vw)"
      destroy-on-close
    >
      <div class="create-table-container">
        <!-- 顶部快速模板横幅 -->
        <div class="template-banner">
          <div class="banner-text">
            <strong>快速体验：</strong> 包含商品标题、分类、图片、价格、库存、状态与图文详情的商城商品表
          </div>
          <el-button type="warning" size="small" @click="loadGoodsTemplate">
            <el-icon><vab-icon icon="magic-line" /></el-icon>
            载入示例商品表模板 (hw_shop_goods)
          </el-button>
        </div>

        <el-form label-width="90px" class="create-form">
          <el-row :gutter="16">
            <el-col :xs="24" :sm="12">
              <el-form-item label="数据表名" required>
                <el-input v-model="newTableForm.table_name" placeholder="如 hw_product 或 product" />
              </el-form-item>
            </el-col>
            <el-col :xs="24" :sm="12">
              <el-form-item label="表中文注释">
                <el-input v-model="newTableForm.table_comment" placeholder="如 商品信息表" />
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>

        <div class="fields-section-header">
          <span>表字段结构列表（主键 id 与审计时间戳 create_time/update_time 将自动建立）</span>
          <el-button type="primary" link size="small" @click="addNewFieldRow">
            <el-icon><vab-icon icon="add-line" /></el-icon>
            添加字段
          </el-button>
        </div>

        <el-table :data="newTableForm.columns" border size="small" max-height="360px">
          <el-table-column label="字段名称" min-width="140">
            <template #default="{ row }">
              <el-input v-model="row.name" placeholder="英文字母/下划线" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="数据类型" width="130">
            <template #default="{ row }">
              <el-select v-model="row.type" size="small">
                <el-option label="varchar (字符)" value="varchar" />
                <el-option label="int (整数)" value="int" />
                <el-option label="tinyint (微整)" value="tinyint" />
                <el-option label="decimal (金额浮点)" value="decimal" />
                <el-option label="text (文本/富文本)" value="text" />
                <el-option label="datetime (日期时间)" value="datetime" />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="长度/精度" width="110">
            <template #default="{ row }">
              <el-input v-model="row.length" placeholder="如 255 / 10,2" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="非空" width="65" align="center">
            <template #default="{ row }">
              <el-checkbox v-model="row.notnull" />
            </template>
          </el-table-column>
          <el-table-column label="默认值" width="110">
            <template #default="{ row }">
              <el-input v-model="row.default" placeholder="默认空" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="中文注释" min-width="140">
            <template #default="{ row }">
              <el-input v-model="row.comment" placeholder="字段说明" size="small" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="68" align="center">
            <template #default="{ $index }">
              <div class="hw-table-actions">
                <el-button type="danger" plain size="small" @click="removeFieldRow($index)">
                  <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="createTableDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="createTableLoading" @click="handleCreateTableSubmit">
            立即创建数据表并进入配置
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 弹窗 2：全栈 4 件套代码实时预览 -->
    <el-drawer
      v-model="previewDrawerVisible"
      title="全栈 CRUD 代码实时生成预览"
      size="min(1040px, 94vw)"
      destroy-on-close
    >
      <div v-if="previewData" class="preview-drawer-content">
        <el-tabs v-model="activePreviewTab" type="border-card">
          <el-tab-pane label="Controller 控制器" name="controller">
            <div class="code-meta-bar">
              <span class="file-path">{{ previewData.paths?.controller_file }}</span>
              <el-button size="small" @click="copyCode(previewData.controller_code)">复制代码</el-button>
            </div>
            <pre class="code-viewer"><code>{{ previewData.controller_code }}</code></pre>
          </el-tab-pane>

          <el-tab-pane label="Model 模型" name="model">
            <div class="code-meta-bar">
              <span class="file-path">{{ previewData.paths?.model_file }}</span>
              <el-button size="small" @click="copyCode(previewData.model_code)">复制代码</el-button>
            </div>
            <pre class="code-viewer"><code>{{ previewData.model_code }}</code></pre>
          </el-tab-pane>

          <el-tab-pane label="Validate 验证器" name="validate">
            <div class="code-meta-bar">
              <span class="file-path">{{ previewData.paths?.validate_file }}</span>
              <el-button size="small" @click="copyCode(previewData.validate_code)">复制代码</el-button>
            </div>
            <pre class="code-viewer"><code>{{ previewData.validate_code }}</code></pre>
          </el-tab-pane>

          <el-tab-pane label="Vue 3 前端页面" name="vue">
            <div class="code-meta-bar">
              <span class="file-path">{{ previewData.paths?.vue_file }}</span>
              <el-button size="small" @click="copyCode(previewData.vue_code)">复制代码</el-button>
            </div>
            <pre class="code-viewer"><code>{{ previewData.vue_code }}</code></pre>
          </el-tab-pane>
        </el-tabs>
      </div>
    </el-drawer>

    <!-- 抽屉 3：生成历史记录与一键撤回 -->
    <el-drawer
      v-model="historyDrawerVisible"
      title="CRUD 生成历史与一键撤回"
      size="min(960px, 94vw)"
      destroy-on-close
    >
      <div v-loading="historyLoading" class="history-drawer-content">
        <el-table :data="historyList" border stripe>
          <el-table-column prop="time" label="生成时间" width="165" align="center" />
          <el-table-column prop="menu_title" label="菜单名称" width="130" />
          <el-table-column prop="table" label="数据表" min-width="140" />
          <el-table-column prop="controller" label="控制器" min-width="130" />
          <el-table-column prop="status" label="状态" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="row.status === 'active' ? 'success' : 'info'">
                {{ row.status === 'active' ? '已生成' : '已撤回' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="128" align="center" fixed="right">
            <template #default="{ row }">
              <div v-if="row.status === 'active'" class="hw-table-actions">
                <el-button
                  type="danger"
                  plain
                  size="small"
                  @click="handleRollback(row)"
                >
                  一键撤回
                </el-button>
              </div>
              <span v-else class="text-muted">-</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '/@/utils/request'

defineOptions({
  name: 'CrudGenerator',
})

// 基础状态
const tablesLoading = ref(false)
const columnsLoading = ref(false)
const previewLoading = ref(false)
const generateLoading = ref(false)
const createTableLoading = ref(false)
const historyLoading = ref(false)

const searchTable = ref('')
const tableList = ref<any[]>([])
const currentTable = ref<any | null>(null)
const columns = ref<any[]>([])

// 弹窗与抽屉控制
const createTableDialogVisible = ref(false)
const previewDrawerVisible = ref(false)
const historyDrawerVisible = ref(false)
const activePreviewTab = ref('controller')
const previewData = ref<any | null>(null)
const historyList = ref<any[]>([])

// 生成参数表单
const genForm = reactive({
  module: 'adminapi',
  controller: '',
  model: '',
  menu_title: '',
  parent_menu_id: 0,
  auto_menu: true,
  data_scope: false,
  force: true,
})

// 在线建表表单
const newTableForm = reactive({
  table_name: '',
  table_comment: '',
  columns: [
    { name: 'name', type: 'varchar', length: '100', notnull: true, default: '', comment: '名称' },
    { name: 'status', type: 'tinyint', length: '1', notnull: true, default: '1', comment: '状态:1=正常,0=禁用' },
  ] as any[],
})

// 计算属性：过滤表列表
const filteredTables = computed(() => {
  const kw = searchTable.value.trim().toLowerCase()
  if (!kw) return tableList.value
  return tableList.value.filter(
    (t) =>
      t.name.toLowerCase().includes(kw) ||
      (t.clean_name && t.clean_name.toLowerCase().includes(kw)) ||
      (t.comment && t.comment.toLowerCase().includes(kw))
  )
})

// 1. 获取数据表列表
const fetchTables = async (autoSelectName = '') => {
  tablesLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/plugin/crud/generator/tables',
      method: 'get',
    })
    tableList.value = res.data || []
    if (autoSelectName) {
      const match = tableList.value.find((t) => t.name === autoSelectName || t.clean_name === autoSelectName)
      if (match) {
        handleSelectTable(match)
        return
      }
    }
    // 默认选中第一个业务表或非系统表
    if (!currentTable.value && tableList.value.length > 0) {
      const firstBiz = tableList.value.find((t) => !t.is_system) || tableList.value[0]
      handleSelectTable(firstBiz)
    }
  } catch (error) {
  } finally {
    tablesLoading.value = false
  }
}

// 2. 选择表并加载字段配置
const handleSelectTable = (table: any) => {
  currentTable.value = table
  const clean = table.clean_name || table.name
  const studly = clean
    .split('_')
    .map((w: string) => w.charAt(0).toUpperCase() + w.slice(1))
    .join('')

  genForm.controller = studly
  genForm.model = studly
  genForm.menu_title = table.comment || studly

  loadColumns(table.name)
}

// 3. 读取表字段及智能推导结果
const loadColumns = async (tableName: string) => {
  columnsLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/plugin/crud/generator/columns',
      method: 'get',
      params: { table: tableName },
    })
    columns.value = res.data || []
  } catch (error) {
  } finally {
    columnsLoading.value = false
  }
}

// 4. 打开新建数据表弹窗
const openCreateTableDialog = () => {
  newTableForm.table_name = ''
  newTableForm.table_comment = ''
  newTableForm.columns = [
    { name: 'name', type: 'varchar', length: '100', notnull: true, default: '', comment: '名称' },
    { name: 'status', type: 'tinyint', length: '1', notnull: true, default: '1', comment: '状态:1=正常,0=禁用' },
  ]
  createTableDialogVisible.value = true
}

// 载入示例商品表模板
const loadGoodsTemplate = () => {
  newTableForm.table_name = 'hw_shop_goods'
  newTableForm.table_comment = '商城商品信息表'
  newTableForm.columns = [
    { name: 'title', type: 'varchar', length: '200', notnull: true, default: '', comment: '商品标题' },
    { name: 'category_id', type: 'int', length: '11', notnull: true, default: '0', comment: '分类ID' },
    { name: 'cover', type: 'varchar', length: '255', notnull: false, default: '', comment: '商品主图' },
    { name: 'price', type: 'decimal', length: '10,2', notnull: true, default: '0.00', comment: '销售单价' },
    { name: 'stock', type: 'int', length: '11', notnull: true, default: '100', comment: '当前库存' },
    { name: 'status', type: 'tinyint', length: '1', notnull: true, default: '1', comment: '商品状态:1=上架,0=下架' },
    { name: 'content', type: 'text', length: '', notnull: false, default: '', comment: '商品图文详情' },
  ]
  ElMessage.success('已载入示例商品表模板！可直接点击下方按钮一键建表')
}

const addNewFieldRow = () => {
  newTableForm.columns.push({
    name: '',
    type: 'varchar',
    length: '255',
    notnull: false,
    default: '',
    comment: '',
  })
}

const removeFieldRow = (index: number) => {
  newTableForm.columns.splice(index, 1)
}

// 提交建表
const handleCreateTableSubmit = async () => {
  if (!newTableForm.table_name.trim()) {
    ElMessage.warning('请输入数据表名')
    return
  }
  createTableLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/plugin/crud/generator/createTable',
      method: 'post',
      data: newTableForm,
    })
    ElMessage.success(res.msg || '数据表创建成功！')
    createTableDialogVisible.value = false
    // 重新获取表列表并自动选中新建的表
    await fetchTables(res.data?.table_name || newTableForm.table_name)
  } catch (error) {
  } finally {
    createTableLoading.value = false
  }
}

// 5. 实时全栈代码预览
const handlePreview = async () => {
  if (!currentTable.value) return
  previewLoading.value = true
  try {
    const payload = {
      table: currentTable.value.name,
      columns: columns.value,
      ...genForm,
    }
    const res: any = await request({
      url: '/adminapi/plugin/crud/generator/preview',
      method: 'post',
      data: payload,
    })
    previewData.value = res.data
    previewDrawerVisible.value = true
  } catch (error) {
  } finally {
    previewLoading.value = false
  }
}

// 6. 一键全栈生成并注册菜单
const handleGenerate = async () => {
  if (!currentTable.value) return

  ElMessageBox.confirm(
    `即将为数据表 [${currentTable.value.name}] 生成 Controller、Model、Validate 及 Vue 前端管理页面，并向系统注入权限菜单。确认生成吗？`,
    '全栈生成确认',
    {
      confirmButtonText: '立即生成',
      cancelButtonText: '取消',
      type: 'primary',
    }
  ).then(async () => {
    generateLoading.value = true
    try {
      const payload = {
        table: currentTable.value.name,
        columns: columns.value,
        ...genForm,
      }
      const res: any = await request({
        url: '/adminapi/plugin/crud/generator/generate',
        method: 'post',
        data: payload,
      })
      ElMessageBox.alert(
        `🎉 ${res.msg || '代码已落盘成功！'}\n\n已生成文件：\n- 控制器: ${res.data?.files?.Controller}\n- 模型: ${res.data?.files?.Model}\n- 验证器: ${res.data?.files?.Validate}\n- 页面: ${res.data?.files?.Vue}\n\n权限节点：${res.data?.node_prefix}`,
        '全栈 CRUD 生成完毕',
        {
          confirmButtonText: '我知道了',
          type: 'success',
        }
      )
    } catch (e) {
    } finally {
      generateLoading.value = false
    }
  })
}

// 7. 生成历史与撤回
const openHistoryDrawer = async () => {
  historyDrawerVisible.value = true
  historyLoading.value = true
  try {
    const res: any = await request({
      url: '/adminapi/plugin/crud/generator/history',
      method: 'get',
    })
    historyList.value = res.data || []
  } catch (e) {
  } finally {
    historyLoading.value = false
  }
}

const handleRollback = (row: any) => {
  ElMessageBox.confirm(
    `确定要撤回本次生成 (ID: ${row.id}) 吗？将自动安全清理生成的 Controller、Model、Validate、Vue 文件以及注册的权限菜单节点！`,
    '安全撤回警告',
    {
      confirmButtonText: '确定撤回',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      const res: any = await request({
        url: '/adminapi/plugin/crud/generator/rollback',
        method: 'post',
        data: { history_id: row.id },
      })
      ElMessage.success(res.msg || '撤回成功！')
      openHistoryDrawer()
    } catch (e) {}
  })
}

const copyCode = (text: string) => {
  if (!text) return
  navigator.clipboard.writeText(text).then(
    () => {
      ElMessage.success('代码已成功复制到剪贴板！')
    },
    () => {
      ElMessage.warning('复制失败，请手动选中文本复制')
    }
  )
}

onMounted(() => {
  fetchTables()
})
</script>

<style scoped lang="scss">
.crud-generator-container {
  padding: 16px;
  min-height: calc(100vh - 110px);
  box-sizing: border-box;

  .crud-layout {
    display: flex;
    gap: 16px;
    align-items: flex-start;

    // 左侧表列表
    .table-sidebar {
      width: clamp(250px, 23vw, 310px);
      flex-shrink: 0;
      background: var(--el-bg-color);
      border: 1px solid var(--el-border-color-light);
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      padding: 14px;
      box-sizing: border-box;
      max-height: calc(100vh - 142px);

      .sidebar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;

        .header-title {
          display: flex;
          align-items: center;
          gap: 8px;
          font-weight: 600;
          font-size: 15px;
        }
      }

      .search-box {
        margin-bottom: 12px;
      }

      .table-list-scroll {
        flex: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding-right: 4px;

        .table-item {
          padding: 10px 12px;
          border-radius: 6px;
          border: 1px solid var(--el-border-color-lighter);
          background: var(--el-fill-color-blank);
          cursor: pointer;
          transition: all 0.2s;

          &:hover {
            border-color: var(--el-color-primary-light-5);
            background: var(--el-fill-color-light);
          }

          &.active {
            border-color: var(--el-color-primary);
            background: var(--el-color-primary-light-9);
          }

          .item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;

            .table-name {
              font-weight: 600;
              font-size: 13px;
              color: var(--el-text-color-primary);
              min-width: 0;
              flex: 1;
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }
          }

          .item-desc {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--el-text-color-secondary);

            .table-comment {
              min-width: 0;
              flex: 1;
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }

            .table-meta {
              font-size: 11px;
              flex-shrink: 0;
            }
          }
        }
      }
    }

    // 右侧工作区
    .main-workspace {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 16px;

      .config-card {
        .card-header-bar {
          width: 100%;
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 10px 16px;

          .active-table-info {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 8px;
            font-size: 14px;
            min-width: 0;

            .active-badge {
              font-weight: 600;
              color: var(--el-text-color-regular);
            }

            .active-name {
              font-weight: 700;
              color: var(--el-color-primary);
              font-family: monospace;
              font-size: 15px;
              word-break: break-all;
            }

            .active-comment {
              color: var(--el-text-color-secondary);
            }
          }

          .action-btn-group {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 10px;
          }
        }

        .gen-form-grid {
          margin-top: 4px;

          .gen-form-fields {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px 18px;
          }

          :deep(.el-form-item) {
            margin-bottom: 0;
          }

          :deep(.el-form-item__label) {
            padding-bottom: 6px;
            line-height: 1.3;
            font-weight: 500;
          }

          .switch-control-row {
            width: 100%;
            min-height: 32px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 0 10px;
            border-radius: 6px;
            background: var(--el-fill-color-light);
            border: 1px solid var(--el-border-color-lighter);
            box-sizing: border-box;

            .switch-hint {
              font-size: 12px;
              color: var(--el-text-color-secondary);
              white-space: nowrap;
            }
          }
        }
      }

      .columns-card {
        display: flex;
        flex-direction: column;

        .columns-header-bar {
          width: 100%;
          display: flex;
          justify-content: space-between;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px 12px;

          .col-title {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 6px;
            font-weight: 600;
            font-size: 14px;
            min-width: 0;

            .sub-text {
              font-size: 12px;
              font-weight: normal;
              color: var(--el-text-color-secondary);
            }
          }

          .col-actions {
            flex-shrink: 0;
          }
        }

        .field-name-cell {
          display: flex;
          flex-direction: column;
          align-items: flex-start;
          gap: 3px;

          .field-name-top {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
          }

          .raw-name {
            font-weight: 600;
            font-family: monospace;
            word-break: break-all;
          }

          .raw-type {
            font-size: 11px;
            line-height: 1.2;
            color: var(--el-text-color-secondary);
            font-family: monospace;
          }
        }
      }

      .empty-placeholder {
        min-height: 360px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--el-bg-color);
        border: 1px dashed var(--el-border-color);
        border-radius: 8px;
        padding: 24px;
      }
    }
  }

  // 弹窗与抽屉内部样式
  .create-table-container {
    .template-banner {
      background: var(--el-color-warning-light-9);
      border: 1px solid var(--el-color-warning-light-5);
      border-radius: 6px;
      padding: 10px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 16px;

      .banner-text {
        font-size: 13px;
        color: var(--el-color-warning-dark-2);
      }
    }

    .fields-section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 8px;
      font-size: 13px;
      font-weight: 600;
      color: var(--el-text-color-regular);
    }
  }

  .preview-drawer-content {
    .code-meta-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      padding: 6px 12px;
      background: var(--el-fill-color-light);
      border-radius: 4px;
      margin-bottom: 10px;

      .file-path {
        font-family: monospace;
        font-size: 12px;
        color: var(--el-color-primary);
        word-break: break-all;
      }
    }

    .code-viewer {
      background: #1e1e1e;
      color: #d4d4d4;
      padding: 14px;
      border-radius: 6px;
      max-height: calc(100vh - 220px);
      overflow: auto;
      font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
      font-size: 12.5px;
      line-height: 1.5;
    }
  }

  @media (max-width: 1180px) {
    .crud-layout {
      flex-direction: column;

      .table-sidebar {
        width: 100%;
        max-height: 300px;
      }

      .main-workspace {
        width: 100%;
      }
    }
  }

  @media (max-width: 768px) {
    padding: 12px;

    .crud-layout .main-workspace .config-card .gen-form-grid .gen-form-fields {
      grid-template-columns: 1fr;
    }
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }
}
</style>
