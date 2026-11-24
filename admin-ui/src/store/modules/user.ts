/**
 * @description 登录、获取用户信息、退出登录、清除token逻辑，不建议修改
 */
import { useAclStore } from './acl'
import { useRoutesStore } from './routes'
import { useSettingsStore } from './settings'
import { useTabsStore } from './tabs'
import { getUserInfo, login, logout } from '/@/api/user'
import { storage, tokenName } from '/@/config'
import { getToken, removeToken, setToken } from '/@/utils/token'
import { gp } from '/@vab/plugins/vab'
import { projectConfig } from '/@/config/project'
import { defaultAvatar, normalizeAvatar } from '/@/utils/branding'

const userInfoStorageKey = `${projectConfig.storageNamespace}-userinfo`

const getStoredUserInfo = () => {
  try {
    const parsed = JSON.parse(localStorage.getItem(userInfoStorageKey) || '{}') as Record<string, any>
    if (Object.prototype.hasOwnProperty.call(parsed, 'token')) {
      delete parsed.token
      localStorage.setItem(userInfoStorageKey, JSON.stringify(parsed))
    }
    return parsed
  } catch {
    return {}
  }
}

const storedUserInfo = getStoredUserInfo()
storedUserInfo.avatar = normalizeAvatar(storedUserInfo.avatar)

export const useUserStore = defineStore('user', {
  state: (): UserModuleType => ({
    token: getToken() as string,
    username: storedUserInfo.nickname || storedUserInfo.username || '游客',
    avatar: storedUserInfo.avatar || defaultAvatar,
    userInfo: storedUserInfo,
  }),
  getters: {
    getToken: (state) => state.token,
    getUsername: (state) => state.username,
    getAvatar: (state) => state.avatar,
  },
  actions: {
    /**
     * @description 设置token
     * @param {*} token
     */
    setToken(token: string) {
      this.token = token
      setToken(token)
    },
    /**
     * @description 设置用户名
     * @param {*} username
     */
    setUsername(username: string) {
      this.username = username
      this.userInfo = { ...this.userInfo, nickname: username }
      localStorage.setItem(userInfoStorageKey, JSON.stringify(this.userInfo))
    },
    /**
     * @description 设置头像
     * @param {*} avatar
     */
    setAvatar(avatar: string) {
      const normalizedAvatar = normalizeAvatar(avatar) || defaultAvatar
      this.avatar = normalizedAvatar
      this.userInfo = { ...this.userInfo, avatar: normalizedAvatar }
      localStorage.setItem(userInfoStorageKey, JSON.stringify(this.userInfo))
    },
    setUserInfo(userInfo: Record<string, any>) {
      const { token: _token, ...safeUserInfo } = userInfo
      this.userInfo = { ...this.userInfo, ...safeUserInfo }
      this.username = this.userInfo.nickname || this.userInfo.username || this.username
      this.userInfo.avatar = normalizeAvatar(this.userInfo.avatar)
      this.avatar = this.userInfo.avatar || defaultAvatar
      localStorage.setItem(userInfoStorageKey, JSON.stringify(this.userInfo))
    },
    /**
     * @description 登录拦截放行时，设置虚拟角色
     */
    setVirtualRoles() {
      const aclStore = useAclStore()
      aclStore.setFull(true)
      this.setUsername('admin(未开启登录拦截)')
      this.setAvatar(defaultAvatar)
    },
    /**
     * @description 设置token并发送提醒
     * @param {string} token 更新令牌
     * @param {string} tokenName 令牌名称
     */
    afterLogin(token: string, tokenName: string) {
      const settingsStore = useSettingsStore()
      if (token) {
        this.setToken(token)
        const hour = new Date().getHours()
        const thisTime = hour < 8 ? '早上好' : hour <= 11 ? '上午好' : hour <= 13 ? '中午好' : hour < 18 ? '下午好' : '晚上好'
        gp.$baseNotify(`欢迎登录${settingsStore.title}`, `${thisTime}！`)
      } else {
        const err = `登录接口异常，未正确返回${tokenName}...`
        gp.$baseMessage(err, 'error', 'hey')
        throw err
      }
    },
    /**
     * @description 登录
     * @param {*} userInfo
     */
    async login(userInfo: any) {
      const { data } = await login(userInfo)
      // 兼容 HwAdmin 后台 ({ token, admin }) 与会员接口 ({ userinfo: { token } })
      const profile = data?.admin || data?.user || data?.userinfo
      const token = data?.token || profile?.token
      this.afterLogin(token, tokenName)
      if (profile) this.setUserInfo(profile)
      // 每次登录都重新获取当前账号的权限与菜单，且仅由路由守卫请求一次用户信息。
      useRoutesStore().$reset()
    },
    /**
     * @description 获取用户信息接口 这个接口非常非常重要，如果没有明确底层前逻辑禁止修改此方法，错误的修改可能造成整个框架无法正常使用
     * @returns
     */
    async getUserInfo() {
      const { data } = await getUserInfo()
      const aclStore = useAclStore()
      // HwAdmin 后台返回 { admin, permissions }；会员探针返回 { welcome }
      if (data?.admin) {
        this.setUserInfo(data.admin)
      } else if (data?.welcome) {
        this.setUsername(data.welcome)
      }
      if (data?.site) {
        const settingsStore = useSettingsStore()
        if (data.site.site_name) settingsStore.updateTitle(data.site.site_name)
        if (data.site.site_logo !== undefined) settingsStore.updateLogo(data.site.site_logo)
        if (data.site.site_favicon !== undefined) settingsStore.updateFavicon(data.site.site_favicon)
        if (data.site.login_image !== undefined) settingsStore.updateLoginImage(data.site.login_image)
        if (data.site.default_avatar !== undefined) settingsStore.updateDefaultAvatar(data.site.default_avatar)
        if (data.site.site_copyright) settingsStore.updateCopyright(data.site.site_copyright)
        if (data.site.beian !== undefined) settingsStore.updateBeian(data.site.beian)
      }
      const isSuperFlag = Boolean(data?.is_super || data?.admin?.is_super)
      if (Array.isArray(data?.permissions)) {
        const isSuper = data.permissions.includes('*') || isSuperFlag
        aclStore.setFull(isSuper)
        aclStore.setPermission(data.permissions)
      } else {
        aclStore.setFull(isSuperFlag)
        aclStore.setPermission([])
      }
    },
    /**
     * @description 退出登录
     */
    async logout() {
      await logout()
      await this.resetAll()
    },
    /**
     * @description 重置token、roles、permission、router、tabsBar等
     */
    async resetAll() {
      const aclStore = useAclStore()
      const tabsStore = useTabsStore()

      // 清除token
      await removeToken(storage)
      this.token = ''

      // 清空权限和角色
      await aclStore.setPermission([])
      await aclStore.setFull(false)
      await aclStore.setRole([])

      // 清除tabs
      await tabsStore.delAllVisitedRoutes()

      // 清除localStorage中的相关缓存
      if (storage === 'localStorage') {
        // 只移除用户相关数据，不影响主题等设置
        localStorage.removeItem('caughtRoutes')
        localStorage.removeItem(userInfoStorageKey)
      }

      // 重置用户信息
      this.username = '游客'
      this.avatar = defaultAvatar
      this.userInfo = {}
    },
  },
})
