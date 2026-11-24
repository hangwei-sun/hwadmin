<template>
  <div class="cms-setting-container">
    <!-- 顶部数据看板与快捷入口 -->
    <el-card shadow="never" class="stat-banner-card">
      <div class="stat-banner-content">
        <div class="stat-grid">
          <div class="stat-item">
            <div class="stat-icon-wrapper blue">
              <el-icon><vab-icon icon="file-text-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">{{ stats.article_count || 0 }}</div>
              <div class="stat-label">文章总数</div>
            </div>
          </div>
          <div class="stat-item">
            <div class="stat-icon-wrapper green">
              <el-icon><vab-icon icon="shopping-bag-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">{{ stats.product_count || 0 }}</div>
              <div class="stat-label">展厅商品</div>
            </div>
          </div>
          <div class="stat-item">
            <div class="stat-icon-wrapper purple">
              <el-icon><vab-icon icon="building-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">{{ stats.solution_count || 0 }}</div>
              <div class="stat-label">方案案例</div>
            </div>
          </div>
          <div class="stat-item">
            <div class="stat-icon-wrapper orange">
              <el-icon><vab-icon icon="eye-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">{{ stats.total_views || 0 }}</div>
              <div class="stat-label">总访问量</div>
            </div>
          </div>
          <div class="stat-item">
            <div class="stat-icon-wrapper red">
              <el-icon><vab-icon icon="question-answer-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">
                {{ stats.pending_inquiry_count || 0 }}
                <span class="stat-sub">/ {{ stats.inquiry_count || 0 }}</span>
              </div>
              <div class="stat-label">待跟进留言询单</div>
            </div>
          </div>
          <div class="stat-item" style="cursor: pointer;" @click="$router.push('/user/user')" title="点击直达后台会员管理">
            <div class="stat-icon-wrapper cyan">
              <el-icon><vab-icon icon="user-line" /></el-icon>
            </div>
            <div class="stat-meta">
              <div class="stat-value">{{ stats.member_count || 0 }}</div>
              <div class="stat-label">注册会员数 <span style="font-size:11px;color:var(--el-color-primary);">👥 跳转管理</span></div>
            </div>
          </div>
        </div>

        <div class="banner-actions">
          <el-button type="primary" plain @click="openPreview('/')">
            <el-icon><vab-icon icon="global-line" /></el-icon>
            访问网站前台首页 (/)
          </el-button>
          <el-button
            v-if="homeMode === 'cms'"
            type="success"
            plain
            style="cursor: default; font-weight: 600;"
          >
            <el-icon><vab-icon icon="check-line" /></el-icon>
            ✅ 已设为网站默认首页 (根域名 / 直达 CMS)
          </el-button>
          <el-button
            v-else
            type="success"
            :loading="homeSettingLoading"
            @click="handleSetAsHome"
          >
            <el-icon><vab-icon icon="flashlight-line" /></el-icon>
            一键将系统默认首页设为 CMS
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 三套通用标准网站模板卡片切换 -->
    <div class="section-title">
      <div class="title-left">
        <span class="indicator"></span>
        <h3>网站主题模板体系</h3>
        <el-tag type="success" size="small" effect="plain" class="current-tag">
          当前生效主题：{{ currentTemplateTitle }} ({{ activeTemplate }})
        </el-tag>
      </div>
      <div class="title-right-controls">
        <div class="switcher-control-pill">
          <span class="switcher-label">前台切换菜单开关</span>
          <el-switch
            v-model="config.show_theme_switcher"
            :active-value="1"
            :inactive-value="0"
            active-text="开启"
            inactive-text="关闭"
            :loading="switcherSaving"
            @change="handleToggleThemeSwitcher"
          />
          <span class="switcher-hint">
            {{ config.show_theme_switcher === 1 ? '开启时前台显示多主题切换入口' : '已关闭：前台仅展示当前主题，不显示其余主题' }}
          </span>
        </div>
      </div>
    </div>

    <div class="template-cards-grid">
      <!-- 模板 1: 个人博客 -->
      <div
        class="template-card"
        :class="{ active: activeTemplate === 'blog' }"
      >
        <div class="active-badge" v-if="activeTemplate === 'blog'">
          <el-icon><vab-icon icon="check-line" /></el-icon> 正在运行
        </div>
        <div class="card-header">
          <div class="theme-icon blog-theme">📝</div>
          <div class="theme-info">
            <h4 class="theme-name">个人博客分享站</h4>
            <span class="theme-code">blog</span>
          </div>
        </div>

        <!-- CSS 模拟微缩排版预览 -->
        <div class="mockup-preview blog-mockup">
          <div class="mock-header">
            <div class="mock-brand">Blog Space</div>
            <div class="mock-nav">
              <span class="dot"></span><span class="dot"></span><span class="dot"></span>
            </div>
          </div>
          <div class="mock-body">
            <div class="mock-sidebar">
              <div class="mock-avatar"></div>
              <div class="mock-line sm"></div>
              <div class="mock-tags">
                <span class="mock-chip blue"></span>
                <span class="mock-chip green"></span>
              </div>
            </div>
            <div class="mock-feed">
              <div class="mock-article-card">
                <div class="mock-line lg"></div>
                <div class="mock-line"></div>
              </div>
              <div class="mock-article-card">
                <div class="mock-line lg"></div>
                <div class="mock-line"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="scene-tags">
          <el-tag size="small" type="primary">技术专栏</el-tag>
          <el-tag size="small" type="info">知识库体系</el-tag>
          <el-tag size="small" type="warning">个人 IP 分享</el-tag>
        </div>

        <div class="feature-list">
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 个人名片与博主社交阵列</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 精美分类归档与标签云导航</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 沉浸式阅读与读者留言互动</div>
        </div>

        <div class="card-actions">
          <el-button
            v-if="activeTemplate === 'blog'"
            type="success"
            disabled
            class="action-btn"
          >
            <el-icon><vab-icon icon="check-line" /></el-icon>
            当前启用模板
          </el-button>
          <el-button
            v-else
            type="primary"
            :loading="switching === 'blog'"
            class="action-btn"
            @click="handleSwitchTemplate('blog')"
          >
            <el-icon><vab-icon icon="palette-line" /></el-icon>
            启用此模板
          </el-button>
          <el-button plain class="preview-btn" @click="openPreview('/?theme=blog')">
            <el-icon><vab-icon icon="eye-line" /></el-icon>
            独立预览
          </el-button>
        </div>
      </div>

      <!-- 模板 2: 企业官网 -->
      <div
        class="template-card"
        :class="{ active: activeTemplate === 'corporate' }"
      >
        <div class="active-badge" v-if="activeTemplate === 'corporate'">
          <el-icon><vab-icon icon="check-line" /></el-icon> 正在运行
        </div>
        <div class="card-header">
          <div class="theme-icon corp-theme">🏢</div>
          <div class="theme-info">
            <h4 class="theme-name">企业官方门户网站</h4>
            <span class="theme-code">corporate</span>
          </div>
        </div>

        <!-- CSS 模拟微缩排版预览 -->
        <div class="mockup-preview corp-mockup">
          <div class="mock-header">
            <div class="mock-brand">Corporation</div>
            <div class="mock-menu-bar">
              <span class="bar-link"></span><span class="bar-link"></span><span class="bar-link"></span>
            </div>
          </div>
          <div class="mock-hero">
            <div class="mock-hero-title"></div>
            <div class="mock-hero-btn"></div>
          </div>
          <div class="mock-stats-bar">
            <span class="num-box"></span>
            <span class="num-box"></span>
            <span class="num-box"></span>
          </div>
          <div class="mock-solution-grid">
            <div class="sol-card"></div>
            <div class="sol-card"></div>
            <div class="sol-card"></div>
          </div>
        </div>

        <div class="scene-tags">
          <el-tag size="small" type="success">科技制造</el-tag>
          <el-tag size="small" type="primary">政企集团官网</el-tag>
          <el-tag size="small" type="info">品牌商务门户</el-tag>
        </div>

        <div class="feature-list">
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 全屏巨幕轮播与实力数据仪表盘</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 核心业务解决方案与案例矩阵</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 商务合作咨询直通在线询单</div>
        </div>

        <div class="card-actions">
          <el-button
            v-if="activeTemplate === 'corporate'"
            type="success"
            disabled
            class="action-btn"
          >
            <el-icon><vab-icon icon="check-line" /></el-icon>
            当前启用模板
          </el-button>
          <el-button
            v-else
            type="primary"
            :loading="switching === 'corporate'"
            class="action-btn"
            @click="handleSwitchTemplate('corporate')"
          >
            <el-icon><vab-icon icon="palette-line" /></el-icon>
            启用此模板
          </el-button>
          <el-button plain class="preview-btn" @click="openPreview('/?theme=corporate')">
            <el-icon><vab-icon icon="eye-line" /></el-icon>
            独立预览
          </el-button>
        </div>
      </div>

      <!-- 模板 3: 产品展厅 -->
      <div
        class="template-card"
        :class="{ active: activeTemplate === 'showcase' }"
      >
        <div class="active-badge" v-if="activeTemplate === 'showcase'">
          <el-icon><vab-icon icon="check-line" /></el-icon> 正在运行
        </div>
        <div class="card-header">
          <div class="theme-icon showcase-theme">🛍️</div>
          <div class="theme-info">
            <h4 class="theme-name">购物产品展示站</h4>
            <span class="theme-code">showcase (不含支付)</span>
          </div>
        </div>

        <!-- CSS 模拟微缩排版预览 -->
        <div class="mockup-preview showcase-mockup">
          <div class="mock-header">
            <div class="mock-brand">Product Mall</div>
            <div class="mock-search-bar"></div>
          </div>
          <div class="mock-banner-mini"></div>
          <div class="mock-products-grid">
            <div class="mock-prod-item">
              <div class="img-ph"></div>
              <div class="price-ph"></div>
            </div>
            <div class="mock-prod-item">
              <div class="img-ph"></div>
              <div class="price-ph"></div>
            </div>
            <div class="mock-prod-item">
              <div class="img-ph"></div>
              <div class="price-ph"></div>
            </div>
          </div>
        </div>

        <div class="scene-tags">
          <el-tag size="small" type="warning">数码硬件</el-tag>
          <el-tag size="small" type="danger">严选好物商城</el-tag>
          <el-tag size="small" type="success">工业产品展厅</el-tag>
        </div>

        <div class="feature-list">
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 精品橱窗网格与多维标签筛选</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 参考价格/型号/多规格参数矩阵</div>
          <div class="feature-item"><el-icon><vab-icon icon="checkbox-circle-line" /></el-icon> 在线采购询价单直达后台跟进</div>
        </div>

        <div class="card-actions">
          <el-button
            v-if="activeTemplate === 'showcase'"
            type="success"
            disabled
            class="action-btn"
          >
            <el-icon><vab-icon icon="check-line" /></el-icon>
            当前启用模板
          </el-button>
          <el-button
            v-else
            type="primary"
            :loading="switching === 'showcase'"
            class="action-btn"
            @click="handleSwitchTemplate('showcase')"
          >
            <el-icon><vab-icon icon="palette-line" /></el-icon>
            启用此模板
          </el-button>
          <el-button plain class="preview-btn" @click="openPreview('/?theme=showcase')">
            <el-icon><vab-icon icon="eye-line" /></el-icon>
            独立预览
          </el-button>
        </div>
      </div>
    </div>

    <!-- 详细参数配置区 Tabs -->
    <el-card shadow="never" class="config-tabs-card">
      <el-tabs v-model="activeTab" class="setting-tabs">
        <!-- Tab 1: 品牌与全局 SEO -->
        <el-tab-pane label="🌐 基础品牌与 SEO 设置" name="base">
          <el-form :model="config" label-width="140px" class="config-form">
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="网站名称 (Site Name)">
                  <el-input v-model="config.site_name" placeholder="例如：HwAdmin 现代科技" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="副标题 / Slogan">
                  <el-input v-model="config.site_slogan" placeholder="例如：新一代开箱即用智能后台与内容门户" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="前台切换菜单开关">
                  <el-switch
                    v-model="config.show_theme_switcher"
                    :active-value="1"
                    :inactive-value="0"
                    active-text="开启切换菜单"
                    inactive-text="关闭"
                  />
                  <div class="form-tip">关闭后，前台仅显示当前启用主题，隐藏悬浮切换条与页脚其余主题入口</div>
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="ICP 备案号">
                  <el-input v-model="config.icp_no" placeholder="例如：京ICP备20268888号-1" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="SEO 关键词">
                  <el-input
                    v-model="config.seo_keywords"
                    placeholder="例如：HwAdmin, ThinkPHP8, Vue3, CMS, 企业官网, 产品展厅"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="SEO 描述信息">
                  <el-input
                    v-model="config.seo_description"
                    type="textarea"
                    :rows="3"
                    placeholder="请输入搜索引擎检索描述文本"
                  />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="页脚版权信息">
                  <el-input v-model="config.copyright" placeholder="Copyright © 2026 HwAdmin Framework. All Rights Reserved." />
                </el-form-item>
              </el-col>

              <el-col :span="24">
                <el-divider content-position="left">👥 会员体系与前台互动规则</el-divider>
              </el-col>
              <el-col :span="8">
                <el-form-item label="开放会员注册">
                  <el-switch
                    v-model="config.allow_member_register"
                    :active-value="1"
                    :inactive-value="0"
                    active-text="允许注册"
                    inactive-text="关闭"
                  />
                  <div class="form-tip">控制前台是否允许访客自行注册新账号</div>
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="评论/询单登录要求">
                  <el-switch
                    v-model="config.comment_require_login"
                    :active-value="1"
                    :inactive-value="0"
                    active-text="强制登录会员"
                    inactive-text="允许游客"
                  />
                  <div class="form-tip">开启后未登录会员无法提交留言/评论/询单</div>
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="互动奖励积分">
                  <el-input-number
                    v-model="config.interaction_reward_score"
                    :min="0"
                    :max="1000"
                    :step="5"
                  />
                  <div class="form-tip">会员每次发表评论或询单赠送积分数</div>
                </el-form-item>
              </el-col>
            </el-row>

            <div class="form-footer">
              <el-button type="primary" :loading="saving" @click="handleSaveConfig">
                <el-icon><vab-icon icon="save-line" /></el-icon>
                保存基础配置
              </el-button>
            </div>
          </el-form>
        </el-tab-pane>

        <!-- Tab 2: 三套模板独立内容设置 -->
        <el-tab-pane label="🎨 各主题独立内容设置" name="templates_config">
          <div class="theme-subtabs-header">
            <el-radio-group v-model="templateConfigTab" size="default">
              <el-radio-button value="corporate">
                🏢 企业官网 (corporate)
                <el-tag v-if="activeTemplate === 'corporate'" size="small" type="success" effect="dark" style="margin-left: 6px;">当前</el-tag>
              </el-radio-button>
              <el-radio-button value="blog">
                📝 个人博客 (blog)
                <el-tag v-if="activeTemplate === 'blog'" size="small" type="success" effect="dark" style="margin-left: 6px;">当前</el-tag>
              </el-radio-button>
              <el-radio-button value="showcase">
                🛍️ 产品展厅 (showcase)
                <el-tag v-if="activeTemplate === 'showcase'" size="small" type="success" effect="dark" style="margin-left: 6px;">当前</el-tag>
              </el-radio-button>
            </el-radio-group>
            <el-button plain size="small" @click="openPreview(`/?theme=${templateConfigTab}`)">
              <el-icon><vab-icon icon="eye-line" /></el-icon>
              预览当前编辑主题
            </el-button>
          </div>

          <!-- 主题 A: 企业官网独立内容 -->
          <el-form v-show="templateConfigTab === 'corporate'" :model="config" label-width="140px" class="config-form">
            <el-divider content-position="left">品牌与首屏配置 (留空自动沿用全局基础设置)</el-divider>
            <el-row :gutter="20">
              <el-col :span="8">
                <el-form-item label="官网站点标题">
                  <el-input v-model="config.corp_site_name" placeholder="例如：HwAdmin 科技" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="官网顶部按钮文案">
                  <el-input v-model="config.corp_cta_text" placeholder="例如：预约" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="官网标语 / Slogan">
                  <el-input v-model="config.corp_site_slogan" placeholder="例如：新一代企业级数字化智能基座" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="Hero 角标">
                  <el-input v-model="config.corp_hero_badge" placeholder="留空则读取企业横幅角标" />
                </el-form-item>
              </el-col>
              <el-col :span="16">
                <el-form-item label="Hero 主标题">
                  <el-input v-model="config.corp_hero_title" placeholder="留空则读取企业横幅主标题" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="Hero 副标题">
                  <el-input v-model="config.corp_hero_sub" placeholder="留空则读取企业横幅副标题" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="Hero 主按钮文案">
                  <el-input v-model="config.corp_hero_btn1_text" placeholder="例如：探索解决方案" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="Hero 次按钮文案">
                  <el-input v-model="config.corp_hero_btn2_text" placeholder="例如：预约专家演示" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-divider content-position="left">企业联系信息与实力指标 (4 组数据)</el-divider>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="企业全称">
                  <el-input v-model="config.corp_name" placeholder="例如：华网云擎科技有限公司" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="全国服务热线">
                  <el-input v-model="config.corp_phone" placeholder="400-888-2026" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="商务合作邮箱">
                  <el-input v-model="config.corp_email" placeholder="business@hwadmin.com" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="企业总部地址">
                  <el-input v-model="config.corp_address" placeholder="北京市海淀区中关村软件园 A 座" />
                </el-form-item>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="指标 1" label-width="64px">
                    <el-input v-model="config.corp_stat_1_num" placeholder="99.999%" />
                  </el-form-item>
                  <el-form-item label="标签 1" label-width="64px">
                    <el-input v-model="config.corp_stat_1_label" placeholder="工业级高可用 SLA" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="指标 2" label-width="64px">
                    <el-input v-model="config.corp_stat_2_num" placeholder="500+" />
                  </el-form-item>
                  <el-form-item label="标签 2" label-width="64px">
                    <el-input v-model="config.corp_stat_2_label" placeholder="行业龙头标杆信赖" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="指标 3" label-width="64px">
                    <el-input v-model="config.corp_stat_3_num" placeholder="35ms" />
                  </el-form-item>
                  <el-form-item label="标签 3" label-width="64px">
                    <el-input v-model="config.corp_stat_3_label" placeholder="微中台超低延迟" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="指标 4" label-width="64px">
                    <el-input v-model="config.corp_stat_4_num" placeholder="24/7" />
                  </el-form-item>
                  <el-form-item label="标签 4" label-width="64px">
                    <el-input v-model="config.corp_stat_4_label" placeholder="专属运维专家保障" />
                  </el-form-item>
                </el-card>
              </el-col>
            </el-row>

            <el-divider content-position="left">板块文案与独立 SEO</el-divider>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="方案板块标题">
                  <el-input v-model="config.corp_solutions_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="方案板块描述">
                  <el-input v-model="config.corp_solutions_desc" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="案例板块标题">
                  <el-input v-model="config.corp_cases_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="案例板块描述">
                  <el-input v-model="config.corp_cases_desc" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="关于我们标题">
                  <el-input v-model="config.corp_about_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="联系板块标题">
                  <el-input v-model="config.corp_contact_title" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="关于企业介绍">
                  <el-input v-model="config.corp_about_html" type="textarea" :rows="3" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏标题">
                  <el-input v-model="config.corp_footer_service_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="官网 SEO 关键词">
                  <el-input v-model="config.corp_seo_keywords" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏说明">
                  <el-input v-model="config.corp_footer_service_desc" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="官网 SEO 描述">
                  <el-input v-model="config.corp_seo_description" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>

          <!-- 主题 B: 个人博客独立内容 -->
          <el-form v-show="templateConfigTab === 'blog'" :model="config" label-width="140px" class="config-form">
            <el-divider content-position="left">博客站点品牌与首屏</el-divider>
            <el-row :gutter="20">
              <el-col :span="8">
                <el-form-item label="博客站点名称">
                  <el-input v-model="config.blog_site_name" placeholder="例如：极客空间" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="顶部按钮文案">
                  <el-input v-model="config.blog_cta_text" placeholder="例如：留言" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="博客副标题">
                  <el-input v-model="config.blog_site_slogan" placeholder="例如：记录技术思考与工程实践" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="Hero 角标">
                  <el-input v-model="config.blog_hero_badge" placeholder="留空则读取博客横幅角标" />
                </el-form-item>
              </el-col>
              <el-col :span="16">
                <el-form-item label="Hero 主标题">
                  <el-input v-model="config.blog_hero_title" placeholder="留空则读取博客横幅主标题" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="Hero 副标题">
                  <el-input v-model="config.blog_hero_sub" placeholder="留空则读取博客横幅副标题" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-divider content-position="left">博主名片与侧边栏标题</el-divider>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="博主昵称">
                  <el-input v-model="config.blog_author" placeholder="例如：极客老王" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="博主头像 URL">
                  <el-input v-model="config.blog_avatar" placeholder="https://... 或图片相对路径" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="GitHub 主页链接">
                  <el-input v-model="config.blog_github" placeholder="留空则隐藏 GitHub 按钮" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="联系邮箱">
                  <el-input v-model="config.blog_email" placeholder="author@example.com" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="个人名片简介">
                  <el-input v-model="config.blog_bio" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="热门榜标题">
                  <el-input v-model="config.blog_rank_title" placeholder="🔥 热门文章榜" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="标签云标题">
                  <el-input v-model="config.blog_tags_title" placeholder="🏷️ 热门标签" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="留言墙标题">
                  <el-input v-model="config.blog_comments_title" placeholder="💬 读者留言" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏标题">
                  <el-input v-model="config.blog_footer_service_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="博客 SEO 关键词">
                  <el-input v-model="config.blog_seo_keywords" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏说明">
                  <el-input v-model="config.blog_footer_service_desc" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="博客 SEO 描述">
                  <el-input v-model="config.blog_seo_description" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>

          <!-- 主题 C: 产品展厅独立内容 -->
          <el-form v-show="templateConfigTab === 'showcase'" :model="config" label-width="140px" class="config-form">
            <el-divider content-position="left">展厅品牌与橱窗首屏</el-divider>
            <el-row :gutter="20">
              <el-col :span="8">
                <el-form-item label="展厅站点名称">
                  <el-input v-model="config.showcase_site_name" placeholder="例如：智算严选展厅" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="顶部按钮文案">
                  <el-input v-model="config.showcase_cta_text" placeholder="例如：询价" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="大客户采购热线">
                  <el-input v-model="config.showcase_hotline" placeholder="010-88886666" />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="展厅徽标">
                  <el-input v-model="config.showcase_hero_badge" placeholder="🛍️ 2026 智能装备展厅" />
                </el-form-item>
              </el-col>
              <el-col :span="16">
                <el-form-item label="展厅橱窗主标题">
                  <el-input v-model="config.showcase_title" placeholder="例如：2026 智能硬件与算力装备精选展厅" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="展厅副标题 / 公告">
                  <el-input v-model="config.showcase_notice" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="在线询价引导文案">
                  <el-input v-model="config.showcase_inquiry_tip" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-divider content-position="left">四大品质承诺条与独立 SEO</el-divider>
            <el-row :gutter="16">
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="承诺 1" label-width="64px">
                    <el-input v-model="config.showcase_promise_1_title" />
                  </el-form-item>
                  <el-form-item label="说明 1" label-width="64px">
                    <el-input v-model="config.showcase_promise_1_desc" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="承诺 2" label-width="64px">
                    <el-input v-model="config.showcase_promise_2_title" />
                  </el-form-item>
                  <el-form-item label="说明 2" label-width="64px">
                    <el-input v-model="config.showcase_promise_2_desc" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="承诺 3" label-width="64px">
                    <el-input v-model="config.showcase_promise_3_title" />
                  </el-form-item>
                  <el-form-item label="说明 3" label-width="64px">
                    <el-input v-model="config.showcase_promise_3_desc" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="6">
                <el-card shadow="never" class="stat-group-box">
                  <el-form-item label="承诺 4" label-width="64px">
                    <el-input v-model="config.showcase_promise_4_title" />
                  </el-form-item>
                  <el-form-item label="说明 4" label-width="64px">
                    <el-input v-model="config.showcase_promise_4_desc" />
                  </el-form-item>
                </el-card>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏标题">
                  <el-input v-model="config.showcase_footer_service_title" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="展厅 SEO 关键词">
                  <el-input v-model="config.showcase_seo_keywords" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="页脚服务栏说明">
                  <el-input v-model="config.showcase_footer_service_desc" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="展厅 SEO 描述">
                  <el-input v-model="config.showcase_seo_description" type="textarea" :rows="2" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>

          <div class="form-footer">
            <el-button type="primary" :loading="saving" @click="handleSaveConfig">
              <el-icon><vab-icon icon="save-line" /></el-icon>
              保存各主题独立内容配置
            </el-button>
          </div>
        </el-tab-pane>

        <!-- Tab 3: 首页轮播横幅 (Banner) 管理 -->
        <el-tab-pane label="🖼️ 首页轮播横幅 (Banner) 管理" name="banners">
          <div class="banner-toolbar">
            <div class="filter-group">
              <span class="filter-label">适用模板过滤：</span>
              <el-radio-group v-model="bannerFilterTemplate" size="small">
                <el-radio-button value="all">全部</el-radio-button>
                <el-radio-button value="blog">博客站</el-radio-button>
                <el-radio-button value="corporate">企业官网</el-radio-button>
                <el-radio-button value="showcase">产品展厅</el-radio-button>
              </el-radio-group>
            </div>
            <el-button type="primary" @click="handleAddBanner">
              <el-icon><vab-icon icon="add-line" /></el-icon>
              添加轮播横幅
            </el-button>
          </div>

          <el-table :data="filteredBanners" row-key="id" border class="banner-table">
            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column label="所属模板" width="120" align="center">
              <template #default="{ row }">
                <el-tag :type="getBannerTemplateTagType(row.template_code || row.template_scope)" size="small">
                  {{ getBannerTemplateLabel(row.template_code || row.template_scope) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="横幅效果预览" width="130" align="center">
              <template #default="{ row }">
                <el-image
                  v-if="row.image"
                  :src="row.image"
                  fit="cover"
                  class="banner-preview-img"
                  preview-teleported
                  :preview-src-list="[row.image]"
                />
                <span v-else class="text-muted">无背景图</span>
              </template>
            </el-table-column>
            <el-table-column prop="title" label="主标题 / 副标题" min-width="200">
              <template #default="{ row }">
                <div class="banner-titles">
                  <div class="main-title">
                    <el-tag v-if="row.badge || row.badge_text" size="small" type="danger" effect="plain" class="badge-tag">
                      {{ row.badge || row.badge_text }}
                    </el-tag>
                    {{ row.title }}
                  </div>
                  <div class="sub-title" v-if="row.subtitle">{{ row.subtitle }}</div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="动作按钮与跳转" min-width="160">
              <template #default="{ row }">
                <div v-if="row.btn_text || row.button_text" class="btn-meta">
                  <span class="btn-name">[{{ row.btn_text || row.button_text }}]</span>
                  <span class="btn-url">{{ row.btn_link || row.button_url || '#' }}</span>
                </div>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
            <el-table-column prop="weight" label="排序权重" width="90" align="center" sortable />
            <el-table-column prop="status" label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '显示' : '隐藏' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="168" align="center" fixed="right">
              <template #default="{ row }">
                <div class="hw-table-actions">
                  <el-button plain type="primary" size="small" @click="handleEditBanner(row)">编辑</el-button>
                  <el-button plain type="danger" size="small" @click="handleDeleteBanner(row)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 横幅新增 / 编辑弹窗 -->
    <el-dialog
      v-model="bannerDialogVisible"
      :title="isEditBanner ? '编辑轮播横幅' : '新增轮播横幅'"
      width="640px"
      destroy-on-close
    >
      <el-form ref="bannerFormRef" :model="bannerForm" :rules="bannerRules" label-width="100px">
        <el-form-item label="所属模板" prop="template_code">
          <el-select v-model="bannerForm.template_code" style="width: 100%">
            <el-option label="🌟 通用 (全部模板均展示)" value="all" />
            <el-option label="📝 个人博客 (blog)" value="blog" />
            <el-option label="🏢 企业官网 (corporate)" value="corporate" />
            <el-option label="🛍️ 产品展厅 (showcase)" value="showcase" />
          </el-select>
        </el-form-item>
        <el-form-item label="主标题" prop="title">
          <el-input v-model="bannerForm.title" placeholder="横幅核心主标题" />
        </el-form-item>
        <el-form-item label="副标题" prop="subtitle">
          <el-input v-model="bannerForm.subtitle" placeholder="横幅描述或宣传口号" />
        </el-form-item>
        <el-form-item label="高亮角标" prop="badge">
          <el-input v-model="bannerForm.badge" placeholder="例如：2026全新架构 / 震撼发布" />
        </el-form-item>
        <el-form-item label="背景图片 URL" prop="image">
          <el-input v-model="bannerForm.image" placeholder="请输入背景大图链接或上传路径" />
        </el-form-item>
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="按钮文字" prop="btn_text">
              <el-input v-model="bannerForm.btn_text" placeholder="例如：立即了解 / 免费体验" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="按钮跳转链接" prop="btn_link">
              <el-input v-model="bannerForm.btn_link" placeholder="例如：/article/1 或外部链接" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="排序权重" prop="weight">
              <el-input-number v-model="bannerForm.weight" :min="0" :max="9999" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="展示状态" prop="status">
              <el-switch
                v-model="bannerForm.status"
                :active-value="1"
                :inactive-value="0"
                active-text="启用"
                inactive-text="停用"
              />
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>
      <template #footer>
        <el-button @click="bannerDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="bannerSubmitting" @click="handleSubmitBanner">确认保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl } from '/@/utils/platform'
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getCmsSetting, saveCmsSetting, saveCmsBanner, delCmsBanner } from '/@/api/hwadmin'

defineOptions({
  name: 'CmsSettingIndex',
})

const activeTemplate = ref('corporate')
const homeMode = ref('cms')
const activeTab = ref('base')
const templateConfigTab = ref<'corporate' | 'blog' | 'showcase'>('corporate')
const saving = ref(false)
const switcherSaving = ref(false)
const switching = ref<string | null>(null)
const homeSettingLoading = ref(false)

const stats = reactive({
  article_count: 0,
  product_count: 0,
  solution_count: 0,
  category_count: 0,
  inquiry_count: 0,
  pending_inquiry_count: 0,
  member_count: 0,
  total_views: 0,
})

const config = reactive<Record<string, any>>({
  site_name: 'HwAdmin 现代科技',
  site_slogan: '驱动企业下一代数字化创新的全栈智能平台',
  show_theme_switcher: 1,
  seo_keywords: 'HwAdmin, ThinkPHP8, Vue3, CMS, 企业官网, 个人博客, 产品展厅',
  seo_description: 'HwAdmin CMS 网站系统，提供个人博客、企业官方门户、购物产品展厅三套通用主题，自由热切换与精细化管理。',
  icp_no: '京ICP备20260888号-1',
  copyright: 'Copyright © 2026 HwAdmin Team. All Rights reserved.',

  // 会员体系与互动
  allow_member_register: 1,
  comment_require_login: 0,
  interaction_reward_score: 10,

  // 博客独立配置
  blog_site_name: '极客空间',
  blog_site_slogan: '专注全栈工程、分布式架构与 AI 实践的技术博客',
  blog_cta_text: '留言',
  blog_hero_badge: '极客老王 · 个人技术空间',
  blog_hero_title: '探索架构与工程美学的无垠边界',
  blog_hero_sub: '汇聚微服务架构、Vue 3 现代化交互与大语言模型工程落地实战手记。',
  blog_author: '极客老王',
  blog_avatar: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&q=80',
  blog_github: 'https://github.com/hwadmin',
  blog_email: 'admin@hwadmin.com',
  blog_bio: '保持专注，热爱开源，探索 AI 与工程架构的无垠边界。十年全栈工程师与技术博主。',
  blog_rank_title: '🔥 热门文章榜',
  blog_tags_title: '🏷️ 热门标签',
  blog_comments_title: '💬 读者留言',
  blog_footer_service_title: '关于博主与交流',
  blog_footer_service_desc: '欢迎订阅专栏或留言交流工程架构、全栈开发与开源工具链实践。',
  blog_seo_keywords: '',
  blog_seo_description: '',

  // 企业官网独立配置
  corp_site_name: 'HwAdmin 科技',
  corp_site_slogan: '新一代企业级数字化高可用智能基座',
  corp_cta_text: '预约',
  corp_hero_badge: 'HwAdmin 2.0 旗舰发布',
  corp_hero_title: '新一代企业级数字化高可用智能基座',
  corp_hero_sub: 'ThinkPHP 8 + Vue 3 极速架构，双数据库原生兼容与零侵入插件体系，驱动企业数智化腾飞。',
  corp_hero_btn1_text: '探索解决方案',
  corp_hero_btn2_text: '预约专家演示',
  corp_solutions_title: '核心业务解决方案矩阵',
  corp_solutions_desc: '面向金融科技、智能制造与政企供应链，提供端到端高可靠自主可控数字底座。',
  corp_cases_title: '标杆客户转型成功实践',
  corp_cases_desc: '世界 500 强与行业先锋企业的信赖之选，见证数字化带来的真实跃升。',
  corp_about_title: '引领下一代智能架构革新',
  corp_contact_title: '预约产品演示与方案洽谈',
  corp_contact_desc: '我们的行业数字化顾问将在 15 分钟内与您致电确认需求细节，提供量身定制的技术选型方案。',
  corp_name: '华网云擎科技有限公司',
  corp_phone: '400-888-2026',
  corp_email: 'business@hwadmin.com',
  corp_address: '北京市海淀区中关村前沿科技软件园 A 座 8 层',
  corp_stat_1_num: '99.999%',
  corp_stat_1_label: '工业级高可用 SLA',
  corp_stat_2_num: '500+',
  corp_stat_2_label: '行业龙头标杆信赖',
  corp_stat_3_num: '35ms',
  corp_stat_3_label: '微中台平均超低延迟',
  corp_stat_4_num: '24/7',
  corp_stat_4_label: '专属运维专家保障',
  corp_about_html: '',
  corp_footer_service_title: '商务合作与咨询',
  corp_footer_service_desc: '支持私有化部署、信创适配与政企项目定制，欢迎提交商务合作需求。',
  corp_seo_keywords: '',
  corp_seo_description: '',

  // 展厅独立配置
  showcase_site_name: '智算严选展厅',
  showcase_site_slogan: '官方原厂直供，支持政企批量阶梯报价与工程打样',
  showcase_cta_text: '询价',
  showcase_hero_badge: '🛍️ 2026 算力硬件与智能装备展厅',
  showcase_title: '2026 智能硬件与算力装备精选展厅',
  showcase_hotline: '010-8888-6666',
  showcase_notice: '官方直供正品，现货库存充裕，支持政企客户按批量规模定制专属阶梯报价',
  showcase_inquiry_tip: '提交采购意向后，专属销售顾问将在 15 分钟内与您致电确认技术选型与阶梯底价',
  showcase_promise_1_title: '官方原厂正品',
  showcase_promise_1_desc: '全系出厂 100% 严苛质检',
  showcase_promise_2_title: '专属批量报价',
  showcase_promise_2_desc: '支持阶梯折扣与政企采购单',
  showcase_promise_3_title: '全国联保售后',
  showcase_promise_3_desc: '3年质保与专属技术顾问',
  showcase_promise_4_title: '软硬方案打样',
  showcase_promise_4_desc: '免费提供工程镜像定制预刷',
  showcase_footer_service_title: '采购询价与支持',
  showcase_footer_service_desc: '本展示站为纯产品与方案展示，不涉及在线支付；如需获取阶梯报价单请提交采购意向。',
  showcase_seo_keywords: '',
  showcase_seo_description: '',
})

const banners = ref<any[]>([])
const bannerFilterTemplate = ref('all')

const currentTemplateTitle = computed(() => {
  const map: Record<string, string> = {
    blog: '个人博客分享站',
    corporate: '企业官方门户网站',
    showcase: '购物产品展示站',
  }
  return map[activeTemplate.value] || '自定义模板'
})

const filteredBanners = computed(() => {
  if (bannerFilterTemplate.value === 'all') {
    return banners.value
  }
  return banners.value.filter((b) => {
    const scope = b.template_code || b.template_scope || 'all'
    return scope === bannerFilterTemplate.value || scope === 'all'
  })
})

const getBannerTemplateLabel = (scope: string) => {
  const map: Record<string, string> = {
    all: '🌟 通用全站',
    blog: '📝 个人博客',
    corporate: '🏢 企业官网',
    showcase: '🛍️ 产品展厅',
  }
  return map[scope] || scope || '通用'
}

const getBannerTemplateTagType = (scope: string): any => {
  const map: Record<string, string> = {
    all: 'warning',
    blog: 'primary',
    corporate: 'success',
    showcase: 'danger',
  }
  return map[scope] || 'info'
}

const openPreview = (path: string) => {
  void openExternalUrl(path)
}

const loadData = async () => {
  try {
    const res = await getCmsSetting()
    const data = res?.data || {}
    if (data.active_template) {
      activeTemplate.value = data.active_template
      if (['corporate', 'blog', 'showcase'].includes(data.active_template)) {
        templateConfigTab.value = data.active_template
      }
    }
    if (data.home_mode) {
      homeMode.value = data.home_mode
    }
    if (data.stats) {
      Object.assign(stats, data.stats)
    }
    if (data.config && typeof data.config === 'object') {
      Object.assign(config, data.config)
      config.show_theme_switcher = Number(config.show_theme_switcher ?? 1)
    }
    if (Array.isArray(data.banners)) {
      banners.value = data.banners
    }
  } catch (e) {
    console.warn('获取 CMS 设置接口异常', e)
  }
}

const handleToggleThemeSwitcher = async (val: string | number | boolean) => {
  const nextVal = Number(val) === 1 ? 1 : 0
  switcherSaving.value = true
  try {
    await saveCmsSetting({
      active_template: activeTemplate.value,
      config: { show_theme_switcher: nextVal },
    })
    ElMessage.success(nextVal === 1 ? '已开启前台切换菜单' : '已关闭前台切换菜单（仅显示当前主题）')
  } catch (e: any) {
    config.show_theme_switcher = nextVal === 1 ? 0 : 1
    ElMessage.error(e?.msg || '切换菜单开关保存失败')
  } finally {
    switcherSaving.value = false
  }
}

const initMockBanners = () => {
  banners.value = [
    {
      id: 1,
      template_scope: 'all',
      title: 'HwAdmin 1.0 全新架构里程碑',
      subtitle: '基于 ThinkPHP 8.1 与 Vue 3 打造的企业级开箱即用基座',
      badge_text: '2026年度重磅',
      image: 'https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&auto=format&fit=crop&q=80',
      button_text: '即刻体验',
      button_url: '/#features',
      weigh: 100,
      status: 1,
    },
    {
      id: 2,
      template_scope: 'corporate',
      title: '赋能企业数字化转型飞跃',
      subtitle: '全栈端到端定制解决方案，赋能核心业务极速增长',
      badge_text: '企业级标杆',
      image: 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&auto=format&fit=crop&q=80',
      button_text: '商务咨询',
      button_url: '/#contact',
      weigh: 90,
      status: 1,
    },
    {
      id: 3,
      template_scope: 'showcase',
      title: '极速云服务器旗舰硬件平台',
      subtitle: '高并发低时延工业级硬件展厅，支持一键询单与专属定制',
      badge_text: '热销爆款',
      image: 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=600&auto=format&fit=crop&q=80',
      button_text: '查看参数规格',
      button_url: '/#products',
      weigh: 80,
      status: 1,
    },
  ]
}

const handleSwitchTemplate = async (templateKey: string) => {
  switching.value = templateKey
  try {
    await saveCmsSetting({ active_template: templateKey })
    activeTemplate.value = templateKey
    if (['corporate', 'blog', 'showcase'].includes(templateKey)) {
      templateConfigTab.value = templateKey as 'corporate' | 'blog' | 'showcase'
    }
    ElMessage.success(`成功切换至「${currentTemplateTitle.value}」模板！`)
  } catch (e: any) {
    ElMessage.error(e?.msg || '切换模板失败')
  } finally {
    switching.value = null
  }
}

const handleSetAsHome = async () => {
  homeSettingLoading.value = true
  try {
    await saveCmsSetting({ set_as_home: 1, active_template: activeTemplate.value })
    homeMode.value = 'cms'
    ElMessage.success('已成功将 CMS 网站设为系统首页 (/)！')
  } catch (e: any) {
    ElMessage.error(e?.msg || '设置首页失败')
  } finally {
    homeSettingLoading.value = false
  }
}

const handleSaveConfig = async () => {
  saving.value = true
  try {
    await saveCmsSetting({
      active_template: activeTemplate.value,
      config: { ...config },
    })
    ElMessage.success('CMS 网站配置保存成功！')
  } catch (e: any) {
    ElMessage.error(e?.msg || '保存配置失败')
  } finally {
    saving.value = false
  }
}

// Banner 轮播图管理相关
const bannerDialogVisible = ref(false)
const isEditBanner = ref(false)
const bannerSubmitting = ref(false)
const bannerFormRef = ref<FormInstance>()
const bannerForm = reactive({
  id: undefined as number | undefined,
  template_code: 'all',
  title: '',
  subtitle: '',
  badge: '',
  image: '',
  btn_text: '',
  btn_link: '',
  weight: 100,
  status: 1,
})

const bannerRules = reactive<FormRules>({
  template_code: [{ required: true, message: '请选择所属模板', trigger: 'change' }],
  title: [{ required: true, message: '请输入主标题', trigger: 'blur' }],
})

const handleAddBanner = () => {
  isEditBanner.value = false
  bannerForm.id = undefined
  bannerForm.template_code = bannerFilterTemplate.value === 'all' ? 'all' : bannerFilterTemplate.value
  bannerForm.title = ''
  bannerForm.subtitle = ''
  bannerForm.badge = ''
  bannerForm.image = ''
  bannerForm.btn_text = ''
  bannerForm.btn_link = ''
  bannerForm.weight = 100
  bannerForm.status = 1
  bannerDialogVisible.value = true
}

const handleEditBanner = (row: any) => {
  isEditBanner.value = true
  bannerForm.id = row.id
  bannerForm.template_code = row.template_code || row.template_scope || 'all'
  bannerForm.title = row.title || ''
  bannerForm.subtitle = row.subtitle || ''
  bannerForm.badge = row.badge || row.badge_text || ''
  bannerForm.image = row.image || ''
  bannerForm.btn_text = row.btn_text || row.button_text || ''
  bannerForm.btn_link = row.btn_link || row.button_url || ''
  bannerForm.weight = row.weight ?? row.weigh ?? 100
  bannerForm.status = row.status ?? 1
  bannerDialogVisible.value = true
}

const handleSubmitBanner = async () => {
  if (!bannerFormRef.value) return
  await bannerFormRef.value.validate(async (valid) => {
    if (!valid) return
    bannerSubmitting.value = true
    try {
      await saveCmsBanner({ ...bannerForm })
      ElMessage.success(isEditBanner.value ? '轮播横幅已更新' : '轮播横幅已添加')
      bannerDialogVisible.value = false
      loadData()
    } catch (e: any) {
      ElMessage.error(e?.msg || '保存轮播横幅失败')
    } finally {
      bannerSubmitting.value = false
    }
  })
}

const handleDeleteBanner = (row: any) => {
  ElMessageBox.confirm(`确定要删除横幅 "${row.title}" 吗？`, '安全警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delCmsBanner(row.id)
      ElMessage.success('轮播横幅已删除')
      loadData()
    } catch (e: any) {
      ElMessage.error(e?.msg || '删除轮播横幅失败')
    }
  })
}

