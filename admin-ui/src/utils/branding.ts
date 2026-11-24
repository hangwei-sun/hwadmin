import { isDesktop, serverUrl } from './platform'

const resolveDefaultBase = () => {
  if (!isDesktop && import.meta.env.BASE_URL === './') {
    try {
      return new URL('../../', import.meta.url).href
    } catch {
      return '/admin/'
    }
  }
  return import.meta.env.BASE_URL
}

const withBase = (path: string, baseUrl = resolveDefaultBase(), documentUrl = document.baseURI) =>
  new URL(`${baseUrl}${path}`, documentUrl).href

export const resolveBrandAsset = (path: string, baseUrl?: string, documentUrl?: string) => withBase(path, baseUrl, documentUrl)
export const resolveConfiguredAsset = (value: string = '') => {
  const normalized = value.trim()
  if (!normalized) return ''
  if (/^(?:https?:|data:|blob:)/i.test(normalized)) return normalized
  const path = normalized.startsWith('/') ? normalized : `/${normalized}`
  return isDesktop ? serverUrl(path) : path
}

const asset = (name: string) => resolveBrandAsset(`brand/${name}`)

export const defaultBrandLogo = asset('logo.svg')
export const defaultFavicon = resolveBrandAsset('favicon.svg')

export const defaultLoginImage = asset('login-illustration.webp')
export const defaultAvatar = asset('default-avatar.webp')
export const defaultBrandImages: Record<string, string> = {
  site_logo: defaultBrandLogo,
  site_favicon: defaultFavicon,
  login_image: defaultLoginImage,
  default_avatar: defaultAvatar,
}

export const normalizeAvatar = (value: string = '') =>
  /(?:^|\/)static\/svg\/avatar\.svg(?:[?#].*)?$/.test(value) ? '' : isDesktop && value ? serverUrl(value) : value
