<template>
  <img v-if="external" class="img-icon" :src="icon" alt="" />
  <svg v-else-if="isCustomSvg" aria-hidden="true" :class="['vab-icon', className]">
    <use :href="`#vab-icon-${icon}`" />
  </svg>
  <i v-else aria-hidden="true" :class="`ri-${icon}`" />
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ icon?: string; isCustomSvg?: boolean; className?: string }>(), {
  icon: '',
  isCustomSvg: false,
  className: '',
})
const external = computed(() => /^(https?:|mailto:|tel:|\/\/)/.test(props.icon))
</script>

<style scoped>
.img-icon, .vab-icon { display: inline-block; width: var(--el-font-size-big); height: var(--el-font-size-big); }
.img-icon { vertical-align: middle; }
.vab-icon { margin: 0 auto; overflow: hidden; vertical-align: top; fill: currentColor; }
i { display: inline-block; font-size: var(--el-font-size-big); text-align: center; vertical-align: 0; }
</style>
