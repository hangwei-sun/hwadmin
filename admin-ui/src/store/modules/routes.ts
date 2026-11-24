/**
 * @description 路由拦截状态管理，目前两种模式：all模式与intelligence模式，其中partialRoutes是菜单暂未使用
 */
import { getList } from '/@/api/router'
import { authentication, rolesControl } from '/@/config'
import { asyncRoutes, constantRoutes, resetRouter } from '/@/router'
import { coreRoutes } from '/@/router/modules/core'
import { convertRouter, filterRoutes } from '/@/utils/routes'
import { isArray } from '/@/utils/validate'

const filterHidden = (data: any) => {
  return data.reduce((acc: any, item: any) => {
    if (item.meta && item.meta.hidden) return acc
    const newItem = { ...item }
    if (item.children && item.children.length > 0) newItem.children = filterHidden(item.children)
    return [...acc, newItem]
  }, [])
}

const filterBreadcrumb = (data: any) => {
  return data.reduce((acc: any, item: any) => {
    const newItem = { ...item }
    if (item.children && item.children.length > 0) newItem.children = filterBreadcrumb(item.children)
    return [...acc, newItem]
  }, [])
}

export const useRoutesStore = defineStore('routes', {
  state: (): RoutesModuleType => ({
    tab: {
      data: undefined,
    },
    tabMenu: undefined,
    activeMenu: {
      data: undefined,
    },
    routes: [],
    allRoutes: [],
    breadcrumbRoutes: [],
  }),
  getters: {
    getTab: (state) => state.tab,
    getTabMenu: (state) => (state.tab.data ? state.routes.find((route) => route.name === state.tab.data) : { meta: { title: '' } }),
    getActiveMenu: (state) => state.activeMenu,
    getRoutes: (state) => state.routes.filter((_route) => _route.meta && _route.meta.hidden !== true),
    getAllRoutes: (state) => state.allRoutes.filter((_route) => _route.meta && _route.meta.hidden !== true),
    getBreadcrumbRoutes: (state) => state.breadcrumbRoutes.filter((_route) => _route.meta && _route.meta.hidden !== true),
    getPartialRoutes: (state) => (state.tab.data ? (state.routes.find((route) => route.name === state.tab.data)?.children ?? []) : []),
  },
  actions: {
    /**
     * @description 多模式设置路由
     * @param mode
     * @returns
     */
    async setRoutes(mode = 'none') {
      // 默认前端路由
      let routes: VabRouteRecord[] = [...asyncRoutes]
      // 设置游客路由关闭路由拦截(不需要可以删除)
      const control = mode === 'visit' ? false : rolesControl
      // 设置后端路由(不需要可以删除)
      if (authentication === 'all') {
        let list: any[] = []
        try {
          const res = await getList()
          list = res?.data?.list ?? (isArray(res?.data) ? res.data : [])
        } catch (error) {
          console.error('获取后端路由失败', error)
        }
        if (!isArray(list) || list.length === 0) {
          list = [
            {
              path: '/',
              name: 'Root',
              component: 'Layout',
              redirect: '/index',
              meta: {
                title: '首页',
                icon: 'home-2-line',
                levelHidden: true,
              },
              children: [
                {
                  path: 'index',
                  name: 'Index',
                  component: '@/views/index/index.vue',
                  meta: {
                    title: '首页',
                    icon: 'home-2-line',
                    noClosable: true,
                  },
                },
              ],
            },
          ]
        } else {
          // 归一化首页路由：将后端返回的首页/控制台单页面节点包装为以 '/' 为根的顶级布局路由，子路由为 '/index'
          const dashboardIdx = list.findIndex(
            (item: any) =>
              item.path === '/index' ||
              item.path === 'index' ||
              item.name === 'dashboard' ||
              item.name === 'Index' ||
              (item.component && typeof item.component === 'string' && item.component.includes('views/index/index.vue'))
          )
          if (dashboardIdx !== -1) {
            const rawHome = list[dashboardIdx]
            list[dashboardIdx] = {
              path: '/',
              name: 'Root',
              component: 'Layout',
              redirect: '/index',
              meta: {
                title: rawHome.meta?.title || rawHome.title || '首页',
                icon: rawHome.meta?.icon || rawHome.icon || 'home-2-line',
                levelHidden: true,
                ...rawHome.meta,
              },
              children: [
                {
                  path: 'index',
                  name: rawHome.name && rawHome.name !== 'Root' ? rawHome.name : 'Index',
                  component: rawHome.component || '@/views/index/index.vue',
                  meta: {
                    title: rawHome.meta?.title || rawHome.title || '首页',
                    icon: rawHome.meta?.icon || rawHome.icon || 'home-2-line',
                    noClosable: true,
                    ...rawHome.meta,
                  },
                },
              ],
            }
          }
        }
        if (!list.some((item: any) => item.path === '/:pathMatch(.*)*')) {
          list.push({
            path: '/:pathMatch(.*)*',
            redirect: '/404',
            name: 'NotFound',
            meta: { hidden: true },
          })
        }
        routes = convertRouter(list)
        // 个人中心不依赖后端菜单授权，但只在登录后注册，且不出现在侧栏。
        const personalCenter = coreRoutes.find((route) => route.path === '/setting')
        if (personalCenter && !routes.some((route) => route.path === personalCenter.path)) {
          routes.push({ ...personalCenter, meta: { ...personalCenter.meta, hidden: true } } as VabRouteRecord)
        }
      }
      // 根据权限和rolesControl过滤路由
      const accessRoutes = filterRoutes([...constantRoutes, ...routes], control)
      // 后端菜单通常从 /index 等页面开始，不一定提供 /；登录默认访问 /。
      // 入口只指向当前用户实际可见的菜单，避免为无权限用户硬编码首页。
      if (authentication === 'all' && !accessRoutes.some((route) => route.path === '/')) {
        const firstMenu = accessRoutes.find(
          (route) => route.meta && !route.meta.hidden && route.path !== '/:pathMatch(.*)*'
        )
        if (firstMenu) {
          accessRoutes.push({
            path: '/',
            name: 'HwAdminHomeRedirect',
            redirect: firstMenu.redirect || firstMenu.path,
            childrenPathList: ['/'],
            meta: { hidden: true },
          } as VabRouteRecord)
        }
      }
      // 设置菜单所需路由
      this.routes = filterHidden(accessRoutes)
      this.allRoutes = accessRoutes
      this.breadcrumbRoutes = filterBreadcrumb(accessRoutes)
      // 根据可访问路由重置Vue Router
      await resetRouter(accessRoutes)
    },
    changeMenuMeta(options: any) {
      function handleRoutes(routes: any[]) {
        return routes.map((route) => {
          if (route.name === options.name) Object.assign(route.meta, options.meta)
          if (route.children && route.children.length > 0) route.children = handleRoutes(route.children)
          return route
        })
      }

      this.routes = handleRoutes(this.routes)
    },
    /**
     * @description 修改 activeName
     * @param activeMenu 当前激活菜单
     */
    changeActiveMenu(activeMenu: string) {
      this.activeMenu.data = activeMenu
    },
  },
})