onMounted(() => {
  loadData()
})
</script>

<style lang="scss" scoped>
.cms-setting-container {
  padding: 20px;

  .stat-banner-card {
    margin-bottom: 20px;
    border-radius: 10px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid var(--el-border-color-lighter);

    .stat-banner-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }

    .stat-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 24px;
    }

    .stat-item {
      display: flex;
      align-items: center;
      gap: 12px;

      .stat-icon-wrapper {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;

        &.blue {
          background: rgba(64, 158, 255, 0.12);
          color: #409eff;
        }
        &.green {
          background: rgba(103, 194, 58, 0.12);
          color: #67c23a;
        }
        &.purple {
          background: rgba(156, 39, 176, 0.12);
          color: #9c27b0;
        }
        &.orange {
          background: rgba(230, 162, 60, 0.12);
          color: #e6a23c;
        }
        &.red {
          background: rgba(245, 108, 108, 0.12);
          color: #f56c6c;
        }
        &.cyan {
          background: rgba(0, 188, 212, 0.12);
          color: #00bcd4;
        }
      }

      .stat-meta {
        .stat-value {
          font-size: 20px;
          font-weight: 700;
          color: var(--el-text-color-primary);
          line-height: 1.2;

          .stat-sub {
            font-size: 13px;
            font-weight: normal;
            color: var(--el-text-color-secondary);
          }
        }

        .stat-label {
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin-top: 2px;
        }
      }
    }

    .banner-actions {
      display: flex;
      gap: 12px;
    }
  }

  .section-title {
    margin: 16px 0 16px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;

    .title-left {
      display: flex;
      align-items: center;
      gap: 10px;

      .indicator {
        width: 4px;
        height: 18px;
        background: var(--el-color-primary);
        border-radius: 2px;
      }

      h3 {
        margin: 0;
        font-size: 17px;
        font-weight: 600;
        color: var(--el-text-color-primary);
      }

      .current-tag {
        font-weight: 500;
      }
    }

    .title-tip {
      font-size: 13px;
      color: var(--el-text-color-secondary);
    }

    .title-right-controls {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;

      .switcher-control-pill {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 6px 14px;
        border-radius: 999px;
        background: var(--el-fill-color-light);
        border: 1px solid var(--el-border-color-lighter);
        flex-wrap: wrap;

        .switcher-label {
          font-size: 13px;
          font-weight: 600;
          color: var(--el-text-color-primary);
        }

        .switcher-hint {
          font-size: 12px;
          color: var(--el-text-color-secondary);
        }
      }
    }
  }

  .theme-subtabs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .template-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;

    @media (max-width: 1200px) {
      grid-template-columns: 1fr;
    }

    .template-card {
      position: relative;
      background: var(--el-bg-color-overlay);
      border: 2px solid var(--el-border-color-lighter);
      border-radius: 12px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

      &:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
      }

      &.active {
        border-color: #67c23a;
        background: linear-gradient(180deg, rgba(103, 194, 58, 0.03) 0%, var(--el-bg-color-overlay) 100%);
        box-shadow: 0 6px 18px rgba(103, 194, 58, 0.15);
      }

      .active-badge {
        position: absolute;
        top: -12px;
        right: 18px;
        background: #67c23a;
        color: #fff;
        padding: 3px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        box-shadow: 0 2px 6px rgba(103, 194, 58, 0.4);
      }

      .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;

        .theme-icon {
          width: 44px;
          height: 44px;
          border-radius: 10px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 22px;

          &.blog-theme {
            background: rgba(64, 158, 255, 0.15);
          }
          &.corp-theme {
            background: rgba(103, 194, 58, 0.15);
          }
          &.showcase-theme {
            background: rgba(230, 162, 60, 0.15);
          }
        }

        .theme-info {
          .theme-name {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--el-text-color-primary);
          }
          .theme-code {
            font-size: 12px;
            color: var(--el-text-color-secondary);
            font-family: monospace;
          }
        }
      }

      // CSS 模拟微缩布局
      .mockup-preview {
        height: 120px;
        background: #f1f5f9;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        margin-bottom: 14px;
        padding: 8px;
        display: flex;
        flex-direction: column;

        .mock-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          height: 16px;
          margin-bottom: 6px;
          border-bottom: 1px solid #e2e8f0;
          padding-bottom: 4px;

          .mock-brand {
            font-size: 10px;
            font-weight: bold;
            color: #475569;
          }
          .mock-nav, .mock-menu-bar {
            display: flex;
            gap: 4px;
            .dot, .bar-link {
              width: 14px;
              height: 4px;
              background: #cbd5e1;
              border-radius: 2px;
            }
          }
        }

        &.blog-mockup {
          .mock-body {
            display: flex;
            gap: 8px;
            flex: 1;

            .mock-sidebar {
              width: 32px;
              background: #fff;
              border-radius: 4px;
              padding: 4px;
              display: flex;
              flex-direction: column;
              align-items: center;
              gap: 4px;

              .mock-avatar {
                width: 16px;
                height: 16px;
                border-radius: 50%;
                background: #94a3b8;
              }
              .mock-line.sm {
                width: 20px;
                height: 3px;
                background: #cbd5e1;
              }
              .mock-tags {
                display: flex;
                gap: 2px;
                .mock-chip {
                  width: 8px;
                  height: 4px;
                  border-radius: 2px;
                  &.blue { background: #60a5fa; }
                  &.green { background: #4ade80; }
                }
              }
            }

            .mock-feed {
              flex: 1;
              display: flex;
              flex-direction: column;
              gap: 4px;

              .mock-article-card {
                background: #fff;
                border-radius: 4px;
                padding: 4px 6px;
                display: flex;
                flex-direction: column;
                gap: 3px;

                .mock-line {
                  height: 4px;
                  background: #e2e8f0;
                  border-radius: 2px;
                  &.lg {
                    background: #64748b;
                    width: 70%;
                  }
                }
              }
            }
          }
        }

        &.corp-mockup {
          .mock-hero {
            height: 36px;
            background: linear-gradient(135deg, #1e293b, #334155);
            border-radius: 4px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;

            .mock-hero-title {
              width: 50px;
              height: 6px;
              background: #94a3b8;
              border-radius: 3px;
            }
            .mock-hero-btn {
              width: 24px;
              height: 10px;
              background: #3b82f6;
              border-radius: 2px;
            }
          }

          .mock-stats-bar {
            display: flex;
            justify-content: space-around;
            height: 12px;
            background: #fff;
            border-radius: 3px;
            margin-bottom: 6px;
            align-items: center;

            .num-box {
              width: 20px;
              height: 4px;
              background: #10b981;
              border-radius: 2px;
            }
          }

          .mock-solution-grid {
            display: flex;
            gap: 4px;
            flex: 1;

            .sol-card {
              flex: 1;
              background: #fff;
              border-radius: 3px;
              border: 1px dashed #cbd5e1;
            }
          }
        }

        &.showcase-mockup {
          .mock-search-bar {
            width: 40px;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
          }
          .mock-banner-mini {
            height: 22px;
            background: linear-gradient(90deg, #f59e0b, #ef4444);
            border-radius: 4px;
            margin-bottom: 6px;
          }
          .mock-products-grid {
            display: flex;
            gap: 6px;
            flex: 1;

            .mock-prod-item {
              flex: 1;
              background: #fff;
              border-radius: 4px;
              padding: 4px;
              display: flex;
              flex-direction: column;
              align-items: center;
              gap: 4px;

              .img-ph {
                width: 100%;
                height: 28px;
                background: #f1f5f9;
                border-radius: 3px;
              }
              .price-ph {
                width: 60%;
                height: 4px;
                background: #f43f5e;
                border-radius: 2px;
              }
            }
          }
        }
      }

      .scene-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 12px;
      }

      .feature-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 18px;
        flex: 1;

        .feature-item {
          display: flex;
          align-items: center;
          gap: 6px;
          font-size: 13px;
          color: var(--el-text-color-regular);

          .el-icon {
            color: #67c23a;
          }
        }
      }

      .card-actions {
        display: flex;
        gap: 10px;

        .action-btn {
          flex: 1;
        }

        .preview-btn {
          width: 96px;
        }
      }
    }
  }

  .config-tabs-card {
    border-radius: 10px;

    .setting-tabs {
      .form-tip {
        font-size: 12px;
        color: var(--el-text-color-secondary);
        margin-top: 4px;
      }

      .template-collapse {
        margin-bottom: 20px;
        border-radius: 8px;
        overflow: hidden;

        .stat-group-box {
          background: var(--el-fill-color-light);
          border-radius: 6px;
          margin-bottom: 8px;
        }
      }

      .form-footer {
        display: flex;
        justify-content: flex-end;
        padding-top: 16px;
        border-top: 1px solid var(--el-border-color-lighter);
      }

      .banner-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;

        .filter-group {
          display: flex;
          align-items: center;
          gap: 10px;

          .filter-label {
            font-size: 13px;
            color: var(--el-text-color-regular);
          }
        }
      }

      .banner-table {
        border-radius: 8px;

        .banner-preview-img {
          width: 90px;
          height: 48px;
          border-radius: 4px;
        }

        .banner-titles {
          .main-title {
            font-weight: 600;
            color: var(--el-text-color-primary);
            display: flex;
            align-items: center;
            gap: 6px;
          }
          .sub-title {
            font-size: 12px;
            color: var(--el-text-color-secondary);
            margin-top: 3px;
          }
        }

        .btn-meta {
          display: flex;
          flex-direction: column;
          font-size: 12px;

          .btn-name {
            font-weight: 500;
            color: var(--el-color-primary);
          }
          .btn-url {
            color: var(--el-text-color-secondary);
            font-family: monospace;
          }
        }

        .text-muted {
          font-size: 12px;
          color: var(--el-text-color-secondary);
        }
      }
    }
  }
}
</style>
