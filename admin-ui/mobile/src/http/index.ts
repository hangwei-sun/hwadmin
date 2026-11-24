import { createClient } from './client'
import { ApiError } from './protocol'

export const TOKEN_KEY = 'hwadmin.mobile.member-token'
let redirecting = false

function baseURL() {
  const origin = (import.meta.env.VITE_API_ORIGIN || '').replace(/\/$/, '')
  let resolved = origin ? `${origin}/api` : '/api'
  // #ifdef MP-WEIXIN
  if (!origin || !/^https?:\/\//.test(origin)) {
    throw new ApiError('请先配置 VITE_API_ORIGIN 为后端域名', 0)
  }
  if (import.meta.env.PROD && !origin.startsWith('https://')) {
    throw new ApiError('正式环境接口域名必须使用 HTTPS', 0)
  }
  resolved = `${origin}/api`
  // #endif
  return resolved
}

export const request = createClient({
  baseURL: () => baseURL() || '',
  token: () => String(uni.getStorageSync(TOKEN_KEY) || ''),
  unauthorized() {
    uni.removeStorageSync(TOKEN_KEY)
    uni.$emit('hwadmin:unauthorized')
    const pages = getCurrentPages()
    if (redirecting || pages[pages.length - 1]?.route === 'pages/login/index') return
    redirecting = true
    uni.navigateTo({
      url: '/pages/login/index',
      complete: () => {
        redirecting = false
      },
    })
  },
  transport: (options) => {
    uni.request(options)
  },
})
