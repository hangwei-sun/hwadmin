<script setup lang="ts">
definePage({ type: 'home', layout: 'default', style: { navigationBarTitleText: 'HwAdmin Mobile' } })
import { ref } from 'vue'
import { useToast } from '@wot-ui/ui'
import { ping } from '@/api/member'
import { useMemberStore } from '@/store/member'

const toast = useToast()
const member = useMemberStore()
const checking = ref(false)
const connection = ref('尚未检测')

type NavigateTarget = Parameters<typeof uni.navigateTo>[0]

function openBooking() {
  uni.navigateTo({
    url: '/pages/booking/index',
    fail: () => {
      toast.warning('请先应用 booking-system 模板以启用在线预约页')
    },
  } as unknown as NavigateTarget)
}
function openMemberOrLogin() {
  if (member.token) {
    uni.switchTab({ url: '/pages/me/index' })
  } else {
    uni.navigateTo({ url: '/pages/login/index' })
  }
}
function openComponents() {
  uni.navigateTo({ url: '/pages-components/index/index' })
}
async function checkConnection() {
  checking.value = true
  try {
    const result = await ping()
    connection.value = `v${result.version} · ${result.installed ? '已安装' : '未安装'}`
    toast.success('已连接 HwAdmin')
  } catch (error) {
    connection.value = '连接未完成'
    toast.error(error instanceof Error ? error.message : '连接失败')
  } finally {
    checking.value = false
  }
}
</script>

<template>
  <view class="page">
    <view class="mb-20rpx flex items-center justify-between">
      <image src="/static/brand/logo-horizontal.png" mode="aspectFit" style="width: 240rpx; height: 52rpx;" />
      <view class="eyebrow">MOBILE</view>
    </view>
    <view class="title">从这里，构建你的应用</view>
    <view class="subtitle">通用移动端底座，连接熟悉的 HwAdmin。组件、主题与会员接口已经就位，业务由你定义。</view>

    <view class="card stack">
      <view class="row">
        <view class="section-title" style="margin-bottom: 0;">核心业务体验 · 在线预约</view>
        <wd-tag type="primary" variant="plain">业务起点</wd-tag>
      </view>
      <view class="hint">
        串联服务选品、动态时段配额、在线预约与会员订单取消；微信开发者工具预览请打开构建产物目录 admin-ui/mobile/dist/dev/mp-weixin（生产构建为 dist/build/mp-weixin）。
      </view>
      <wd-button block @click="openBooking">立即进入在线预约</wd-button>
      <wd-button variant="plain" block @click="openMemberOrLogin">
        {{ member.token ? '会员中心 / 已登录' : '会员中心 / 登录' }}
      </wd-button>
    </view>

    <view class="card">
      <view class="row">
        <view class="section-title">Wot UI V2</view>
        <wd-tag type="success" variant="plain">2.4.0</wd-tag>
      </view>
      <view class="hint">按需加载 · 统一主题 · 微信小程序 / H5</view>
      <view class="mt-6">
        <wd-button block variant="plain" @click="openComponents">浏览组件与主题</wd-button>
      </view>
    </view>

    <view class="card stack">
      <view class="row">
        <view>后端连接</view>
        <wd-tag variant="plain">{{ connection }}</wd-tag>
      </view>
      <view class="hint">对接公开健康接口 /api/index/ping，状态来自实际请求。</view>
      <wd-button variant="plain" block :loading="checking" @click="checkConnection">检查接口连通性</wd-button>
    </view>
  </view>
</template>
