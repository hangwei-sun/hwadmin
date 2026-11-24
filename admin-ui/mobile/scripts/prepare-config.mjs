// uni-app CLI reads these before Vite can generate the full configurations.
// Keep only bootstrap data here; *.config.ts remains the source of truth.
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = new URL('../src/', import.meta.url)
mkdirSync(new URL('types/', root), { recursive: true })
const files = {
  'manifest.json': { h5: { router: { mode: 'hash' } }, vueVersion: '3', uniStatistics: { enable: false } },
  'pages.json': {
    pages: [{ path: 'pages/index/index' }, { path: 'pages/me/index' }],
    easycom: { autoscan: true, custom: { '^wd-(.*)': '@wot-ui/ui/components/wd-$1/wd-$1.vue' } },
    globalStyle: { navigationBarTitleText: 'HwAdmin Mobile' },
  },
}
for (const [name, data] of Object.entries(files)) {
  const path = fileURLToPath(new URL(name, root))
  if (!existsSync(path)) writeFileSync(path, JSON.stringify(data, null, 2))
}
