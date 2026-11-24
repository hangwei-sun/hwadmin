import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import chokidar from 'chokidar'
import dayjs from 'dayjs'
import pc from 'picocolors'
import type { PluginOption } from 'vite'
import { createBanner } from './banner/index.ts'
import { createCompress } from './compress/index.ts'
import { createHttps } from './https/index.ts'
import { createPwa } from './pwa/index.ts'
import { createSvgIcons } from './svgSprite/index.ts'
import { createUnPlugin } from './unplugin/index.ts'
import { createVisualizer } from './visualizer/index.ts'
import { compress, https, port, pwa, pwaDev, report } from '../../src/config/index.ts'

const viteUser = 'VITE_' + 'USER_'

export const createVitePlugin = (env: Record<string, string>) => {
  const vitePlugins: PluginOption[] = [vue()]
  const nodeEnv = env[`${viteUser}NODE_ENV`]
  vitePlugins.push(
    vueJsx(),
    createUnPlugin(),
    createSvgIcons(),
    createBanner()
  )
  if (compress) vitePlugins.push(createCompress(compress))
  if (pwa && process.env.VITE_USER_NODE_ENV !== 'desktop') vitePlugins.push(createPwa(nodeEnv, pwaDev))
  if (https) vitePlugins.push(createHttps())
  if (report) vitePlugins.push(createVisualizer())
  return vitePlugins
}

export const createWatch = (env: Record<string, string>) => {
  const nodeEnv = env[`${viteUser}NODE_ENV`]

  if (nodeEnv === 'development') {
    chokidar.watch('./src/views').on('change', (path) => {
      if (path.endsWith('vue')) {
        console.log(
          `\n${pc.gray(dayjs().format('HH:mm:ss'))} ${pc.cyan('[HwAdmin]')} ${pc.cyan(`http://localhost:${port}/`)} ${pc.green(
            'update success'
          )} `
        )
      }
    })
  }
}
