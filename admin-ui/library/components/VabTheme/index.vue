<template>
  <vab-icon icon="t-shirt-line" @click="handleOpenTheme" />
</template>

<script lang="ts" setup>
defineOptions({
  name: 'VabTheme',
})

const handleOpenTheme = () => {
  $pub('hwadmin-open-theme')
}
</script>
