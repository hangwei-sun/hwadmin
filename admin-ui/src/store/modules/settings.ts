/**
 * @description 所有全局配置的状态管理，如无必要请勿修改
 */

import {
  persistenceTab as _persistenceTab,
  color,
  colorWeakness,
  columnStyle,
  fixedHeader,
  foldSidebar,
  fontSize,
  i18n,
  isFollow,
  layout,
  logo,
  menuWidth,
  pageTransition,
  radius,
  rightToolsDrag,
  showBox,
  showColorPicker,
  showDark,
  showDeepSeek,
  showFontSize,
  showFooter,
  showFullScreen,
  showLanguage,
  showLock,
  showNotice,
  showProgressBar,
  showRefresh,
  showSearch,
  showTabs,
  showTabsIcon,
  showTheme,
  showThemeSetting,
  tabDrag,
  tabsBarStyle,
  themeName,
} from '/@/config'
import { themeConfig } from '/@/config/theme.config'
import { colorRgba, lightenColorChrome } from '/@/utils/lightenColor'
import { getLocalStorage } from '/@/utils/localStorage'
import { projectConfig } from '/@/config/project'

import { defaultAvatar, defaultBrandImages, defaultBrandLogo, defaultFavicon, normalizeAvatar, resolveConfiguredAsset } from '/@/utils/branding'

const themeStorageKey = `${projectConfig.storageNamespace}-theme`
let scrollTopPersistTimer: ReturnType<typeof setTimeout> | null = null
const storedLogo = localStorage.getItem('site_logo') || getLocalStorage('logo').logo || logo
const normalizeLogo = (value: string) => !value || ['vite', 'vab', 'hwadmin'].includes(value) ? defaultBrandLogo : value
const initialLogo = normalizeLogo(storedLogo)
// 仅迁移历史主题偏好；不再用旧模板命名保存新数据。
try {
  const legacyThemeKey = 'shop-vite-theme'
  const legacyTheme = getLocalStorage(legacyThemeKey)
  if (!localStorage.getItem(themeStorageKey) && legacyTheme && typeof legacyTheme === 'object' && !Array.isArray(legacyTheme)) {
    localStorage.setItem(themeStorageKey, JSON.stringify(legacyTheme))
  }
  localStorage.removeItem(legacyThemeKey)
} catch {
  // 存储不可用时继续使用默认主题。
}

const defaultTheme: ThemeType = {
  color,
  colorWeakness,
  columnStyle,
  fixedHeader,
  foldSidebar,
  isFollow,
  layout,
  menuWidth,
  pageTransition,
  radius,
  showColorPicker,
  showDark,
  showFooter,
  showFullScreen,
  showLanguage,
  showLock,
  showNotice,
  showProgressBar,
  showRefresh,
  showSearch,
  showTabs,
  showTabsIcon,
  showTheme,
  showThemeSetting,
  tabsBarStyle,
  themeName,
  showFontSize,
  tabDrag,
  fontSize,
  rightToolsDrag,
  showBox,
  showDeepSeek,
  glassMode: themeConfig.glassMode,
  glassOpacity: themeConfig.glassOpacity,
}

const { collapse = foldSidebar } = getLocalStorage('collapse')
const { persistenceTab = _persistenceTab } = getLocalStorage('persistenceTab')

function applyFavicon(value: string) {
  if (typeof document === 'undefined') return
  let links = Array.from(document.querySelectorAll<HTMLLinkElement>("link[rel~='icon']"))
  if (links.length === 0) {
    const link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
    links = [link]
  }
  const href = resolveConfiguredAsset(value || defaultFavicon)
  links.forEach((link) => {
    link.href = href
    link.removeAttribute('type')
  })
}

const initialFavicon = localStorage.getItem('site_favicon') || defaultFavicon
applyFavicon(initialFavicon)

// 兼容 store 外部调用
function setGlassModeClass(val: boolean) {
  const body = document.body
  if (val) {
    body.classList.add('glass-mode')
  } else {
    body.classList.remove('glass-mode')
  }
  // 更新CSS变量
  const settingsStore = useSettingsStore()
  settingsStore.setCssVar()
}

export { setGlassModeClass }

