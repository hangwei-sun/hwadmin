import { request, TOKEN_KEY } from '@/http'

export interface Member {
  id: number
  username: string
  nickname: string
  avatar: string
  email: string
  mobile: string
  level: number
  score: number
}

export interface LoginResult {
  token: string
  expires_in: number
  openid?: string
  unionid?: string
  is_new?: boolean
  user: Member
}

export interface RegisterParams {
  [key: string]: unknown
  username: string
  password: string
  nickname?: string
  mobile?: string
}

export interface SendResetCodeParams {
  [key: string]: unknown
  account: string
}

export interface SendResetCodeResult {
  expires_in: number
  cooldown: number
  reset_token?: string
}

export interface ResetPasswordParams {
  [key: string]: unknown
  account?: string
  new_password: string
  reset_token?: string
  old_password?: string
}

export interface UploadAvatarResult {
  url: string
  file_name: string
  mime?: string
  width?: number
  height?: number
}

export const login = (account: string, password: string) =>
  request<LoginResult>({
    path: '/user/login',
    method: 'POST',
    data: { account, password },
    authenticated: false,
  })

export const register = (data: RegisterParams) =>
  request<LoginResult>({
    path: '/user/register',
    method: 'POST',
    data,
    authenticated: false,
  })

export const sendResetCode = (data: SendResetCodeParams) =>
  request<SendResetCodeResult>({
    path: '/user/sendResetCode',
    method: 'POST',
    data,
    authenticated: false,
  })

export const resetPassword = (data: ResetPasswordParams) =>
  request<null>({
    path: '/user/resetPassword',
    method: 'POST',
    data,
    authenticated: Boolean(data.old_password && !data.reset_token),
  })

export const wechatLogin = (code: string, profileHint?: { nickname?: string; avatar?: string }) =>
  request<LoginResult>({
    path: '/user/wechatLogin',
    method: 'POST',
    data: { code, ...profileHint },
    authenticated: false,
  })

export const getProfile = () => request<Member>({ path: '/user/info' })

export const logout = () => request<null>({ path: '/user/logout', method: 'POST' })

export const uploadAvatar = (filePath: string) =>
  new Promise<UploadAvatarResult>((resolve, reject) => {
    const origin = (import.meta.env.VITE_API_ORIGIN || '').replace(/\/$/, '')
    const url = (origin ? `${origin}/api` : '/api') + '/user/upload'
    uni.uploadFile({
      url,
      filePath,
      name: 'file',
      header: {
        Authorization: 'Bearer ' + String(uni.getStorageSync(TOKEN_KEY) || ''),
      },
      success: (uploadRes) => {
        try {
          const data = JSON.parse(uploadRes.data)
          if (data.code === 1) {
            resolve(data.data)
          } else {
            reject(new Error(data.msg || '上传失败'))
          }
        } catch {
          reject(new Error('上传响应解析失败'))
        }
      },
      fail: (err) => reject(new Error(err.errMsg || '上传网络错误')),
    })
  })
