<template>
  <div class="plugin-hub-container">
    <!-- 顶部蓝色渐变 Banner：主框架版本与在线更新中枢 -->
    <div class="welcome-header">
      <div class="welcome-info">
        <div class="title-row">
          <div class="framework-icon-wrap">
            <vab-icon icon="apps-2-line" />
          </div>
          <h2>HwAdmin 智能管理后台</h2>
          <el-tag effect="plain" type="info" class="version-tag">
            当前版本 v{{ displayCurrentVersion }}
          </el-tag>
          <el-tag
            v-if="frameworkInfo.has_update"
            type="danger"
            effect="dark"
            class="status-tag pulse-tag is-clickable"
            @click="frameworkUpdateDialogVisible = true"
          >
            新版本 v{{ frameworkInfo.latest_version }}
          </el-tag>
          <el-tag v-else effect="plain" type="success" class="status-tag is-latest">
            已是最新
          </el-tag>
        </div>
        <p class="subtitle">
          挑个现成应用一键装好，或让 AI 智能体照着规范快速做一个 · 兼容 MySQL 8.0+ / PG 16+ / SQLite
        </p>
      </div>
      <div class="header-actions">
        <el-button
          :loading="checkingUpdates"
          class="banner-btn banner-btn-check"
          @click="handleCheckUpdates(true)"
        >
          <vab-icon icon="scan-line" />
          检查更新
        </el-button>
        <el-button
          v-if="frameworkInfo.has_update"
          type="danger"
          class="banner-btn-upgrade"
          @click="frameworkUpdateDialogVisible = true"
        >
          <vab-icon icon="upload-cloud-line" />
          立即升级
        </el-button>
        <el-button class="banner-btn banner-btn-backup" @click="openBackupDialog">
          <vab-icon icon="history-line" />
          应用回滚 ({{ backupsList.length }})
        </el-button>
        <el-button class="banner-btn banner-btn-source" @click="openMarketSourceDialog">
          <vab-icon icon="global-line" />
          商城源
        </el-button>
        <el-button class="banner-btn banner-btn-guide" @click="guideDrawerVisible = true">
          <vab-icon icon="book-open-line" />
          开发指南
        </el-button>
        <el-button
          :loading="loading"
          class="banner-btn banner-btn-refresh"
          @click="handleFullRefresh"
        >
          <vab-icon icon="refresh-line" />
          刷新
        </el-button>
      </div>
    </div>

    <!-- 官网市场通行证 & 站点授权条 -->
    <el-card
      shadow="never"
      class="market-auth-card"
      :class="{ 'is-bound': marketAccountInfo.bound }"
    >
      <div class="auth-card-content">
        <div class="auth-card-left">
          <div :class="['auth-badge-icon', marketAccountInfo.bound ? 'is-bound' : 'is-unbound']">
            <vab-icon
              :icon="marketAccountInfo.bound ? 'shield-check-line' : 'shield-keyhole-line'"
            />
          </div>
          <div class="auth-info-block">
            <div class="auth-title-line">
              <span class="auth-main-title">官网账号与授权</span>
              <el-tag
                v-if="marketAccountInfo.bound"
                size="small"
                type="warning"
                effect="dark"
                class="vip-level-tag"
              >
                <vab-icon icon="vip-crown-line" />
                <span>{{ marketAccountInfo.user?.level || '授权会员' }}</span>
              </el-tag>
              <el-tag v-else size="small" type="info" effect="plain" class="status-pill">
                未绑定 · 可用免费应用
              </el-tag>
              <span class="market-status-pill">
                <span :class="['status-dot', marketOnline ? 'live' : 'offline']" />
                <span class="pill-text">{{ marketStatusText }}</span>
              </span>
            </div>
            <div v-if="marketAccountInfo.bound" class="auth-details-row">
              <span class="detail-item">
                <vab-icon icon="user-3-line" />
                <strong>
                  {{ marketAccountInfo.user?.nickname || marketAccountInfo.user?.username || '官网用户' }}
                </strong>
                <span class="sub-account">
                  ({{ marketAccountInfo.user?.email || marketAccountInfo.user?.username }})
                </span>
              </span>
              <span class="detail-divider">/</span>
              <span class="detail-item">
                <vab-icon icon="global-line" />
                绑定站点：<code>{{ marketAccountInfo.site_domain }}</code>
              </span>
              <span class="detail-divider">/</span>
              <span class="detail-item highlight">
                <vab-icon icon="award-line" />
                已购 <strong>{{ marketAccountInfo.licensed_plugins?.length || 0 }}</strong> 个付费应用
              </span>
            </div>
            <p v-else class="auth-tip-desc">
              绑定官网账号后，已购应用自动同步，一键安装、在线更新；支持免编译 <code>public/admin.html</code> 微视图即插即用。
            </p>
          </div>
        </div>

        <div class="auth-card-actions">
          <el-button v-if="marketPortalUrl" plain @click="openMarketPortal">
            <vab-icon icon="external-link-line" />
            访问官网
          </el-button>
          <template v-if="marketAccountInfo.bound">
            <el-button
              type="primary"
              plain
              :loading="syncingMarketLicenses"
              @click="handleSyncMarketLicenses"
            >
              <vab-icon icon="refresh-line" />
              同步已购
            </el-button>
            <el-button
              type="danger"
              plain
              :loading="loggingOutMarket"
              @click="handleLogoutMarketAccount"
            >
              <vab-icon icon="logout-box-r-line" />
              解绑
            </el-button>
          </template>
          <template v-else>
            <el-button type="primary" class="login-bind-btn" @click="openMarketLoginDialog">
              <vab-icon icon="key-2-line" />
              登录并绑定
            </el-button>
          </template>
        </div>
      </div>
    </el-card>

    <!-- 主视图双轨 Tab 导航 -->
    <div class="main-tabs-wrap">
      <el-tabs v-model="activeTab" class="plugin-tabs" @tab-change="handleTabChange">
        <!-- Tab 1: 已装与本地插件 -->
        <el-tab-pane name="local">
          <template #label>
            <div class="tab-label-item">
              <vab-icon icon="inbox-archive-line" />
              <span>我的应用</span>
              <el-badge :value="pluginList.length" class="tab-badge" type="primary" />
            </div>
          </template>

          <!-- 本地插件过滤器 -->
          <div class="filter-panel">
            <div class="filter-left">
              <el-radio-group v-model="localStatusFilter" size="default" class="capsule-radio-group">
                <el-radio-button value="all">全部状态</el-radio-button>
                <el-radio-button value="running">运行中</el-radio-button>
                <el-radio-button value="disabled">已停用</el-radio-button>
                <el-radio-button value="uninstalled">未安装</el-radio-button>
              </el-radio-group>

              <el-radio-group v-model="localSourceFilter" size="default" class="capsule-radio-group">
                <el-radio-button value="all">全部来源</el-radio-button>
                <el-radio-button value="official">官方精选</el-radio-button>
                <el-radio-button value="local">自己开发</el-radio-button>
              </el-radio-group>

              <el-select
                v-model="localCategoryFilter"
                placeholder="全部分类"
                clearable
                style="width: 150px"
              >
                <el-option label="全部分类" value="" />
                <el-option label="开发提效与工具" value="tools" />
                <el-option label="营销与运营" value="marketing" />
                <el-option label="云存储" value="storage" />
                <el-option label="内容管理" value="content" />
                <el-option label="消息" value="communication" />
                <el-option label="安全审计" value="security" />
                <el-option label="自己开发" value="custom" />
              </el-select>
            </div>

            <div class="filter-right">
              <el-input
                v-model="localSearchKeyword"
                placeholder="搜索应用"
                clearable
                prefix-icon="Search"
                style="width: 240px"
              />
            </div>
          </div>

          <!-- 本地插件卡片网格 -->
          <div v-loading="loading" class="card-grid-container">
            <el-empty
              v-if="filteredLocalPlugins.length === 0 && !loading"
              description="没有找到相关应用"
            >
              <el-button type="primary" plain @click="resetLocalFilters">重置筛选</el-button>
            </el-empty>

            <el-row v-else :gutter="20">
              <el-col
                v-for="item in filteredLocalPlugins"
                :key="item.name"
                :xs="24"
                :sm="12"
                :lg="8"
                class="grid-col"
              >
                <div
                  class="plugin-hub-card"
                  :class="{ 'is-running': item.enabled && item.installed }"
                >
                  <!-- 卡片头部 -->
                  <div class="card-header">
                    <div
                      class="icon-avatar"
                      :style="{ background: item.color || defaultGradient }"
                    >
                      <vab-icon :icon="item.icon || 'puzzle-2-line'" />
                    </div>
                    <div class="info-meta">
                      <div class="name-row">
                        <span class="plugin-name" :title="item.title || item.name">
                          {{ item.title || item.name }}
                        </span>
                        <span
                          class="source-tag"
                          :class="item.source === 'official' ? 'tag-official' : 'tag-custom'"
                        >
                          {{ item.source_label || (item.source === 'official' ? '官方精选' : '自己开发') }}
                        </span>
                        <el-tag
                          v-if="getPluginUpdateInfo(item.name)?.has_update"
                          type="danger"
                          size="small"
                          effect="dark"
                          class="pulse-tag"
                        >
                          可更新 v{{ getPluginUpdateInfo(item.name).latest_version }}
                        </el-tag>
                      </div>
                      <div class="code-row">
                        <code>{{ item.name }}</code>
                        <span class="ver-text">v{{ item.version || '1.0.0' }}</span>
                      </div>
                    </div>

                    <!-- 状态开关/徽章 -->
                    <div class="header-status">
                      <template v-if="item.installed">
                        <el-switch
                          v-model="item.enabled"
                          :loading="item._loading"
                          active-text="启用"
                          inactive-text="禁用"
                          inline-prompt
                          @change="handleToggleEnable(item)"
                        />
                      </template>
                      <template v-else>
                        <el-tag type="info" size="small" effect="plain">未安装</el-tag>
                      </template>
                    </div>
                  </div>

                  <!-- 卡片描述 -->
                  <p class="card-desc" :title="item.description">
                    {{ item.description || '暂无简介' }}
                  </p>

                  <!-- 标签组 -->
                  <div class="tag-row">
                    <span
                      v-for="(tag, idx) in item.tags || ['扩展']"
                      :key="idx"
                      class="mini-pill"
                    >
                      {{ tag }}
                    </span>
                  </div>

                  <!-- 底部信息与操作区 -->
                  <div class="card-footer">
                    <div class="author-info">
                      <vab-icon icon="user-line" />
                      <span>{{ item.author || 'HwAdmin Team' }}</span>
                    </div>

                    <div class="footer-actions">
                      <!-- 1. 未安装 -->
                      <template v-if="!item.installed">
                        <el-button
                          type="primary"
                          size="small"
                          :loading="item._loading"
                          @click="handleInstall(item)"
                        >
                          <vab-icon icon="download-line" />
                          一键安装
                        </el-button>
                      </template>

                      <!-- 2. 已安装且已启用 -->
                      <template v-else-if="item.enabled">
                        <el-button
                          v-if="item.entry_path"
                          type="primary"
                          size="small"
                          @click="goToWorkbench(item.entry_path)"
                        >
                          <vab-icon icon="rocket-2-line" />
                          进入工作台
                        </el-button>
                        <el-button size="small" @click="handleOpenConfig(item)">
                          <vab-icon icon="settings-3-line" />
                          设置
                        </el-button>
                        <el-button
                          v-if="getPluginUpdateInfo(item.name)?.has_update"
                          size="small"
                          type="danger"
                          plain
                          :loading="pluginUpdatingMap[item.name]"
                          @click="handleOnlineUpdatePlugin(item)"
                        >
                          <vab-icon icon="upload-cloud-line" />
                          更新
                        </el-button>
                        <el-button
                          v-else-if="item.upgradable"
                          size="small"
                          type="warning"
                          plain
                          @click="handleUpgrade(item)"
                        >
                          更新
                        </el-button>
                        <el-dropdown
                          trigger="click"
                          @command="(cmd: string) => handleCardCommand(cmd, item)"
                        >
                          <el-button size="small" class="more-btn">
                            <vab-icon icon="more-fill" />
                          </el-button>
                          <template #dropdown>
                            <el-dropdown-menu>
                              <el-dropdown-item command="detail">
                                <vab-icon icon="file-info-line" /> 应用详情
                              </el-dropdown-item>
                              <el-dropdown-item command="disable">
                                <vab-icon icon="shut-down-line" /> 禁用
                              </el-dropdown-item>
                              <el-dropdown-item
                                v-if="getPluginUpdateInfo(item.name)?.has_update || item.upgradable"
                                command="upgrade"
                              >
                                <vab-icon icon="upload-line" /> 更新
                              </el-dropdown-item>
                              <el-dropdown-item
                                command="uninstall"
                                divided
                                style="color: var(--el-color-danger)"
                              >
                                <vab-icon icon="delete-bin-line" /> 卸载
                              </el-dropdown-item>
                            </el-dropdown-menu>
                          </template>
                        </el-dropdown>
                      </template>

                      <!-- 3. 已安装但已停用 -->
                      <template v-else>
                        <el-button
                          type="success"
                          size="small"
                          :loading="item._loading"
                          @click="handleEnable(item)"
                        >
                          <vab-icon icon="play-circle-line" />
                          立即启用
                        </el-button>
                        <el-button size="small" @click="handleOpenConfig(item)">设置</el-button>
                        <el-button
                          v-if="getPluginUpdateInfo(item.name)?.has_update"
                          size="small"
                          type="danger"
                          plain
                          :loading="pluginUpdatingMap[item.name]"
                          @click="handleOnlineUpdatePlugin(item)"
                        >
                          更新
                        </el-button>
                        <el-dropdown
                          trigger="click"
                          @command="(cmd: string) => handleCardCommand(cmd, item)"
                        >
                          <el-button size="small" class="more-btn">
                            <vab-icon icon="more-fill" />
                          </el-button>
                          <template #dropdown>
                            <el-dropdown-menu>
                              <el-dropdown-item command="detail">
                                <vab-icon icon="file-info-line" /> 应用详情
                              </el-dropdown-item>
                              <el-dropdown-item
                                command="uninstall"
                                divided
                                style="color: var(--el-color-danger)"
                              >
                                <vab-icon icon="delete-bin-line" /> 卸载
                              </el-dropdown-item>
                            </el-dropdown-menu>
                          </template>
                        </el-dropdown>
                      </template>
                    </div>
                  </div>
                </div>
              </el-col>
            </el-row>
          </div>
        </el-tab-pane>

        <!-- Tab 2: 发现应用（官方生态应用商城） -->
        <el-tab-pane name="market">
          <template #label>
            <div class="tab-label-item">
              <vab-icon icon="store-2-line" />
              <span>发现应用</span>
              <span class="market-pill-badge">HOT</span>
            </div>
          </template>

          <!-- 市场筛选工具栏 -->
          <div class="filter-panel">
            <div class="filter-left">
              <!-- 分类胶囊按钮组 -->
              <el-radio-group
                v-model="marketCategoryFilter"
                size="default"
                class="capsule-radio-group"
              >
                <el-radio-button value="all">全部应用</el-radio-button>
                <el-radio-button value="marketing">营销</el-radio-button>
                <el-radio-button value="storage">云存储</el-radio-button>
                <el-radio-button value="content">内容管理</el-radio-button>
                <el-radio-button value="communication">消息</el-radio-button>
                <el-radio-button value="ai">AI 与自动化</el-radio-button>
                <el-radio-button value="enterprise">企业套件</el-radio-button>
              </el-radio-group>

              <!-- 费用筛选胶囊组 -->
              <el-radio-group
                v-model="marketPriceFilter"
                size="default"
                class="capsule-radio-group"
              >
                <el-radio-button value="all">全部费用</el-radio-button>
                <el-radio-button value="free">免费</el-radio-button>
                <el-radio-button value="commercial">付费</el-radio-button>
              </el-radio-group>
            </div>

            <div class="filter-right">
              <el-input
                v-model="marketSearchKeyword"
                placeholder="搜索应用"
                clearable
                prefix-icon="Search"
                style="width: 220px"
              />
              <el-button type="warning" plain @click="openLicenseDialog">
                <vab-icon icon="key-2-line" />
                输入授权码
              </el-button>
            </div>
          </div>

          <el-alert
            v-if="!marketAccountInfo.bound"
            type="warning"
            show-icon
            :closable="false"
            style="margin-bottom: 16px"
          >
            <template #title>
              应用商城上的应用需要先「登录并绑定」官网账号才能安装使用；本地已有插件可在「我的应用」中直接安装。
              <el-button
                type="primary"
                link
                size="small"
                style="margin-left: 6px; font-weight: 600"
                @click="openMarketLoginDialog"
              >
                立即登录并绑定 →
              </el-button>
            </template>
          </el-alert>

          <!-- 发现应用 3 列卡片网格 -->
          <div v-loading="marketLoading" class="card-grid-container">
            <el-empty
              v-if="filteredMarketPlugins.length === 0 && !marketLoading"
              description="没有找到相关应用"
            >
              <el-button type="primary" plain @click="resetMarketFilters">重置筛选</el-button>
            </el-empty>

            <el-row v-else :gutter="20">
              <el-col
                v-for="item in filteredMarketPlugins"
                :key="item.name"
                :xs="24"
                :sm="12"
                :lg="8"
                class="grid-col"
              >
                <div class="plugin-hub-card market-card">
                  <!-- 卡片头部 -->
                  <div class="card-header">
                    <div
                      class="icon-avatar"
                      :style="{ background: item.color || defaultGradient }"
                    >
                      <vab-icon :icon="item.icon || 'store-2-line'" />
                    </div>
                    <div class="info-meta">
                      <div class="name-row">
                        <span class="plugin-name" :title="item.title || item.name">
                          {{ item.title || item.name }}
                        </span>
                      </div>
                      <div class="code-row">
                        <code>{{ item.name }}</code>
                        <span class="rate-badge">★ {{ formatRating(item.rating) }}</span>
                        <span class="downloads-text">
                          {{ formatDownloads(item.downloads) }} 次安装
                        </span>
                      </div>
                    </div>

                    <!-- 右上角价格徽章 -->
                    <div class="header-price">
                      <span
                        class="price-badge"
                        :class="Number(item.price || 0) === 0 ? 'price-free' : 'price-commercial'"
                      >
                        {{ Number(item.price || 0) === 0 ? '免费' : item.price_label || `¥${item.price}` }}
                      </span>
                    </div>
                  </div>

                  <!-- 2-3 行描述 -->
                  <p class="card-desc" :title="item.description || item.subtitle">
                    {{ item.description || item.subtitle || '暂无描述' }}
                  </p>

                  <!-- 3 条绿色勾选图标特性列表 -->
                  <div class="features-box">
                    <div
                      v-for="(feature, fIdx) in getMarketFeatures(item)"
                      :key="fIdx"
                      class="feature-line"
                    >
                      <vab-icon icon="checkbox-circle-line" class="chk-icon" />
                      <span class="feature-txt">{{ feature }}</span>
                    </div>
                  </div>

                  <!-- 底部浅灰分割线：左作者、右状态/查看详情按钮 -->
                  <div class="card-footer">
                    <div class="author-info">
                      <vab-icon icon="building-2-line" />
                      <span>{{ item.author || 'HwAdmin Team' }}</span>
                    </div>

                    <div class="footer-actions">
                      <el-tag
                        v-if="item.local_installed && item.local_enabled"
                        type="success"
                        size="small"
                        effect="light"
                      >
                        运行中
                      </el-tag>
                      <el-tag
                        v-else-if="item.local_installed"
                        type="warning"
                        size="small"
                        effect="light"
                      >
                        未启用
                      </el-tag>
                      <el-tag
                        v-else-if="isPluginLicensed(item.name)"
                        type="success"
                        size="small"
                        effect="light"
                      >
                        已授权
                      </el-tag>

                      <el-button
                        v-if="item.local_installed && item.upgradable && marketAccountInfo.bound"
                        type="warning"
                        size="small"
                        plain
                        :loading="item._downloading"
                        @click="handleInstallMarket(item)"
                      >
                        <vab-icon icon="refresh-line" />
                        更新
                      </el-button>

                      <el-button
                        type="primary"
                        size="small"
                        plain
                        @click="openMarketDetailDrawer(item)"
                      >
                        <vab-icon icon="shopping-bag-3-line" />
                        查看详情
                      </el-button>
                    </div>
                  </div>
                </div>
              </el-col>
            </el-row>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- 主框架在线升级弹窗 -->
    <FrameworkUpdateDialog
      v-model:visible="frameworkUpdateDialogVisible"
      :framework-info="frameworkInfo"
      @updated="handleFrameworkUpdated"
    />

    <!-- 动态配置弹窗 -->
    <el-dialog
      v-model="configDialogVisible"
      :title="`应用设置：${currentPlugin?.title || currentPlugin?.name}`"
      width="640px"
      destroy-on-close
      class="registry-dialog"
    >
      <div v-loading="configLoading" class="config-dialog-content">
        <el-empty
          v-if="!configSchema.length && !configLoading"
          description="这个应用无需设置"
        />

        <el-form v-else label-width="120px">
          <el-form-item
            v-for="field in configSchema"
            :key="field.name"
            :label="field.title || field.name"
          >
            <el-switch
              v-if="field.type === 'switch' || field.type === 'bool'"
              v-model="pluginConfigValues[field.name]"
              :active-value="1"
              :inactive-value="0"
            />

            <el-select
              v-else-if="field.type === 'select'"
              v-model="pluginConfigValues[field.name]"
              style="width: 100%"
            >
              <el-option
                v-for="(label, val) in field.content || field.options || {}"
                :key="val"
                :label="label"
                :value="val"
              />
            </el-select>

            <el-input
              v-else-if="field.type === 'text' || field.type === 'textarea'"
              v-model="pluginConfigValues[field.name]"
              type="textarea"
              :rows="3"
            />

            <el-input
              v-else
              v-model="pluginConfigValues[field.name]"
              :placeholder="field.tip || ''"
            />

            <span v-if="field.tip" class="field-tip">{{ field.tip }}</span>
          </el-form-item>
        </el-form>
      </div>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="configDialogVisible = false">取消</el-button>
          <el-button
            type="primary"
            :loading="configSaveLoading"
            :disabled="!configSchema.length"
            @click="handleSaveConfig"
          >
            保存
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 插件市场详情与授权安装抽屉 -->
    <el-drawer
      v-model="marketDetailDrawerVisible"
      :title="`应用详情：${selectedMarketPlugin?.title || selectedMarketPlugin?.name || ''}`"
      size="560px"
      destroy-on-close
    >
      <div v-if="selectedMarketPlugin" class="market-detail-body">
        <div class="detail-header-card">
          <div
            class="detail-avatar"
            :style="{ background: selectedMarketPlugin.color || defaultGradient }"
          >
            <vab-icon :icon="selectedMarketPlugin.icon || 'store-2-line'" />
          </div>
          <div class="detail-header-info">
            <div class="dh-title-row">
              <h3>{{ selectedMarketPlugin.title || selectedMarketPlugin.name }}</h3>
              <span
                class="price-badge"
                :class="
                  Number(selectedMarketPlugin.price || 0) === 0
                    ? 'price-free'
                    : 'price-commercial'
                "
              >
                {{
                  Number(selectedMarketPlugin.price || 0) === 0
                    ? '免费'
                    : selectedMarketPlugin.price_label || `¥${selectedMarketPlugin.price}`
                }}
              </span>
            </div>
            <div class="dh-meta">
              <code>{{ selectedMarketPlugin.name }}</code>
              <span>
                v{{
                  selectedMarketPlugin.latest_version || selectedMarketPlugin.version || '1.0.0'
                }}
              </span>
              <span>★ {{ formatRating(selectedMarketPlugin.rating) }} 评分</span>
              <span>{{ formatDownloads(selectedMarketPlugin.downloads) }} 次安装</span>
              <span>{{ selectedMarketPlugin.author || 'HwAdmin Team' }}</span>
            </div>
          </div>
        </div>

        <div class="detail-block">
          <h4 class="block-title">应用简介</h4>
          <p class="block-text">
            {{ selectedMarketPlugin.description || selectedMarketPlugin.subtitle || '暂无描述' }}
          </p>
        </div>

        <div class="detail-block">
          <h4 class="block-title">主要功能</h4>
          <div class="feature-checklist">
            <div
              v-for="(f, i) in getMarketFeatures(selectedMarketPlugin, 8)"
              :key="i"
              class="feature-check-item"
            >
              <vab-icon icon="checkbox-circle-fill" class="check-icon" />
              <span>{{ f }}</span>
            </div>
          </div>
        </div>

        <div class="detail-block license-block">
          <div class="license-block-head">
            <h4 class="block-title" style="margin-bottom: 0">安装与授权</h4>
            <el-tag
              v-if="isPluginLicensed(selectedMarketPlugin.name)"
              size="small"
              type="success"
              effect="dark"
            >
              已获取授权
            </el-tag>
            <el-tag v-else-if="!marketAccountInfo.bound" size="small" type="warning" effect="plain">
              需先绑定官网账号
            </el-tag>
          </div>
          <p class="license-tip">
            <template v-if="!marketAccountInfo.bound">
              安装商城应用需先登录并绑定官网账号；如已购买授权码，也可直接填入下方激活。
            </template>
            <template v-else-if="isPluginLicensed(selectedMarketPlugin.name)">
              当前账号已拥有该应用授权，可直接一键安装或更新。
            </template>
            <template v-else>
              已购买授权码？在下方输入即可；或先点击「{{
                Number(selectedMarketPlugin.price || 0) > 0 ? '购买授权' : '申领授权'
              }}」。
            </template>
          </p>
          <el-input
            v-model="marketLicenseKey"
            placeholder="授权码（可选），如 LIC-HW-2026-XXXX"
            clearable
          >
            <template #prefix>
              <vab-icon icon="key-2-line" />
            </template>
          </el-input>
          <div v-if="!marketAccountInfo.bound" style="margin-top: 10px">
            <el-button type="primary" size="small" plain @click="openMarketLoginDialog">
              <vab-icon icon="user-3-line" />
              立即登录并绑定官网账号
            </el-button>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="drawer-footer-actions">
          <el-button @click="marketDetailDrawerVisible = false">关闭</el-button>
          <el-button
            v-if="
              selectedMarketPlugin &&
              !isPluginLicensed(selectedMarketPlugin.name) &&
              !selectedMarketPlugin.local_installed
            "
            :type="Number(selectedMarketPlugin.price || 0) > 0 ? 'warning' : 'primary'"
            plain
            @click="handlePurchaseLicense(selectedMarketPlugin)"
          >
            {{ Number(selectedMarketPlugin.price || 0) > 0 ? '购买授权' : '申领授权' }}
          </el-button>
          <el-button
            v-if="
              selectedMarketPlugin &&
              selectedMarketPlugin.local_installed &&
              selectedMarketPlugin.upgradable
            "
            type="warning"
            :loading="selectedMarketPlugin._downloading"
            @click="handleInstallMarket(selectedMarketPlugin)"
          >
            <vab-icon icon="refresh-line" />
            更新至 v{{ selectedMarketPlugin.latest_version }}
          </el-button>
          <el-button
            v-else-if="
              selectedMarketPlugin &&
              selectedMarketPlugin.local_installed &&
              !selectedMarketPlugin.local_enabled
            "
            type="success"
            @click="handleEnableFromDrawer(selectedMarketPlugin)"
          >
            <vab-icon icon="play-circle-line" />
            立即启用
          </el-button>
          <el-button
            v-else-if="selectedMarketPlugin && selectedMarketPlugin.local_installed"
            type="success"
            plain
            disabled
          >
            <vab-icon icon="checkbox-circle-line" />
            已安装
          </el-button>
          <el-button
            v-else
            type="primary"
            :loading="downloadSubmitting"
            @click="handleDownloadAndActivate"
          >
            <vab-icon icon="download-cloud-2-line" />
            立即安装
          </el-button>
        </div>
      </template>
    </el-drawer>

    <!-- 输入授权码弹窗 -->
    <el-dialog
      v-model="licenseDialogVisible"
      title="输入授权码"
      width="480px"
      destroy-on-close
      class="registry-dialog"
    >
      <el-form label-width="90px" style="padding-top: 6px">
        <el-form-item label="选择应用" required>
          <el-select
            v-model="globalLicensePlugin"
            placeholder="请选择要激活的应用"
            filterable
            style="width: 100%"
          >
            <el-option
              v-for="p in marketPluginList"
              :key="p.name"
              :label="`${p.title || p.name} (${p.name})`"
              :value="p.name"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="授权码" required>
          <el-input
            v-model="globalLicenseKey"
            placeholder="例如：LIC-HW-XXXX-XXXX-2026"
            clearable
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="licenseDialogVisible = false">取消</el-button>
          <el-button
            type="primary"
            :loading="globalLicenseLoading"
            @click="handleBindGlobalLicense"
          >
            绑定并安装
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 开发指南与免编译微视图抽屉 -->
    <el-drawer v-model="guideDrawerVisible" title="开发指南与免编译写法" size="620px" destroy-on-close>
      <div class="guide-drawer-content">
        <el-alert
          title="免编译即插即用：插件带个 public/admin.html，或把插件目录放进 server/plugins/ 点击「刷新」就能使用。"
          type="success"
          :closable="false"
          show-icon
          style="margin-bottom: 20px"
        />

        <div class="guide-section">
          <h4>1. 免编译微视图（推荐）</h4>
          <p>服务器无需安装 Node.js，AI 智能体也能一次写好：</p>
          <ol class="step-list">
            <li>在插件目录放一个 <code>public/admin.html</code>；</li>
            <li>后台通过 <code>runner.vue</code> 自动加载它；</li>
            <li>登录 Token 与主题通过 <code>window.__HWADMIN_BRIDGE__</code> 自动传入；</li>
            <li>可直接用 CDN 引入 Vue、Element Plus 或 React。</li>
          </ol>
        </div>

        <div class="guide-section">
          <h4>2. 目录结构</h4>
          <p>每个插件是 <code>server/plugins/</code> 下的一个目录：</p>
          <pre class="code-block"><code>server/plugins/my_plugin/
