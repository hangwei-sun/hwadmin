import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const API_BASE = projectConfig.apiBase || '/adminapi'

export const getList = () => {
  return request({
    url: `${API_BASE}/dashboard/notices`,
    method: 'get',
  })
}

export const readNotice = (data?: { id?: string; clear_all?: boolean }) => {
  return request({
    url: `${API_BASE}/dashboard/readNotice`,
    method: 'post',
    data,
  })
}
