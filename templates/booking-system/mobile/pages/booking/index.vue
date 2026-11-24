<script setup lang="ts">
import { computed, ref } from 'vue'
import { uploadAvatar } from '@/api/user'
import { request } from '@/http'
import { useToast } from '@wot-ui/ui'

interface BookingServiceOption {
  id: number
  title: string
  category?: string
  price: string | number
  duration?: number
}

interface BookingSlotOption {
  id: number
  service_id: number
  date: string
  start_time: string
  end_time: string
  capacity: number
  booked_count: number
  remaining_capacity?: number
  status: number
}

interface BookingOrderRecord {
  id: number
  order_no: string
  service_id: number
  slot_id: number
  booking_date: string
  contact_name: string
  contact_mobile: string
  status: number
  remark?: string
}

const toast = useToast()
const services = ref<BookingServiceOption[]>([])
const slots = ref<BookingSlotOption[]>([])
const myOrders = ref<BookingOrderRecord[]>([])
const selectedCategory = ref<string>('')
const selectedService = ref<number>(0)
const selectedSlotId = ref<number>(0)
const bookingDate = ref<string>(new Date().toISOString().slice(0, 10))
const contactName = ref('')
const contactMobile = ref('')
const remark = ref('')
const avatarUrl = ref('')
const uploadingAvatar = ref(false)
const submitting = ref(false)
const cancellingId = ref<number>(0)
const needLoginPrompt = ref(false)

const categories = computed(() => {
  const set = new Set<string>()
  for (const s of services.value) {
    if (s.category) {
      set.add(s.category)
    }
  }
  return Array.from(set)
})

const filteredServices = computed(() => {
  if (!selectedCategory.value) {
    return services.value
  }
  return services.value.filter((s) => s.category === selectedCategory.value)
})

function goToLogin() {
  uni.navigateTo({ url: '/pages/login/index' })
}

async function fetchServices(category = '') {
  try {
    const query = category ? `?category=${encodeURIComponent(category)}` : ''
    const res = await request<BookingServiceOption[]>({
      path: `/booking/services${query}`,
      authenticated: false,
    })
    services.value = res || []
    if (services.value.length > 0) {
      selectedService.value = services.value[0].id
      await fetchSlots(selectedService.value, bookingDate.value)
    } else {
      selectedService.value = 0
      slots.value = []
      selectedSlotId.value = 0
    }
  } catch (e: any) {
    toast.error(e.message || '加载服务失败')
  }
}

async function selectCategory(category: string) {
  selectedCategory.value = category
  const first = filteredServices.value[0]
  if (first) {
    await selectService(first.id)
  } else {
    selectedService.value = 0
    slots.value = []
    selectedSlotId.value = 0
  }
}

async function fetchSlots(serviceId: number, date = '') {
  if (!serviceId) {
    slots.value = []
    selectedSlotId.value = 0
    return
  }
  try {
    const queryDate = date ? `&date=${encodeURIComponent(date)}` : ''
    const res = await request<BookingSlotOption[]>({
      path: `/booking/slots?service_id=${serviceId}${queryDate}`,
      authenticated: false,
    })
    slots.value = (res || []).filter((s) => s.status === 1 && s.booked_count < s.capacity)
    selectedSlotId.value = slots.value.length > 0 ? slots.value[0].id : 0
  } catch (e: any) {
    toast.error(e.message || '加载可用时段失败')
  }
}

async function selectService(serviceId: number) {
  selectedService.value = serviceId
  await fetchSlots(serviceId, bookingDate.value)
}

async function handleDateChange() {
  if (selectedService.value) {
    await fetchSlots(selectedService.value, bookingDate.value)
  }
}

function selectSlot(slotId: number) {
  selectedSlotId.value = slotId
}

async function fetchMyOrders() {
  try {
    const res = await request<{ list: BookingOrderRecord[]; total: number }>({
      path: '/booking/myOrders',
      authenticated: true,
    })
    myOrders.value = res?.list || []
    needLoginPrompt.value = false
  } catch (e: any) {
    if (e?.code === 401 || e?.status === 401) {
      needLoginPrompt.value = true
    }
  }
}

