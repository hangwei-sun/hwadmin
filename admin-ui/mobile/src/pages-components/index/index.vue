<script setup lang="ts">
definePage({ layout: 'default', style: { navigationBarTitleText: '组件与主题' } })
import { ref } from 'vue'
import { useDialog, useToast } from '@wot-ui/ui'

const toast = useToast()
const dialog = useDialog()
const enabled = ref(true)
const value = ref('')
const popup = ref(false)
const selected = ref('first')
const colors = ['#087f70', '#32a18e', '#e9f6f2', '#192b3e', '#687587']
async function openDialog() {
  try {
    await dialog.confirm({ title: '确认操作', msg: '通用确认弹窗，可用于业务提交前确认。' })
    toast.success('已确认')
  } catch {
    /* Cancelling the dialog is an expected user action. */
  }
}
</script>

<template>
  <view class="page">
    <view class="eyebrow">COMPONENTS / THEME</view>
    <view class="title">一套组件，一致体验</view>
    <view class="subtitle">这些示例直接使用 Wot UI V2，展示实际交互和项目主题。</view>
    <view class="card stack">
      <view class="section-title">品牌主题</view>
      <view class="row">
        <view v-for="color in colors" :key="color" class="swatch" :style="{ background: color }" />
      </view>
      <view class="hint">全局 CSS 变量同时覆盖页面与弹出层，主题入口位于 style/theme.scss。</view>
    </view>
    <view class="card stack">
      <view class="section-title">按钮与反馈</view>
      <view class="actions">
        <wd-button @click="toast.success('组件已接入')">主要按钮</wd-button>
        <wd-button variant="plain" @click="toast.warning('这是一条提示')">描边按钮</wd-button>
        <wd-button variant="text" @click="popup = true">打开弹层</wd-button>
        <wd-button variant="soft" @click="openDialog">确认弹窗</wd-button>
      </view>
      <view class="actions">
        <wd-tag type="success">成功</wd-tag>
        <wd-tag type="warning" variant="plain">待处理</wd-tag>
        <wd-tag variant="plain">默认</wd-tag>
      </view>
    </view>
    <view class="card stack">
      <view class="section-title">输入与状态</view>
      <wd-cell title="名称"><wd-input v-model="value" compact placeholder="试着输入内容" clearable /></wd-cell>
      <view class="row">
        <text>接收通知</text>
        <wd-switch v-model="enabled" />
      </view>
      <wd-progress :percentage="68" />
    </view>
    <view class="card">
      <wd-tabs v-model="selected">
        <wd-tab title="使用说明" name="first"><view class="tab-content hint">按需引入组件；业务页面可直接使用 wd-* 标签。</view></wd-tab>
        <wd-tab title="扩展方式" name="second">
          <view class="tab-content hint">新增页面放入 pages；独立模块放入分包目录并在 Vite 中注册。</view>
        </wd-tab>
      </wd-tabs>
    </view>
    <wd-popup v-model="popup" position="bottom" closable round>
      <view class="popup-content stack">
        <view class="section-title">主题延伸到每一个细节</view>
        <view class="hint">弹层和页面共享主色，业务项目可以在主题文件中统一定制。</view>
        <wd-button block @click="popup = false">完成</wd-button>
      </view>
    </wd-popup>
  </view>
</template>

<style scoped>
.swatch {
  width: 88rpx;
  height: 88rpx;
  border: 1rpx solid #e4e9ed;
  border-radius: 24rpx;
}
.tab-content {
  padding: 28rpx 0;
}
.popup-content {
  padding: 56rpx 40rpx calc(40rpx + env(safe-area-inset-bottom));
}
</style>
