/** Framework-independent configuration. Same-origin APIs only in the native profile. */
export function resolveProjectConfig(input = {}) {
  const apiBase = input.apiBase ?? '/adminapi'
  if (typeof apiBase !== 'string' || !/^\/(?!\/)[A-Za-z0-9_/-]+$/.test(apiBase) || apiBase.includes('//')) {
    throw new Error('HwAdmin apiBase must be a same-origin absolute path, e.g. /adminapi')
  }
  const tokenHeader = input.tokenHeader ?? 'X-Token'
  if (typeof tokenHeader !== 'string' || !/^[A-Za-z0-9-]+$/.test(tokenHeader)) throw new Error('Invalid tokenHeader')
  const storageNamespace = input.storageNamespace ?? 'hwadmin'
  if (typeof storageNamespace !== 'string' || !/^[A-Za-z0-9_-]+$/.test(storageNamespace)) throw new Error('Invalid storageNamespace')
  if (input.demoEnabled !== undefined && typeof input.demoEnabled !== 'boolean') throw new Error('demoEnabled must be boolean')
  return Object.freeze({
    title: typeof input.title === 'string' && input.title.trim() ? input.title.trim() : 'HwAdmin',
    apiBase: apiBase.replace(/\/$/, ''),
    tokenHeader,
    storageNamespace,
    demoEnabled: input.demoEnabled ?? false,
  })
}

export function isHwAdminSuccess(data, httpStatus) {
  return httpStatus >= 200 && httpStatus < 300 && data !== null && typeof data === 'object' && [1, '1', 200, '200'].includes(data.code)
}
