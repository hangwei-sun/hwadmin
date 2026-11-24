import { ApiError, unwrapResponse } from './protocol.ts'

export interface RequestOptions {
  path: string
  method?: 'GET' | 'POST'
  data?: Record<string, unknown>
  authenticated?: boolean
}
export interface TransportOptions {
  url: string
  method: 'GET' | 'POST'
  data?: Record<string, unknown>
  header: Record<string, string>
  timeout: number
  success: (response: { statusCode: number; data: unknown }) => void
  fail: () => void
}
export interface ClientDependencies {
  baseURL: () => string
  token: () => string
  unauthorized: () => void
  transport: (options: TransportOptions) => void
}

export function createClient(deps: ClientDependencies) {
  return function request<T>(options: RequestOptions): Promise<T> {
    return new Promise((resolve, reject) => {
      // Only internal API paths are accepted; never send tokens to caller-supplied hosts.
      if (!/^\/[a-zA-Z0-9_/-]+$/.test(options.path) || options.path.startsWith('//')) {
        reject(new ApiError('接口路径无效', 0))
        return
      }
      const header: Record<string, string> = { 'Content-Type': 'application/json' }
      const token = deps.token()
      if (options.authenticated !== false && token) header.Authorization = `Bearer ${token}`
      deps.transport({
        url: `${deps.baseURL().replace(/\/$/, '')}${options.path}`,
        method: options.method || 'GET',
        data: options.data,
        header,
        timeout: 15000,
        success(response) {
          try {
            resolve(unwrapResponse<T>(response.statusCode, response.data))
          } catch (error) {
            if (error instanceof ApiError && error.code === 401 && options.authenticated !== false) {
              deps.unauthorized()
            }
            reject(error)
          }
        },
        fail() {
          reject(new ApiError('网络连接失败，请检查网络和接口域名', 0, 0))
        },
      })
    })
  }
}
