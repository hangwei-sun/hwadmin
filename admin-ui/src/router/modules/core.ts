const Layout = () => import('/@vab/layouts/index.vue')

export const coreRoutes = [
  {
    path: '/', name: 'Root', component: Layout,
    meta: { title: '首页', icon: 'home-2-line' },
    children: [{
      path: 'index', name: 'Index', component: () => import('/@/views/index/index.vue'),
      meta: { title: '首页', icon: 'home-2-line', noClosable: true, activeMenu: '/index' },
    }],
  },
  {
    path: '/setting', name: 'MemberSettings', component: Layout,
    meta: { title: '会员中心', icon: 'user-settings-line' },
    children: [{
      path: 'personalCenter', name: 'PersonalCenter', component: () => import('/@/views/setting/personalCenter/index.vue'),
      meta: { title: '个人中心', icon: 'user-follow-line' },
    }],
  },
]
