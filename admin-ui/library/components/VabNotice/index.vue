<template>
  <el-badge type="danger" :value="badge" :hidden="!badge">
    <el-popover placement="bottom" popper-class="vab-notice-popper" :width="350" trigger="click">
      <template #reference>
        <div class="vab-notice-icon-trigger">
          <vab-icon icon="notification-2-line" />
        </div>
      </template>

      <div class="vab-notice-header">
        <span class="vab-notice-header-title">{{ translate('实时消息与运维中心') }}</span>
        <el-tag v-if="badge" size="small" type="danger" effect="dark" round>{{ badge }} 未读</el-tag>
        <el-tag v-else size="small" type="success" effect="plain" round>全部已读</el-tag>
      </div>

      <el-tabs v-model="activeName" class="vab-notice-tabs">
        <el-tab-pane :label="`通知 (${countByType('notice')})`" name="notice">
          <div class="notice-list">
            <el-scrollbar max-height="300px">
              <ul v-if="filteredNotices('notice').length">
                <li
                  v-for="item in filteredNotices('notice')"
                  :key="item.id"
                  :class="{ 'is-read': item.read }"
                  @click="handleNoticeClick(item)"
                >
                  <div class="notice-list-left">
                    <div class="notice-icon-box" :class="item.level || 'primary'">
                      <vab-icon :icon="item.icon || 'notification-line'" />
                    </div>
                  </div>
                  <div class="notice-list-right">
                    <div class="notice-title-row">
                      <span class="notice-title">{{ item.title }}</span>
                      <span v-if="!item.read" class="unread-dot"></span>
                    </div>
                    <div class="notice-desc">{{ item.notice }}</div>
                    <div class="notice-time">{{ item.datetime }}</div>
                  </div>
                </li>
              </ul>
              <el-empty v-else description="暂无系统通知" :image-size="60" />
            </el-scrollbar>
          </div>
        </el-tab-pane>

        <el-tab-pane :label="`安全 (${countByType('security')})`" name="security">
          <div class="notice-list">
            <el-scrollbar max-height="300px">
              <ul v-if="filteredNotices('security').length">
                <li
                  v-for="item in filteredNotices('security')"
                  :key="item.id"
                  :class="{ 'is-read': item.read }"
                  @click="handleNoticeClick(item)"
                >
                  <div class="notice-list-left">
                    <div class="notice-icon-box" :class="item.level || 'warning'">
                      <vab-icon :icon="item.icon || 'shield-keyhole-line'" />
                    </div>
                  </div>
                  <div class="notice-list-right">
                    <div class="notice-title-row">
                      <span class="notice-title">{{ item.title }}</span>
                      <span v-if="!item.read" class="unread-dot"></span>
                    </div>
                    <div class="notice-desc">{{ item.notice }}</div>
                    <div class="notice-action-tag">
                      <span class="notice-time">{{ item.datetime }}</span>
                      <span class="action-hint">立即加固 &rarr;</span>
                    </div>
                  </div>
                </li>
              </ul>
              <el-empty v-else description="暂无安全风险巡检" :image-size="60" />
            </el-scrollbar>
          </div>
        </el-tab-pane>

        <el-tab-pane :label="`待办 (${countByType('todo')})`" name="todo">
          <div class="notice-list">
            <el-scrollbar max-height="300px">
              <ul v-if="filteredNotices('todo').length">
                <li
                  v-for="item in filteredNotices('todo')"
                  :key="item.id"
                  :class="{ 'is-read': item.read }"
                  @click="handleNoticeClick(item)"
                >
                  <div class="notice-list-left">
                    <div class="notice-icon-box" :class="item.level || 'info'">
                      <vab-icon :icon="item.icon || 'time-line'" />
                    </div>
                  </div>
                  <div class="notice-list-right">
                    <div class="notice-title-row">
                      <span class="notice-title">{{ item.title }}</span>
                      <span v-if="!item.read" class="unread-dot"></span>
                    </div>
                    <div class="notice-desc">{{ item.notice }}</div>
                    <div class="notice-action-tag">
                      <span class="notice-time">{{ item.datetime }}</span>
                      <span class="action-hint">前往处置 &rarr;</span>
                    </div>
                  </div>
                </li>
              </ul>
              <el-empty v-else description="暂无待办事项" :image-size="60" />
            </el-scrollbar>
          </div>
        </el-tab-pane>
      </el-tabs>

      <div class="notice-footer">
        <el-button size="small" text @click="fetchData">
          <vab-icon icon="refresh-line" />
          <span>刷新</span>
        </el-button>
        <el-button size="small" type="primary" text @click="handleClearNotice">
          <vab-icon icon="check-double-line" />
          <span>全部标为已读</span>
        </el-button>
      </div>
    </el-popover>
  </el-badge>