function chooseAvatarUpload() {
  if (uploadingAvatar.value) return
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success: async (chooseRes) => {
      const filePath = chooseRes.tempFilePaths?.[0]
      if (!filePath) return
      uploadingAvatar.value = true
      try {
        const res = await uploadAvatar(filePath)
        avatarUrl.value = res.url
        toast.success('会员头像已更新')
      } catch (e: any) {
        toast.error(e.message || '头像上传失败')
      } finally {
        uploadingAvatar.value = false
      }
    },
  })
}

async function submitBooking() {
  if (!selectedService.value) {
    toast.warning('请选择预约服务')
    return
  }
  if (!selectedSlotId.value) {
    toast.warning('请选择可预约时段')
    return
  }
  if (!contactName.value.trim() || !contactMobile.value.trim()) {
    toast.warning('请填写姓名与手机号')
    return
  }
  if (remark.value.trim().length > 255) {
    toast.warning('预约备注不能超过255字')
    return
  }
  submitting.value = true
  try {
    await request({
      path: '/booking/create',
      method: 'POST',
      data: {
        service_id: selectedService.value,
        slot_id: selectedSlotId.value,
        contact_name: contactName.value.trim(),
        contact_mobile: contactMobile.value.trim(),
        remark: remark.value.trim(),
      },
    })
    needLoginPrompt.value = false
    toast.success('预约提交成功')
    contactName.value = ''
    contactMobile.value = ''
    remark.value = ''
    await fetchSlots(selectedService.value, bookingDate.value)
    await fetchMyOrders()
  } catch (e: any) {
    const isUnauth = e?.code === 401 || e?.status === 401 || String(e?.message || '').includes('登录')
    if (isUnauth) {
      needLoginPrompt.value = true
      toast.warning('请先登录会员账号后再提交预约')
    } else {
      toast.error(e.message || '预约失败')
    }
  } finally {
    submitting.value = false
  }
}

async function cancelBooking(orderId: number) {
  cancellingId.value = orderId
  try {
    await request({
      path: '/booking/cancel',
      method: 'POST',
      data: { id: orderId },
    })
    toast.success('预约已取消')
    await fetchSlots(selectedService.value, bookingDate.value)
    await fetchMyOrders()
  } catch (e: any) {
    toast.error(e.message || '取消失败')
  } finally {
    cancellingId.value = 0
  }
}

function statusLabel(status: number): string {
  const map: Record<number, string> = { 0: '待确认', 1: '已确认', 2: '已完成', 3: '已取消' }
  return map[status] ?? '未知'
}

fetchServices()
fetchMyOrders()
</script>

