<script setup lang="ts">
definePage({ layout: 'default', style: { navigationBarTitleText: '会员中心' } })
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useToast } from '@wot-ui/ui'
import { useMemberStore } from '@/store/member'

const member = useMemberStore()
const toast = useToast()
const loading = ref(false)
const loadError = ref('')
function openLogin() {
  uni.navigateTo({ url: '/pages/login/index' })
}
async function refresh() {
  if (!member.token || loading.value) return
  loading.value = true
  loadError.value = ''
  try {
    await member.refresh()
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '资料加载失败'
  } finally {
    loading.value = false
  }
}
async function signOut() {
  if (loading.value) return
  loading.value = true
  try {
    await member.signOut()
    toast.success('已退出登录')
  } catch (error) {
    toast.error(error instanceof Error ? error.message : '退出失败，请重试')
  } finally {
    loading.value = false
  }
}
onShow(refresh)
</script>

<template>
  <view class="page">
    <view class="eyebrow">YOUR ACCOUNT</view>
    <view class="title">会员中心</view>
    <view v-if="!member.token" class="card stack">
      <view class="section-title">登录后管理个人资料</view>
      <view class="hint">会员账号与后台管理员账号独立。</view>
      <wd-button block @click="openLogin">登录会员账号</wd-button>
    </view>
    <view v-else class="card stack">
      <wd-loading v-if="loading" />
      <view v-if="loadError" class="hint">{{ loadError }}</view>
      <template v-if="member.profile">
        <view class="section-title">{{ member.profile.nickname || member.profile.username }}</view>
        <wd-cell-group border>
          <wd-cell title="会员 ID" :value="String(member.profile.id)" />
          <wd-cell title="用户名" :value="member.profile.username" />
          <wd-cell title="邮箱" :value="member.profile.email || '未设置'" />
        </wd-cell-group>
      </template>
      <wd-button variant="plain" block :disabled="loading" @click="refresh">刷新资料</wd-button>
      <wd-button type="danger" variant="plain" block :disabled="loading" @click="signOut">退出登录</wd-button>
    </view>
  </view>
</template>
