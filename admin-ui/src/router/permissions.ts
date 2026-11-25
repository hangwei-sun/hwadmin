/**
 * @description 路由守卫，目前两种模式：all模式与intelligence模式
 */
import VabProgress from 'nprogress'
import 'nprogress/nprogress.css'
import type { Router } from 'vue-router'
import { authentication, loginInterception, rolesControl, routesWhiteList, supportVisit } from '/@/config'
import { useRoutesStore } from '/@/store/modules/routes'
import { useSettingsStore } from '/@/store/modules/settings'
import { useUserStore } from '/@/store/modules/user'
import getPageTitle from '/@/utils/pageTitle'
import { isChunkLoadError, recoverFromChunkError } from '/@/utils/chunkRecovery'
import { handleActivePath, toLoginRoute } from '/@/utils/routes'

export const setupPermissions = (router: Router) => {
  VabProgress.configure({
    easing: 'ease',
    speed: 500,
    trickleSpeed: 200,
    showSpinner: false,
  })
  router.beforeEach(async (to, from, next) => {
    const {
      getTheme: { showProgressBar },
    } = useSettingsStore()
    const { routes, setRoutes } = useRoutesStore()
    const { token, getUserInfo, setVirtualRoles, resetAll } = useUserStore()

    if (showProgressBar) VabProgress.start()

    let hasToken = token

    if (!loginInterception) hasToken = true

    if (hasToken) {
      if (routes.length > 0) {
        // 禁止已登录用户返回登录页
        if (to.path === '/login') {
          next({ path: '/' })
          if (showProgressBar) VabProgress.done()
        } else next()
      } else {
        try {
          if (loginInterception) {
            if (rolesControl) {
              await getUserInfo()
              await setRoutes(authentication)
            } else {
              // rolesControl 为 false 时路由过滤不依赖前端角色/权限表，可并发拉取用户信息与菜单路由
              await Promise.all([getUserInfo(), setRoutes(authentication)])
            }
          } else {
            // config/setting.config.js loginInterception为false(关闭登录拦截时)时，创建虚拟角色
            setVirtualRoles()
            await setRoutes(authentication)
          }
          next({ ...to, replace: true })
        } catch (error) {
          console.error('HwAdmin 路由错误:', error)
          await resetAll()
          if (to.path === '/login') next()
          else next(toLoginRoute(to.fullPath))
        }
      }
    } else {
      if (routesWhiteList.includes(to.path)) {
        // 设置游客路由(不需要可以删除)
        if (supportVisit && routes.length === 0) {
          await setRoutes('visit')
          next({ path: to.path, replace: true })
        } else next()
      } else next(toLoginRoute(to.fullPath))
    }
  })
  router.afterEach((to) => {
    if (typeof to.meta.title === 'string') document.title = getPageTitle(to.meta.title)
    if (VabProgress.status) VabProgress.done()
    const routesStore = useRoutesStore()
    if (to.matched.length > 0) {
      if (routesStore.tab.data !== to.matched[0].name) {
        routesStore.tab.data = to.matched[0].name as string
      }
      routesStore.changeActiveMenu(handleActivePath(to))
    }
  })

  router.onError((error: any) => {
    console.error('HwAdmin 路由错误:', error?.message || error)
    if (isChunkLoadError(error)) {
      recoverFromChunkError()
    }
  })

  return router
}
