import { defineManifestConfig } from '@uni-helper/vite-plugin-uni-manifest'
import { loadEnv } from 'vite'
import pkg from './package.json'

const modeIndex = process.argv.indexOf('--mode')
const mode = modeIndex >= 0 ? process.argv[modeIndex + 1] : process.env.NODE_ENV || 'development'
const env = loadEnv(mode, process.cwd())

export default defineManifestConfig({
  name: env.VITE_APP_TITLE || 'HwAdmin Mobile',
  appid: '',
  description: 'HwAdmin 通用移动端',
  versionName: pkg.version,
  versionCode: String(pkg.version.split('.').reduce((code, part) => code * 100 + Number(part), 0)),
  vueVersion: '3',
  h5: { router: { mode: 'hash' } },
  'mp-weixin': {
    appid: env.VITE_WX_APPID || 'touristappid',
    setting: { urlCheck: true, es6: true, minified: true },
    usingComponents: true,
    mergeVirtualHostAttributes: true,
    optimization: { subPackages: true },
  },
  uniStatistics: { enable: false },
})
