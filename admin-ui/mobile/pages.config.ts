import { defineUniPages } from '@uni-helper/vite-plugin-uni-pages'

export default defineUniPages({
  globalStyle: {
    navigationBarTitleText: 'HwAdmin Mobile',
    navigationBarBackgroundColor: '#f6f8fa',
    navigationBarTextStyle: 'black',
    backgroundColor: '#f6f8fa',
  },
  easycom: {
    autoscan: true,
    custom: { '^wd-(.*)': '@wot-ui/ui/components/wd-$1/wd-$1.vue' },
  },
  tabBar: {
    color: '#687587',
    selectedColor: '#087f70',
    backgroundColor: '#ffffff',
    list: [
      { pagePath: 'pages/index/index', text: '首页' },
      { pagePath: 'pages/me/index', text: '我的' },
    ],
  },
})
