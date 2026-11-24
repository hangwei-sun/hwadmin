import { setupVab } from '~/library'
import App from './App.vue'
import { setupI18n } from '/@/i18n'
import { setupRouter } from '/@/router'
import { setupStore } from '/@/store'
import { setupDesktopLinks } from '/@/utils/platform'

const bootstrap = async () => {
  const app = createApp(App)
  setupVab(app)
  setupI18n(app)
  setupStore(app)
  setupRouter(app)
  setupDesktopLinks()
  app.mount('#app')
}

bootstrap()