├── plugin.json         # 插件清单（声明名称、版本、依赖与自动菜单）
├── Plugin.php          # 插件生命周期（install/uninstall/enable/disable）
├── controller/         # 业务控制器（继承 ApiController / AdminController）
├── model/              # ThinkORM 业务模型
├── public/admin.html   # 免编译单文件后台视图（可选）
├── install.sql         # 安装 SQL 脚本（支持 MySQL / PG / SQLite）
├── uninstall.sql       # 卸载清理脚本
└── config.json         # 动态配置项 Schema（可选）</code></pre>
        </div>

        <div class="guide-section">
          <h4>3. 刷新并启用</h4>
          <ol class="step-list">
            <li>把插件目录放到 <code>server/plugins/&lt;name&gt;</code>；</li>
            <li>点击右上角「刷新」；</li>
            <li>在「我的应用」里找到它，点击「一键安装」或「启用」；</li>
            <li>菜单和路由会自动生成，马上就能用。</li>
          </ol>
        </div>
      </div>
    </el-drawer>

    <!-- 配置应用商城云端节点地址弹窗 -->
    <el-dialog
      v-model="marketSourceDialogVisible"
      title="商城源设置"
      width="600px"
      destroy-on-close
      class="registry-dialog"
    >
      <el-form label-width="110px" style="padding-top: 10px">
        <el-form-item label="连接状态">
          <el-tag :type="marketOnline ? 'success' : 'danger'" effect="plain">
            {{ marketOnline ? '已连接' : '未连接' }}
          </el-tag>
          <code class="gateway-inline-code">{{ activeGatewayUrl }}</code>
        </el-form-item>

        <el-form-item label="来源">
          <el-tag :type="isCustomMarketUrl ? 'warning' : 'success'" effect="plain">
            {{ isCustomMarketUrl ? '自定义' : '官方' }}
          </el-tag>
          <span v-if="defaultMarketUrl" class="gateway-tip" style="margin-left: 10px; display: inline">
            官方地址：<code>{{ defaultMarketUrl }}</code>
          </span>
        </el-form-item>

        <el-form-item label="自定义地址">
          <el-input
            v-model="marketServerUrlInput"
            placeholder="留空使用官方源（https://hw.baotoutv.cn），也可填自定义商城地址"
            clearable
          />
          <div class="gateway-tip">
            可填自建商城地址，留空即恢复官方源。保存时会自动测试连接。
          </div>
        </el-form-item>

        <el-form-item label="快速切换">
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <el-button size="small" type="primary" plain @click="marketServerUrlInput = ''">
              恢复官方源
            </el-button>
          </div>
        </el-form-item>
      </el-form>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="marketSourceDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="savingMarketSource" @click="handleSaveMarketSource">
            测试并保存
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 应用历史备份与快照回滚弹窗 -->
    <el-dialog v-model="backupDialogVisible" title="备份与回滚" width="760px" destroy-on-close>
      <div v-if="!backupsList.length" style="padding: 24px 0; text-align: center">
        <el-empty description="还没有备份记录" />
      </div>

      <el-table v-else :data="backupsList" stripe style="width: 100%">
        <el-table-column prop="id" label="备份编号" min-width="190">
          <template #default="{ row }">
            <code class="mono-id-badge">{{ row.id }}</code>
          </template>
        </el-table-column>
        <el-table-column prop="type" label="类型" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="row.type === 'framework' ? 'primary' : 'info'" effect="plain">
              {{ row.type === 'framework' ? '框架' : '应用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="名称" width="110" />
        <el-table-column prop="version" label="版本" width="90">
          <template #default="{ row }">
            <el-tag size="small" type="info" class="mono-tag">v{{ row.version }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="备份时间" width="160" />
        <el-table-column prop="size" label="体积" width="80" />
        <el-table-column label="操作" width="100" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button
                type="danger"
                plain
                size="small"
                :loading="rollingBackId === row.id"
                @click="handleRollback(row)"
              >
                回滚
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <template #footer>
        <span class="dialog-footer">
          <el-button @click="backupDialogVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- 官网市场通行证登录 & 站点绑定弹窗 -->
    <el-dialog
      v-model="marketLoginDialogVisible"
      title="登录官网账号"
      width="540px"
      destroy-on-close
      class="market-login-dialog"
    >
      <div class="market-login-intro">
        <div class="intro-icon"><vab-icon icon="shield-user-line" /></div>
        <div class="intro-text">
          <h4>绑定后，已购应用自动同步</h4>
          <p>一键安装、在线更新，省心省力。</p>
        </div>
      </div>

      <el-form :model="marketLoginForm" label-width="90px" class="market-login-form">
        <el-form-item label="账号" required>
          <el-input
            v-model="marketLoginForm.username"
            placeholder="用户名或邮箱"
            clearable
          />
        </el-form-item>
        <el-form-item label="密码" required>
          <el-input
            v-model="marketLoginForm.password"
            type="password"
            placeholder="请输入密码"
            show-password
            clearable
          />
        </el-form-item>
        <el-form-item label="绑定站点" required>
          <el-input
            v-model="marketLoginForm.site_domain"
            placeholder="当前站点域名，如 https://admin.yourdomain.com"
          />
          <span class="form-tip">授权将绑定到这个域名。</span>
        </el-form-item>
      </el-form>

      <template #footer>
        <div class="dialog-footer-actions">
          <el-button type="primary" link @click="openMarketRegister">
            <vab-icon icon="user-add-line" />
            没有账号？去官方注册 →
          </el-button>
          <div>
            <el-button @click="marketLoginDialogVisible = false">取消</el-button>
            <el-button
              type="primary"
              :loading="loggingInMarket"
              @click="handleMarketLoginSubmit"
            >
              登录并绑定
            </el-button>
          </div>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { openExternalUrl, serverOrigin } from '/@/utils/platform'
import FrameworkUpdateDialog, {
  type FrameworkReleaseInfo,
} from '/@/components/FrameworkUpdateDialog.vue'
import {
  getPluginList,
  installPlugin,
  uninstallPlugin,
  enablePlugin,
  disablePlugin,
  upgradePlugin,
  getPluginConfig,
  savePluginConfig,
  getPluginMarketList,
  downloadMarketPlugin,
  saveMarketUrl,
  getMarketAccount,
  loginMarketAccount,
  syncMarketLicenses,
  claimMarketLicense,
  logoutMarketAccount,
  checkSystemUpdates,
  updatePluginPackage,
  rollbackSystemUpdate,
  getSystemBackups,
} from '/@/api/hwadmin'
import { useRoutesStore } from '/@/store/modules/routes'

defineOptions({
  name: 'PluginManager',
})

const router = useRouter()
const routesStore = useRoutesStore()
const defaultGradient = 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)'

// 标签页
const activeTab = ref<'local' | 'market'>('local')

// 本地与市场状态
const loading = ref(false)
const marketLoading = ref(false)
const pluginList = ref<any[]>([])
const marketPluginList = ref<any[]>([])

// 本地筛选状态
const localStatusFilter = ref<string>('all')
const localSourceFilter = ref<string>('all')
const localCategoryFilter = ref<string>('')
const localSearchKeyword = ref<string>('')

// 市场筛选状态
const marketCategoryFilter = ref<string>('all')
const marketPriceFilter = ref<string>('all')
const marketSearchKeyword = ref<string>('')

// 主框架与插件在线更新状态
const checkingUpdates = ref(false)
const frameworkUpdateDialogVisible = ref(false)
const frameworkInfo = reactive<FrameworkReleaseInfo>({
  name: 'hwadmin',
  current_version: '1.6.1',
  latest_version: '1.6.1',
  has_update: false,
  release_date: '',
  changelog: [],
  download_url: '',
  min_php: '8.1',
})
const displayCurrentVersion = computed(() => {
  return frameworkInfo.current_version || '1.6.1'
})

// 备份与指南抽屉/弹窗
const backupDialogVisible = ref(false)
const guideDrawerVisible = ref(false)
const backupsList = ref<any[]>([])
const rollingBackId = ref('')
const pluginUpdatingMap = reactive<Record<string, boolean>>({})
const pluginsUpdateDict = ref<Record<string, any>>({})

// 市场详情抽屉与授权码弹窗
const marketDetailDrawerVisible = ref(false)
const selectedMarketPlugin = ref<any>(null)
const marketLicenseKey = ref('')
const downloadSubmitting = ref(false)

const licenseDialogVisible = ref(false)
const globalLicensePlugin = ref('')
const globalLicenseKey = ref('')
const globalLicenseLoading = ref(false)

// ==================== 官网市场通行证 & 站点授权中枢 ====================
const marketAccountInfo = reactive({
  bound: false,
  user: null as any,
  site_domain: serverOrigin,
  bind_time: '',
  licensed_plugins: [] as string[],
  license_map: {} as Record<string, string>,
})

const loadingMarketAccount = ref(false)
const syncingMarketLicenses = ref(false)
const loggingInMarket = ref(false)
const loggingOutMarket = ref(false)
const marketLoginDialogVisible = ref(false)
const marketLoginForm = reactive({
  username: '',
  password: '',
  site_domain: serverOrigin,
})

const isPluginLicensed = (name: string) => {
  if (!marketAccountInfo.bound) return false
  return (
    marketAccountInfo.licensed_plugins.includes(name) ||
    Boolean(marketAccountInfo.license_map[name])
  )
}

const getPluginLicenseKey = (name: string) => {
  return marketAccountInfo.license_map[name] || ''
}

// 分类兼容映射（兼容线上商城分类与本地备用目录分类）
const MARKET_CATEGORY_ALIAS: Record<string, string[]> = {
  marketing: ['marketing', 'business'],
  storage: ['storage'],
  content: ['content'],
  communication: ['communication', 'message'],
  ai: ['ai', 'tools', 'devtools'],
  enterprise: ['enterprise', 'security'],
}

const LOCAL_CATEGORY_ALIAS: Record<string, string[]> = {
  tools: ['tools', 'devtools', 'ai'],
  marketing: ['marketing', 'business'],
  storage: ['storage'],
  content: ['content'],
  communication: ['communication', 'message'],
  security: ['security', 'enterprise'],
  custom: ['custom'],
}

// 筛选本地插件
const filteredLocalPlugins = computed(() => {
  let list = pluginList.value

  if (localStatusFilter.value === 'running') {
    list = list.filter((p) => Boolean(p.installed && (p.enabled || p.status === 1)))
  } else if (localStatusFilter.value === 'disabled') {
    list = list.filter((p) => Boolean(p.installed && !p.enabled && p.status !== 1))
  } else if (localStatusFilter.value === 'uninstalled') {
    list = list.filter((p) => !p.installed)
  }

  if (localSourceFilter.value === 'official') {
    list = list.filter((p) => p.source === 'official')
  } else if (localSourceFilter.value === 'local') {
    list = list.filter((p) => p.source !== 'official')
  }

  if (localCategoryFilter.value) {
    const allowed = LOCAL_CATEGORY_ALIAS[localCategoryFilter.value] || [localCategoryFilter.value]
    list = list.filter((p) => allowed.includes(p.category || 'custom'))
  }

  const kw = localSearchKeyword.value.trim().toLowerCase()
  if (kw) {
    list = list.filter((p) => {
      return (
        (p.name && String(p.name).toLowerCase().includes(kw)) ||
        (p.title && String(p.title).toLowerCase().includes(kw)) ||
        (p.description && String(p.description).toLowerCase().includes(kw))
      )
    })
  }

  return list
})

// 筛选市场应用
const filteredMarketPlugins = computed(() => {
  let list = marketPluginList.value

  if (marketCategoryFilter.value !== 'all') {
    const allowed = MARKET_CATEGORY_ALIAS[marketCategoryFilter.value] || [
      marketCategoryFilter.value,
    ]
    list = list.filter((p) => allowed.includes(p.category))
  }

  if (marketPriceFilter.value === 'free') {
    list = list.filter((p) => Number(p.price || 0) === 0)
  } else if (marketPriceFilter.value === 'commercial') {
    list = list.filter((p) => Number(p.price || 0) > 0)
  }

  const kw = marketSearchKeyword.value.trim().toLowerCase()
  if (kw) {
    list = list.filter((p) => {
      return (
        (p.name && String(p.name).toLowerCase().includes(kw)) ||
        (p.title && String(p.title).toLowerCase().includes(kw)) ||
        (p.description && String(p.description).toLowerCase().includes(kw)) ||
        (p.subtitle && String(p.subtitle).toLowerCase().includes(kw))
      )
    })
  }

  return list
})

const resetLocalFilters = () => {
  localStatusFilter.value = 'all'
  localSourceFilter.value = 'all'
  localCategoryFilter.value = ''
  localSearchKeyword.value = ''
}

const resetMarketFilters = () => {
  marketCategoryFilter.value = 'all'
  marketPriceFilter.value = 'all'
  marketSearchKeyword.value = ''
}

const formatRating = (rating: unknown) => {
  const num = Number(rating)
  if (!Number.isFinite(num) || num <= 0) return '4.9'
  return num.toFixed(1)
}

const formatDownloads = (downloads: unknown) => {
  const num = Number(downloads)
  if (!Number.isFinite(num) || num <= 0) return '0'
  if (num >= 1000) return `${(num / 1000).toFixed(1)}k`
  return String(num)
}

const getMarketFeatures = (item: any, limit = 3): string[] => {
  if (Array.isArray(item?.features) && item.features.length) {
    return item.features.slice(0, limit)
  }
  if (Array.isArray(item?.tags) && item.tags.length) {
    return item.tags.slice(0, limit)
  }
  return ['开箱即用，自动生成菜单', '支持在线安装与热更新', '标准权限与多库兼容']
}

const fetchMarketAccount = async () => {
  loadingMarketAccount.value = true
  try {
    const res: any = await getMarketAccount()
    if (res?.code === 1 && res.data) {
      marketAccountInfo.bound = Boolean(res.data.bound)
      marketAccountInfo.user = res.data.user || null
      marketAccountInfo.site_domain = res.data.site_domain || serverOrigin
      marketAccountInfo.bind_time = res.data.bind_time || ''
      marketAccountInfo.licensed_plugins = Array.isArray(res.data.licensed_plugins)
        ? res.data.licensed_plugins
        : []
      marketAccountInfo.license_map = res.data.license_map || {}
    }
  } catch (e) {
    console.warn('获取官网市场账号绑定状态失败', e)
  } finally {
    loadingMarketAccount.value = false
  }
}

const openMarketLoginDialog = () => {
  marketLoginForm.username = marketAccountInfo.user?.username || ''
  marketLoginForm.password = ''
  marketLoginForm.site_domain = marketAccountInfo.site_domain || serverOrigin
  marketLoginDialogVisible.value = true
}

const handleMarketLoginSubmit = async () => {
  if (!marketLoginForm.username.trim()) {
    ElMessage.warning('请输入账号或邮箱')
    return
  }
  loggingInMarket.value = true
  try {
    const res: any = await loginMarketAccount({
      username: marketLoginForm.username.trim(),
      password: marketLoginForm.password,
      site_domain: marketLoginForm.site_domain.trim() || serverOrigin,
    })
    if (res?.code === 1 && res.data) {
      marketAccountInfo.bound = true
      marketAccountInfo.user = res.data.user
      marketAccountInfo.site_domain = res.data.site_domain
      marketAccountInfo.bind_time = res.data.bind_time
      marketAccountInfo.licensed_plugins = res.data.licensed_plugins || []
      marketAccountInfo.license_map = res.data.license_map || {}

      ElMessage.success(res.msg || '绑定成功')
      marketLoginDialogVisible.value = false
      await fetchMarket()
    } else {
      ElMessage.error(res?.msg || '绑定失败')
    }
  } catch (e: any) {
    ElMessage.error('绑定失败：' + (e?.msg || e?.message || '网络错误'))
  } finally {
    loggingInMarket.value = false
  }
}

const handleSyncMarketLicenses = async () => {
  syncingMarketLicenses.value = true
  try {
    const res: any = await syncMarketLicenses()
    if (res?.code === 1 && res.data) {
      marketAccountInfo.licensed_plugins = res.data.licensed_plugins || []
      marketAccountInfo.license_map = res.data.license_map || {}
      if (res.data.user) marketAccountInfo.user = res.data.user
      ElMessage.success(res.msg || '已同步已购应用')
      await Promise.all([fetchMarketAccount(), fetchMarket(), handleCheckUpdates(false)])
    } else {
      if (res?.data?.need_login) {
        marketAccountInfo.bound = false
        openMarketLoginDialog()
      }
      ElMessage.error(res?.msg || '同步失败')
    }
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
    }
    ElMessage.error('同步失败：' + (e?.msg || e?.message || '网络错误'))
  } finally {
    syncingMarketLicenses.value = false
  }
}

const handleLogoutMarketAccount = async () => {
  try {
    await ElMessageBox.confirm(
      '解绑后，付费应用将无法直接安装和更新。确定解绑吗？',
      '解绑账号',
      {
        confirmButtonText: '确定解绑',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  loggingOutMarket.value = true
  try {
    const res: any = await logoutMarketAccount()
    if (res?.code === 1) {
      marketAccountInfo.bound = false
      marketAccountInfo.user = null
      marketAccountInfo.licensed_plugins = []
      marketAccountInfo.license_map = {}
      ElMessage.success('已解绑')
      await fetchMarket()
    } else {
      ElMessage.error(res?.msg || '解绑失败')
    }
  } catch (e: any) {
    ElMessage.error('解绑失败: ' + (e?.message || '未知错误'))
  } finally {
    loggingOutMarket.value = false
  }
}

const getPluginUpdateInfo = (name: string) => {
  return pluginsUpdateDict.value[name] || null
}

const openBackupDialog = async () => {
  backupDialogVisible.value = true
  try {
    const res: any = await getSystemBackups()
    if (res?.code === 1 && Array.isArray(res.data)) {
      backupsList.value = res.data
    }
  } catch {
    // 忽略瞬时拉取失败，保留已有列表
  }
}

const handleCheckUpdates = async (showToast = true) => {
  checkingUpdates.value = true
  try {
    const res: any = await checkSystemUpdates()
    if (res?.code === 1 && res?.data) {
      const data = res.data
      if (data.framework) {
        Object.assign(frameworkInfo, data.framework)
      }
      if (data.plugins) {
        pluginsUpdateDict.value = data.plugins
      }
      if (Array.isArray(data.backups)) {
        backupsList.value = data.backups
      }
      const updatableCount = Object.values(pluginsUpdateDict.value).filter(
        (p: any) => p?.has_update
      ).length
      if (showToast || updatableCount > 0 || frameworkInfo.has_update) {
        if (frameworkInfo.has_update && updatableCount > 0) {
          ElMessage.success(
            `发现框架新版本 v${frameworkInfo.latest_version}，以及 ${updatableCount} 个应用更新`
          )
        } else if (frameworkInfo.has_update) {
          ElMessage.success(`发现框架新版本 v${frameworkInfo.latest_version}`)
        } else if (updatableCount > 0) {
          ElMessage.success(`发现 ${updatableCount} 个应用有新版本`)
        } else if (showToast) {
          ElMessage.success('框架与应用均为最新版本')
        }
      }
    } else if (showToast) {
      ElMessage.warning(res?.msg || '暂时无法检查更新，请稍后再试')
    }
  } catch {
    if (showToast) {
      ElMessage.warning('暂时无法检查更新，请稍后再试')
    }
  } finally {
    checkingUpdates.value = false
  }
}

const handleFrameworkUpdated = async () => {
  await Promise.all([fetchPlugins(), handleCheckUpdates(false)])
}

const handleOnlineUpdatePlugin = async (item: any) => {
  const pInfo = getPluginUpdateInfo(item.name) || {}
  const targetVer = pInfo.latest_version || '最新版本'
  const changelog = pInfo.changelog || '体验优化与问题修复'

  try {
    await ElMessageBox.confirm(
      `将「${item.title || item.name}」更新到 v${targetVer}？\n\n更新内容：\n${changelog}\n\n更新前会自动备份，失败会自动回滚。`,
      '更新应用',
      {
        confirmButtonText: '立即更新',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  pluginUpdatingMap[item.name] = true
  try {
    const res: any = await updatePluginPackage({ name: item.name })
    if (res?.code === 1) {
      ElMessage.success(res.msg || `「${item.title || item.name}」已更新`)
      await fetchPlugins()
      await handleCheckUpdates(false)
      await refreshMenuRoutes()
    } else {
      if (res?.data?.need_login) {
        marketAccountInfo.bound = false
        openMarketLoginDialog()
      }
      ElMessage.error(res?.msg || '更新失败')
    }
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
    }
    ElMessage.error('更新失败：' + (e?.msg || e?.message || '未知错误'))
  } finally {
    pluginUpdatingMap[item.name] = false
  }
}

const handleRollback = async (backup: any) => {
  try {
    await ElMessageBox.confirm(
      `确定回滚到 v${backup.version}（${backup.id}）吗？代码和配置将恢复到该版本。`,
      '回滚确认',
      {
        confirmButtonText: '确认回滚',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  rollingBackId.value = backup.id
  try {
    const res: any = await rollbackSystemUpdate({ backup_id: backup.id })
    if (res?.code === 1) {
      ElMessage.success(res.msg || '回滚成功')
      backupDialogVisible.value = false
      await handleCheckUpdates(false)
      await fetchPlugins()
    } else {
      ElMessage.error(res?.msg || '回滚失败')
    }
  } catch (e: any) {
    ElMessage.error('回滚失败：' + (e.message || '网络错误'))
  } finally {
    rollingBackId.value = ''
  }
}

// 节点切换状态
const marketOnline = ref(false)
const marketPortalUrl = ref('')
const configuredMarketUrl = ref('')
const defaultMarketUrl = ref('')
const isCustomMarketUrl = ref(false)
const activeGatewayUrl = ref('')
const marketSourceDialogVisible = ref(false)
const marketServerUrlInput = ref('')
const savingMarketSource = ref(false)

const marketStatusText = computed(() => {
  if (configuredMarketUrl.value) {
    return marketOnline.value
      ? `已连接 · ${configuredMarketUrl.value}`
      : `连接失败 · ${configuredMarketUrl.value}`
  }
  return marketOnline.value ? '已连接官方商城' : '商城未连接'
})

// 插件配置弹窗状态
const configDialogVisible = ref(false)
const configLoading = ref(false)
const configSaveLoading = ref(false)
const currentPlugin = ref<any>(null)
const configSchema = ref<any[]>([])
const pluginConfigValues = reactive<Record<string, any>>({})

const refreshMenuRoutes = async () => {
  try {
    await routesStore.setRoutes()
  } catch (e) {
    console.warn('刷新动态菜单路由失败', e)
  }
}

const fetchPlugins = async () => {
  loading.value = true
  try {
    const res = await getPluginList()
    const list = res?.data?.list || res?.data || []
    if (Array.isArray(list) && list.length) {
      pluginList.value = list.map((item: any) => ({
        ...item,
        enabled: Boolean(item.enabled ?? item.status === 1),
        status: item.enabled || item.status === 1 ? 1 : 0,
        _loading: false,
      }))
    } else {
      initMockPlugins()
    }
  } catch (e) {
    console.warn('获取插件列表失败，加载默认示范生态', e)
    initMockPlugins()
  } finally {
    loading.value = false
  }
}

const fetchMarket = async () => {
  marketLoading.value = true
  try {
    const res: any = await getPluginMarketList()
    const data = res?.data || {}
    marketOnline.value = Boolean(data.market_online)
    marketPortalUrl.value = data.market_portal_url || ''
    if (data.configured_market_url !== undefined)
      configuredMarketUrl.value = data.configured_market_url
    if (data.default_market_url !== undefined) defaultMarketUrl.value = data.default_market_url
    if (data.is_custom_market_url !== undefined)
      isCustomMarketUrl.value = Boolean(data.is_custom_market_url)
    if (data.active_gateway_url !== undefined) activeGatewayUrl.value = data.active_gateway_url || ''

    const list = data.list || (Array.isArray(data) ? data : [])
    marketPluginList.value = Array.isArray(list)
      ? list.map((item: any) => ({
          ...item,
          _downloading: false,
        }))
      : []
  } catch (e) {
    console.error('获取市场列表失败', e)
    marketOnline.value = false
  } finally {
    marketLoading.value = false
  }
}

const handleTabChange = (tabName: any) => {
  if (tabName === 'market' && !marketPluginList.value.length) {
    void fetchMarket()
  }
}

const handleFullRefresh = async () => {
  await Promise.all([
    fetchPlugins(),
    fetchMarket(),
    fetchMarketAccount(),
    handleCheckUpdates(false),
  ])
  await refreshMenuRoutes()
  ElMessage.success('已刷新')
}

const resolveMarketPortalBase = () => {
  if (marketPortalUrl.value) return marketPortalUrl.value.replace(/\/+$/, '')
  if (activeGatewayUrl.value) {
    return activeGatewayUrl.value
      .replace(/\/api\/plugin\/market\/gateway\/?$/, '')
      .replace(/\/+$/, '')
  }
  return 'https://hw.baotoutv.cn'
}

const openMarketPortal = () => {
  if (!marketPortalUrl.value) {
    ElMessage.warning('还没有可用的商城地址，请先在「商城源」中设置')
    return
  }
  void openExternalUrl(marketPortalUrl.value)
}

const openMarketRegister = () => {
  const base = resolveMarketPortalBase()
  void openExternalUrl(`${base}/?tab=login&mode=register`)
}

const openMarketSourceDialog = () => {
  marketServerUrlInput.value = configuredMarketUrl.value || ''
  marketSourceDialogVisible.value = true
}

const handleSaveMarketSource = async () => {
  savingMarketSource.value = true
  try {
    const res: any = await saveMarketUrl(marketServerUrlInput.value.trim())
    ElMessage.success(res?.msg || '商城源已更新')
    marketSourceDialogVisible.value = false
    await Promise.all([fetchMarket(), handleCheckUpdates(false)])
  } catch (e: any) {
    ElMessage.error(e?.msg || e?.message || '连接失败，请检查地址')
  } finally {
    savingMarketSource.value = false
  }
}

const initMockPlugins = () => {
  pluginList.value = [
    {
      name: 'cms',
      title: '内容管理',
      version: '2.1.2',
      author: 'HwAdmin Team',
      source: 'official',
      source_label: '官方精选',
      category: 'content',
      icon: 'article-line',
      color: 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
      tags: ['文章发布', '栏目管理', '标签库'],
      description: '分类、文章与内容发布，支持 SEO。',
      installed: true,
      enabled: true,
      status: 1,
      _loading: false,
    },
    {
      name: 'sms',
      title: '腾讯云短信',
      version: '1.0.0',
      author: 'HwAdmin Official',
      source: 'official',
      source_label: '官方精选',
      category: 'communication',
      icon: 'message-3-line',
      color: 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)',
      tags: ['腾讯云短信', 'TC3原生签名', '沙盒调试'],
      description: '验证码与通知短信，支持阿里云、腾讯云。',
      installed: true,
      enabled: false,
      status: 0,
      _loading: false,
    },
    {
      name: 'oss',
      title: '对象云存储 (COS/OSS/七牛)',
      version: '1.0.2',
      author: 'HwAdmin Official',
      source: 'official',
      source_label: '官方精选',
      category: 'storage',
      icon: 'cloud-line',
      color: 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
      tags: ['腾讯云COS', '阿里云OSS', '七牛云'],
      description: '文件直传阿里云 OSS、腾讯云 COS、七牛云。',
      installed: false,
      enabled: false,
      status: 0,
      _loading: false,
    },
  ]
}

const goToWorkbench = (path: string) => {
  if (!path) return
  void router.push(path)
}

// 开关切换启用/禁用
const handleToggleEnable = async (item: any) => {
  item._loading = true
  try {
    if (item.enabled) {
      await enablePlugin(item.name)
      item.status = 1
      ElMessage.success(`已启用「${item.title || item.name}」`)
    } else {
      await disablePlugin(item.name)
      item.status = 0
      ElMessage.warning(`已禁用「${item.title || item.name}」`)
    }
    await Promise.all([refreshMenuRoutes(), fetchMarket()])
  } catch (e: any) {
    item.enabled = !item.enabled
    ElMessage.error(e?.msg || e?.message || '操作失败')
  } finally {
    item._loading = false
  }
}

// 本地安装
const handleInstall = async (item: any) => {
  item._loading = true
  try {
    await installPlugin(item.name)
    ElMessage.success(`「${item.title || item.name}」已安装`)
    await Promise.all([fetchPlugins(), fetchMarket()])
    await refreshMenuRoutes()
  } catch (e: any) {
    ElMessage.error(e?.msg || e?.message || '安装失败')
  } finally {
    item._loading = false
  }
}

// 启用
const handleEnable = async (item: any) => {
  item._loading = true
  try {
    await enablePlugin(item.name)
    ElMessage.success(`已启用「${item.title || item.name}」`)
    await Promise.all([fetchPlugins(), fetchMarket()])
    await refreshMenuRoutes()
  } catch (e: any) {
    ElMessage.error(e?.msg || e?.message || '启用失败')
  } finally {
    item._loading = false
  }
}

// 禁用
const handleDisable = async (item: any) => {
  item._loading = true
  try {
    await disablePlugin(item.name)
    ElMessage.warning(`已禁用「${item.title || item.name}」`)
    await Promise.all([fetchPlugins(), fetchMarket()])
    await refreshMenuRoutes()
  } catch (e: any) {
    ElMessage.error(e?.msg || e?.message || '禁用失败')
  } finally {
    item._loading = false
  }
}

// 卸载
const handleUninstall = (item: any) => {
  ElMessageBox.confirm(
    `确定卸载「${item.title || item.name}」吗？相关数据可能会被清除。`,
    '卸载应用',
    {
      confirmButtonText: '确定卸载',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(async () => {
      try {
        await uninstallPlugin(item.name)
        ElMessage.success(`「${item.title || item.name}」已卸载`)
        await Promise.all([fetchPlugins(), fetchMarket()])
        await refreshMenuRoutes()
      } catch (e: any) {
        ElMessage.error(e?.msg || e?.message || '卸载失败')
      }
    })
    .catch(() => {})
}

// 升级
const handleUpgrade = async (item: any) => {
  try {
    await upgradePlugin(item.name)
    ElMessage.success(`「${item.title || item.name}」已更新`)
    await fetchPlugins()
    await refreshMenuRoutes()
  } catch (e: any) {
    ElMessage.error(e?.msg || e?.message || '更新失败')
  }
}

// 卡片更多菜单
const handleCardCommand = (command: string, item: any) => {
  if (command === 'uninstall') {
    handleUninstall(item)
  } else if (command === 'disable') {
    void handleDisable(item)
  } else if (command === 'upgrade') {
    if (getPluginUpdateInfo(item.name)?.has_update) {
      void handleOnlineUpdatePlugin(item)
    } else {
      void handleUpgrade(item)
    }
  } else if (command === 'detail') {
    const matchedMarket = marketPluginList.value.find((m) => m.name === item.name)
    openMarketDetailDrawer(
      matchedMarket || {
        ...item,
        local_installed: item.installed,
        local_enabled: item.enabled,
      }
    )
  }
}

// 打开市场详情抽屉
const openMarketDetailDrawer = (item: any) => {
  selectedMarketPlugin.value = item
  marketLicenseKey.value = getPluginLicenseKey(item.name) || item.license_key || ''
  marketDetailDrawerVisible.value = true
}

const openLicenseDialog = () => {
  if (!globalLicensePlugin.value && marketPluginList.value.length) {
    globalLicensePlugin.value = marketPluginList.value[0].name
  }
  licenseDialogVisible.value = true
}

// 抽屉内启用本地已装应用
const handleEnableFromDrawer = async (item: any) => {
  await handleEnable({ name: item.name, title: item.title })
  marketDetailDrawerVisible.value = false
}

// 抽屉内下载并激活
const handleDownloadAndActivate = async () => {
  if (!selectedMarketPlugin.value) return
  if (!marketAccountInfo.bound) {
    ElMessage.warning(
      `安装应用商城插件「${selectedMarketPlugin.value.title || selectedMarketPlugin.value.name}」前，请先登录并绑定官网账号`
    )
    openMarketLoginDialog()
    return
  }
  downloadSubmitting.value = true
  try {
    const licenseKey =
      marketLicenseKey.value.trim() ||
      getPluginLicenseKey(selectedMarketPlugin.value.name) ||
      selectedMarketPlugin.value.license_key ||
      ''
    const res: any = await downloadMarketPlugin({
      name: selectedMarketPlugin.value.name,
      license_key: licenseKey,
      action_type: 'install',
    })
    ElMessage.success(res?.msg || '安装成功')
    marketDetailDrawerVisible.value = false
    await Promise.all([fetchPlugins(), fetchMarket(), handleCheckUpdates(false)])
    await refreshMenuRoutes()
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
    }
    ElMessage.error(e?.msg || e?.message || '安装失败')
  } finally {
    downloadSubmitting.value = false
  }
}

// 授权码全局绑定
const handleBindGlobalLicense = async () => {
  if (!globalLicensePlugin.value) {
    ElMessage.warning('请先选择应用')
    return
  }
  if (!globalLicenseKey.value.trim()) {
    ElMessage.warning('请输入授权码')
    return
  }
  if (!marketAccountInfo.bound) {
    ElMessage.warning('绑定授权码安装应用前，请先登录并绑定官网账号')
    openMarketLoginDialog()
    return
  }
  globalLicenseLoading.value = true
  try {
    const res: any = await downloadMarketPlugin({
      name: globalLicensePlugin.value,
      license_key: globalLicenseKey.value.trim(),
      action_type: 'install',
    })
    ElMessage.success(res?.msg || '授权成功')
    licenseDialogVisible.value = false
    globalLicenseKey.value = ''
    await Promise.all([
      fetchPlugins(),
      fetchMarket(),
      fetchMarketAccount(),
      handleCheckUpdates(false),
    ])
    await refreshMenuRoutes()
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
    }
    ElMessage.error(e?.msg || e?.message || '授权绑定失败')
  } finally {
    globalLicenseLoading.value = false
  }
}

// 打开配置
const handleOpenConfig = async (item: any) => {
  currentPlugin.value = item
  configDialogVisible.value = true
  configLoading.value = true
  try {
    const res = await getPluginConfig(item.name)
    const data = res?.data || {}
    configSchema.value = data.schema || []
    const valObj = data.values || {}
    Object.keys(pluginConfigValues).forEach((k) => delete pluginConfigValues[k])
    Object.assign(pluginConfigValues, valObj)
  } catch (e) {
    console.warn('获取插件配置项失败', e)
    configSchema.value = []
  } finally {
    configLoading.value = false
  }
}

// 保存配置
const handleSaveConfig = async () => {
  if (!currentPlugin.value) return
  configSaveLoading.value = true
  try {
    await savePluginConfig(currentPlugin.value.name, { ...pluginConfigValues })
    ElMessage.success('已保存')
    configDialogVisible.value = false
  } catch (e: any) {
    ElMessage.error(e?.message || '保存失败')
  } finally {
    configSaveLoading.value = false
  }
}

// 云端市场直接安装/更新 (必须先登录并绑定官网账号；若拥有商业授权，自动携带 LicenseKey)
const handleInstallMarket = async (m: any) => {
  if (m.local_installed && !m.upgradable) {
    ElMessage.info(`「${m.title || m.name}」已在本地安装，无需重复安装`)
    return
  }
  if (!marketAccountInfo.bound) {
    ElMessage.warning(`安装应用商城插件「${m.title || m.name}」前，请先登录并绑定官网账号`)
    openMarketLoginDialog()
    return
  }
  m._downloading = true
  try {
    const licenseKey = getPluginLicenseKey(m.name) || m.license_key || ''
    const res: any = await downloadMarketPlugin({
      name: m.name,
      license_key: licenseKey,
      action_type: 'install',
    })
    ElMessage.success(res?.msg || `「${m.title || m.name}」已安装`)
    await Promise.all([fetchPlugins(), fetchMarket(), handleCheckUpdates(false)])
    await refreshMenuRoutes()
    if (selectedMarketPlugin.value?.name === m.name) {
      marketDetailDrawerVisible.value = false
    }
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
    }
    ElMessage.error(e?.msg || e?.message || '安装失败')
  } finally {
    m._downloading = false
  }
}

// 申领 / 购买商业授权 License
const handlePurchaseLicense = async (m: any) => {
  if (!marketAccountInfo.bound) {
    ElMessage.warning(`申领「${m.title || m.name}」授权前，请先登录并绑定官网账号`)
    openMarketLoginDialog()
    return
  }
  try {
    const res: any = await claimMarketLicense({
      plugin_name: m.name,
      client_domain: marketAccountInfo.site_domain || '',
    })
    if (res?.code === 1 && res.data) {
      marketAccountInfo.licensed_plugins = res.data.licensed_plugins || []
      marketAccountInfo.license_map = res.data.license_map || {}
      ElMessage.success(res.msg || `「${m.title || m.name}」授权申领成功`)
      await Promise.all([fetchMarketAccount(), fetchMarket()])
    } else {
      if (res?.data?.need_login) {
        marketAccountInfo.bound = false
        openMarketLoginDialog()
      }
      ElMessage.error(res?.msg || '获取授权失败')
    }
  } catch (e: any) {
    if (e?.data?.need_login) {
      marketAccountInfo.bound = false
      openMarketLoginDialog()
      ElMessage.error(e?.msg || e?.message || '登录态已失效，请重新登录')
      return
    }
    ElMessageBox.alert(
      `「${m.title || m.name}」是付费应用（¥${m.price}），请前往官方商城完成购买或获取授权码。`,
      '获取授权',
      {
        confirmButtonText: '去官网购买',
        callback: (action: any) => {
          if (action === 'confirm') {
            openMarketPortal()
          }
        },
      }
    )
  }
}

onMounted(async () => {
  await Promise.all([
    fetchPlugins(),
    fetchMarket(),
    fetchMarketAccount(),
    handleCheckUpdates(false),
  ])
})
</script>

<style scoped lang="scss">
.plugin-hub-container {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;

  /* 脉冲动态呼吸灯标签 */
  .pulse-tag {
    animation: pulseGlow 2s infinite ease-in-out;
  }

  @keyframes pulseGlow {
    0% {
      box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6);
    }
    70% {
      box-shadow: 0 0 0 8px rgba(239, 68, 68, 0);
    }
    100% {
      box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
    }
  }

  /* 顶部蓝色渐变 Banner（对齐控制台主框架版本条） */
  .welcome-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #1890ff 0%, #36cfc9 100%);
    border-radius: 10px;
    color: #fff;
    box-shadow: 0 4px 14px rgba(24, 144, 255, 0.16);

    .welcome-info {
      min-width: 0;
      flex: 1;

      .title-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 12px;
        margin-bottom: 6px;

        .framework-icon-wrap {
          width: 34px;
          height: 34px;
          border-radius: 9px;
          background: rgba(255, 255, 255, 0.22);
          border: 1px solid rgba(255, 255, 255, 0.35);
          display: inline-flex;
          align-items: center;
          justify-content: center;
          font-size: 18px;
          color: #ffffff;
          flex-shrink: 0;
        }

        h2 {
          margin: 0;
          font-size: 20px;
          font-weight: 600;
          letter-spacing: -0.2px;
          color: #ffffff;
        }

        .version-tag {
          background: rgba(255, 255, 255, 0.2);
          color: #fff;
          border-color: rgba(255, 255, 255, 0.38);
          font-weight: 500;
          font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
          border-radius: 999px;
        }

        .status-tag {
          font-weight: 500;
          border-radius: 999px;

          &.is-clickable {
            cursor: pointer;
          }

          &.is-latest {
            background: rgba(240, 253, 244, 0.96);
            border-color: rgba(187, 247, 208, 0.96);
            color: #15803d;
          }
        }
      }

      .subtitle {
        margin: 0;
        font-size: 13px;
        opacity: 0.92;
        line-height: 1.5;
      }
    }

    .header-actions {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      flex-shrink: 0;

      .banner-btn {
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(255, 255, 255, 0.96);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
        font-weight: 500;
        transition: all 0.2s ease;

        &:hover,
        &:focus {
          background: #ffffff;
          border-color: #ffffff;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(15, 23, 42, 0.16);
        }

        &.banner-btn-check,
        &.banner-btn-backup,
        &.banner-btn-source,
        &.banner-btn-guide {
          color: #1d4ed8;
        }

        &.banner-btn-refresh {
          color: #0958d9;
        }
      }

      .banner-btn-upgrade {
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
      }
    }
  }

  /* 官网市场通行证 & 站点授权条 */
  .market-auth-card {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    transition: all 0.3s ease;

    :deep(.el-card__body) {
      padding: 14px 18px;
    }

    &.is-bound {
      background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 55%, #fefce8 100%);
      border: 1px solid #bfdbfe;
    }

    .auth-card-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 14px;

      .auth-card-left {
        display: flex;
        align-items: center;
        gap: 14px;

        .auth-badge-icon {
          width: 42px;
          height: 42px;
          border-radius: 10px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 20px;
          flex-shrink: 0;

          &.is-unbound {
            background: #e2e8f0;
            color: #64748b;
          }

          &.is-bound {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
          }
        }

        .auth-info-block {
          .auth-title-line {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 4px;

            .auth-main-title {
              font-size: 14px;
              font-weight: 700;
              color: #0f172a;
            }

            .vip-level-tag {
              font-weight: 600;
              letter-spacing: 0.5px;
            }

            .status-pill {
              font-size: 11px;
            }

            .market-status-pill {
              display: inline-flex;
              align-items: center;
              gap: 6px;
              padding: 2px 8px;
              background: #ffffff;
              border: 1px solid #e2e8f0;
              border-radius: 999px;
              font-size: 11px;
              color: #475569;

              .status-dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: #94a3b8;

                &.live {
                  background: #10b981;
                }

                &.offline {
                  background: #ef4444;
                }
              }
            }
          }

          .auth-details-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 12px;
            color: #475569;

            .detail-item {
              display: inline-flex;
              align-items: center;
              gap: 4px;

              .sub-account {
                color: #64748b;
              }

              code {
                background: #e2e8f0;
                padding: 1px 6px;
                border-radius: 4px;
                font-family: ui-monospace, SFMono-Regular, monospace;
                font-size: 11px;
                color: #0f172a;
              }

              &.highlight {
                color: #b45309;
                font-weight: 500;

                strong {
                  color: #d97706;
                  font-size: 13px;
                }
              }
            }

            .detail-divider {
              color: #cbd5e1;
            }
          }

          .auth-tip-desc {
            margin: 0;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;

            code {
              background: #e2e8f0;
              padding: 1px 5px;
              border-radius: 4px;
              color: #0f172a;
              font-size: 11px;
            }
          }
        }
      }

      .auth-card-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px 10px;

        .login-bind-btn {
          font-weight: 600;
          box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }
      }
    }
  }

  /* 标签页与导航 */
  .main-tabs-wrap {
    .plugin-tabs {
      :deep(.el-tabs__header) {
        margin-bottom: 18px;
      }

      :deep(.el-tabs__nav-wrap::after) {
        height: 1px;
        background-color: var(--el-border-color-lighter);
      }

      .tab-label-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 15px;
        font-weight: 500;

        .tab-badge {
          margin-left: 2px;
        }

        .market-pill-badge {
          padding: 1px 6px;
          border-radius: 10px;
          font-size: 10px;
          font-weight: 700;
          color: #fff;
          background: #ef4444;
          line-height: 1.3;
        }
      }
    }
  }

  /* 筛选工具栏 */
  .filter-panel {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
    padding: 14px 16px;
    background: var(--el-bg-color);
    border-radius: 10px;
    border: 1px solid var(--el-border-color-lighter);

    .filter-left {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .filter-right {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }

    .capsule-radio-group {
      :deep(.el-radio-button__inner) {
        font-weight: 500;
      }
    }
  }

  /* 插件卡片 3 列网格 */
  .card-grid-container {
    .grid-col {
      margin-bottom: 20px;
    }

    .plugin-hub-card {
      position: relative;
      display: flex;
      flex-direction: column;
      height: 100%;
      border-radius: 10px;
      padding: 18px;
      background: var(--el-bg-color);
      border: 1px solid var(--el-border-color-lighter);
      box-shadow: var(--el-box-shadow-lighter);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);

      &:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
        border-color: var(--el-color-primary-light-5);
      }

      &.is-running {
        border-color: rgba(16, 185, 129, 0.35);
      }

      .card-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;

        .icon-avatar {
          flex-shrink: 0;
          width: 50px;
          height: 50px;
          border-radius: 10px;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #fff;
          font-size: 26px;
          box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .info-meta {
          flex: 1;
          min-width: 0;

          .name-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 4px;

            .plugin-name {
              font-size: 15px;
              font-weight: 600;
              color: var(--el-text-color-primary);
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            }

            .source-tag {
              font-size: 11px;
              padding: 1px 6px;
              border-radius: 4px;
              white-space: nowrap;

              &.tag-official {
                background: #e0e7ff;
                color: #4338ca;
              }

              &.tag-custom {
                background: #ccfbf1;
                color: #0f766e;
              }
            }
          }

          .code-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 12px;

            code {
              font-size: 11px;
              padding: 1px 6px;
              background: var(--el-fill-color-light);
              border-radius: 4px;
              color: var(--el-color-primary);
              font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
            }

            .ver-text {
              color: var(--el-text-color-secondary);
            }

            .rate-badge {
              color: #f59e0b;
              font-weight: 600;
            }

            .downloads-text {
              color: var(--el-text-color-placeholder);
            }
          }
        }

        .header-status {
          flex-shrink: 0;
        }

        .header-price {
          flex-shrink: 0;

          .price-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 6px;
            display: inline-block;

            &.price-free {
              background: #dcfce7;
              color: #15803d;
            }

            &.price-commercial {
              background: #fef3c7;
              color: #b45309;
            }
          }
        }
      }

      .card-desc {
        font-size: 13px;
        line-height: 1.55;
        color: var(--el-text-color-regular);
        margin: 0 0 12px 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 40px;
      }

      .tag-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 14px;

        .mini-pill {
          font-size: 11px;
          padding: 2px 7px;
          border-radius: 4px;
          background: var(--el-fill-color-light);
          color: var(--el-text-color-secondary);
        }
      }

      .features-box {
        margin-bottom: 14px;
        min-height: 68px;

        .feature-line {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 12px;
          color: var(--el-text-color-regular);
          margin-bottom: 5px;

          .chk-icon {
            color: #10b981;
            font-size: 14px;
            flex-shrink: 0;
          }

          .feature-txt {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
          }
        }
      }

      .card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-top: auto;
        padding-top: 12px;
        border-top: 1px solid var(--el-border-color-lighter);

        .author-info {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          font-size: 12px;
          color: var(--el-text-color-secondary);
          min-width: 0;

          span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
          }
        }

        .footer-actions {
          display: flex;
          align-items: center;
          gap: 6px;
          flex-shrink: 0;

          .more-btn {
            padding: 6px 8px;
          }
        }
      }
    }
  }

  /* 开发指南抽屉 */
  .guide-drawer-content {
    .guide-section {
      margin-bottom: 24px;

      h4 {
        margin: 0 0 8px 0;
        font-size: 15px;
        color: var(--el-text-color-primary);
      }

      p {
        margin: 0 0 8px 0;
        font-size: 13px;
        color: var(--el-text-color-regular);
        line-height: 1.6;
      }

      .code-block {
        background: #0f172a;
        color: #e2e8f0;
        padding: 14px;
        border-radius: 8px;
        font-size: 12px;
        line-height: 1.5;
        overflow-x: auto;
      }

      .step-list {
        margin: 0;
        padding-left: 20px;
        font-size: 13px;
        line-height: 1.8;
        color: var(--el-text-color-regular);

        code {
          background: var(--el-fill-color-light);
          padding: 1px 5px;
          border-radius: 4px;
          font-size: 12px;
        }
      }
    }
  }

  /* 市场详情抽屉 */
  .market-detail-body {
    .detail-header-card {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 16px;
      background: var(--el-fill-color-light);
      border-radius: 10px;
      margin-bottom: 20px;

      .detail-avatar {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 32px;
        flex-shrink: 0;
      }

      .detail-header-info {
        flex: 1;
        min-width: 0;

        .dh-title-row {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          margin-bottom: 6px;

          h3 {
            margin: 0;
            font-size: 17px;
            color: var(--el-text-color-primary);
          }

          .price-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 6px;
            flex-shrink: 0;

            &.price-free {
              background: #dcfce7;
              color: #15803d;
            }

            &.price-commercial {
              background: #fef3c7;
              color: #b45309;
            }
          }
        }

        .dh-meta {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px 10px;
          font-size: 12px;
          color: var(--el-text-color-secondary);

          code {
            padding: 1px 6px;
            background: var(--el-bg-color);
            border-radius: 4px;
            color: var(--el-color-primary);
          }
        }
      }
    }

    .detail-block {
      margin-bottom: 22px;

      .block-title {
        font-size: 15px;
        margin: 0 0 10px 0;
        color: var(--el-text-color-primary);
      }

      .block-text {
        margin: 0;
        font-size: 13px;
        line-height: 1.7;
        color: var(--el-text-color-regular);
      }

      .feature-checklist {
        display: flex;
        flex-direction: column;
        gap: 8px;

        .feature-check-item {
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 13px;
          color: var(--el-text-color-regular);

          .check-icon {
            color: #10b981;
            font-size: 16px;
            flex-shrink: 0;
          }
        }
      }

      &.license-block {
        padding: 14px;
        background: var(--el-color-primary-light-9);
        border-radius: 8px;

        .license-block-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 8px;
        }

        .license-tip {
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin: 0 0 10px 0;
          line-height: 1.5;
        }
      }
    }
  }

  .drawer-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .gateway-inline-code {
    margin-left: 10px;
    font-size: 12px;
    color: #64748b;
    font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
    background: #f8fafc;
    padding: 2px 6px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
  }

  .gateway-tip {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
    line-height: 1.4;

    code {
      background: #f1f5f9;
      padding: 1px 4px;
      border-radius: 3px;
      border: 1px solid #e2e8f0;
    }
  }

  .field-tip {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
    display: block;
  }

  .mono-id-badge {
    font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
    font-size: 11px;
    padding: 2px 6px;
    background: #f1f5f9;
    border-radius: 4px;
    color: #334155;
  }

  .mono-tag {
    font-family: ui-monospace, SFMono-Regular, 'JetBrains Mono', Consolas, monospace;
  }
}

