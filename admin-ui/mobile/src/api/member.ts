import { request } from '@/http'

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
  user: Member
}

export const login = (account: string, password: string) =>
  request<LoginResult>({
    path: '/user/login',
    method: 'POST',
    data: { account, password },
    authenticated: false,
  })
export const getProfile = () => request<Member>({ path: '/user/info' })
export const logout = () => request<null>({ path: '/user/logout', method: 'POST' })
export const ping = () =>
  request<{ version: string; installed: boolean }>({
    path: '/index/ping',
    authenticated: false,
  })