<template>
  <view class="page">
    <view class="header-bar">
      <view class="title">在线预约</view>
      <wd-button size="small" plain :loading="uploadingAvatar" aria-label="上传会员头像" @click="chooseAvatarUpload">
        {{ avatarUrl ? '更换头像' : '上传头像' }}
      </wd-button>
    </view>

    <view class="card">
      <view class="section-title">1. 选择服务项目</view>
      <view v-if="categories.length > 0" class="category-bar" aria-label="服务分类筛选">
        <text class="category-pill" :class="{ active: selectedCategory === '' }" @click="selectCategory('')">全部</text>
        <text
          v-for="cat in categories"
          :key="cat"
          class="category-pill"
          :class="{ active: selectedCategory === cat }"
          @click="selectCategory(cat)"
        >
          {{ cat }}
        </text>
      </view>
      <view v-if="filteredServices.length === 0" class="empty-tip">暂无可预约服务项目</view>
      <view
        v-for="item in filteredServices"
        :key="item.id"
        class="service-card"
        :class="{ 'service-card-active': selectedService === item.id }"
        @click="selectService(item.id)"
      >
        <view class="service-card-main">
          <text class="service-title" :class="{ active: selectedService === item.id }">{{ item.title }}</text>
          <text class="service-meta">服务时长 {{ item.duration || 60 }} 分钟</text>
        </view>
        <view class="service-card-price">
          <text class="price-text">¥{{ item.price }}</text>
        </view>
      </view>

      <view class="section-title" style="margin-top: 16px;">2. 选择预约时段</view>
      <wd-input
        v-model="bookingDate"
        label="预约日期"
        placeholder="YYYY-MM-DD"
        aria-label="预约日期"
        @blur="handleDateChange"
      />
      <view v-if="slots.length === 0" class="empty-tip">当前暂无可约时段</view>
      <view
        v-for="slot in slots"
        :key="slot.id"
        class="slot-item"
        @click="selectSlot(slot.id)"
      >
        <text :class="{ active: selectedSlotId === slot.id }">
          {{ slot.date }} {{ slot.start_time }}-{{ slot.end_time }}
          (余 {{ slot.capacity - slot.booked_count }} 名额)
        </text>
      </view>

      <view class="section-title" style="margin-top: 16px;">3. 填写联系信息</view>
      <wd-input v-model="contactName" label="联系人" placeholder="请输入姓名" aria-label="联系人姓名" />
      <wd-input v-model="contactMobile" label="手机号" placeholder="请输入手机号" aria-label="联系人手机号" />
      <wd-input v-model="remark" label="备注" placeholder="选填预约备注（最多255字）" aria-label="预约备注" />

      <!-- @hw-custom-start: mobile-booking-extensions -->
      <!-- @hw-custom-end: mobile-booking-extensions -->

      <view v-if="needLoginPrompt" class="login-prompt">
        <text class="login-prompt-text">当前尚未登录会员账号，登录后即可提交预约并查看个人预约记录。</text>
        <wd-button size="small" type="warning" plain aria-label="前往登录会员账号" @click="goToLogin">
          前往登录
        </wd-button>
      </view>

      <wd-button block :loading="submitting" aria-label="提交在线预约" style="margin-top: 20px;" @click="submitBooking">
        立即预约
      </wd-button>
    </view>

    <view class="card" style="margin-top: 16px;">
      <view class="section-title">我的预约记录</view>
      <view v-if="myOrders.length === 0" class="empty-tip">暂无预约记录</view>
      <view v-for="order in myOrders" :key="order.id" class="order-item">
        <view class="order-info">
          <text>{{ order.booking_date }} · {{ order.order_no }} ({{ statusLabel(order.status) }})</text>
          <text v-if="order.remark" class="order-remark">备注：{{ order.remark }}</text>
        </view>
        <wd-button
          v-if="order.status < 2"
          size="small"
          type="danger"
          :loading="cancellingId === order.id"
          aria-label="取消本人预约"
          @click="cancelBooking(order.id)"
        >
          取消预约
        </wd-button>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page { padding: 16px; }
.header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.title { font-size: 20px; font-weight: bold; }
.card { background: #fff; padding: 16px; border-radius: 8px; }
.section-title { font-weight: bold; margin-bottom: 10px; }
.category-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.category-pill { padding: 4px 10px; border-radius: 14px; background: #f5f5f5; font-size: 12px; color: #555; }
.service-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px;
  margin-bottom: 10px;
  border: 1px solid #eceff3;
  border-radius: 8px;
  background: #fafbfc;
}
.service-card-active {
  border-color: #1677ff;
  background: #f0f6ff;
}
.service-card-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.service-title {
  font-size: 15px;
  font-weight: 600;
  color: #1f2937;
}
.service-meta {
  font-size: 12px;
  color: #6b7280;
}
.service-card-price {
  flex-shrink: 0;
}
.price-text {
  font-size: 16px;
  font-weight: 700;
  color: #087f70;
}
.slot-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.order-item { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
.order-info { display: flex; flex-direction: column; gap: 4px; }
.order-remark { color: #666; font-size: 12px; }
.empty-tip { color: #999; font-size: 13px; padding: 8px 0; }
.login-prompt {
  margin-top: 14px;
  padding: 12px;
  border-radius: 8px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}
.login-prompt-text {
  flex: 1;
  font-size: 12px;
  color: #92400e;
  line-height: 1.5;
}
.active { color: #1677ff; font-weight: bold; }
</style>
