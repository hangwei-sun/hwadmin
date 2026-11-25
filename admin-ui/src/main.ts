import { setupVab } from '~/library'
import App from './App.vue'
import { setupI18n } from '/@/i18n'
import { setupRouter } from '/@/router'
import { setupStore } from '/@/store'
import { setupDesktopLinks } from '/@/utils/platform'
import { recoverFromChunkError } from '/@/utils/chunkRecovery'

window.addEventListener('vite:preloadError', (event: Event) => {
  if (recoverFromChunkError()) {
    event.preventDefault()
  }
})

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
