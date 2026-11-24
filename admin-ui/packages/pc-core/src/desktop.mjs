/** Desktop APIs are bound to one explicitly configured server origin. */
export function resolveServerOrigin(desktop, configuredOrigin, pageOrigin) {
  if (!desktop) return pageOrigin
  const url = new URL(configuredOrigin || 'http://127.0.0.1:8000')
  const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && loopback)) ||
      url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw new Error('桌面服务端地址必须是 HTTPS 源地址（本机开发允许 HTTP），不能包含路径、凭据或参数')
  }
  return url.origin
}

export function isBackendApi(url, serverOrigin) {
  const target = new URL(url, `${serverOrigin}/`)
  return target.origin === serverOrigin && /^\/(?:adminapi|api)(?:\/|$)/.test(target.pathname)
}
