<template>
  <div class="crud-page-container site-article-page">
    <HwTable
      ref="tableRef"
      :columns="columns"
      :data="tableData"
      :loading="loading"
      :pagination="pagination"
      @selection-change="handleSelectionChange"
      @page-change="fetchList"
    >
      <template #toolbar>
        <div class="toolbar-actions" role="toolbar" aria-label="文章与产品操作工具栏">
          <el-button
            v-auth="'site/article/add'"
            type="primary"
            aria-label="新增文章、产品或客户案例"
            @click="openCreate"
          >
            新增内容
          </el-button>
          <el-button
            v-auth="'site/article/del'"
            type="danger"
            :disabled="selectedRows.length === 0"
            aria-label="批量删除选中内容"
            @click="handleBatchDelete"
          >
            批量删除 ({{ selectedRows.length }})
          </el-button>
        </div>
      </template>

      <template #cell-status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'">
          {{ row.status === 1 ? '已发布' : '草稿' }}
        </el-tag>
      </template>

      <template #actions="{ row }">
        <el-button v-auth="'site/article/edit'" text type="primary" size="small" aria-label="编辑内容" @click="openEdit(row)">
          编辑
        </el-button>
        <el-button v-auth="'site/article/del'" text type="danger" size="small" aria-label="删除内容" @click="handleDelete(row)">
          删除
        </el-button>
      </template>
    </HwTable>

    <el-empty v-if="!loading && tableData.length === 0" description="暂无官网内容，点击上方“新增内容”创建" />

    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑官网内容' : '新增官网内容'"
      width="min(680px, 92vw)"
      destroy-on-close
      aria-label="官网内容编辑弹窗"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="110px">
        <el-form-item label="内容类型" prop="type">
          <el-radio-group v-model="formData.type" aria-label="选择内容类型">
            <el-radio value="article">新闻文章</el-radio>
            <el-radio value="product">产品展示</el-radio>
            <el-radio value="case">客户案例</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="标题" prop="title">
          <el-input v-model="formData.title" placeholder="请输入文章、产品或案例标题" aria-label="内容标题" />
        </el-form-item>
        <el-form-item label="URL别名(Slug)" prop="slug">
          <el-input v-model="formData.slug" placeholder="例如 enterprise-cloud-solution" aria-label="URL别名" />
        </el-form-item>
        <el-form-item label="摘要描述" prop="summary">
          <el-input v-model="formData.summary" type="textarea" :rows="2" placeholder="请输入摘要" aria-label="摘要描述" />
        </el-form-item>
        <el-form-item label="正文内容" prop="content">
          <el-input v-model="formData.content" type="textarea" :rows="5" placeholder="请输入正文内容" aria-label="正文内容" />
        </el-form-item>
        <el-form-item label="SEO 标题" prop="seo_title">
          <el-input v-model="formData.seo_title" placeholder="选填，留空默认使用主标题" aria-label="SEO标题" />
        </el-form-item>
        <el-form-item label="SEO 关键词" prop="seo_keywords">
          <el-input v-model="formData.seo_keywords" placeholder="多个关键词用英文逗号分隔" aria-label="SEO关键词" />
        </el-form-item>
        <el-form-item label="SEO 描述" prop="seo_description">
          <el-input v-model="formData.seo_description" type="textarea" :rows="2" placeholder="选填，留空默认使用摘要" aria-label="SEO描述" />
        </el-form-item>
        <el-form-item label="规范URL(Canonical)" prop="canonical_url">
          <el-input v-model="formData.canonical_url" placeholder="选填，自定义 Canonical 链接" aria-label="规范URL" />
        </el-form-item>
        <!-- @hw-custom-start: site-article-form-items -->
        <!-- @hw-custom-end: site-article-form-items -->
        <el-form-item label="发布状态" prop="status">
          <el-radio-group v-model="formData.status" aria-label="选择发布状态">
            <el-radio :value="1">发布</el-radio>
            <el-radio :value="0">草稿</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button aria-label="取消保存" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" aria-label="确认保存" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage } from 'element-plus'
import { reactive, ref } from 'vue'
import HwTable from '/@/components/HwTable/index.vue'
import type { HwTableColumn } from '/@/components/HwTable/types'
import { useCrudTable } from '/@/hooks/useCrudTable'
import request from '/@/utils/request'

defineOptions({ name: 'SiteArticleIndex' })

const columns: HwTableColumn<any>[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'type', label: '类型', width: 100 },
  { prop: 'title', label: '标题' },
  { prop: 'views', label: '浏览量', width: 100 },
  { prop: 'status', label: '状态', width: 100 },
  { prop: 'create_time', label: '创建时间', width: 170 },
]

const {
  loading,
  tableData,
  selectedRows,
  pagination,
  fetchList,
  handleSelectionChange,
  handleDelete,
  handleBatchDelete,
} = useCrudTable<any, any>({
  fetchApi: (params) => request({ url: '/adminapi/site.article/index', method: 'get', params }),
  delApi: (ids) => request({ url: '/adminapi/site.article/del', method: 'post', data: { ids } }),
  rowKey: 'id',
})

const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)
const editingId = ref(0)
const formRef = ref<FormInstance>()

const createDefaultForm = () => ({
  type: 'article',
  title: '',
  slug: '',
  summary: '',
  content: '',
  seo_title: '',
  seo_keywords: '',
  seo_description: '',
  canonical_url: '',
  status: 1,
})

const formData = reactive(createDefaultForm())

const formRules: FormRules = {
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
}

const openCreate = () => {
  isEdit.value = false
  editingId.value = 0
  Object.assign(formData, createDefaultForm())
  dialogVisible.value = true
}

const openEdit = (row: any) => {
  isEdit.value = true
  editingId.value = row.id
  Object.assign(formData, createDefaultForm(), row)
  dialogVisible.value = true
}

const submitForm = async () => {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  submitting.value = true
  try {
    if (isEdit.value) {
      await request({ url: `/adminapi/site.article/edit?id=${editingId.value}`, method: 'post', data: formData })
      ElMessage.success('保存成功')
    } else {
      await request({ url: '/adminapi/site.article/add', method: 'post', data: formData })
      ElMessage.success('新增成功')
    }
    dialogVisible.value = false
    await fetchList()
  } catch (err: any) {
    ElMessage.error(err?.message || '保存失败，请检查表单或网络')
  } finally {
    submitting.value = false
  }
}

/* @hw-custom-start: site-article-view-extensions */
/* @hw-custom-end: site-article-view-extensions */
</script>

<style scoped>
.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

@media (max-width: 768px) {
  .site-article-page :deep(.el-form-item) {
    flex-direction: column;
  }
}
</style>
