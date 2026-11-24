<script setup lang="ts">
definePage({ layout: 'default', style: { navigationBarTitleText: '会员登录' } })
import { ref } from 'vue'
import { useToast } from '@wot-ui/ui'
import { useMemberStore } from '@/store/member'
import { register as apiRegister, wechatLogin as apiWechatLogin } from '@/api/user'

const member = useMemberStore()
const toast = useToast()

const activeTab = ref<'login' | 'register'>('login')
const account = ref('')
const password = ref('')
const regUsername = ref('')
const regPassword = ref('')
const regNickname = ref('')
const submitting = ref(false)

async function submit() {
  if (submitting.value) return

  if (activeTab.value === 'login') {
    if (!account.value.trim() || !password.value) {
      toast.warning('请输入账号和密码')
      return
    }
    submitting.value = true
    try {
      await member.signIn(account.value.trim(), password.value)
      password.value = ''
      uni.switchTab({ url: '/pages/me/index' })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : '登录失败')
    } finally {
      submitting.value = false
    }
  } else {
    if (!regUsername.value.trim() || !regPassword.value) {
      toast.warning('请输入用户名与密码')
      return
    }
    submitting.value = true
    try {
      const res = await apiRegister({
        username: regUsername.value.trim(),
        password: regPassword.value,
        nickname: regNickname.value.trim() || undefined,
      })
      toast.success('注册成功')
      await member.signIn(regUsername.value.trim(), regPassword.value)
      regPassword.value = ''
      uni.switchTab({ url: '/pages/me/index' })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : '注册失败')
    } finally {
      submitting.value = false
    }
  }
}

async function handleWechatAuth() {
  try {
    toast.loading('检测微信授权凭据...')
    await apiWechatLogin('mock_code_probe')
  } catch (error: any) {
    // 微信小程序配置未就绪时给出明确的待办指示，严禁伪造成功
    toast.warning(error?.message || '微信小程序配置未就绪，缺少 AppID/AppSecret')
  }
}
</script>

<template>
  <view class="page">
    <view class="mb-16rpx flex items-center gap-16rpx">
      <image src="/static/brand/logo-square.png" mode="aspectFit" style="width: 56rpx; height: 56rpx;" />
      <view class="eyebrow">MEMBER ACCESS</view>
    </view>
    <view class="title">{{ activeTab === 'login' ? '欢迎回来' : '快速注册' }}</view>
    <view class="subtitle">使用 HwAdmin 会员账号登录</view>

    <view class="card stack">
      <view class="tab-header">
        <text :class="['tab-btn', activeTab === 'login' && 'active']" @click="activeTab = 'login'">账号登录</text>
        <text class="divider">|</text>
        <text :class="['tab-btn', activeTab === 'register' && 'active']" @click="activeTab = 'register'">快速注册</text>
      </view>

      <template v-if="activeTab === 'login'">
        <wd-cell title="账号"><wd-input v-model="account" compact placeholder="用户名 / 邮箱 / 手机号" :maxlength="100" clearable /></wd-cell>
        <wd-cell title="密码">
          <wd-input v-model="password" compact placeholder="请输入密码" :maxlength="64" show-password @confirm="submit" />
        </wd-cell>
      </template>

      <template v-else>
        <wd-cell title="用户名"><wd-input v-model="regUsername" compact placeholder="3-30位字母数字" :maxlength="30" clearable /></wd-cell>
        <wd-cell title="密码">
          <wd-input v-model="regPassword" compact placeholder="至少6位密码" :maxlength="64" show-password />
        </wd-cell>
        <wd-cell title="昵称"><wd-input v-model="regNickname" compact placeholder="用户显示昵称(选填)" :maxlength="50" clearable /></wd-cell>
      </template>

      <wd-button block :loading="submitting" @click="submit">
        {{ activeTab === 'login' ? '登录' : '立即注册' }}
      </wd-button>

      <view class="wechat-box">
        <wd-button plain block size="small" type="success" @click="handleWechatAuth">
          微信授权登录（诊断）
        </wd-button>
      </view>

      <view class="hint">当前连接已有会员接口。若微信凭据未配置，系统将返回明确诊断错误。</view>
    </view>
  </view>
</template>

<style scoped>
.tab-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding-bottom: 8px;
}
.tab-btn {
  font-size: 15px;
  color: #666;
  cursor: pointer;
}
.tab-btn.active {
  color: #1677ff;
  font-weight: bold;
}
.divider {
  color: #ddd;
}
.wechat-box {
  margin-top: 10px;
}
</style>
