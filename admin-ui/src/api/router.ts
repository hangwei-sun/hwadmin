import { projectConfig } from '/@/config/project'
import request from '/@/utils/request'

export const getList = (params?: any) => {
  return request({
    url: `${projectConfig.apiBase}/index/menus`,
    method: 'get',
    params,
  })
}
