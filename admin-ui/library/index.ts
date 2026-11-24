import 'virtual:svg-icons-register'

import VabIcon from './components/VabIcon/index.vue'
import type { App } from 'vue'
import './styles/vab.scss'

export const setupVab = (app: App<Element>) => {
  app.component('VabIcon', VabIcon)
  const Plugins = import.meta.glob('./plugins/*.ts', { eager: true })
  Object.getOwnPropertyNames(Plugins).forEach((key) => {
    const plugin: any = Plugins[key]
    app.use(plugin.default)
  })
}
