import { stringify } from 'qs'
import { recordRoute } from '/@/config'
import { useTabsStore } from '/@/store/modules/tabs'
import { hasPermission } from '/@/utils/permission'
import { isExternal } from '/@/utils/validate'

/**
 * @description all模式渲染后端返回路由,支持包含views路径的所有页面
 * @param asyncRoutes
 * @param isRoot 是否为顶级根路由
 * @returns {*}
 */
export const convertRouter = (asyncRoutes: VabRouteRecord[], isRoot = true): VabRouteRecord[] => {
  const routeAllPathToCompMap: Record<string, any> = import.meta.glob(`../**/*.vue`)
  const defaultRunner =
    routeAllPathToCompMap['../views/system/plugin/runner.vue'] || (() => import('/@vab/components/VabRouterView/index.vue'))

  const resolveComp = (compStr: string) => {
    if (!compStr) return defaultRunner
    if (compStr === 'Layout') return () => import('/@vab/layouts/index.vue')

    // 清理路径前缀，例如 "@/views/auth/admin/index.vue" -> "views/auth/admin/index.vue"
    const clean = compStr.replace(/^@\/?/, '').replace(/^\//, '')
    const candidateKeys = [
      `../${clean}`,
      `../views/${clean}`,
      clean.includes('views/') ? `../${clean.slice(clean.indexOf('views/'))}` : '',
    ].filter(Boolean)

    for (const key of candidateKeys) {
      if (routeAllPathToCompMap[key]) {
        return routeAllPathToCompMap[key]
      }
    }
    // 找不到组件（例如插件动态页面 plugin:xxx/yyy 或未开发定制页面），回退到通用 runner.vue 容器
    return defaultRunner
  }

  const result: VabRouteRecord[] = []

  for (const rawRoute of asyncRoutes) {
    const route: VabRouteRecord = { ...rawRoute }

    if (route.children && route.children.length > 0) {
      route.children = convertRouter(route.children, false)
      if (!route.component || route.component === 'Layout') {
        route.component = isRoot ? () => import('/@vab/layouts/index.vue') : () => import('/@vab/components/VabRouterView/index.vue')
      } else {
        route.component = resolveComp(route.component as string)
      }
    } else {
      if (route.children && route.children.length === 0) {
        delete route.children
      }
      if (route.component === 'Layout') {
        route.component = () => import('/@vab/layouts/index.vue')
      } else if (route.component) {
        const comp = resolveComp(route.component as string)
        if (isRoot) {
          // 根层级的单页面（如控制台），包裹在 Layout 容器内，使得后台框架侧边栏与顶栏正常渲染
          route.component = () => import('/@vab/layouts/index.vue')
          route.meta = { ...route.meta, levelHidden: true }
          // Vue Router 要求父子路由名称唯一，页面保留原名称供菜单和标签使用。
          if (route.name) route.name = `${route.name}__layout`
          const childMeta = { ...rawRoute.meta }
          if (rawRoute.path === '/index' || rawRoute.path === 'index') {
            childMeta.noClosable = true
            childMeta.activeMenu = '/index'
          }
          route.children = [
            {
              path: '',
              name: rawRoute.name,
              component: comp,
              meta: childMeta,
            } as any,
          ]
        } else {
          route.component = comp
        }
      } else {
        route.component = isRoot ? () => import('/@vab/layouts/index.vue') : defaultRunner
      }
    }

    result.push(route)
  }

  return result
}

/**
 * @description 根据roles数组拦截路由
 * @param routes 路由
 * @param rolesControl 是否进行权限控制
 * @param baseUrl 基础路由
 * @returns {[]}
 */
export const filterRoutes = (routes: VabRouteRecord[], rolesControl: boolean, baseUrl = '/') => {
  return routes
    .filter((route: VabRouteRecord) => (rolesControl && route.meta && route.meta.guard ? hasPermission(route.meta.guard) : true))
    .map((route: VabRouteRecord) => {
      route = { ...route }
      if (route.path !== '*' && !isExternal(route.path)) {
        if (route.path === '') {
          route.path = baseUrl
        } else if (baseUrl.slice(-1) === '/') {
          route.path = baseUrl + (route.path[0] === '/' ? route.path.slice(1) : route.path)
        } else {
          route.path = baseUrl + (route.path[0] === '/' ? route.path : `/${route.path}`)
        }
      }
      if (route.children && route.children.length > 0) {
        route.children = filterRoutes(route.children, rolesControl, route.path)
        if (route.children.length > 0) {
          route.childrenPathList = route.children.flatMap((item: VabRouteRecord) =>
            Array.isArray(item.childrenPathList) ? item.childrenPathList : [item.path]
          )
          const target = route.children[0].redirect || route.children[0].path
          if (!route.redirect || target === '') {
            route.redirect = target === '' ? route.path : target
          }
        } else {
          route.childrenPathList = [route.path]
        }
      } else route.childrenPathList = [route.path]
      return route
    })
}

/**
 * 根据path路径获取matched
 * @param routes 菜单routes
 * @param path 路径
 * @returns {*} matched
 */
export const handleMatched = (routes: VabRouteRecord[], path: string): VabRouteRecord[] => {
  if (!Array.isArray(routes) || !path) return []
  return routes
    .filter((route) => Array.isArray(route.childrenPathList) && route.childrenPathList.includes(path))
    .flatMap((route) => (route.children ? [route, ...handleMatched(route.children, path)] : [route]))
}

/**
 * 生成单个多标签元素，可用于同步/异步添加多标签
 * @param tag route页信息
 */
export const handleTabs = (tag: VabRoute) => {
  let parentIcon = null
  if (tag.matched)
    for (let i = tag.matched.length - 2; i >= 0; i--) if (!parentIcon && tag.matched[i].meta.icon) parentIcon = tag.matched[i].meta.icon
  if (!parentIcon) parentIcon = 'menu-line'
  const path = handleActivePath(tag, true)
  if (tag.name && tag.meta && tag.meta.tabHidden !== true) {
    const tabsStore = useTabsStore && useTabsStore()
    let existedMeta = null
    if (tabsStore && tabsStore.visitedRoutes) {
      const existed = tabsStore.visitedRoutes.find((r: any) => r.path === path)
      if (existed) existedMeta = existed.meta
    }
    return {
      path,
      query: tag.query,
      params: tag.params,
      name: tag.name,
      parentIcon,
      meta: existedMeta ? { ...tag.meta, ...existedMeta } : { ...tag.meta },
    }
  }
}

/**
 * 根据当前route获取激活菜单
 * @param route 当前路由
 * @param isTab 是否是标签
 * @returns {string|*}
 */
export const handleActivePath = (route: VabRoute, isTab = false) => {
  const { meta = {}, path, matched, query }: any = route
  const rawPath = matched && matched.length > 0 ? matched.at(-1).path : path
  const fullPath = query && Object.keys(query).length > 0 ? `${path}?${stringify(query)}` : path
  if (isTab) return meta.dynamicNewTab ? fullPath : rawPath
  if (meta.activeMenu) return meta.activeMenu
  return fullPath
}

/**
 * 获取当前跳转登录页的Route
 * @param currentPath 当前页面地址
 */
export const toLoginRoute = (currentPath: string) => {
  if (recordRoute && currentPath !== '/')
    return {
      path: '/login',
      query: { redirect: currentPath },
      replace: true,
    }
  else return { path: '/login', replace: true }
}

/**
 * 获取路由中所有的Name
 * @param routes 路由数组
 * @returns {*} Name数组
 */
export const getNames = (routes: VabRouteRecord[]): string[] => {
  return routes.flatMap((route: VabRouteRecord) => {
    const names = []
    if (route.name) names.push(route.name)
    if (route.children) names.push(...getNames(route.children))
    return names
  })
}
