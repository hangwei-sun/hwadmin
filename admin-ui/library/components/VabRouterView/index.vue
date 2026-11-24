<template>
  <router-view v-slot="{ Component }">
    <transition
      :mode="theme.pageTransition && theme.pageTransition !== 'null' ? 'out-in' : undefined"
      :name="theme.pageTransition && theme.pageTransition !== 'null' ? theme.pageTransition : undefined"
    >
      <keep-alive :include="keepAliveNameList" :max="keepAliveMaxNum">
        <component :is="Component" :key="routerKey" ref="componentRef" />
      </keep-alive>
    </transition>
  </router-view>
</template>

<script lang="ts" setup>
import VabProgress from 'nprogress'
import { keepAliveMaxNum } from '/@/config'
import { useSettingsStore } from '/@/store/modules/settings'
import { useTabsStore } from '/@/store/modules/tabs'
import { handleActivePath } from '/@/utils/routes'

defineOptions({
  name: 'VabRouterView',
})

const route = useRoute()
const settingsStore = useSettingsStore()
const { theme, title } = storeToRefs(settingsStore)
const tabsStore = useTabsStore()
const { getVisitedRoutes: visitedRoutes } = storeToRefs(tabsStore)
const componentRef = ref<any>()
const routerKey = ref<any>()
const keepAliveNameList = ref<any>()
const descriptionMeta = document.querySelector<HTMLMetaElement>('meta[name="description"]') || document.createElement('meta')
descriptionMeta.name = 'description'
if (!descriptionMeta.isConnected) document.head.appendChild(descriptionMeta)

const updateKeepAliveNameList = (refreshRouteName = null) => {
  const nameSet = new Set<string>()
  visitedRoutes.value
    .filter((item) => !item.meta?.noKeepAlive && item.name !== refreshRouteName)
    .forEach((item) => {
      if (item.name) {
        nameSet.add(String(item.name))
        if (typeof item.name === 'string' && item.name.endsWith('__layout')) {
          nameSet.add(item.name.replace(/__layout$/, ''))
        }
      }
      if (item.matched && Array.isArray(item.matched)) {
        item.matched.forEach((m: any) => {
          if (m.name && m.name !== refreshRouteName) nameSet.add(String(m.name))
          if (m.components?.default?.name && m.components.default.name !== refreshRouteName) {
            nameSet.add(String(m.components.default.name))
          }
        })
      }
    })
  keepAliveNameList.value = Array.from(nameSet)
}

// 更新KeepAlive缓存页面
watchEffect(() => {
  routerKey.value = handleActivePath(route, true)
  updateKeepAliveNameList()
  descriptionMeta.content = `${title.value} - ${route.meta.title || '管理后台'}`
})

onBeforeMount(() => {
  $sub('reload-router-view', (refreshRouteName: any = route.name) => {
    if (theme.value.showProgressBar) VabProgress.start()
    const cacheActivePath = routerKey.value
    routerKey.value = null
    updateKeepAliveNameList(refreshRouteName)
    nextTick(() => {
      routerKey.value = cacheActivePath
      updateKeepAliveNameList()
    })
    setTimeout(() => {
      if (theme.value.showProgressBar) VabProgress.done()
    }, 200)
  })

  // 刷新全部页面
  $sub('reload-all', () => {
    if (theme.value.showProgressBar) VabProgress.start()
    const cacheActivePath = routerKey.value
    routerKey.value = null
    // 清空所有缓存
    keepAliveNameList.value = []
    nextTick(() => {
      routerKey.value = cacheActivePath
      // 重新加载当前页面
      updateKeepAliveNameList()
    })
    setTimeout(() => {
      if (theme.value.showProgressBar) VabProgress.done()
    }, 200)
  })
})
</script>
