<template>
  <div class="quiz-prize-container">
    <el-tabs v-model="activeTab" type="border-card" class="prize-tabs">
      <!-- ==================== Tab 1: 奖品库配置 ==================== -->
      <el-tab-pane label="奖品库配置" name="prizes">
        <el-card shadow="never" class="toolbar-card">
          <el-form :inline="true" :model="prizeQueryForm" class="query-form" @submit.prevent>
            <el-form-item label="奖品名称">
              <el-input
                v-model="prizeQueryForm.search"
                placeholder="搜索奖品名称"
                clearable
                @keyup.enter="handlePrizeSearch"
              />
            </el-form-item>
            <el-form-item label="奖品类型">
              <el-select v-model="prizeQueryForm.type" placeholder="全部类型" clearable style="width: 130px">
                <el-option label="实物奖品" value="physical" />
                <el-option label="虚拟卡券" value="virtual" />
              </el-select>
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="prizeQueryForm.status" placeholder="全部状态" clearable style="width: 120px">
                <el-option label="上架中" :value="1" />
                <el-option label="已下架" :value="0" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handlePrizeSearch">
                <el-icon><Search /></el-icon>
                查询
              </el-button>
              <el-button @click="handlePrizeReset">
                <el-icon><Refresh /></el-icon>
                重置
              </el-button>
            </el-form-item>
          </el-form>

          <div class="action-buttons">
            <el-button type="primary" @click="handleAddPrize">
              <el-icon><Plus /></el-icon>
              新增奖品
            </el-button>
            <el-button
              type="danger"
              :disabled="selectedPrizeRows.length === 0"
              @click="handleBatchDeletePrizes"
            >
              <el-icon><Delete /></el-icon>
              批量删除 ({{ selectedPrizeRows.length }})
            </el-button>
          </div>
        </el-card>

        <!-- 奖品列表表格 -->
        <el-card shadow="never" class="table-card mt-16">
          <el-table
            v-loading="prizeLoading"
            :data="prizeList"
            row-key="id"
            border
            stripe
            @selection-change="handlePrizeSelectionChange"
          >
            <el-table-column type="selection" width="50" align="center" />
            <el-table-column prop="id" label="ID" width="70" align="center" sortable />
            <el-table-column label="奖品封面" width="85" align="center">
              <template #default="{ row }">
                <el-image
                  v-if="row.image"
                  :src="row.image"
                  fit="cover"
                  class="prize-thumb"
                  preview-teleported
                  :preview-src-list="[row.image]"
                />
                <span v-else class="text-muted">无图</span>
              </template>
            </el-table-column>
            <el-table-column prop="title" label="奖品名称" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="font-bold">{{ row.title }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="type" label="类型" width="100" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.type === 'physical' ? 'primary' : 'success'">
                  {{ row.type === 'physical' ? '实物礼品' : '虚拟卡券' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="兑换门槛条件" min-width="180">
              <template #default="{ row }">
                <div class="condition-cell">
                  <div v-if="row.exchange_type === 'rank'">
                    <el-tag size="small" type="danger" effect="plain">
                      🏆 榜单前 {{ row.top_rank_limit || 10 }} 名专享
                    </el-tag>
                  </div>
                  <div v-else>
                    <el-tag size="small" type="warning" effect="plain">
                      所需积分: {{ row.cost_points }} 分
                    </el-tag>
                  </div>
                  <div v-if="row.min_score" class="min-score text-muted mt-2">
                    答题门槛最低 {{ row.min_score }} 分
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="stock" label="剩余库存" width="95" align="center" sortable>
              <template #default="{ row }">
                <span :class="row.stock <= 5 ? 'text-danger font-bold' : 'font-bold'">
                  {{ row.stock }} 件
                </span>
              </template>
            </el-table-column>
            <el-table-column label="每人限兑" width="95" align="center">
              <template #default="{ row }">
                <span>{{ row.per_user_limit ? `${row.per_user_limit} 次` : '不限' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="85" align="center">
              <template #default="{ row }">
                <el-switch
                  v-model="row.status"
                  :active-value="1"
                  :inactive-value="0"
                  @change="handlePrizeStatusToggle(row)"
                />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="168" fixed="right" align="center">
              <template #default="{ row }">
                <div class="hw-table-actions">
                  <el-button plain type="primary" size="small" @click="handleEditPrize(row)">
                    编辑
                  </el-button>
                  <el-button plain type="danger" size="small" @click="handleDeletePrize(row)">
                    删除
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-container">
            <el-pagination
              v-model:current-page="prizePagination.page"
              v-model:page-size="prizePagination.limit"
              :total="prizePagination.total"
              :page-sizes="[10, 20, 50]"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="loadPrizeData"
              @current-change="loadPrizeData"
            />
          </div>
        </el-card>
      </el-tab-pane>

      <!-- ==================== Tab 2: 兑换订单与发货 ==================== -->
      <el-tab-pane label="兑换订单与发货" name="orders">
        <el-card shadow="never" class="toolbar-card">
          <el-form :inline="true" :model="orderQueryForm" class="query-form" @submit.prevent>
            <el-form-item label="订单状态">
              <el-select v-model="orderQueryForm.status" placeholder="全部状态" clearable style="width: 140px">
                <el-option label="待发货/待核销" value="pending" />
                <el-option label="已发货" value="shipped" />
                <el-option label="已核销" value="verified" />
                <el-option label="已驳回" value="rejected" />
              </el-select>
            </el-form-item>
            <el-form-item label="奖品类型">
              <el-select v-model="orderQueryForm.type" placeholder="全部类型" clearable style="width: 130px">
                <el-option label="实物奖品" value="physical" />
                <el-option label="虚拟卡券" value="virtual" />
              </el-select>
            </el-form-item>
            <el-form-item label="关键词">
              <el-input
                v-model="orderQueryForm.search"
                placeholder="订单号 / 选手 / 手机 / 快递单号"
                clearable
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleOrderSearch">
                <el-icon><Search /></el-icon>
                查询
              </el-button>
              <el-button @click="handleOrderReset">
                <el-icon><Refresh /></el-icon>
                重置
              </el-button>
            </el-form-item>
          </el-form>

          <div class="action-buttons">
            <el-button
              type="primary"
              :disabled="selectedOrderRows.length === 0"
              @click="handleBatchShip"
            >
              <el-icon><Van /></el-icon>
              批量发货 ({{ selectedOrderRows.length }})
            </el-button>
          </div>
        </el-card>

        <!-- 订单表格 -->
        <el-card shadow="never" class="table-card mt-16">
          <el-table
            v-loading="orderLoading"
            :data="orderList"
            row-key="id"
            border
            stripe
            @selection-change="handleOrderSelectionChange"
          >
            <el-table-column type="selection" width="50" align="center" />
            <el-table-column prop="order_no" label="兑换单号" width="160" align="center">
              <template #default="{ row }">
                <span class="font-bold text-slate">{{ row.order_no }}</span>
              </template>
            </el-table-column>
            <el-table-column label="兑换选手" width="140">
              <template #default="{ row }">
                <div class="user-info-cell">
                  <div class="name-row">
                    <span class="font-bold">{{ row.user_name }}</span>
                    <el-tag size="small" type="info" class="ml-4">{{ row.user_no || 'HW0000' }}</el-tag>
                  </div>
                  <div class="dept-text text-muted">{{ row.department || '华为技术' }}</div>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="prize_title" label="奖品名称" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <div class="prize-cell">
                  <span>{{ row.prize_title }}</span>
                  <el-tag size="small" :type="row.prize_type === 'physical' ? 'primary' : 'success'" class="ml-4">
                    {{ row.prize_type === 'physical' ? '实物' : '虚拟' }}
                  </el-tag>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="cost_points" label="消耗积分" width="95" align="center">
              <template #default="{ row }">
                <span class="text-orange font-bold">{{ row.cost_points }} 积分</span>
              </template>
            </el-table-column>
            <el-table-column label="收货 / 核销信息" min-width="220">
              <template #default="{ row }">
                <!-- 虚拟核销码 -->
                <div v-if="row.prize_type === 'virtual'" class="virtual-info">
                  <span class="lbl">核销券码：</span>
                  <el-tag size="small" type="success" effect="dark">{{ row.voucher_code || 'TKT-20268888' }}</el-tag>
                </div>
                <!-- 实物收货信息 -->
                <div v-else class="physical-info">
                  <div><strong>{{ row.receiver_name }}</strong> ({{ row.receiver_phone }})</div>
                  <div class="address-text text-muted" :title="row.receiver_address">
                    {{ row.receiver_address }}
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="物流发货信息" min-width="170">
              <template #default="{ row }">
                <div v-if="row.express_company && row.tracking_no" class="express-info">
                  <el-tag size="small" type="info">{{ row.express_company }}</el-tag>
                  <div class="tracking-num font-bold">{{ row.tracking_no }}</div>
                </div>
                <span v-else class="text-muted">暂未发货</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="100" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="getOrderStatusTagType(row.status)">
                  {{ getOrderStatusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="下单时间" width="160" align="center">
              <template #default="{ row }">
                {{ formatTime(row.create_time) }}
              </template>
            </el-table-column>
            <el-table-column label="操作" width="200" fixed="right" align="center">
              <template #default="{ row }">
                <div v-if="row.status === 'pending'" class="hw-table-actions">
                  <!-- 实物未发货：录入单号发货 -->
                  <el-button
                    v-if="row.prize_type === 'physical'"
                    plain
                    type="primary"
                    size="small"
                    @click="openShipDialog(row)"
                  >
                    录入发货
                  </el-button>
                  <!-- 虚拟券码未核销：一键核销 -->
                  <el-button
                    v-if="row.prize_type === 'virtual'"
                    plain
                    type="success"
                    size="small"
                    @click="handleVerifyVoucher(row)"
                  >
                    一键核销
                  </el-button>
                  <!-- 待发货允许驳回 -->
                  <el-button
                    plain
                    type="danger"
                    size="small"
                    @click="openRejectDialog(row)"
                  >
                    驳回退分
                  </el-button>
                </div>
                <span v-else class="text-muted">已办结</span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-container">
            <el-pagination
              v-model:current-page="orderPagination.page"
              v-model:page-size="orderPagination.limit"
              :total="orderPagination.total"
              :page-sizes="[10, 20, 50]"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="loadOrderData"
              @current-change="loadOrderData"
            />
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 新增 / 编辑奖品弹窗 -->
    <el-dialog
      v-model="prizeDialogVisible"
      :title="isPrizeEdit ? '编辑奖品' : '新增奖品'"
      width="650px"
      destroy-on-close
    >
      <el-form
        ref="prizeFormRef"
        :model="prizeForm"
        :rules="prizeRules"
        label-width="110px"
      >
        <el-form-item label="奖品名称" prop="title">
          <el-input v-model="prizeForm.title" placeholder="如：华为 MatePad 11.5 英寸平板电脑" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="奖品类型" prop="type">
              <el-radio-group v-model="prizeForm.type">
                <el-radio-button value="physical">实物礼品</el-radio-button>
                <el-radio-button value="virtual">虚拟卡券</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="兑换模式" prop="exchange_type">
              <el-select v-model="prizeForm.exchange_type" style="width: 100%">
                <el-option label="积分达标兑换" value="points" />
                <el-option label="榜单 Top N 专享" value="rank" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="12" v-if="prizeForm.exchange_type === 'points'">
            <el-form-item label="所需积分" prop="cost_points">
              <el-input-number v-model="prizeForm.cost_points" :min="1" :max="99999" />
              <span class="unit-text">分</span>
            </el-form-item>
          </el-col>
          <el-col :span="12" v-else>
            <el-form-item label="榜单名额限制">
              <el-input-number v-model="prizeForm.top_rank_limit" :min="1" :max="100" />
              <span class="unit-text">前 N 名可兑</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="答题分数门槛">
              <el-input-number v-model="prizeForm.min_score" :min="0" :max="1000" />
              <span class="unit-text">最低分</span>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="库存总量" prop="stock">
              <el-input-number v-model="prizeForm.stock" :min="0" :max="99999" />
              <span class="unit-text">件</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="每人限兑">
              <el-input-number v-model="prizeForm.per_user_limit" :min="0" :max="100" />
              <span class="unit-text">次 (0为不限)</span>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="奖品封面图">
          <div class="prize-img-upload">
            <el-input v-model="prizeForm.image" placeholder="图片 URL 地址或本地上传" class="mb-4">
              <template #append>
                <el-upload
                  :show-file-list="false"
                  :http-request="customUploadPrizeImage"
                  accept="image/*"
                >
                  <el-button type="primary">本地上传</el-button>
                </el-upload>
              </template>
            </el-input>
            <div v-if="prizeForm.image" class="thumb-preview">
              <el-image :src="prizeForm.image" fit="cover" class="preview-img" />
              <el-button link type="danger" size="small" @click="prizeForm.image = ''">删除图片</el-button>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="奖品详细说明">
          <el-input
            v-model="prizeForm.description"
            type="textarea"
            :rows="3"
            placeholder="请输入奖品规格参数、发放形式及有效期..."
          />
        </el-form-item>

        <el-form-item label="上下架状态">
          <el-radio-group v-model="prizeForm.status">
            <el-radio :value="1">立即上架</el-radio>
            <el-radio :value="0">暂存仓库</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="prizeDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="prizeSubmitLoading" @click="handlePrizeSubmit">
          确认保存
        </el-button>
      </template>
    </el-dialog>

    <!-- 发货弹窗 -->
    <el-dialog v-model="shipDialogVisible" title="录入物流单号发货" width="480px" destroy-on-close>
      <el-form :model="shipForm" label-width="90px">
        <el-form-item label="收货人信息">
          <div>
            <strong>{{ currentShipOrder?.receiver_name }}</strong> ({{ currentShipOrder?.receiver_phone }})
          </div>
          <div class="text-muted">{{ currentShipOrder?.receiver_address }}</div>
        </el-form-item>
        <el-form-item label="物流快递" required>
          <el-select v-model="shipForm.express_company" placeholder="请选择快递公司" style="width: 100%">
            <el-option label="顺丰速运" value="顺丰速运" />
            <el-option label="京东快递" value="京东快递" />
            <el-option label="中通快递" value="中通快递" />
            <el-option label="圆通速递" value="圆通速递" />
            <el-option label="极兔速递" value="极兔速递" />
            <el-option label="中国邮政 EMS" value="中国邮政 EMS" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号" required>
          <el-input v-model="shipForm.tracking_no" placeholder="请输入物流快递单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="shipDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="shipLoading" @click="handleConfirmShip">
          确认发货
        </el-button>
      </template>
    </el-dialog>

    <!-- 驳回弹窗 -->
    <el-dialog v-model="rejectDialogVisible" title="驳回兑换申请并退分" width="440px" destroy-on-close>
      <el-form :model="rejectForm" label-width="90px">
        <el-form-item label="驳回理由" required>
          <el-input
            v-model="rejectForm.reject_reason"
            type="textarea"
            :rows="3"
            placeholder="请输入驳回原因，将通知选手并全额原路退还所扣积分..."
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rejectDialogVisible = false">取消</el-button>
        <el-button type="danger" :loading="rejectLoading" @click="handleConfirmReject">
          确认驳回
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Search, Refresh, Plus, Delete, Van } from '@element-plus/icons-vue'
import {
  getQuizPrizeList,
  addQuizPrize,
  editQuizPrize,
  delQuizPrize,
  getQuizOrderList,
  shipQuizOrder,
  uploadQuizAttachment,
} from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizPrizeManage',
})

const activeTab = ref('prizes')

// ==================== 奖品配置逻辑 ====================
const prizeLoading = ref(false)
const prizeSubmitLoading = ref(false)
const prizeDialogVisible = ref(false)
const isPrizeEdit = ref(false)
const editPrizeId = ref<number | null>(null)
const selectedPrizeRows = ref<any[]>([])

const prizeQueryForm = reactive({
  search: '',
  type: '',
  status: undefined as number | undefined,
})

const prizePagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const prizeList = ref<any[]>([])

const prizeFormRef = ref<FormInstance>()
const prizeForm = reactive({
  title: '',
  type: 'physical',
  exchange_type: 'points',
  cost_points: 100,
  min_score: 60,
  top_rank_limit: 10,
  stock: 50,
  per_user_limit: 1,
  image: '',
  description: '',
  status: 1,
})

const prizeRules = reactive<FormRules>({
  title: [{ required: true, message: '请输入奖品名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择奖品类型', trigger: 'change' }],
})

// ==================== 订单逻辑 ====================
const orderLoading = ref(false)
const shipLoading = ref(false)
const rejectLoading = ref(false)
const shipDialogVisible = ref(false)
const rejectDialogVisible = ref(false)
const currentShipOrder = ref<any>(null)
const currentRejectOrder = ref<any>(null)
const selectedOrderRows = ref<any[]>([])

const orderQueryForm = reactive({
  status: '',
  type: '',
  search: '',
})

const orderPagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const orderList = ref<any[]>([])

const shipForm = reactive({
  express_company: '顺丰速运',
  tracking_no: '',
})

const rejectForm = reactive({
  reject_reason: '答题数据核实异常或收货信息不完整',
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

// 订单状态标签
const getOrderStatusLabel = (status: string) => {
  if (status === 'pending') return '待发货/待核销'
  if (status === 'shipped') return '已发货'
  if (status === 'verified') return '已核销'
  if (status === 'rejected') return '已驳回'
  return status || '处理中'
}

const getOrderStatusTagType = (status: string) => {
  if (status === 'pending') return 'warning'
  if (status === 'shipped') return 'primary'
  if (status === 'verified') return 'success'
  if (status === 'rejected') return 'danger'
  return 'info'
}

// 奖品查询
const handlePrizeSearch = () => {
  prizePagination.page = 1
  loadPrizeData()
}

const handlePrizeReset = () => {
  prizeQueryForm.search = ''
  prizeQueryForm.type = ''
  prizeQueryForm.status = undefined
  prizePagination.page = 1
  loadPrizeData()
}

const handlePrizeSelectionChange = (rows: any[]) => {
  selectedPrizeRows.value = rows
}

// 加载奖品数据
const loadPrizeData = async () => {
  prizeLoading.value = true
  try {
    const res: any = await getQuizPrizeList({
      page: prizePagination.page,
      limit: prizePagination.limit,
      ...prizeQueryForm,
    })
    if (res?.code === 1 && res?.data) {
      prizeList.value = res.data.list || []
      prizePagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取奖品数据失败，展示模拟数据', error)
    if (prizeList.value.length === 0) {
      prizeList.value = [
        {
          id: 1,
          title: '华为 FreeBuds 5i 无线降噪耳机',
          type: 'physical',
          exchange_type: 'points',
          cost_points: 300,
          min_score: 90,
          stock: 15,
          per_user_limit: 1,
          image: 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=100&q=80',
          description: '高保真音质，42dB 多模式降噪',
          status: 1,
        },
        {
          id: 2,
          title: '京东电子 E 卡 (100 元面值)',
          type: 'virtual',
          exchange_type: 'points',
          cost_points: 150,
          min_score: 80,
          stock: 80,
          per_user_limit: 2,
          image: '',
          description: '电子兑换券，即时充值全国通用',
          status: 1,
        },
        {
          id: 3,
          title: '华为 Watch GT4 智能手表 (榜单尊享)',
          type: 'physical',
          exchange_type: 'rank',
          top_rank_limit: 3,
          min_score: 95,
          stock: 3,
          per_user_limit: 1,
          image: '',
          description: '全员竞答总榜冠亚季军专享终极大奖',
          status: 1,
        },
      ]
      prizePagination.total = 3
    }
  } finally {
    prizeLoading.value = false
  }
}

// 上传奖品图
const customUploadPrizeImage = async (options: any) => {
  try {
    const res: any = await uploadQuizAttachment(options.file)
    if (res?.code === 1 && res?.data) {
      prizeForm.image = res.data.url || res.data.path || ''
      ElMessage.success('奖品图片上传成功')
    } else {
      ElMessage.error(res?.msg || '上传失败')
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '上传失败')
  }
}

// 新增奖品
const handleAddPrize = () => {
  isPrizeEdit.value = false
  editPrizeId.value = null
  Object.assign(prizeForm, {
    title: '',
    type: 'physical',
    exchange_type: 'points',
    cost_points: 100,
    min_score: 60,
    top_rank_limit: 10,
    stock: 50,
    per_user_limit: 1,
    image: '',
    description: '',
    status: 1,
  })
  prizeDialogVisible.value = true
}

// 编辑奖品
const handleEditPrize = (row: any) => {
  isPrizeEdit.value = true
  editPrizeId.value = row.id
  Object.assign(prizeForm, {
    title: row.title,
    type: row.type || 'physical',
    exchange_type: row.exchange_type || 'points',
    cost_points: row.cost_points ?? 100,
    min_score: row.min_score ?? 60,
    top_rank_limit: row.top_rank_limit ?? 10,
    stock: row.stock ?? 50,
    per_user_limit: row.per_user_limit ?? 1,
    image: row.image || '',
    description: row.description || '',
    status: row.status ?? 1,
  })
  prizeDialogVisible.value = true
}

// 提交奖品
const handlePrizeSubmit = async () => {
  if (!prizeFormRef.value) return
  await prizeFormRef.value.validate(async (valid) => {
    if (!valid) return
    prizeSubmitLoading.value = true
    try {
      if (isPrizeEdit.value && editPrizeId.value) {
        await editQuizPrize(editPrizeId.value, prizeForm)
        ElMessage.success('奖品更新成功')
      } else {
        await addQuizPrize(prizeForm)
        ElMessage.success('奖品添加成功')
      }
      prizeDialogVisible.value = false
      loadPrizeData()
    } catch (err: any) {
      ElMessage.error(err?.message || '保存失败')
    } finally {
      prizeSubmitLoading.value = false
    }
  })
}

// 上下架切换
const handlePrizeStatusToggle = async (row: any) => {
  try {
    await editQuizPrize(row.id, { status: row.status })
    ElMessage.success(`奖品【${row.title}】状态已更新`)
  } catch (err: any) {
    ElMessage.error(err?.message || '状态更新失败')
    row.status = row.status === 1 ? 0 : 1
  }
}

// 删除奖品
const handleDeletePrize = (row: any) => {
  ElMessageBox.confirm(`确定要彻底删除奖品【${row.title}】吗？`, '删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizPrize(row.id)
      ElMessage.success('奖品已删除')
      loadPrizeData()
    } catch (err: any) {
      ElMessage.error(err?.message || '删除失败')
    }
  })
}

// 批量删除奖品
const handleBatchDeletePrizes = () => {
  const ids = selectedPrizeRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要批量删除选中的 ${ids.length} 项奖品吗？`, '批量删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizPrize(ids)
      ElMessage.success('批量删除完成')
      loadPrizeData()
    } catch (err: any) {
      ElMessage.error(err?.message || '批量删除失败')
    }
  })
}

// ==================== 订单逻辑 ====================
const handleOrderSearch = () => {
  orderPagination.page = 1
  loadOrderData()
}

const handleOrderReset = () => {
  orderQueryForm.status = ''
  orderQueryForm.type = ''
  orderQueryForm.search = ''
  orderPagination.page = 1
  loadOrderData()
}

const handleOrderSelectionChange = (rows: any[]) => {
  selectedOrderRows.value = rows
}

// 加载订单列表
const loadOrderData = async () => {
  orderLoading.value = true
  try {
    const res: any = await getQuizOrderList({
      page: orderPagination.page,
      limit: orderPagination.limit,
      ...orderQueryForm,
    })
    if (res?.code === 1 && res?.data) {
      orderList.value = res.data.list || []
      orderPagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取兑换订单失败，呈现模拟数据', error)
    if (orderList.value.length === 0) {
      orderList.value = [
        {
          id: 501,
          order_no: 'ORD202610280001',
          user_name: '张三',
          user_no: 'HW1001',
          department: '技术研发中心',
          prize_title: '华为 FreeBuds 5i 无线降噪耳机',
          prize_type: 'physical',
          cost_points: 300,
          receiver_name: '张三',
          receiver_phone: '13800138001',
          receiver_address: '广东省深圳市龙岗区坂田华为基地 F 区 3 号楼',
          express_company: '',
          tracking_no: '',
          status: 'pending',
          create_time: Date.now() - 1800000,
        },
        {
          id: 502,
          order_no: 'ORD202610280002',
          user_name: '李四',
          user_no: 'HW1002',
          department: '云原生架构部',
          prize_title: '京东电子 E 卡 (100 元面值)',
          prize_type: 'virtual',
          cost_points: 150,
          voucher_code: 'JD-2026-9876-5432',
          status: 'pending',
          create_time: Date.now() - 3600000,
        },
        {
          id: 503,
          order_no: 'ORD202610270003',
          user_name: '孙七',
          user_no: 'HW1005',
          department: '产品设计中心',
          prize_title: '华为 FreeBuds 5i 无线降噪耳机',
          prize_type: 'physical',
          cost_points: 300,
          receiver_name: '孙七',
          receiver_phone: '13900139002',
          receiver_address: '上海市浦东新区金桥华为研究所 B 座',
          express_company: '顺丰速运',
          tracking_no: 'SF1428570098',
          status: 'shipped',
          create_time: Date.now() - 86400000,
        },
      ]
      orderPagination.total = 3
    }
  } finally {
    orderLoading.value = false
  }
}

// 录入发货
const openShipDialog = (row: any) => {
  currentShipOrder.value = row
  shipForm.express_company = '顺丰速运'
  shipForm.tracking_no = ''
  shipDialogVisible.value = true
}

const handleConfirmShip = async () => {
  if (!shipForm.tracking_no.trim()) {
    ElMessage.warning('请输入快递单号')
    return
  }
  shipLoading.value = true
  try {
    await shipQuizOrder({
      order_id: currentShipOrder.value.id,
      express_company: shipForm.express_company,
      tracking_no: shipForm.tracking_no,
      action: 'ship',
    })
    ElMessage.success('发货成功，物流单号已录入')
    shipDialogVisible.value = false
    loadOrderData()
  } catch (err: any) {
    ElMessage.error(err?.message || '发货操作失败')
  } finally {
    shipLoading.value = false
  }
}

// 批量发货
const handleBatchShip = () => {
  const pendingPhysical = selectedOrderRows.value.filter(
    (r) => r.status === 'pending' && r.prize_type === 'physical'
  )
  if (pendingPhysical.length === 0) {
    ElMessage.warning('选中的订单中没有待发货的实物订单')
    return
  }
  ElMessageBox.confirm(`确定要将已选中的 ${pendingPhysical.length} 笔实物订单标记为自动批量发货（顺丰速运）吗？`, '批量发货提示', {
    confirmButtonText: '确定发货',
    cancelButtonText: '取消',
    type: 'primary',
  }).then(async () => {
    try {
      for (const ord of pendingPhysical) {
        await shipQuizOrder({
          order_id: ord.id,
          express_company: '顺丰速运',
          tracking_no: `SF${Date.now().toString().slice(-8)}${ord.id}`,
          action: 'ship',
        })
      }
      ElMessage.success(`批量发货成功完成 ${pendingPhysical.length} 单`)
      loadOrderData()
    } catch (err: any) {
      ElMessage.error(err?.message || '批量发货异常')
    }
  })
}

// 虚拟卡券核销
const handleVerifyVoucher = (row: any) => {
  ElMessageBox.confirm(`确定要核销该虚拟券码【${row.voucher_code || '电子卡券'}】吗？核销后将标记为已兑换完成。`, '券码核销提示', {
    confirmButtonText: '确认核销',
    cancelButtonText: '取消',
    type: 'success',
  }).then(async () => {
    try {
      await shipQuizOrder({
        order_id: row.id,
        action: 'verify',
      })
      ElMessage.success('虚拟券码已成功核销')
      loadOrderData()
    } catch (err: any) {
      ElMessage.error(err?.message || '核销失败')
    }
  })
}

// 驳回订单
const openRejectDialog = (row: any) => {
  currentRejectOrder.value = row
  rejectForm.reject_reason = '答题资格核验未通过，原路返还所消耗积分'
  rejectDialogVisible.value = true
}

const handleConfirmReject = async () => {
  if (!rejectForm.reject_reason.trim()) {
    ElMessage.warning('请输入驳回理由')
    return
  }
  rejectLoading.value = true
  try {
    await shipQuizOrder({
      order_id: currentRejectOrder.value.id,
      action: 'reject',
      reject_reason: rejectForm.reject_reason,
    })
    ElMessage.success('订单已驳回，积分已全额返还')
    rejectDialogVisible.value = false
    loadOrderData()
  } catch (err: any) {
    ElMessage.error(err?.message || '驳回操作失败')
  } finally {
    rejectLoading.value = false
  }
}

onMounted(() => {
  loadPrizeData()
  loadOrderData()
})
</script>

<style scoped lang="scss">
.quiz-prize-container {
  padding: 16px;

  .prize-tabs {
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

  .mt-2 {
    margin-top: 2px;
  }

  .ml-4 {
    margin-left: 4px;
  }

  .mb-4 {
    margin-bottom: 4px;
  }

  .text-muted {
    color: #94a3b8;
    font-size: 12px;
  }

  .text-danger {
    color: #ef4444;
  }

  .text-orange {
    color: #f97316;
  }

  .text-slate {
    color: #334155;
  }

  .font-bold {
    font-weight: 700;
  }

  .prize-thumb {
    width: 50px;
    height: 40px;
    border-radius: 4px;
    border: 1px solid #e2e8f0;
  }

  .condition-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .pagination-container {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  .prize-img-upload {
    width: 100%;

    .thumb-preview {
      display: flex;
      align-items: center;
      gap: 10px;

      .preview-img {
        width: 100px;
        height: 60px;
        border-radius: 4px;
        border: 1px solid #e2e8f0;
      }
    }
  }

  .unit-text {
    margin-left: 8px;
    font-size: 12px;
    color: #64748b;
  }

  .user-info-cell {
    display: flex;
    flex-direction: column;

    .name-row {
      display: flex;
      align-items: center;
    }
  }

  .prize-cell {
    display: flex;
    align-items: center;
  }

  .physical-info {
    font-size: 12px;
    color: #334155;

    .address-text {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 260px;
    }
  }

  .virtual-info {
    font-size: 12px;
  }

  .express-info {
    font-size: 12px;

    .tracking-num {
      color: #2563eb;
      margin-top: 2px;
    }
  }
}
</style>
