import autoprefixer from 'autoprefixer'
import dayjs from 'dayjs'
import { resolve } from 'node:path'
import type { ConfigEnv, UserConfig } from 'vite'
import { defineConfig, loadEnv, normalizePath } from 'vite'
import {
  assetsDir,
  chunkSizeWarningLimit,
  cssCodeSplit,
  exclude,
  https,
  include,
  minify,
  open,
  outputHash,
  port,
  reportCompressedSize,
} from './src/config/index.ts'
import { createVitePlugin, createWatch } from './library/build/index.ts'
import { resolveServerOrigin } from './packages/pc-core/src/desktop.mjs'

const lastBuildTime = dayjs().format('YYYY-MM-DD HH:mm:ss')

export default defineConfig(({ mode }: ConfigEnv): UserConfig => {
  process.env['VITE_APP_UPDATE_TIME'] = lastBuildTime
  process.env['VITE_USER_NODE_ENV'] = mode
  const root = process.cwd()
  const env = loadEnv(mode, root)
  const desktop = mode === 'desktop'
  if (desktop) resolveServerOrigin(true, env.VITE_DESKTOP_SERVER_ORIGIN, '')
  createWatch(env)

  if (mode === 'development') {
    console.log(`构建时间: ${lastBuildTime}`)
  }

  return {
    // 开发环境统一使用 /admin/ 作为基准路径，生产打包默认使用相对路径或配置的 VITE_APP_BASE
    base: desktop ? './' : mode === 'development' ? (env.VITE_APP_BASE || '/admin/') : (env.VITE_APP_BASE || './'),
    root,
    server: {
      open: desktop ? false : open,
      port: desktop ? 15174 : Number(process.env.PORT || env.VITE_PORT || (port === 5200 ? 15173 : port)),
      strictPort: desktop,
      hmr: {
        overlay: true,
      },
      host: desktop ? '127.0.0.1' : '0.0.0.0',
      watch: { ignored: ['**/src-tauri/**'] },
      warmup: {
        clientFiles: ['./index.html', './library/{components,layouts}/*', './src/{views,plugins}/*'],
      },
      https,
      proxy: {
        '/adminapi': {
          target: env.VITE_HWADMIN_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/api': {
          target: env.VITE_HWADMIN_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/uploads': {
          target: env.VITE_HWADMIN_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
        '/plugins': {
          target: env.VITE_HWADMIN_PROXY_TARGET || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
      },
      fs: {},
    },
    resolve: {
      alias: {
        ...(desktop ? { 'virtual:pwa-register/vue': resolve(import.meta.dirname, 'src/utils/desktopPwa.ts') } : {}),
        '~/': `${resolve(import.meta.dirname, '.')}/`,
        '/@/': `${normalizePath(resolve(import.meta.dirname, 'src'))}/`,
        '/@vab/': `${normalizePath(resolve(import.meta.dirname, 'library'))}/`,
      },
    },
    optimizeDeps: {
      include,
      exclude,
    },
    build: {
      assetsDir,
      chunkSizeWarningLimit,
      cssCodeSplit,
      // 生产打包目录构建
      outDir: desktop ? 'dist-desktop' : 'dist',
      emptyOutDir: true,
      reportCompressedSize,
      rolldownOptions: {
        //treeshake: false,
        onwarn: () => {
          return
        },
        output: {
          chunkFileNames: outputHash ? 'static/js/[name]-[hash].js' : 'static/js/[name].js',
          entryFileNames: outputHash ? 'static/js/[name]-[hash].js' : 'static/js/[name].js',
          assetFileNames: outputHash ? 'static/[ext]/[name]-[hash].[ext]' : 'static/[ext]/[name].[ext]',
          codeSplitting: {
            groups: [
              { name: 'vendor-vue', test: /node_modules[\\/](?:vue|@vue|vue-router|pinia|vue-i18n|@vueuse)[\\/]/, priority: 25 },
              { name: 'vendor-element-plus', test: /node_modules[\\/](?:element-plus|@element-plus)[\\/]/, priority: 20 },
              { name: 'vendor-nprogress', test: /node_modules[\\/]nprogress[\\/]/, priority: 15 },
            ],
          },
        },
      },
      minify,
      sourcemap: false,
      target: 'es2015',
    },
    css: {
      postcss: {
        plugins: [
          autoprefixer({ grid: true }) as any,
          {
            postcssPlugin: 'internal:charset-removal',
            AtRule: {
              charset: (atRule: { name: string; remove: () => void }) => {
                if (atRule.name === 'charset') atRule.remove()
              },
            },
          },
          {
            postcssPlugin: 'internal:remixicon-woff2-only',
            AtRule: {
              'font-face': (atRule: any) => {
                let isRemixIcon = false
                atRule.walkDecls('font-family', (decl: any) => {
                  if (/['"]?remixicon['"]?/i.test(decl.value)) isRemixIcon = true
                })
                if (!isRemixIcon) return
                let keptDecl: any = null
                atRule.walkDecls('src', (decl: any) => {
                  const match = decl.value.match(/url\(([^)]+\.woff2[^)]*)\)/i)
                  if (match && !keptDecl) {
                    decl.value = `url(${match[1]}) format("woff2")`
                    keptDecl = decl
                  } else {
                    decl.remove()
                  }
                })
              },
            },
          },
        ],
      },
      preprocessorOptions: {
        scss: {
          //api: 'modern-compiler',
        },
      },
      devSourcemap: true,
    },
    plugins: [
      ...createVitePlugin(env),
      ...(desktop ? [{
        name: 'hwadmin-desktop-html',
        transformIndexHtml: (html: string) => html.replace(/\s*<script type="speculationrules">[\s\S]*?<\/script>/, ''),
      }] : []),
      {
        name: 'hwadmin-portal-proxy',
        configureServer(server) {
          server.middlewares.use(async (req, res, next) => {
            const url = req.url || '/'
            if (url === '/admin') {
              res.writeHead(302, { Location: '/admin/' })
              res.end()
              return
            }
            // 当访问根路径 / 或 /?...（非 /admin/、非静态资源与 Vite 内部路径）时，代理到后端 PHP 门户 Index::index()
            if (!desktop && (url === '/' || url.startsWith('/?'))) {
              try {
                const proxyTarget = env.VITE_HWADMIN_PROXY_TARGET || 'http://127.0.0.1:8000'
                const response = await fetch(`${proxyTarget}${url}`, {
                  headers: { Accept: 'text/html' },
                })
                const html = await response.text()
                res.statusCode = response.status
                res.setHeader('Content-Type', 'text/html; charset=utf-8')
                res.end(html)
                return
              } catch {
                // fallback
              }
            }
            next()
          })
        },
      },
    ],
    define: {},
    // 为生产环境添加预加载指令，提高初次访问速度
    experimental: {
      renderBuiltUrl(filename, { hostType }) {
        // 为JS和CSS资源添加preload，提高资源加载优先级
        if (hostType === 'js' && filename.endsWith('.js')) {
          return { relative: true, preload: true }
        }
        if (hostType === 'css' && filename.endsWith('.css')) {
          return { relative: true, preload: true }
        }
        return { relative: true }
      },
    },
  }
})
