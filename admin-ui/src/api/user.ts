import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const apiBase = projectConfig.apiBase || '/adminapi'

export interface LoginParams {
  account?: string
  username?: string
  password: string
  captcha_key?: string
  captcha_code?: string
}

export const login = (data: LoginParams) =>
  request({
    url: `${apiBase}/index/login`,
    method: 'post',
    data: {
      username: data.username || data.account || '',
      password: data.password,
      captcha_key: data.captcha_key,
      captcha_code: data.captcha_code,
    },
  })

export const getCaptcha = () =>
  request({
    url: `${apiBase}/index/captcha`,
    method: 'get',
  })

export const getUserInfo = () => request({ url: `${apiBase}/index/info`, method: 'get' })

export const logout = () => request({ url: `${apiBase}/index/logout`, method: 'post' })
