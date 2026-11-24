/**
 * 移动端外部凭据与环境配置集中检查器
 * 负责收集 API 根路径、微信小程序 AppID 等外部依赖项，返回结构化诊断状态
 */

export interface ExternalConfigItem {
  key: string
  title: string
  category: 'api' | 'wechat' | 'storage' | 'other'
  configured: boolean
  required: boolean
  valueMask: string
  description: string
}

export interface ExternalConfigReport {
  ready: boolean
  missingCount: number
  items: ExternalConfigItem[]
}

export function checkExternalConfig(): ExternalConfigReport {
  const items: ExternalConfigItem[] = []

  // 1. 服务端 API 地址
  const apiBase = import.meta.env.VITE_API_BASE_URL || ''
  items.push({
    key: 'VITE_API_BASE_URL',
    title: '后端 API 服务地址',
    category: 'api',
    required: true,
    configured: Boolean(apiBase),
    valueMask: apiBase ? (apiBase.length > 15 ? apiBase.substring(0, 10) + '...' : apiBase) : '未配置(使用相对路径)',
    description: '移动端请求后端的基准 HTTP/HTTPS 地址',
  })

  // 2. 微信小程序 AppID (仅公开客户端ID，AppSecret 严禁留在客户端)
  const wechatAppId = import.meta.env.VITE_WECHAT_MP_APPID || ''
  const hasWechatAppId = Boolean(wechatAppId) && !wechatAppId.includes('your-')
  items.push({
    key: 'VITE_WECHAT_MP_APPID',
    title: '微信小程序 AppID',
    category: 'wechat',
    required: false,
    configured: hasWechatAppId,
    valueMask: hasWechatAppId ? wechatAppId.substring(0, 3) + '****' + wechatAppId.slice(-3) : '未配置',
    description: '微信公众平台分配的小程序 AppID，用于小程序授权登录与真机预览',
  })

  // 3. 上传服务器地址
  const uploadBase = import.meta.env.VITE_UPLOAD_URL || apiBase || ''
  items.push({
    key: 'VITE_UPLOAD_URL',
    title: '文件存储上传基准地址',
    category: 'storage',
    required: false,
    configured: Boolean(uploadBase),
    valueMask: uploadBase ? '已配置' : '未配置',
    description: '会员上传头像和附件的存储接入点',
  })

  const missingRequired = items.filter((i) => i.required && !i.configured)

  return {
    ready: missingRequired.length === 0,
    missingCount: missingRequired.length,
    items,
  }
}
