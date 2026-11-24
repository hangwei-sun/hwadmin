<template>
  <div class="crud-page-container booking-order-page">
    <HwTable
      ref="tableRef"
      :columns="columns"
      :data="tableData"
      :loading="loading"
      :pagination="pagination"
      @selection-change="handleSelectionChange"
      @page-change="fetchList"
    >
      <template #toolbar>
        <div class="toolbar-actions" role="toolbar" aria-label="预约订单筛选与操作">
          <el-select
            v-model="queryForm.status"
            placeholder="全部预约状态"
            clearable
            style="width: 160px"
            aria-label="筛选预约状态"
            @change="handleSearch"
          >
            <el-option :value="0" label="待确认" />
            <el-option :value="1" label="已确认" />
            <el-option :value="2" label="已完成" />
            <el-option :value="3" label="已取消" />
          </el-select>
          <el-button aria-label="刷新预约列表" @click="fetchList">刷新</el-button>
          <el-button
            v-auth="'booking/order/saveService'"
            type="primary"
            aria-label="新增预约服务项目"
            @click="openServiceDialog"
          >
            新增服务项目
          </el-button>
          <el-button
            v-auth="'booking/order/saveSlot'"
            type="success"
            plain
            aria-label="新增可预约时段"
            @click="openSlotDialog"
          >
            新增预约时段
          </el-button>
          <!-- @hw-custom-start: booking-order-toolbar -->
          <!-- @hw-custom-end: booking-order-toolbar -->
        </div>
      </template>

      <template #cell-status="{ row }">
        <el-tag :type="statusTagType(row.status)">
          {{ statusText(row.status) }}
        </el-tag>
      </template>

      <template #actions="{ row }">
        <el-button
          v-if="row.status === 0"
          v-auth="'booking/order/transition'"
          text
          type="primary"
          size="small"
          aria-label="确认预约"
          @click="handleConfirm(asRow(row))"
        >
          确认
        </el-button>
        <el-button
          v-if="row.status === 1"
          v-auth="'booking/order/transition'"
          text
          type="success"
          size="small"
          aria-label="完成预约"
          @click="handleComplete(asRow(row))"
        >
          完成
        </el-button>
        <el-button
          v-if="row.status < 2"
          v-auth="'booking/order/transition'"
          text
          type="danger"
          size="small"
          aria-label="取消预约"
          @click="handleCancel(asRow(row))"
        >
          取消
        </el-button>
      </template>
    </HwTable>

    <el-empty v-if="!loading && tableData.length === 0" description="暂无预约订单数据" />

    <el-dialog v-model="serviceDialogVisible" title="新增服务项目" width="min(520px, 92vw)">
      <el-form ref="serviceFormRef" :model="serviceForm" :rules="serviceRules" label-width="96px" aria-label="预约服务项目表单">
        <el-form-item label="服务名称" prop="title">
          <el-input v-model="serviceForm.title" placeholder="例如：专家一对一架构咨询" maxlength="128" aria-label="服务项目名称" />
        </el-form-item>
        <el-form-item label="服务时长" prop="duration">
          <el-input-number v-model="serviceForm.duration" :min="5" :max="1440" :step="15" style="width: 100%" aria-label="服务时长(分钟)" />
        </el-form-item>
        <el-form-item label="服务价格" prop="price">
          <el-input v-model="serviceForm.price" placeholder="例如 199.00" aria-label="服务价格(元)" />
        </el-form-item>
        <el-form-item label="上架状态" prop="status">
          <el-radio-group v-model="serviceForm.status" aria-label="服务上架状态">
            <el-radio :value="1">上架可约</el-radio>
            <el-radio :value="0">暂不上架</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消新增服务" @click="serviceDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="serviceSubmitting" aria-label="保存服务项目" @click="submitService">保存服务</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="slotDialogVisible" title="新增可预约时段" width="min(540px, 92vw)">
      <el-form ref="slotFormRef" :model="slotForm" :rules="slotRules" label-width="96px" aria-label="预约时段排期表单">
        <el-form-item label="所属服务" prop="service_id">
          <el-select
            v-model="slotForm.service_id"
            placeholder="请选择服务项目"
            :loading="servicesLoading"
            style="width: 100%"
            aria-label="选择所属服务项目"
          >
            <el-option
              v-for="item in serviceOptions"
              :key="item.id"
              :label="`${item.title} (${item.duration ?? 60}分钟 · ¥${item.price})`"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="预约日期" prop="date">
          <el-date-picker
            v-model="slotForm.date"
            type="date"
            value-format="YYYY-MM-DD"
            placeholder="选择日期 (YYYY-MM-DD)"
            style="width: 100%"
            aria-label="预约日期"
          />
        </el-form-item>
        <el-form-item label="开始时间" prop="start_time">
          <el-input v-model="slotForm.start_time" placeholder="例如 09:00" maxlength="10" aria-label="时段开始时间" />
        </el-form-item>
        <el-form-item label="结束时间" prop="end_time">
          <el-input v-model="slotForm.end_time" placeholder="例如 10:00" maxlength="10" aria-label="时段结束时间" />
        </el-form-item>
        <el-form-item label="配额名额" prop="capacity">
          <el-input-number v-model="slotForm.capacity" :min="1" :max="999" style="width: 100%" aria-label="时段配额名额" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消新增时段" @click="slotDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="slotSubmitting" aria-label="保存预约时段" @click="submitSlot">保存时段</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { reactive, ref } from 'vue'
