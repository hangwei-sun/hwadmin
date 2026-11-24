<script setup lang="ts">
import { themeVars } from '@/theme'
</script>

<template>
  <wd-config-provider :theme-vars="themeVars">
    <slot />
    <wd-toast />
    <wd-dialog />
  </wd-config-provider>
</template>
