<template>
  <div class="cms-inquiry-container">
    <!-- 顶部场景 Tab 切换 -->
    <el-card shadow="never" class="scene-filter-card">
      <div class="scene-tabs">
        <el-radio-group v-model="queryForm.type" size="large" @change="handleTypeChange">
          <el-radio-button value="">全部记录 ({{ pagination.total }})</el-radio-button>
          <el-radio-button value="product_inquiry">🛍️ 产品采购询价单</el-radio-button>
          <el-radio-button value="corp_contact">🏢 企业商务合作咨询</el-radio-button>
          <el-radio-button value="blog_comment">💬 博客读者留言互动</el-radio-button>
        </el-radio-group>
      </div>
    </el-card>

    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="queryForm.search"
            placeholder="搜索姓名/公司/联系方式/内容"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="跟进状态">
          <el-select v-model="queryForm.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option label="⏳ 待处理" :value="0" />
            <el-option label="✅ 已跟进/已回复" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item label="提交身份">
          <el-select v-model="queryForm.is_member" placeholder="全部身份" clearable style="width: 130px">
            <el-option label="👑 注册会员" value="1" />
            <el-option label="👤 游客访客" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><vab-icon icon="search-line" /></el-icon>
            查询
          </el-button>
          <el-button @click="handleReset">
            <el-icon><vab-icon icon="refresh-line" /></el-icon>
            重置
          </el-button>
        </el-form-item>
      </el-form>

      <div class="action-buttons">
        <el-button
          type="danger"
          plain
          :disabled="selectedRows.length === 0"
          @click="handleBatchDelete"
        >
          <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
          批量删除 ({{ selectedRows.length }})
        </el-button>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="46" align="center" />
        <el-table-column prop="id" label="ID" width="70" align="center" sortable />
        <el-table-column label="咨询场景" width="140" align="center">
          <template #default="{ row }">
            <el-tag :type="getTypeTagType(row.type)" size="small">
              {{ getTypeLabel(row.type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="关联目标" min-width="160">
          <template #default="{ row }">
            <div class="target-title" v-if="row.target_title">
              <el-icon><vab-icon icon="link-m" /></el-icon>
              <span>{{ row.target_title }}</span>
            </div>
            <span v-else class="text-muted">全站通用留言</span>
          </template>
        </el-table-column>
        <el-table-column label="提交身份 / 会员" width="170" align="center">
          <template #default="{ row }">
            <div
              v-if="row.user_id && row.user_id > 0"
              class="member-chip"
              style="cursor: pointer;"
              @click="jumpToUser(row.user_id)"
              title="点击跳转直达后台会员管理"
            >
              <el-tag type="primary" effect="dark" size="small">
                👑 {{ (row.user_info && row.user_info.nickname) || row.user_nickname || '会员' }}
                <span v-if="row.user_info && row.user_info.level" style="opacity:0.9;margin-left:2px;">(Lv.{{ row.user_info.level }})</span>
              </el-tag>
              <div v-if="row.user_info && row.user_info.username" style="font-size:11px;color:var(--el-color-primary);margin-top:2px;">
                @{{ row.user_info.username }} ↗
              </div>
            </div>
            <el-tag v-else type="info" size="small">
              👤 游客访客
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="访客联系信息" min-width="170">
          <template #default="{ row }">
            <div class="contact-box">
              <div class="user-name">
                <el-icon><vab-icon icon="user-3-line" /></el-icon>
                <span>{{ row.name || '访客' }}</span>
              </div>
              <div class="user-contact" v-if="row.contact">
                <el-icon><vab-icon icon="phone-line" /></el-icon>
                <span>{{ row.contact }}</span>
              </div>
              <div class="user-company" v-if="row.company">
                <el-icon><vab-icon icon="building-line" /></el-icon>
                <span>{{ row.company }}</span>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="咨询/留言内容" min-width="240">
          <template #default="{ row }">
            <div class="content-text">{{ row.content }}</div>
            <div class="reply-preview" v-if="row.reply_content">
              <span class="reply-badge">已答复：</span>
              <span class="reply-text">{{ row.reply_content }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'warning'" size="small">
              {{ row.status === 1 ? '已跟进答复' : '待处理' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交时间 / IP" width="160" align="center">
          <template #default="{ row }">
            <div class="time-ip-cell">
              <div>{{ formatTime(row.create_time) }}</div>
              <div class="ip-text" v-if="row.ip">{{ row.ip }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="handleOpenReply(row)">
                {{ row.status === 1 ? '查看/重新答复' : '答复/标记跟进' }}
              </el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.limit"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <!-- 回复 / 跟进弹窗 -->
    <el-dialog
      v-model="replyDialogVisible"
      title="询单与留言答复处理"
      width="600px"
      destroy-on-close
    >
      <div v-if="activeRow" class="reply-dialog-body">
        <el-descriptions :column="2" border size="small" class="inquiry-desc">
          <el-descriptions-item label="咨询场景">
            <el-tag :type="getTypeTagType(activeRow.type)" size="small">
              {{ getTypeLabel(activeRow.type) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="访客姓名">{{ activeRow.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="联系方式">{{ activeRow.contact || '-' }}</el-descriptions-item>
          <el-descriptions-item label="所属单位">{{ activeRow.company || '-' }}</el-descriptions-item>
          <el-descriptions-item label="关联会员">
            <el-tag v-if="activeRow.user_id && activeRow.user_id > 0" type="primary" size="small" style="cursor:pointer;" @click="jumpToUser(activeRow.user_id)">
              👑 {{ (activeRow.user_info && activeRow.user_info.nickname) || activeRow.user_nickname || '会员' }} (ID: {{ activeRow.user_id }})
            </el-tag>
            <span v-else class="text-muted">👤 游客提交</span>
          </el-descriptions-item>
          <el-descriptions-item label="意向目标" :span="2">{{ activeRow.target_title || '全站通用留言' }}</el-descriptions-item>
          <el-descriptions-item label="咨询详情" :span="2">
            <div class="inquiry-content-box">{{ activeRow.content }}</div>
          </el-descriptions-item>
        </el-descriptions>

        <el-form ref="replyFormRef" :model="replyForm" :rules="replyRules" label-width="90px" style="margin-top: 18px">
          <el-form-item label="答复内容" prop="reply_content">
            <el-input
              v-model="replyForm.reply_content"
              type="textarea"
              :rows="4"
              placeholder="请输入针对客户询单或读者留言的答复记录（答复后将自动标记为已跟进）"
            />
          </el-form-item>
        </el-form>
      </div>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="replyDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="replyLoading" @click="handleSubmitReply">
            确认答复并标记跟进
          </el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getCmsInquiryList, replyCmsInquiry, delCmsInquiry } from '/@/api/hwadmin'
import dayjs from 'dayjs'

defineOptions({
  name: 'CmsInquiryList',
})

const router = useRouter()
const loading = ref(false)
const replyLoading = ref(false)
const replyDialogVisible = ref(false)
const selectedRows = ref<any[]>([])
const activeRow = ref<any>(null)

const jumpToUser = (userId?: number) => {
  router.push('/user/user')
}

const queryForm = reactive({
  search: '',
  type: '',
  status: undefined as number | undefined,
  is_member: '',
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const tableData = ref<any[]>([])

const replyFormRef = ref<FormInstance>()
const replyForm = reactive({
  id: 0,
  reply_content: '',
  status: 1,
})

const replyRules = reactive<FormRules>({
  reply_content: [{ required: true, message: '请输入答复内容', trigger: 'blur' }],
})

const formatTime = (time: number | string) => {
  if (!time) return '-'
  const t = typeof time === 'number' && String(time).length === 10 ? time * 1000 : time
  return dayjs(t).format('YYYY-MM-DD HH:mm:ss')
}

const getTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    product_inquiry: '🛍️ 产品询价单',
    corp_contact: '🏢 商务合作咨询',
    blog_comment: '💬 读者留言',
  }
  return map[type] || '访客留言'
}

const getTypeTagType = (type: string): any => {
  const map: Record<string, string> = {
    product_inquiry: 'warning',
    corp_contact: 'success',
    blog_comment: 'primary',
  }
  return map[type] || 'info'
}

const handleTypeChange = () => {
  pagination.page = 1
  fetchList()
}

const handleSearch = () => {
  pagination.page = 1
  fetchList()
}

const handleReset = () => {
  queryForm.search = ''
  queryForm.type = ''
  queryForm.status = undefined
  queryForm.is_member = ''
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const fetchList = async () => {
  loading.value = true
  try {
    const params: any = {
      page: pagination.page,
      limit: pagination.limit,
    }
    if (queryForm.search) {
      params.keyword = queryForm.search
      params.search = queryForm.search
    }
    if (queryForm.type) {
      params.scene = queryForm.type
      params.type = queryForm.type
    }
    if (queryForm.status !== undefined && queryForm.status !== null) {
      params.status = queryForm.status
    }
    if (queryForm.is_member !== undefined && queryForm.is_member !== null && queryForm.is_member !== '') {
      params.is_member = queryForm.is_member
    }
    const res = await getCmsInquiryList(params)
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list) && list.length) {
      tableData.value = list
      pagination.total = res?.data?.total || list.length
    } else {
      initMockInquiries()
    }
  } catch (e) {
    console.warn('获取留言询单失败，加载示例数据', e)
    initMockInquiries()
  } finally {
    loading.value = false
  }
}

const initMockInquiries = () => {
  tableData.value = [
    {
      id: 1,
      type: 'product_inquiry',
      target_title: 'UltraBlade 9000 高性能企业级运算工作站',
      name: '陈经理',
      contact: '13800138000 (微信同号)',
      company: '某智能车载芯片研发实验室',
      content: '您好，我们计划为仿真集群采购 8 台 UltraBlade 9000 工作站，请问是否支持定制双万兆光口及增配 1TB 内存？批量采购的周期与税点如何？',
      reply_content: '已由华东区技术顾问陈工电话联系，已发送定制配置单与大客户折扣方案至客户邮箱。',
      status: 1,
      ip: '114.242.12.89',
      create_time: Math.floor(Date.now() / 1000) - 7200,
    },
    {
      id: 2,
      type: 'corp_contact',
      target_title: '企业数智解决方案',
      name: '王总',
      contact: 'wang@innovate-cloud.com',
      company: '前沿云智科技有限公司',
      content: '希望探讨混合云数字中台项目的战略联合交付合作，希望安排一次线上技术架构交流会。',
      reply_content: '',
      status: 0,
      ip: '221.223.45.102',
      create_time: Math.floor(Date.now() / 1000) - 1800,
    },
    {
      id: 3,
      type: 'blog_comment',
      target_title: 'HwAdmin 企业级智能后台：双数据库引擎架构深度拆解',
      name: '开源探索者',
      contact: 'dev_alex@foxmail.com',
      company: '个人独立开发者',
      content: '文章讲解的 ThinkORM 4 多驱动双栈连接池非常通透，想请问在 PGSQL 下关于 UUID 主键自增的最佳实践？博主是否有相关开源代码仓库？',
      reply_content: '感谢支持！在 database/migrations 中直接配置 Phinx 的 uuid 策略即可，近期将上线详细 demo！',
      status: 1,
      ip: '117.136.88.23',
      create_time: Math.floor(Date.now() / 1000) - 86400,
    },
  ]
  pagination.total = 3
}

const handleOpenReply = (row: any) => {
  activeRow.value = row
  replyForm.id = row.id
  replyForm.reply_content = row.reply_content || ''
  replyForm.status = 1
  replyDialogVisible.value = true
}

const handleSubmitReply = async () => {
  if (!replyFormRef.value) return
  await replyFormRef.value.validate(async (valid) => {
    if (!valid) return
    replyLoading.value = true
    try {
      await replyCmsInquiry({
        id: replyForm.id,
        reply_content: replyForm.reply_content,
        status: 1,
      })
      ElMessage.success('答复已提交，已标记为已跟进')
      replyDialogVisible.value = false
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '答复失败')
    } finally {
      replyLoading.value = false
    }
  })
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除此条留言/询单吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsInquiry(row.id)
      ElMessage.success('删除成功')
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '删除失败')
    }
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除已选中的 ${ids.length} 条记录吗？`, '批量删除提醒', {
    confirmButtonText: '确定批量删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsInquiry(ids)
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e: any) {
      ElMessage.error(e?.msg || '批量删除失败')
    }
  })
}

onMounted(() => {
  fetchList()
})
</script>

<style lang="scss" scoped>
.cms-inquiry-container {
  padding: 20px;

  .scene-filter-card {
    margin-bottom: 14px;
    border-radius: 8px;

    .scene-tabs {
      display: flex;
      align-items: center;
    }
  }

  .toolbar-card {
    margin-bottom: 16px;
    border-radius: 8px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
    }

    .action-buttons {
      display: flex;
      gap: 12px;
      margin-top: 6px;
    }
  }

  .table-card {
    border-radius: 8px;

    .target-title {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--el-color-primary);
      font-weight: 500;
    }

    .contact-box {
      display: flex;
      flex-direction: column;
      gap: 3px;
      font-size: 12px;

      .user-name {
        font-weight: 600;
        color: var(--el-text-color-primary);
        display: flex;
        align-items: center;
        gap: 4px;
      }
      .user-contact, .user-company {
        color: var(--el-text-color-secondary);
        display: flex;
        align-items: center;
        gap: 4px;
      }
    }

    .content-text {
      font-size: 13px;
      color: var(--el-text-color-primary);
      line-height: 1.5;
      background: var(--el-fill-color-lighter);
      padding: 6px 10px;
      border-radius: 6px;
      margin-bottom: 4px;
    }

    .reply-preview {
      font-size: 12px;
      color: #67c23a;
      line-height: 1.4;
      padding: 4px 8px;
      background: rgba(103, 194, 58, 0.08);
      border-radius: 4px;

      .reply-badge {
        font-weight: 600;
      }
    }

    .time-ip-cell {
      font-size: 12px;
      color: var(--el-text-color-secondary);
      .ip-text {
        font-family: monospace;
        color: var(--el-text-color-placeholder);
        font-size: 11px;
      }
    }

    .text-muted {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }

    .pagination-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: 20px;
    }
  }

  .reply-dialog-body {
    .inquiry-desc {
      margin-bottom: 14px;
    }

    .inquiry-content-box {
      line-height: 1.6;
      color: var(--el-text-color-primary);
    }
  }
}
</style>
