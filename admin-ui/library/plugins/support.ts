import { ElMessageBox } from 'element-plus'
import pinia from '/@/store'
import { useSettingsStore } from '/@/store/modules/settings'

export default {
  install: () => {
    const { title } = useSettingsStore(pinia)

    // @ts-ignore
    if (!!globalThis.ActiveXObject || 'ActiveXObject' in globalThis) {
      ElMessageBox({
        title: '温馨提示',
        message:
          '检测到您当前浏览器使用的是IE内核，自2015年3月起，微软已宣布弃用IE，且不再对IE提供任何更新维护，请更新现代浏览器，如果您使用的是双核浏览器，请切换浏览器内核为极速模式',
        type: 'warning',
        showClose: true,
        showConfirmButton: false,
        closeOnClickModal: false,
        closeOnPressEscape: false,
        closeOnHashChange: false,
      }).then(() => {})
    }
    if (import.meta.env.MODE !== 'development') {
      console.log(
        ` %c ${title}  %c HwAdmin 构建 `,
        'color: #fadfa3; background: #030307; padding:5px 0;',
        'background: #fadfa3; padding:5px 0;'
      )
    }
  },
}
