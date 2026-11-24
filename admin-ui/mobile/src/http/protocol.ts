export interface ApiResponse<T> {
  code: number
  msg: string
  data: T
  time: number
}
export interface PageResult<T> {
  list: T[]
  total: number
}

export class ApiError extends Error {
  readonly code: number
  readonly status: number
  constructor(message: string, code: number, status = 200) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
  }
}

export function unwrapResponse<T>(status: number, body: unknown): T {
  if (status === 401) throw new ApiError('登录已失效，请重新登录', 401, status)
  if (status === 403) throw new ApiError('暂无访问权限', 403, status)
  if (status < 200 || status >= 300) throw new ApiError('服务暂不可用，请稍后重试', 0, status)
  if (!body || typeof body !== 'object' || !('code' in body) || typeof body.code !== 'number') {
    throw new ApiError('接口响应格式不符合 HwAdmin 协议', 0, status)
  }
  const response = body as ApiResponse<T>
  if (response.code !== 1) {
    throw new ApiError(response.msg || '操作未完成', response.code, status)
  }
  return response.data
}