import HwTable from '/@/components/HwTable/index.vue'
import type { HwTableColumn } from '/@/components/HwTable/types'
import { useCrudTable } from '/@/hooks/useCrudTable'
import request from '/@/utils/request'

defineOptions({ name: 'BookingOrderIndex' })

type ElTagType = 'primary' | 'success' | 'warning' | 'info' | 'danger'

interface BookingOrderRow {
  id: number
  order_no: string
  contact_name: string
  contact_mobile: string
  booking_date: string
  status: number
  remark?: string
  create_time: string
  [key: string]: unknown
}

interface BookingServiceOption {
  id: number
  title: string
  duration?: number
  price: string | number
}

const columns: HwTableColumn<BookingOrderRow>[] = [
  { prop: 'order_no', label: '预约单号', width: 170 },
  { prop: 'contact_name', label: '联系人', width: 120 },
  { prop: 'contact_mobile', label: '电话', width: 140 },
  { prop: 'booking_date', label: '预约日期', width: 130 },
  { prop: 'status', label: '状态', width: 100 },
  { prop: 'remark', label: '备注' },
  // @hw-custom-start: booking-order-columns-config
  // @hw-custom-end: booking-order-columns-config
  { prop: 'create_time', label: '创建时间', width: 170 },
]

const {
  loading,
  tableData,
  pagination,
  queryForm,
  fetchList,
  handleSearch,
  handleSelectionChange,
  asRow,
} = useCrudTable<BookingOrderRow, { status?: number }>({
  fetchApi: (params) => request({ url: '/adminapi/booking.order/index', method: 'get', params }),
  delApi: (ids) => request({ url: '/adminapi/booking.order/del', method: 'post', data: { ids } }),
  defaultQuery: () => ({ status: undefined }),
  rowKey: 'id',
})

const serviceDialogVisible = ref(false)
const serviceSubmitting = ref(false)
const serviceFormRef = ref<FormInstance>()
const serviceForm = reactive({
  title: '',
  duration: 60,
  price: '0.00',
  status: 1,
})
const serviceRules: FormRules = {
  title: [{ required: true, message: '请输入服务项目名称', trigger: 'blur' }],
  price: [{ pattern: /^\d{1,8}(\.\d{1,2})?$/, message: '请输入有效金额（最多两位小数）', trigger: 'blur' }],
}

const slotDialogVisible = ref(false)
const slotSubmitting = ref(false)
const servicesLoading = ref(false)
const slotFormRef = ref<FormInstance>()
const serviceOptions = ref<BookingServiceOption[]>([])
const slotForm = reactive<{
  service_id?: number
  date: string
  start_time: string
  end_time: string
  capacity: number
}>({
  service_id: undefined,
  date: new Date().toISOString().slice(0, 10),
  start_time: '09:00',
  end_time: '10:00',
  capacity: 5,
})
const slotRules: FormRules = {
  service_id: [{ required: true, message: '请选择所属服务项目', trigger: 'change' }],
  date: [{ required: true, message: '请选择预约日期', trigger: 'change' }],
  start_time: [{ required: true, message: '请填写开始时间', trigger: 'blur' }],
  end_time: [{ required: true, message: '请填写结束时间', trigger: 'blur' }],
}

