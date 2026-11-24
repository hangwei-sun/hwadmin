import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, reactive, ref, type Ref } from 'vue'

export interface CrudPagination {
  page: number
  limit: number
  total: number
}

export interface UseCrudTableOptions<T extends Record<string, any>, Q extends Record<string, any>> {
  fetchApi: (params: Record<string, any>) => Promise<any>
  delApi?: (ids: (number | string)[]) => Promise<any>
  defaultQuery?: () => Q
  rowKey?: keyof T & string
  defaultLimit?: number
  immediate?: boolean
  transformParams?: (query: Q, pagination: CrudPagination) => Record<string, any>
}

/**
 * 强类型 CRUD 列表通用 Hook：统一管理查询条件、分页、多选、加载态与删除闭环，
 * 并提供 asRow(row) 辅助函数解决 Element Plus 默认插槽 row 被推导为 DefaultRow 的 TS 类型摩擦。
 */
export function useCrudTable<
  T extends Record<string, any>,
  Q extends Record<string, any> = Record<string, any>,
>(options: UseCrudTableOptions<T, Q>) {
  const rowKey = (options.rowKey ?? 'id') as keyof T & string
  const loading = ref(false)
  const tableData = ref<T[]>([]) as Ref<T[]>
  const selectedRows = ref<T[]>([]) as Ref<T[]>

  const initialQuery = options.defaultQuery ? options.defaultQuery() : ({} as Q)
  const queryForm = reactive<Q>({ ...initialQuery }) as Q

  const pagination = reactive<CrudPagination>({
    page: 1,
    limit: options.defaultLimit ?? 20,
    total: 0,
  })

  const buildParams = () => {
    if (options.transformParams) {
      return options.transformParams(queryForm, pagination)
    }
    return {
      ...queryForm,
      page: pagination.page,
      limit: pagination.limit,
    }
  }

  const fetchList = async () => {
    loading.value = true
    try {
      const res = await options.fetchApi(buildParams())
      const data = res?.data ?? {}
      tableData.value = Array.isArray(data.list) ? (data.list as T[]) : []
      pagination.total = Number(data.total ?? tableData.value.length) || 0
    } finally {
      loading.value = false
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    return fetchList()
  }

  const handleReset = () => {
    const fresh = options.defaultQuery ? options.defaultQuery() : ({} as Q)
    Object.keys(queryForm).forEach((key) => {
      delete (queryForm as Record<string, any>)[key]
    })
    Object.assign(queryForm, fresh)
    pagination.page = 1
    return fetchList()
  }

  const handleSelectionChange = (rows: unknown[]) => {
    selectedRows.value = rows as T[]
  }

  const handlePageChange = (page: number, limit?: number) => {
    pagination.page = page
    if (typeof limit === 'number' && limit > 0) {
      pagination.limit = limit
    }
    return fetchList()
  }

  const handleDelete = async (row: T, label?: string) => {
    if (!options.delApi) return
    const targetId = row[rowKey] as number | string
    const display = label || `#${targetId}`
    await ElMessageBox.confirm(`确认删除「${display}」吗？删除后不可恢复。`, '删除确认', {
      type: 'warning',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    })
    await options.delApi([targetId])
    ElMessage.success('删除成功')
    await fetchList()
  }

  const handleBatchDelete = async () => {
    if (!options.delApi || selectedRows.value.length === 0) return
    const ids = selectedRows.value.map((r) => r[rowKey] as number | string)
    await ElMessageBox.confirm(`确认批量删除选中的 ${ids.length} 条记录吗？`, '批量删除确认', {
      type: 'warning',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    })
    await options.delApi(ids)
    ElMessage.success('批量删除成功')
    selectedRows.value = []
    await fetchList()
  }

  /** 将 Element Plus 插槽中的 DefaultRow 显式收窄为业务行类型 T */
  const asRow = (row: unknown): T => row as T

  if (options.immediate !== false) {
    onMounted(() => {
      void fetchList()
    })
  }

  return {
    loading,
    tableData,
    selectedRows,
    pagination,
    queryForm,
    fetchList,
    handleSearch,
    handleReset,
    handleSelectionChange,
    handlePageChange,
    handleDelete,
    handleBatchDelete,
    asRow,
  }
}
