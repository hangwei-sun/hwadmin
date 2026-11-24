<template>
  <div class="hw-icon-selector">
    <el-input
      v-model="internalValue"
      :placeholder="placeholder"
      clearable
      @input="handleInput"
      @clear="handleClear"
    >
      <template #prefix>
        <div class="input-icon-preview" :class="{ 'has-icon': Boolean(modelValue) }">
          <vab-icon v-if="modelValue" :icon="modelValue" />
          <el-icon v-else class="empty-placeholder-icon"><component :is="PictureRounded" /></el-icon>
        </div>
      </template>

      <template #append>
        <el-button type="primary" class="select-btn" @click="openDialog">
          <span class="btn-text">🎨 选择图标</span>
        </el-button>
      </template>
    </el-input>

    <!-- 可视化图标选择弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      title="选择 RemixIcon 菜单图标"
      width="780px"
      top="6vh"
      append-to-body
      destroy-on-close
      class="hw-icon-dialog"
    >
      <div class="dialog-inner">
        <!-- 顶部搜索与风格过滤栏 -->
        <div class="toolbar-box">
          <div class="search-input-wrap">
            <el-input
              v-model="searchKeyword"
              placeholder="搜索图标英文，如 home, setting, user..."
              clearable
              prefix-icon="Search"
            />
          </div>

          <div class="style-filter-wrap">
            <el-radio-group v-model="activeStyle" size="default">
              <el-radio-button value="all">全部风格</el-radio-button>
              <el-radio-button value="line">线条 (Line)</el-radio-button>
              <el-radio-button value="fill">实心 (Fill)</el-radio-button>
            </el-radio-group>
          </div>

          <div v-if="modelValue" class="current-selected-badge">
            <div class="selected-chip">
              <vab-icon :icon="modelValue" />
              <span class="chip-label">{{ modelValue }}</span>
            </div>
            <el-button link type="danger" size="small" @click="handleClear">
              清除已选
            </el-button>
          </div>
        </div>

        <!-- 分类胶囊切换栏 -->
        <div class="category-tabs">
          <div
            v-for="cat in categories"
            :key="cat.key"
            class="category-pill"
            :class="{ active: activeCategory === cat.key }"
            @click="activeCategory = cat.key"
          >
            <span class="cat-label">{{ cat.label }}</span>
            <span class="cat-count">{{ getCategoryCount(cat.key) }}</span>
          </div>
        </div>

        <!-- 图标响应式网格区 -->
        <div class="icon-grid-container">
          <el-empty
            v-if="filteredIcons.length === 0"
            description="未匹配到相关图标，支持在输入框直接手写任意 RemixIcon 名称"
            :image-size="80"
          />

          <div v-else class="icon-grid">
            <div
              v-for="iconName in filteredIcons"
              :key="iconName"
              class="icon-grid-item"
              :class="{ selected: modelValue === iconName }"
              :title="iconName"
              @click="handleSelect(iconName)"
            >
              <div class="glyph-wrap">
                <vab-icon :icon="iconName" />
              </div>
              <span class="name-text">{{ iconName }}</span>
              <div v-if="modelValue === iconName" class="active-badge">
                <el-icon><Check /></el-icon>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="dialog-footer-layout">
          <div class="stat-info">
            共计 <strong>{{ allDistinctIcons.length }}</strong> 款常用图标，当前匹配 <strong>{{ filteredIcons.length }}</strong> 款
          </div>
          <div class="footer-btns">
            <el-button @click="dialogVisible = false">取消</el-button>
            <el-button v-if="modelValue" type="danger" plain @click="handleClear">清空当前</el-button>
            <el-button type="primary" @click="dialogVisible = false">确定</el-button>
          </div>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { PictureRounded, Check } from '@element-plus/icons-vue'

defineOptions({
  name: 'HwIconSelector',
})