const statusText = (status: number): string => {
  const map: Record<number, string> = { 0: '待确认', 1: '已确认', 2: '已完成', 3: '已取消' }
  return map[status] ?? '未知'
}

const statusTagType = (status: number): ElTagType => {
  const map: Record<number, ElTagType> = { 0: 'warning', 1: 'primary', 2: 'success', 3: 'info' }
  return map[status] ?? 'info'
}

const loadServices = async () => {
  servicesLoading.value = true
  try {
    const res = await request({ url: '/api/booking/services', method: 'get' })
    serviceOptions.value = Array.isArray(res?.data) ? res.data : []
    if (serviceOptions.value.length > 0 && !slotForm.service_id) {
      slotForm.service_id = serviceOptions.value[0].id
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '加载服务项目列表失败')
  } finally {
    servicesLoading.value = false
  }
}

const openServiceDialog = () => {
  serviceForm.title = ''
  serviceForm.duration = 60
  serviceForm.price = '0.00'
  serviceForm.status = 1
  serviceDialogVisible.value = true
}

const submitService = async () => {
  if (!serviceFormRef.value) return
  const valid = await serviceFormRef.value.validate().catch(() => false)
  if (!valid) return
  serviceSubmitting.value = true
  try {
    await request({
      url: '/adminapi/booking.order/saveService',
      method: 'post',
      data: {
        title: serviceForm.title.trim(),
        duration: Number(serviceForm.duration || 60),
        price: String(serviceForm.price || '0.00').trim(),
        status: Number(serviceForm.status ?? 1),
      },
    })
    ElMessage.success('服务项目保存成功')
    serviceDialogVisible.value = false
    await loadServices()
  } catch (err: any) {
    ElMessage.error(err?.message || '服务项目保存失败')
  } finally {
    serviceSubmitting.value = false
  }
}

const openSlotDialog = async () => {
  slotForm.date = new Date().toISOString().slice(0, 10)
  slotForm.start_time = '09:00'
  slotForm.end_time = '10:00'
  slotForm.capacity = 5
  slotDialogVisible.value = true
  await loadServices()
}

const submitSlot = async () => {
  if (!slotFormRef.value) return
  const valid = await slotFormRef.value.validate().catch(() => false)
  if (!valid) return
  slotSubmitting.value = true
  try {
    await request({
      url: '/adminapi/booking.order/saveSlot',
      method: 'post',
      data: {
        service_id: Number(slotForm.service_id),
        date: slotForm.date,
        start_time: slotForm.start_time.trim(),
        end_time: slotForm.end_time.trim(),
        capacity: Number(slotForm.capacity || 1),
        status: 1,
      },
    })
    ElMessage.success('预约时段保存成功')
    slotDialogVisible.value = false
  } catch (err: any) {
    ElMessage.error(err?.message || '预约时段保存失败')
  } finally {
    slotSubmitting.value = false
  }
}

const handleConfirm = async (row: BookingOrderRow) => {
  try {
    await request({ url: '/adminapi/booking.order/transition', method: 'post', data: { id: row.id, status: 1 } })
    ElMessage.success('已确认预约')
    await fetchList()
  } catch (err: any) {
    ElMessage.error(err?.message || '确认预约失败')
  }
}

const handleComplete = async (row: BookingOrderRow) => {
  try {
    await request({ url: '/adminapi/booking.order/transition', method: 'post', data: { id: row.id, status: 2 } })
    ElMessage.success('预约已标记完成')
    await fetchList()
  } catch (err: any) {
    ElMessage.error(err?.message || '更新状态失败')
  }
}

const handleCancel = async (row: BookingOrderRow) => {
  try {
    await ElMessageBox.confirm('确定要取消该预约吗？取消后名额将自动释放。', '提示', { type: 'warning' })
    await request({ url: '/adminapi/booking.order/transition', method: 'post', data: { id: row.id, status: 3 } })
    ElMessage.success('已取消预约')
    await fetchList()
  } catch (err: any) {
    if (err !== 'cancel' && err !== 'close') {
      ElMessage.error(err?.message || '取消预约失败')
    }
  }
}
</script>

<style scoped>
.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
</style>