:deep(.el-dialog.registry-dialog) {
  border-radius: 8px;

  .el-dialog__header {
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    margin-right: 0;

    .el-dialog__title {
      font-size: 16px;
      font-weight: 600;
      color: #0f172a;
    }
  }

  .el-dialog__body {
    padding: 24px 24px 10px;
  }

  .el-dialog__footer {
    border-top: 1px solid #f1f5f9;
    padding: 14px 20px;
  }

  .el-form-item {
    margin-bottom: 20px !important;

    .el-form-item__label {
      font-size: 13px;
      font-weight: 500;
      color: #334155;
    }
  }
}

:deep(.el-dialog.market-login-dialog) {
  border-radius: 10px;

  .market-login-intro {
    display: flex;
    gap: 12px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 12px 14px;
    border-radius: 6px;
    margin-bottom: 18px;

    .intro-icon {
      font-size: 24px;
      color: #2563eb;
      line-height: 1;
    }

    .intro-text {
      h4 {
        margin: 0 0 4px;
        font-size: 14px;
        color: #1e3a8a;
      }

      p {
        margin: 0;
        font-size: 12px;
        color: #3b82f6;
        line-height: 1.5;
      }
    }
  }

  .market-login-form {
    .form-tip {
      font-size: 11px;
      color: #94a3b8;
      line-height: 1.4;
      margin-top: 4px;
      display: block;
    }
  }

  .dialog-footer-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    gap: 12px;
  }
}
</style>
