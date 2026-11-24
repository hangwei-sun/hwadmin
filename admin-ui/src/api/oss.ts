import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const API_BASE = projectConfig.apiBase || '/adminapi'
const OSS_BASE = `${API_BASE}/plugin/oss/config`

export interface CosConfig {
  secret_id: string
  secret_key: string
  bucket: string
  region: string
  domain: string
  prefix: string
}

export interface AliyunOssConfig {
  access_key_id: string
  access_key_secret: string
  bucket: string
  endpoint: string
  domain: string
  prefix: string
}

export interface QiniuConfig {
  access_key: string
  secret_key: string
  bucket: string
  zone: string
  domain: string
  prefix: string
}

export interface OssConfigData {
  mock_mode: number
  cos: CosConfig
  oss: AliyunOssConfig
  qiniu: QiniuConfig
}

export interface OssConfigResponse {
  upload_storage: string
  storage_options: Record<string, string>
  config: OssConfigData
  stats: {
    total_count: number
    cos_count: number
    oss_count: number
    qiniu_count: number
    total_size: number
  }
}

export interface OssLogItem {
  id: number
  driver: 'cos' | 'oss' | 'qiniu'
  bucket: string
  object_key: string
  url: string
  original_name: string
  mime_type: string
  size: number
  sha1: string
  is_mock: number
  cost_ms: number
  status: number
  error_msg: string
  admin_id: number
  create_time: number
}

/**
 * 获取对象云存储配置及运行统计
 */
export const getOssConfig = () => {
  return request<OssConfigResponse>({
    url: `${OSS_BASE}/index`,
    method: 'get',
  })
}

/**
 * 保存全局存储路由及三大云参数
 */
export const saveOssConfig = (data: { upload_storage?: string; config?: Partial<OssConfigData> }) => {
  return request({
    url: `${OSS_BASE}/save`,
    method: 'post',
    data,
  })
}

/**
 * 在线测试连通性与签名计算
 */
export const testOssConnection = (data: { driver: string; driver_config?: any; mock_mode?: number }) => {
  return request({
    url: `${OSS_BASE}/test`,
    method: 'post',
    data,
  })
}

/**
 * 查询云存储上传流水日志
 */
export const getOssLogs = (params?: { driver?: string; search?: string; page?: number; limit?: number }) => {
  return request<{ list: OssLogItem[]; total: number }>({
    url: `${OSS_BASE}/logs`,
    method: 'get',
    params,
  })
}

/**
 * 删除指定上传流水记录
 */
export const delOssLogs = (ids: (number | string)[] | number | string) => {
  return request({
    url: `${OSS_BASE}/delLog`,
    method: 'post',
    data: { ids: Array.isArray(ids) ? ids : [ids] },
  })
}