const props = withDefaults(
  defineProps<{
    modelValue?: string
    placeholder?: string
  }>(),
  {
    modelValue: '',
    placeholder: '点击右侧按钮选择或直接输入 RemixIcon 图标名称',
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', val: string): void
}>()

const internalValue = ref(props.modelValue)
watch(
  () => props.modelValue,
  (val) => {
    internalValue.value = val || ''
  }
)

const dialogVisible = ref(false)
const searchKeyword = ref('')
const activeStyle = ref<'all' | 'line' | 'fill'>('all')
const activeCategory = ref('all')

const categories = [
  { key: 'all', label: '全部图标' },
  { key: 'common', label: '常用精选' },
  { key: 'system', label: '系统设置' },
  { key: 'user', label: '用户权限' },
  { key: 'content', label: '内容文档' },
  { key: 'cloud', label: '云存硬件' },
  { key: 'commerce', label: '商业图表' },
]

// 7 大分类精选后台图标库（确保 100% 存在于 RemixIcon）
const ICON_PRESETS: Record<string, string[]> = {
  common: [
    'home-4-line', 'home-2-line', 'dashboard-3-line', 'dashboard-2-line', 'apps-2-line', 'apps-line',
    'compass-3-line', 'star-line', 'bookmark-line', 'fire-line', 'trophy-line', 'gift-2-line',
    'flashlight-line', 'heart-3-line', 'thumb-up-line', 'lightbulb-line', 'sparkling-2-line',
    'magic-line', 'rocket-2-line', 'flag-2-line', 'map-pin-2-line', 'scan-2-line', 'qr-code-line',
    'barcode-line', 'time-line', 'calendar-todo-line', 'notification-3-line', 'alarm-warning-line',
    'information-line', 'question-line', 'checkbox-circle-line', 'close-circle-line', 'check-line',
    'close-line', 'add-line', 'subtract-line', 'search-line', 'share-forward-line', 'menu-line'
  ],
  system: [
    'settings-3-line', 'settings-4-line', 'tools-line', 'shield-check-line', 'shield-keyhole-line',
    'lock-password-line', 'key-2-line', 'equalizer-line', 'terminal-box-line', 'bug-line',
    'global-line', 'refresh-line', 'history-line', 'cpu-line', 'server-line', 'hard-drive-2-line',
    'database-2-line', 'router-line', 'macbook-line', 'device-line', 'shut-down-line', 'restart-line',
    'command-line', 'code-box-line', 'terminal-line', 'filter-3-line', 'sort-asc', 'search-2-line',
    'eye-line', 'eye-close-line', 'contrast-2-line', 'palette-line', 'paint-brush-line', 'cursor-line',
    'function-line', 'code-s-slash-line', 'cpu-fill', 'database-fill'
  ],
  user: [
    'user-3-line', 'user-settings-line', 'user-add-line', 'user-star-line', 'user-heart-line',
    'team-line', 'group-line', 'admin-line', 'vip-crown-2-line', 'id-card-line', 'fingerprint-line',
    'user-follow-line', 'parent-line', 'contacts-book-2-line', 'profile-line', 'account-circle-line',
    'user-shared-line', 'user-received-line', 'emotion-happy-line', 'emotion-line', 'user-smile-line',
    'shield-user-line', 'women-line', 'men-line', 'open-arm-line', 'body-scan-line', 'vip-diamond-line',
    'medal-line', 'medal-2-line', 'user-voice-line', 'user-location-line', 'user-unfollow-line',
    'user-search-line', 'shield-user-fill', 'admin-fill'
  ],
  content: [
    'article-line', 'book-open-line', 'file-list-3-line', 'folder-open-line', 'folder-3-line',
    'edit-box-line', 'questionnaire-line', 'feedback-line', 'chat-3-line', 'chat-1-line',
    'mail-send-line', 'mail-open-line', 'image-2-line', 'video-line', 'music-2-line',
    'attachment-line', 'file-copy-2-line', 'file-pdf-line', 'file-word-line', 'file-excel-line',
    'markdown-line', 'newspaper-line', 'draft-line', 'survey-line', 'chat-smile-2-line',
    'message-3-line', 'discuss-line', 'translate-2', 'pages-line', 'layout-masonry-line',
    'markup-line', 'edit-2-line', 'draft-fill', 'article-fill', 'book-read-line'
  ],
  cloud: [
    'cloud-line', 'cloud-windy-line', 'upload-cloud-2-line', 'download-cloud-2-line', 'links-line',
    'wifi-line', 'base-station-line', 'signal-tower-line', 'rss-line', 'broadcast-line',
    'share-box-line', 'download-2-line', 'upload-2-line', 'inbox-archive-line', 'archive-drawer-line',
    'folder-cloud-line', 'server-fill', 'cloud-fill', 'node-tree', 'git-branch-line',
    'git-commit-line', 'git-pull-request-line', 'git-repository-line', 'terminal-window-line',
    'cloud-off-line', 'hard-drive-line', 'sd-card-line', 'usb-line', 'radar-line',
    'wireless-charging-line', 'remote-control-line', 'sim-card-2-line', 'bluetooth-line', 'hotspot-line'
  ],
  commerce: [
    'shopping-bag-3-line', 'shopping-cart-2-line', 'store-2-line', 'bank-card-line', 'money-cny-circle-line',
    'coupon-3-line', 'pie-chart-2-line', 'bar-chart-grouped-line', 'line-chart-line', 'wallet-3-line',
    'exchange-cny-line', 'funds-box-line', 'stock-line', 'safe-2-line', 'price-tag-3-line',
    'copper-coin-line', 'scales-3-line', 'auction-line', 'presentation-line', 'shake-hands-line',
    'customer-service-2-line', 'gift-line', 'trophy-fill', 'building-2-line', 'building-4-line',
    'funds-line', 'red-packet-line', 'refund-2-line', 'hand-coin-line', 'money-dollar-circle-line',
    'percent-line', 'bar-chart-2-line', 'donut-chart-line', 'bubble-chart-line', 'coins-line'
  ],
}

// 提取所有不重复图标
const allDistinctIcons = computed(() => {
  const set = new Set<string>()
  Object.values(ICON_PRESETS).forEach((list) => {
    list.forEach((item) => set.add(item))
  })
  return Array.from(set)
})

// 各分类图标数量
const getCategoryCount = (catKey: string): number => {
  if (catKey === 'all') {
    return allDistinctIcons.value.length
  }
  return ICON_PRESETS[catKey]?.length || 0
}

// 动态过滤图标列表
const filteredIcons = computed(() => {
  let baseList: string[] = []
  if (activeCategory.value === 'all') {
    baseList = allDistinctIcons.value
  } else {
    baseList = ICON_PRESETS[activeCategory.value] || []
  }

  // 风格转换与过滤
  let styledList = baseList
  if (activeStyle.value === 'fill') {
    styledList = baseList.map((icon) => (icon.endsWith('-line') ? icon.replace(/-line$/, '-fill') : icon))
  } else if (activeStyle.value === 'line') {
    styledList = baseList.map((icon) => (icon.endsWith('-fill') ? icon.replace(/-fill$/, '-line') : icon))
  }

  // 去重
  styledList = Array.from(new Set(styledList))

  // 关键词过滤
  const kw = searchKeyword.value.trim().toLowerCase()
  if (!kw) {
    return styledList
  }
  return styledList.filter((name) => name.toLowerCase().includes(kw))
})

const handleInput = (val: string) => {
  emit('update:modelValue', val)
}

const handleClear = () => {
  internalValue.value = ''
  emit('update:modelValue', '')
}

const openDialog = () => {
  dialogVisible.value = true
  // 若当前已选图标是 -fill 风格，自动切换为 fill 单选
  if (props.modelValue && props.modelValue.endsWith('-fill')) {
    activeStyle.value = 'fill'
  }
}

const handleSelect = (iconName: string) => {
  internalValue.value = iconName
  emit('update:modelValue', iconName)
  dialogVisible.value = false
}
</script>

<style lang="scss" scoped>
.hw-icon-selector {
  width: 100%;

  .input-icon-preview {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 4px;
    margin-right: 6px;
    font-size: 17px;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-light);
    transition: all 0.2s ease;

    &.has-icon {
      color: #fff;
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
      box-shadow: 0 2px 6px rgba(59, 130, 246, 0.35);
    }

    .empty-placeholder-icon {
      font-size: 15px;
    }
  }

  .select-btn {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    font-weight: 500;
  }
}

