<template>
  <div
    class="vab-fall-bar"
    :class="{
      'is-collapse': collapse,
    }"
  >
    <vab-logo style="z-index: 999" />
    <el-scrollbar class="vab-fall-bar-scrollbar">
      <el-menu
        :collapse="collapse"
        :collapse-transition="false"
        :default-active="route.path"
        mode="vertical"
        class="vab-fall-menu"
      >
        <template v-for="item in handleRoutes" :key="item.path">
          <el-sub-menu v-if="item.children && item.children.length > 0" :index="item.path">
            <template #title>
              <vab-icon :icon="item.meta && item.meta.icon" />
              <span>{{ translate(item.meta && item.meta.title) }}</span>
            </template>
            <el-menu-item
              v-for="sub in item.children"
              :key="sub.path"
              :index="sub.path"
              @click="handleLink(sub)"
            >
              <vab-icon :icon="sub.meta && sub.meta.icon" />
              <template #title>{{ translate(sub.meta && sub.meta.title) }}</template>
            </el-menu-item>
          </el-sub-menu>
          <el-menu-item v-else :index="item.path" @click="handleLink(item)">
            <vab-icon :icon="item.meta && item.meta.icon" />
            <template #title>{{ translate(item.meta && item.meta.title) }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-scrollbar>
  </div>
</template>

<script lang="ts" setup>
import { isHashRouterMode } from '/@/config'
import { translate } from '/@/i18n'
import { useRoutesStore } from '/@/store/modules/routes'
import { useSettingsStore } from '/@/store/modules/settings'
import { isExternal } from '/@/utils/validate'

defineOptions({
  name: 'VabFallBar',
})

const settingsStore = useSettingsStore()
const routesStore = useRoutesStore()
const { getRoutes: routes } = storeToRefs(routesStore)
const route = useRoute()
const router = useRouter()

const { device, collapse } = storeToRefs(settingsStore)
const { foldSideBar } = settingsStore
const { enter, exit } = useFullscreen()

const handleRoutes = computed(() =>
  routes.value.flatMap((route: any) => (route.meta?.levelHidden && route.children ? [...route.children] : route))
)

const handleLink = (slotScope: any) => {
  nextTick(() => {
    const routePath = slotScope.path
    const target = slotScope.meta?.target
    const fullscreen = slotScope.meta?.fullscreen

    if (target === '_blank') {
      if (isExternal(routePath)) {
        window.open(routePath)
        router.push('/redirect')
      } else if (route.path !== routePath) {
        if (isHashRouterMode) window.open(`#${routePath}`)
        else window.open(routePath)
      }
      router.push('/redirect')
    } else {
      if (isExternal(routePath)) globalThis.location.href = routePath
      else if (route.path === routePath) {
        $pub('reload-router-view')
      } else {
        if (device.value === 'mobile') foldSideBar()
        if (slotScope.children && slotScope.redirect) router.push(slotScope.redirect)
        else router.push(slotScope.path)
      }
    }

    setTimeout(() => {
      if (fullscreen) enter()
      else exit()
    }, 1000)
  })
}
</script>

<style lang="scss" scoped>
.vab-fall-bar {
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  z-index: var(--el-z-index);
  width: calc(var(--el-left-menu-width) - 1px);
  background: var(--el-menu-background-color);
  border-right: 1px solid var(--el-border-color);
  transition: width var(--el-transition-duration);

  &.is-collapse {
    width: var(--el-left-menu-width-min);
  }

  .vab-fall-bar-scrollbar {
    height: calc(100vh - var(--el-header-height));
  }

  .vab-fall-menu {
    border-right: none;
    background: transparent;
  }
}
</style>