export const useSettingsStore = defineStore('settings', {
  state: (): SettingsModuleType => ({
    collapse,
    device: 'desktop',
    language: getLocalStorage('language').language || i18n,
    lock: getLocalStorage('lock').lock || false,
    logo: initialLogo,
    mode: localStorage.getItem('vueuse-color-scheme') || 'light',
    persistenceTab,
    theme: { ...defaultTheme, ...getLocalStorage(themeStorageKey) },
    title: localStorage.getItem('site_name') || projectConfig.title,
    scrollTop: JSON.parse(localStorage.getItem('scrollTop') || '[]'),
    copyright: localStorage.getItem('site_copyright') || 'Copyright © 2026 HwAdmin. All rights reserved.',
    beian: localStorage.getItem('site_beian') || '',
    favicon: initialFavicon,
    loginImage: localStorage.getItem('site_login_image') || defaultBrandImages.login_image,
    defaultAvatar: normalizeAvatar(localStorage.getItem('site_default_avatar') || '') || defaultAvatar,
  }),
  getters: {
    getCollapse: (state) => state.collapse,
    getDevice: (state) => state.device,
    getPersistenceTab: (state) => state.persistenceTab,
    getLanguage: (state) => state.language,
    getLock: (state) => state.lock,
    getLogo: (state) => state.logo,
    getMode: (state) => state.mode,
    getTheme: (state) => state.theme,
    getTitle: (state) => state.title,
    getScrollTop: (state) => state.scrollTop,
    getCopyright: (state) => state.copyright,
    getBeian: (state) => state.beian,
    getFavicon: (state) => state.favicon,
    getLoginImage: (state) => state.loginImage,
    getDefaultAvatar: (state) => state.defaultAvatar,
  },
  actions: {
    updateState(obj: any) {
      Object.getOwnPropertyNames(obj).forEach((key) => {
        // @ts-ignore
        this[key] = obj[key]
        localStorage.setItem(key, typeof obj[key] == 'string' ? `{"${key}":"${obj[key]}"}` : `{"${key}":${obj[key]}}`)
      })
    },
    updateMode(value: any) {
      this.mode = value
    },
    saveTheme() {
      localStorage.setItem(themeStorageKey, JSON.stringify(this.theme))
    },
    resetTheme() {
      this.theme = { ...defaultTheme }
      this.persistenceTab = _persistenceTab
      this.changeLanguage(i18n)
      if (this.device === 'mobile')
        this.theme = {
          ...defaultTheme,
          layout: 'vertical',
        }
      localStorage.removeItem(themeStorageKey)
      this.updateTheme()
      setGlassModeClass(!!this.theme.glassMode)
      // 更新CSS变量
      this.setCssVar()
    },
    updateTheme() {
      document.querySelectorAll('body')[0].className = `vab-theme-${this.theme.themeName}`

      if (this.theme.themeName === 'default') {
        const colorScheme = localStorage.getItem('vueuse-color-scheme')
        const htmlElement = document.querySelectorAll('html')[0]
        htmlElement.className += ` ${colorScheme}`
        this.mode = colorScheme as string
      } else {
        document.querySelectorAll('html')[0].className = ''
        localStorage.setItem('vueuse-color-scheme', 'light')
        this.mode = 'light'
      }

      this.setCssVar()
    },
    setCssVar() {
      /**
       * @description 主题配置，如不精通前端css样式请勿修改
       * @author sundan
       */
      const el = ref<HTMLElement | null>(null)

      //菜单宽度
      if (this.theme.menuWidth && this.theme.menuWidth.endsWith('px')) useCssVar('--el-left-menu-width', el).value = this.theme.menuWidth
      else useCssVar('--el-left-menu-width', el).value = '266px'
      //tabs处理
      if (this.theme.showTabs) {
        useCssVar('--el-tabs-height', el).value = '50px'
      } else {
        useCssVar('--el-tabs-height', el).value = '0px'
      }
      //页脚处理
      if (this.theme.showFooter) {
        useCssVar('--el-footer-height', el).value = '50px'
      } else {
        useCssVar('--el-footer-height', el).value = '-20px'
      }
      //圆角处理
      if (this.theme.radius) {
        useCssVar('--el-border-radius-base', el).value = `${this.theme.radius}px`
      } else {
        useCssVar('--el-border-radius-base', el).value = '5px'
      }
      //分栏一级菜单跟随背景色处理
      if (this.theme.isFollow) {
        useCssVar('--el-menu-background-color', el).value = lightenColorChrome(this.theme.color, 18)
      } else {
        useCssVar('--el-menu-background-color', el).value = '#282c34'
      }
      //主题色处理
      useCssVar('--el-color-primary-dark-2', el).value = this.theme.color
      useCssVar('--el-color-primary', el).value = this.theme.color
      for (let index = 1; index < 10; index++) {
        useCssVar(`--el-color-primary-light-${index}`, el).value = colorRgba(this.theme.color, 1 - index * 0.1)
      }
      //色弱处理
      if (this.theme.colorWeakness) document.querySelectorAll('body')[0].classList.add('color-weakness')
      else document.querySelectorAll('body')[0].classList.remove('color-weakness')
      //字体大小处理
      useCssVar('--el-font-size-base', el).value = this.theme.fontSize
      // 液态玻璃透明度
      document.documentElement.style.setProperty('--glass-opacity', String(this.theme.glassOpacity ?? 0.85))
    },
    toggleCollapse() {
      this.collapse = !this.collapse
      localStorage.setItem('collapse', `{"collapse":${this.collapse}}`)
    },
    toggleDevice(device: string) {
      this.updateState({ device })
    },
    openSideBar() {
      this.updateState({ collapse: false })
    },
    foldSideBar() {
      this.updateState({ collapse: true })
    },
    changeLanguage(language: string) {
      this.updateState({ language })
    },
    handleLock() {
      this.updateState({ lock: true })
    },
    handleUnLock() {
      this.updateState({ lock: false })
    },
    updateCaughtTabs(value: any) {
      this.updateState({ persistenceTab: value })
      if (!value) localStorage.removeItem('caughtRoutes')
    },
    changeLogo(logo: string) {
      this.updateState({ logo })
    },
    changeTitle(title: string) {
      this.updateState({ title })
    },
    updateTitle(val: string) {
      this.title = val
      localStorage.setItem('site_name', val)
    },
    updateLogo(val: string) {
      this.logo = normalizeLogo(val)
      localStorage.setItem('site_logo', this.logo)
    },
    updateCopyright(val: string) {
      this.copyright = val
      localStorage.setItem('site_copyright', val)
    },
    updateBeian(val: string) {
      this.beian = val
      localStorage.setItem('site_beian', val)
    },
    updateFavicon(val: string) {
      this.favicon = val || defaultFavicon
      localStorage.setItem('site_favicon', this.favicon)
      applyFavicon(this.favicon)
    },
    updateLoginImage(val: string) {
      this.loginImage = val || defaultBrandImages.login_image
      localStorage.setItem('site_login_image', this.loginImage)
    },
    updateDefaultAvatar(val: string) {
      this.defaultAvatar = normalizeAvatar(val) || defaultAvatar
      localStorage.setItem('site_default_avatar', this.defaultAvatar)
    },
    updateScrollTop(scrollTop: number, routeName: any) {
      if (!routeName) return
      const routeKey = String(routeName)
      const originalArray = Array.isArray(this.scrollTop)
        ? [...this.scrollTop]
        : [...JSON.parse(localStorage.getItem('scrollTop') || '[]')]

      interface Item {
        routeName: string
        scrollTop: number
      }

      let found = false
      const modifiedArray: Item[] = originalArray.map((item: Item) => {
        if (item.routeName === routeKey) {
          found = true
          return { ...item, scrollTop }
        }
        return item
      })
      if (!found) {
        modifiedArray.push({ routeName: routeKey, scrollTop })
      }

      const filteredArray = modifiedArray.filter((item) => item.scrollTop !== 0)
      this.scrollTop = filteredArray

      if (scrollTopPersistTimer) clearTimeout(scrollTopPersistTimer)
      scrollTopPersistTimer = setTimeout(() => {
        scrollTopPersistTimer = null
        try {
          localStorage.setItem('scrollTop', JSON.stringify(this.scrollTop))
        } catch {
          // ignore storage quota errors
        }
      }, 300)
    },
  },
})