</template>

<script lang="ts" setup>
import { ref, computed, onBeforeMount } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { getList, readNotice } from '/@/api/notice'
import { translate } from '/@/i18n'
import { useSettingsStore } from '/@/store/modules/settings'

defineOptions({
  name: 'VabNotice',
})

const router = useRouter()
const settingsStore = useSettingsStore()
const { theme } = storeToRefs(settingsStore)

const activeName = ref<string>('security')
const notices = ref<Array<any>>([])
const badge = ref<number | undefined>(undefined)

const filteredNotices = (type: string) => {
  return notices.value.filter((item) => (item.type || item.category) === type)
}

const countByType = (type: string) => {
  return notices.value.filter((item) => (item.type || item.category) === type && !item.read).length
}

const updateBadge = () => {
  const unreadCount = notices.value.filter((item) => !item.read).length
  badge.value = unreadCount > 0 ? unreadCount : undefined
}

const fetchData = async () => {
  try {
    const res: any = await getList()
    if (res && res.data) {
      notices.value = Array.isArray(res.data.list) ? res.data.list : []
      updateBadge()
    }
  } catch (e) {
    console.warn('获取通知中心数据失败', e)
  }
}

const handleNoticeClick = async (item: any) => {
  if (!item.read) {
    item.read = true
    updateBadge()
    try {
      await readNotice({ id: item.id })
    } catch (e) {}
  }
  if (item.path) {
    router.push(item.path)
  }
}

const handleClearNotice = async () => {
  notices.value.forEach((item) => (item.read = true))
  updateBadge()
  try {
    await readNotice({ clear_all: true })
    if (typeof $baseMessage === 'function') {
      $baseMessage('已全部标记为已读', 'success', 'hey')
    }
  } catch (e) {}
}

onBeforeMount(() => {
  if (theme.value.showNotice) fetchData()
})
</script>

<style lang="scss" scoped>
.vab-notice-icon-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.vab-notice-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px 10px;
  border-bottom: 1px solid var(--el-border-color-lighter);

  .vab-notice-header-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
}

.vab-notice-tabs {
  :deep(.el-tabs__header) {
    margin: 0 0 8px 0;
  }
  :deep(.el-tabs__nav-wrap) {
    padding: 0 10px;
  }
}

.notice-list {
  height: 290px;

  ul {
    padding: 0;
    margin: 0;
    list-style: none;

    li {
      display: flex;
      align-items: flex-start;
      padding: 10px 12px;
      cursor: pointer;
      border-radius: 6px;
      transition: all 0.2s ease;

      &:hover {
        background-color: var(--el-fill-color-light);
      }

      &.is-read {
        opacity: 0.65;
      }

      .notice-list-left {
        margin-right: 10px;
        flex-shrink: 0;

        .notice-icon-box {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 36px;
          height: 36px;
          border-radius: 8px;
          font-size: 18px;

          &.primary {
            background: rgba(24, 144, 255, 0.12);
            color: #1890ff;
          }
          &.warning {
            background: rgba(250, 140, 22, 0.12);
            color: #fa8c16;
          }
          &.danger {
            background: rgba(245, 34, 45, 0.12);
            color: #f5222d;
          }
          &.success {
            background: rgba(82, 196, 26, 0.12);
            color: #52c41a;
          }
          &.info {
            background: rgba(144, 147, 153, 0.12);
            color: #909399;
          }
        }
      }

      .notice-list-right {
        flex: 1;
        min-width: 0;

        .notice-title-row {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 3px;

          .notice-title {
            font-size: 13px;
            font-weight: 500;
            color: var(--el-text-color-primary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
          }

          .unread-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: var(--el-color-danger);
            flex-shrink: 0;
            margin-left: 6px;
          }
        }

        .notice-desc {
          font-size: 12px;
          line-height: 1.45;
          color: var(--el-text-color-secondary);
          margin-bottom: 6px;
          display: -webkit-box;
          -webkit-line-clamp: 2;
          -webkit-box-orient: vertical;
          overflow: hidden;
        }

        .notice-action-tag {
          display: flex;
          align-items: center;
          justify-content: space-between;

          .action-hint {
            font-size: 11px;
            color: var(--el-color-primary);
            font-weight: 500;
          }
        }

        .notice-time {
          font-size: 11px;
          color: var(--el-text-color-placeholder);
        }
      }
    }
  }
}

.notice-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px 0 12px;
  border-top: 1px solid var(--el-border-color-lighter);
}
</style>
