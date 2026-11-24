import { coreRoutes } from './core'
import { projectRoutes } from '/@/extensions/routes'

export const asyncRoutes = [
  ...coreRoutes,
  ...projectRoutes,
  {
    path: '/:pathMatch(.*)*',
    redirect: '/404',
    name: 'NotFound',
    meta: {
      title: '404',
      hidden: true,
    },
  },
]

export { coreRoutes }
