<template>
  <div class="quiz-feedback-container">
    <!-- 筛选工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="处理状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option label="待回复" value="pending" />
            <el-option label="已回复" value="replied" />
          </el-select>
        </el-form-item>
        <el-form-item label="关联活动">
          <el-select
            v-model="queryForm.activity_id"
            placeholder="全部活动"
            clearable
            style="width: 220px"
          >
            <el-option
              v-for="act in activityOptions"
              :key="act.id"
              :label="act.title"
              :value="act.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索选手 / 工号 / 反馈内容"
            clearable
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><Search /></el-icon>
            查询
          </el-button>
          <el-button @click="handleReset">
            <el-icon><Refresh /></el-icon>
            重置
          </el-button>
        </el-form-item>
      </el-form>

      <div class="action-buttons">
        <el-button
          type="danger"
          :disabled="selectedRows.length === 0"
          @click="handleBatchDelete"
        >
          <el-icon><Delete /></el-icon>
          批量删除 ({{ selectedRows.length }})
        </el-button>
      </div>
    </el-card>

    <!-- 反馈列表表格 -->
    <el-card shadow="never" class="table-card mt-16">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" align="center" />
        <el-table-column prop="id" label="ID" width="70" align="center" sortable />
        <el-table-column label="反馈选手" width="160">
          <template #default="{ row }">
            <div class="user-cell">
              <div class="name-row">
                <span class="font-bold">{{ row.user_name }}</span>
                <el-tag size="small" type="info" class="ml-4">{{ row.user_no || 'HW0000' }}</el-tag>
              </div>
              <div class="dept-text text-muted">{{ row.department || '未分组' }}</div>
              <div class="phone-text text-muted">{{ row.contact || '暂无电话' }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="activity_title" label="关联活动" min-width="160" show-overflow-tooltip />
        <el-table-column label="反馈内容与凭据截图" min-width="260">
          <template #default="{ row }">
            <div class="feedback-content-box">
              <div class="content-text">{{ row.content }}</div>
              <!-- 截图列表 -->
              <div v-if="row.images && row.images.length" class="images-preview-row">
                <el-image
                  v-for="(img, idx) in row.images"
                  :key="idx"
                  :src="img"
                  fit="cover"
                  class="thumb-img"
                  preview-teleported
                  :preview-src-list="row.images"
                />
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="管理员官方答复" min-width="220">
          <template #default="{ row }">
            <div v-if="row.reply_content" class="reply-box">
              <div class="reply-text text-primary">{{ row.reply_content }}</div>
              <div class="reply-time text-muted">{{ formatTime(row.reply_time) }}</div>
            </div>
            <span v-else class="text-muted">尚未回复</span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 'replied' ? 'success' : 'danger'" effect="plain">
              {{ row.status === 'replied' ? '已回复' : '待处理' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="反馈时间" width="160" align="center">
          <template #default="{ row }">
            {{ formatTime(row.create_time) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="176" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="openReplyDialog(row)">
                {{ row.status === 'replied' ? '再次回复' : '在线回复' }}
              </el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">
                删除
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页器 -->
      <div class="pagination-container">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.limit"
          :total="pagination.total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="loadData"
          @current-change="loadData"
        />
      </div>
    </el-card>

    <!-- 回复弹窗 -->
    <el-dialog
      v-model="replyDialogVisible"
      title="回复选手反馈"
      width="540px"
      destroy-on-close
    >
      <div v-if="currentFeedback" class="reply-dialog-body">
        <div class="orig-feedback-quote">
          <div class="quote-header">
            <strong>{{ currentFeedback.user_name }}</strong> 于 {{ formatTime(currentFeedback.create_time) }} 提报：
          </div>
          <div class="quote-body">{{ currentFeedback.content }}</div>
        </div>
        <el-form :model="replyForm" label-width="80px" class="mt-16">
          <el-form-item label="回复内容" required>
            <el-input
              v-model="replyForm.reply_content"
              type="textarea"
              :rows="4"
              placeholder="请输入答复内容，将同步在移动端答题中心向选手展示..."
            />
          </el-form-item>
        </el-form>
      </div>
      <template #footer>
        <el-button @click="replyDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="replyLoading" @click="handleConfirmReply">
          发送回复
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Refresh, Delete } from '@element-plus/icons-vue'
import {
  getQuizFeedbackList,
  replyQuizFeedback,
  delQuizFeedback,
  getQuizActivityList,
} from '/@/api/quiz'
import dayjs from 'dayjs'

defineOptions({
  name: 'QuizFeedbackManage',
})

const loading = ref(false)
const replyLoading = ref(false)
const replyDialogVisible = ref(false)
const selectedRows = ref<any[]>([])
const activityOptions = ref<any[]>([])
const currentFeedback = ref<any>(null)

// 查询表单
const queryForm = reactive({
  status: '',
  activity_id: undefined as number | undefined,
  search: '',
})

// 分页
const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

// 列表数据
const tableData = ref<any[]>([])

// 回复表单
const replyForm = reactive({
  reply_content: '',
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm')
}

// 搜索
const handleSearch = () => {
  pagination.page = 1
  loadData()
}

const handleReset = () => {
  queryForm.status = ''
  queryForm.activity_id = undefined
  queryForm.search = ''
  pagination.page = 1
  loadData()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

// 加载活动列表
const loadActivities = async () => {
  try {
    const res: any = await getQuizActivityList({ limit: 100 })
    if (res?.code === 1 && res?.data) {
      activityOptions.value = res.data.list || []
    }
  } catch (error) {
    activityOptions.value = [
      { id: 1, title: '2026 年度全员信息安全知识竞答' },
      { id: 2, title: '敏捷开发与云原生架构技术摸底自测' },
    ]
  }
}

// 加载反馈数据
const loadData = async () => {
  loading.value = true
  try {
    const res: any = await getQuizFeedbackList({
      page: pagination.page,
      limit: pagination.limit,
      ...queryForm,
    })
    if (res?.code === 1 && res?.data) {
      tableData.value = res.data.list || []
      pagination.total = res.data.total || 0
    }
  } catch (error) {
    console.warn('获取意见反馈失败，呈现本地模拟数据', error)
    if (tableData.value.length === 0) {
      tableData.value = [
        {
          id: 1,
          user_name: '赵六',
          user_no: 'HW1004',
          department: '数字营销部',
          contact: '13812345678',
          activity_title: '2026 年度全员信息安全知识竞答',
          content: '第 8 题“关于数据跨境合规要求”的题干中，参考法规的发布年份似乎有笔误，建议教研组核验。',
          images: [
            'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?w=200&q=80',
          ],
          status: 'pending',
          reply_content: '',
          reply_time: '',
          create_time: Date.now() - 3600000,
        },
        {
          id: 2,
          user_name: '钱八',
          user_no: 'HW1006',
          department: '质量效能中心',
          contact: 'qianba@huawei.com',
          activity_title: '敏捷开发与云原生架构技术摸底自测',
          content: '答题过程中由于网络短暂抖动提示了一次切屏，希望答题记录里切屏统计能够区分切出与瞬断。',
          images: [],
          status: 'replied',
          reply_content: '已收到您的宝贵反馈，已优化切屏检测容差算法，瞬断网络不会误计入作弊切屏。',
          reply_time: Date.now() - 7200000,
          create_time: Date.now() - 14400000,
        },
      ]
      pagination.total = 2
    }
  } finally {
    loading.value = false
  }
}

// 打开回复弹窗
const openReplyDialog = (row: any) => {
  currentFeedback.value = row
  replyForm.reply_content = row.reply_content || ''
  replyDialogVisible.value = true
}

// 确认回复
const handleConfirmReply = async () => {
  if (!replyForm.reply_content.trim()) {
    ElMessage.warning('请输入回复内容')
    return
  }
  replyLoading.value = true
  try {
    await replyQuizFeedback({
      id: currentFeedback.value.id,
      reply_content: replyForm.reply_content,
    })
    ElMessage.success('回复成功已同步')
    replyDialogVisible.value = false
    loadData()
  } catch (err: any) {
    ElMessage.error(err?.message || '回复失败')
  } finally {
    replyLoading.value = false
  }
}

// 删除反馈
const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除此条来自【${row.user_name}】的反馈吗？`, '删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizFeedback(row.id)
      ElMessage.success('反馈记录已删除')
      loadData()
    } catch (err: any) {
      ElMessage.error(err?.message || '删除失败')
    }
  })
}

// 批量删除
const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要批量删除选中的 ${ids.length} 条反馈吗？`, '批量删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delQuizFeedback(ids)
      ElMessage.success('批量删除完成')
      loadData()
    } catch (err: any) {
      ElMessage.error(err?.message || '批量删除失败')
    }
  })
}

onMounted(() => {
  loadActivities()
  loadData()
})
</script>

<style scoped lang="scss">
.quiz-feedback-container {
  padding: 16px;

  .toolbar-card {
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .action-buttons {
      margin-top: 12px;
      display: flex;
      gap: 10px;
    }
  }

  .mt-16 {
    margin-top: 16px;
  }

  .ml-4 {
    margin-left: 4px;
  }

  .text-muted {
    color: #94a3b8;
    font-size: 12px;
  }

  .text-primary {
    color: #2563eb;
  }

  .font-bold {
    font-weight: 700;
  }

  .user-cell {
    display: flex;
    flex-direction: column;

    .name-row {
      display: flex;
      align-items: center;
    }
  }

  .feedback-content-box {
    .content-text {
      color: #1e293b;
      line-height: 1.5;
      font-size: 13px;
      margin-bottom: 6px;
    }

    .images-preview-row {
      display: flex;
      gap: 6px;

      .thumb-img {
        width: 48px;
        height: 48px;
        border-radius: 4px;
        border: 1px solid #e2e8f0;
      }
    }
  }

  .reply-box {
    background: #f0f7ff;
    border-radius: 6px;
    padding: 8px 10px;

    .reply-text {
      font-size: 13px;
      line-height: 1.4;
      margin-bottom: 4px;
    }

    .reply-time {
      font-size: 11px;
    }
  }

  .pagination-container {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }

  .reply-dialog-body {
    .orig-feedback-quote {
      background: #f8fafc;
      border-left: 4px solid #3b82f6;
      border-radius: 4px;
      padding: 10px 12px;

      .quote-header {
        font-size: 13px;
        color: #475569;
        margin-bottom: 4px;
      }

      .quote-body {
        font-size: 13px;
        color: #1e293b;
        line-height: 1.4;
      }
    }
  }
}
</style>
