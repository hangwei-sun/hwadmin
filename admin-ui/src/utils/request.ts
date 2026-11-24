import { stringify } from 'qs'
import { contentType, debounce, messageName, statusName, timeout } from '/@/config'
import router from '/@/router'
import { useSettingsStore } from '/@/store/modules/settings'
import { useUserStore } from '/@/store/modules/user'
import { addErrorLog, needErrorLog } from '/@vab/plugins/errorLog'
import { gp } from '/@vab/plugins/vab'
import { serverOrigin } from '/@/utils/platform'
import { isBackendApi } from '../../packages/pc-core/src/desktop.mjs'

interface LoadingInstance {
  close: () => void
}

let loadingInstance: LoadingInstance | null = null

const CODE_MESSAGE: Record<number, string> = {
  200: '服务器成功返回请求数据',
  201: '新建或修改数据成功',
  202: '一个请求已经进入后台排队(异步任务)',
  204: '删除数据成功',
  205: '后端code指令强制开启锁屏',
  400: '发出信息有误',
  401: '用户没有权限(令牌失效、用户名、密码错误、登录过期)',
  402: '令牌过期',
  403: '用户得到授权，但是访问是被禁止的',
  404: '访问资源不存在',
  406: '请求格式不可得',
  410: '请求资源被永久删除，且不会被看到',
  500: '服务器发生错误',
  502: '网关错误',
  503: '服务不可用，服务器暂时过载或维护',
  504: '网关超时',
}

/**
 * axios请求拦截器配置
 * @param config
 * @returns {any}
 */
const requestConfig = (config: any): any => {
  const userStore = useUserStore()
  const { token } = userStore

  // Web 使用同源代理，桌面包使用唯一配置的 HwAdmin 服务端。
  if (typeof config.url === 'string' && /^\/(?:adminapi|api)(?:\/|$)/.test(config.url)) {
    config.baseURL = serverOrigin
  }

  // 统一注入 HwAdmin 令牌凭据
  const requestUrl = new URL(config.url || '', new URL(config.baseURL || '/', window.location.origin))
  if (token && config.headers && isBackendApi(requestUrl.href, serverOrigin)) {
    config.headers['X-Token'] = token
    config.headers['Authorization'] = `Bearer ${token}`
  }

  // 上传文件时由浏览器自动计算 multipart boundary，避免丢失
  if (config.data instanceof FormData && config.headers) {
    delete config.headers['Content-Type']
    delete config.headers['content-type']
  }

  if (
    config.data &&
    !(config.data instanceof FormData) &&
    config.headers &&
    config.headers['Content-Type'] === 'application/x-www-form-urlencoded;charset=UTF-8'
  ) {
    config.data = stringify(config.data)
  }

  if (config.url && debounce.some((item: string) => config.url.includes(item))) {
    loadingInstance = gp.$baseLoading()
  }
  return config
}

/**
 * axios响应拦截器
 * @param response {any} 响应对象
 * @returns {Promise<any>}
 */
const handleData = async (response: any): Promise<any> => {
  const { resetAll } = useUserStore()
  if (loadingInstance) loadingInstance.close()

  const { data, status, statusText } = response

  // 提取业务状态码：优先读取 data.code，若不存在则回退至 HTTP status
  let code: number | string = status
  if (data !== null && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, statusName)) {
    code = data[statusName]
  }
  if (typeof code === 'string' && /^\d+$/.test(code)) {
    code = Number(code)
  }

  // HTTP failures must not be masked by a successful or unrelated business code.
  if (status >= 400) code = status

  // 成功判定：HwAdmin 标准为 1，HTTP 标准为 200
  const isSuccess = code === 1 || code === 200

  if (isSuccess) {
    return data
  }

  switch (code) {
    case 205: {
      // 屏幕锁定
      const settingsStore = useSettingsStore()
      settingsStore.handleLock()
      setTimeout(() => {
        gp.$baseMessage(CODE_MESSAGE[205] || '已开启锁屏', 'success', 'hey')
      }, 1500)
      return data
    }
    case 401: {
      resetAll().then(() => {
        router.push({ path: '/login', replace: true }).then(() => {})
      })
      break
    }
    case 403: {
      router.push({ path: '/403' }).then(() => {})
      break
    }
  }

  // 错误提示提取：优先使用后端返回的 msg，其次根据状态码映射通用消息
  const errMsg =
    data && typeof data === 'object' && data[messageName]
      ? String(data[messageName])
      : typeof code === 'number' && CODE_MESSAGE[code]
        ? CODE_MESSAGE[code]
        : statusText || '接口响应异常'

  gp.$baseMessage(errMsg, 'error', 'hey')
  if (needErrorLog()) addErrorLog({ message: errMsg, stack: data, isRequest: true })
  return Promise.reject(data || new Error(errMsg))
}

/**
 * @description axios初始化
 */
const instance = axios.create({
  baseURL: `${import.meta.env.VITE_APP_BASE_URL}`,
  timeout,
  headers: {
    'Content-Type': contentType,
  },
})

/**
 * @description axios请求拦截器
 */
instance.interceptors.request.use(requestConfig, (error) => {
  return Promise.reject(error)
})

/**
 * @description axios响应拦截器
 */
instance.interceptors.response.use(
  (response) => handleData(response),
  (error) => {
    const { response } = error
    if (response === undefined) {
      if (loadingInstance) loadingInstance.close()
      gp.$baseMessage(
        '连接后台接口失败，请检查网络连接或确认后端服务已启动',
        'error',
        'hey'
      )
      return Promise.reject(error)
    } else {
      return handleData(response)
    }
  }
)

export default instance
