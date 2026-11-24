<template>
  <el-table border :data="errorLogs">
    <el-table-column label="报错路由">
      <template #default="{ row }">
        <el-button :href="row.url" rel="noopener noreferrer" tag="a" target="_blank" text type="success">
          {{ row.url }}
        </el-button>
      </template>
    </el-table-column>
    <el-table-column label="错误信息">
      <template #default="{ row }">
        <el-tag type="danger">{{ row.err.message }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column label="操作">
      <template #default="{ row }">
        <el-tooltip :content="row.err.stack" effect="light">
          <el-button text type="primary">错误详情</el-button>
        </el-tooltip>
      </template>
    </el-table-column>
    <template #empty>
      <el-empty class="vab-data-empty" description="暂无数据" />
    </template>
  </el-table>
</template>

<script lang="ts" setup>
import { useErrorLogStore } from '/@/store/modules/errorLog'

defineOptions({
  name: 'VabErrorLogContent',
})

const errorLogStore = useErrorLogStore()
const { errorLogs } = storeToRefs(errorLogStore)
</script>