.dialog-inner {
  .toolbar-box {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--el-border-color-lighter);

    .search-input-wrap {
      flex: 1;
      min-width: 220px;
    }

    .current-selected-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 2px 10px;
      background: var(--el-color-primary-light-9);
      border-radius: 16px;
      font-size: 12px;

      .selected-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--el-color-primary);
        font-weight: 600;

        .chip-label {
          max-width: 130px;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }
  }

  .category-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    overflow-x: auto;

    .category-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 20px;
      background: var(--el-fill-color-light);
      color: var(--el-text-color-regular);
      font-size: 13px;
      cursor: pointer;
      user-select: none;
      transition: all 0.2s ease;

      .cat-count {
        font-size: 11px;
        padding: 1px 6px;
        border-radius: 10px;
        background: var(--el-fill-color-darker);
        color: var(--el-text-color-secondary);
      }

      &:hover {
        background: var(--el-color-primary-light-9);
        color: var(--el-color-primary);
      }

      &.active {
        background: var(--el-color-primary);
        color: #fff;
        font-weight: 600;

        .cat-count {
          background: rgba(255, 255, 255, 0.25);
          color: #fff;
        }
      }
    }
  }

  .icon-grid-container {
    min-height: 280px;
    max-height: 420px;
    overflow-y: auto;
    padding-right: 4px;

    .icon-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
      gap: 10px;

      .icon-grid-item {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 12px 6px 8px;
        border-radius: 8px;
        border: 1px solid var(--el-border-color-lighter);
        background: var(--el-bg-color);
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);

        .glyph-wrap {
          font-size: 24px;
          color: var(--el-text-color-primary);
          line-height: 1;
          margin-bottom: 8px;
          transition: transform 0.2s ease;
        }

        .name-text {
          font-size: 11px;
          color: var(--el-text-color-secondary);
          text-align: center;
          width: 100%;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        &:hover {
          border-color: var(--el-color-primary);
          background: var(--el-color-primary-light-9);
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);

          .glyph-wrap {
            color: var(--el-color-primary);
            transform: scale(1.15);
          }

          .name-text {
            color: var(--el-color-primary);
            font-weight: 500;
          }
        }

        &.selected {
          border-color: var(--el-color-primary);
          background: var(--el-color-primary-light-9);
          box-shadow: 0 0 0 1px var(--el-color-primary);

          .glyph-wrap {
            color: var(--el-color-primary);
          }

          .name-text {
            color: var(--el-color-primary);
            font-weight: 600;
          }

          .active-badge {
            position: absolute;
            top: 0;
            right: 0;
            width: 18px;
            height: 18px;
            border-bottom-left-radius: 8px;
            border-top-right-radius: 7px;
            background: var(--el-color-primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
          }
        }
      }
    }
  }
}

.dialog-footer-layout {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;

  .stat-info {
    font-size: 13px;
    color: var(--el-text-color-secondary);

    strong {
      color: var(--el-color-primary);
    }
  }

  .footer-btns {
    display: flex;
    gap: 8px;
  }
}
</style>
