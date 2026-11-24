/**
 * @description: 动态导入components
 * @author sundan
 */

import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'

export const createUnPlugin = () => {
  return [AutoImport({
    imports: [
      'vue',
      'pinia',
      'vue-i18n',
      'vue-router',
      '@vueuse/core',
      {
        axios: [['default', 'axios']],
        // '/@/i18n': [['translate']],
      },
    ],
    resolvers: [ElementPlusResolver({ importStyle: false })],
    dirs: ['src/hooks'],
    dts: 'library/build/unplugin/auto-imports.d.ts',
  }), Components({
    dirs: ['library/components', 'src/plugins', 'src/**/vabAutoComponents'],
    resolvers: [ElementPlusResolver({ importStyle: false })],
    // 声明文件用于开发期类型提示，生产构建无需并发改写工作区文件。
    dts: process.env.NODE_ENV === 'production' ? false : 'library/build/unplugin/components.d.ts',
  })]
}
