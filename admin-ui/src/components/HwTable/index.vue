<template>
  <el-card shadow="never" class="hw-table-card">
    <div v-if="$slots.toolbar" class="hw-table-toolbar">
      <slot name="toolbar" />
    </div>

    <el-table
      v-loading="loading"
      :data="data"
      :row-key="rowKey"
      :border="border"
      :stripe="stripe"
      @selection-change="onSelectionChange"
    >
      <el-table-column v-if="selectable" type="selection" width="48" align="center" />

      <template v-for="col in columns" :key="col.prop">
        <el-table-column
          :prop="col.prop"
          :label="col.label"
          :width="col.width"
          :min-width="col.minWidth"
          :align="col.align || 'left'"
          :fixed="col.fixed"
          :sortable="col.sortable"
          :show-overflow-tooltip="col.showOverflowTooltip ?? true"
        >
          <template #default="scope">
            <slot
              v-if="hasColumnSlot(col)"
              :name="columnSlotName(col)"
              :row="scope.row as T"
              :column="col"
              :index="scope.$index"
            >
              {{ (scope.row as T)[col.prop] }}
            </slot>
            <template v-else-if="col.formatter">
              {{ col.formatter(scope.row as T, (scope.row as T)[col.prop], scope.$index) }}
            </template>
            <template v-else>
              {{ (scope.row as T)[col.prop] ?? '-' }}
            </template>
          </template>
        </el-table-column>
      </template>

      <slot />

      <el-table-column
        v-if="$slots.actions"
        :label="actionLabel"
        :width="actionWidth"
        :fixed="actionFixed"
        align="center"
      >
        <template #default="scope">
          <div class="hw-table-actions">
            <slot name="actions" :row="scope.row as T" :index="scope.$index" />
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div v-if="pagination" class="hw-table-pagination">
      <el-pagination
        :current-page="pagination.page"
        :page-size="pagination.limit"
        :total="pagination.total"
        :page-sizes="pageSizes"
        layout="total, sizes, prev, pager, next, jumper"
        @size-change="onSizeChange"
        @current-change="onCurrentChange"
      />
    </div>
  </el-card>
</template>

<script setup lang="ts" generic="T extends Record<string, any>">
import { useSlots } from 'vue'
import type { HwTableColumn } from './types'

export type { HwTableColumn }

const props = withDefaults(
  defineProps<{
    data: T[]
    columns?: HwTableColumn<T>[]
    loading?: boolean
    rowKey?: string
    border?: boolean
    stripe?: boolean
    selectable?: boolean
    pagination?: {
      page: number
      limit: number
      total: number
    }
    pageSizes?: number[]
    actionLabel?: string
    actionWidth?: number | string
    actionFixed?: 'left' | 'right' | false
  }>(),
  {
    columns: () => [],
    loading: false,
    rowKey: 'id',
    border: true,
    stripe: false,
    selectable: true,
    pagination: undefined,
    pageSizes: () => [10, 20, 50, 100],
    actionLabel: '操作',
    actionWidth: 160,
    actionFixed: 'right',
  }
)

const emit = defineEmits<{
  (e: 'selection-change', rows: T[]): void
  (e: 'page-change', page: number, limit: number): void
}>()

defineSlots<{
  toolbar?: () => any
  default?: () => any
  actions?: (props: { row: T; index: number }) => any
  [key: `cell-${string}`]: ((props: { row: T; column: HwTableColumn<T>; index: number }) => any) | undefined
}>()

const slots = useSlots() as Record<string, unknown>

const columnSlotName = (col: HwTableColumn<T>): `cell-${string}` => {
  return (col.slot || `cell-${col.prop}`) as `cell-${string}`
}

const hasColumnSlot = (col: HwTableColumn<T>): boolean => {
  if (col.slot) return true
  return Boolean(slots[`cell-${col.prop}`])
}

const onSelectionChange = (rows: any[]) => {
  emit('selection-change', rows as T[])
}

const onSizeChange = (size: number) => {
  if (!props.pagination) return
  props.pagination.limit = size
  props.pagination.page = 1
  emit('page-change', 1, size)
}

const onCurrentChange = (page: number) => {
  if (!props.pagination) return
  props.pagination.page = page
  emit('page-change', page, props.pagination.limit)
}
</script>

<style scoped lang="scss">
.hw-table-card {
  .hw-table-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
  }

  .hw-table-pagination {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}
</style>
