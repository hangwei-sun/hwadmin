<template>
  <div class="crud-page-container site-inquiry-page">
    <HwTable
      ref="tableRef"
      :columns="columns"
      :data="tableData"
      :loading="loading"
      :pagination="pagination"
      @selection-change="handleSelectionChange"
      @page-change="fetchList"
    >
      <template #cell-status="{ row }">
        <el-tag :type="row.status === 2 ? 'success' : (row.status === 1 ? 'warning' : 'danger')">
          {{ row.status === 2 ? '已完成' : (row.status === 1 ? '已联系' : '待处理') }}
        </el-tag>
      </template>

      <template #actions="{ row }">
        <el-button
          v-auth="'site/inquiry/edit'"
          text
          type="primary"
          size="small"
          aria-label="打开询盘跟进弹窗"
          @click="openFollowUp(row)"
        >
          跟进处理
        </el-button>
        <el-button
          v-auth="'site/inquiry/del'"
          text
          type="danger"
          size="small"
          aria-label="删除询盘"
          @click="handleDelete(row)"
        >
          删除
        </el-button>
      </template>
    </HwTable>

    <el-empty v-if="!loading && tableData.length === 0" description="暂无客户在线询盘记录" />

    <el-dialog
      v-model="followDialogVisible"
      title="询盘跟进与状态流转"
      width="min(520px, 92vw)"
      destroy-on-close
      aria-label="询盘跟进弹窗"
    >
      <el-form ref="followFormRef" :model="followForm" label-width="96px">
        <el-form-item label="跟进状态">
          <el-select v-model="followForm.status" style="width: 100%" aria-label="选择跟进状态">
            <el-option :value="0" label="待处理" />
            <el-option :value="1" label="已联系" />
            <el-option :value="2" label="已完成" />
          </el-select>
        </el-form-item>
        <el-form-item label="跟进备注">
          <el-input
            v-model="followForm.remark"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            placeholder="请记录客户沟通情况与后续跟进计划"
            aria-label="填写跟进备注"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消跟进" @click="followDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" aria-label="保存跟进记录" @click="submitFollowUp">
          保存跟进
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import type { FormInstance } from 'element-plus'
import { ElMessage } from 'element-plus'
import { reactive, ref } from 'vue'
import HwTable from '/@/components/HwTable/index.vue'
import type { HwTableColumn } from '/@/components/HwTable/types'
import { useCrudTable } from '/@/hooks/useCrudTable'
import request from '/@/utils/request'

defineOptions({ name: 'SiteInquiryIndex' })

const columns: HwTableColumn<any>[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '客户姓名', width: 120 },
  { prop: 'mobile', label: '电话', width: 140 },
  { prop: 'email', label: '邮箱', width: 180 },
  { prop: 'company', label: '公司' },
  { prop: 'content', label: '需求描述' },
  { prop: 'remark', label: '跟进备注' },
  { prop: 'status', label: '状态', width: 100 },
  { prop: 'create_time', label: '提交时间', width: 170 },
]

const {
  loading,
  tableData,
  pagination,
  fetchList,
  handleSelectionChange,
  handleDelete,
} = useCrudTable<any, any>({
  fetchApi: (params) => request({ url: '/adminapi/site.inquiry/index', method: 'get', params }),
  delApi: (ids) => request({ url: '/adminapi/site.inquiry/del', method: 'post', data: { ids } }),
  rowKey: 'id',
})

const followDialogVisible = ref(false)
const submitting = ref(false)
const followFormRef = ref<FormInstance>()
const followForm = reactive({
  id: 0,
  status: 1,
  remark: '',
})

const openFollowUp = (row: any) => {
  followForm.id = Number(row.id || 0)
  followForm.status = Number(row.status ?? 1)
  followForm.remark = String(row.remark ?? '')
  followDialogVisible.value = true
}

const submitFollowUp = async () => {
  submitting.value = true
  try {
    await request({
      url: '/adminapi/site.inquiry/followUp',
      method: 'post',
      data: {
        id: followForm.id,
        status: followForm.status,
        remark: followForm.remark,
      },
    })
    ElMessage.success('询盘跟进记录已保存')
    followDialogVisible.value = false
    await fetchList()
  } catch (err: any) {
    ElMessage.error(err?.message || '更新跟进状态失败')
  } finally {
    submitting.value = false
  }
}

/* @hw-custom-start: site-inquiry-view-extensions */
/* @hw-custom-end: site-inquiry-view-extensions */
</script>

<style scoped>
@media (max-width: 768px) {
  .site-inquiry-page :deep(.el-form-item) {
    flex-direction: column;
  }
}
</style>
