// Adapted from unibest base 8277fab1; upstream license: LICENSE.unibest.
import { fileURLToPath, URL } from 'node:url'
import uni from '@uni-helper/plugin-uni'
import UniLayouts from '@uni-helper/vite-plugin-uni-layouts'
import UniManifest from '@uni-helper/vite-plugin-uni-manifest'
import UniPages from '@uni-helper/vite-plugin-uni-pages'
import UnoCSS from 'unocss/vite'
import AutoImport from 'unplugin-auto-import/vite'
import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd())
  return {
    plugins: [
      UniLayouts({ dts: 'src/types/uni-layouts.d.ts' }),
      UniManifest(),
      UniPages({
        subPackages: ['src/pages-components'],
        dts: 'src/types/uni-pages.d.ts',
      }),
      uni(),
      UnoCSS(),
      AutoImport({ imports: ['vue', 'uni-app'], dts: 'src/types/auto-import.d.ts' }),
    ],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    css: { preprocessorOptions: { scss: { api: 'modern-compiler', silenceDeprecations: ['legacy-js-api'] } } },
    server: {
      host: '127.0.0.1',
      port: 9000,
      strictPort: true,
      proxy: {
        '/api': {
          target: env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
      },
    },
    build: { sourcemap: false },
  }
})
