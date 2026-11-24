<template>
  <div class="system-config-container">
    <DeploymentPanel v-model="deploymentVisible" />
    <el-card shadow="never" class="config-card">
      <template #header>
        <div class="card-header">
          <div class="header-title">
            <el-icon><vab-icon icon="equalizer-line" /></el-icon>
            <span>系统与运行配置中心</span>
          </div>
          <div class="header-actions">
            <el-button v-if="aclStore.getAdmin" @click="deploymentVisible = true">部署安全复查</el-button>
            <el-button type="primary" plain @click="handleAddConfigItem">
              <el-icon><vab-icon icon="add-line" /></el-icon>
              新增配置项
            </el-button>
            <el-button @click="fetchConfig">
              <el-icon><vab-icon icon="refresh-line" /></el-icon>
              刷新配置
            </el-button>
          </div>
        </div>
      </template>

      <el-tabs v-model="activeGroup" class="config-tabs">
        <el-tab-pane
          v-for="group in groupList"
          :key="group.key"
          :label="group.title"
          :name="group.key"
        >
          <div v-loading="loading" class="tab-content">
            <el-empty
              v-if="!currentGroupItems.length && !loading"
              description="该分组下暂无配置项，可点击右上角新增配置项"
            />

            <el-form
              v-else
              ref="configFormRef"
              label-width="160px"
              class="config-dynamic-form"
            >
              <div
                v-for="item in currentGroupItems"
                :key="item.id || item.name"
                class="config-form-row"
              >
                <el-form-item :label="item.title || item.name">
                  <!-- 后台安全入口专用渲染 -->
                  <div v-if="item.name === 'admin_entry'" style="display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 540px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                      <el-input
                        v-model="formValues[item.name]"
                        :placeholder="item.tip || '如 myconsole'"
                        style="max-width: 380px"
                      >
                        <template #prepend>{{ originHost }}/</template>
                        <template #append>/</template>
                      </el-input>
                      <el-button type="primary" plain @click="previewAdminEntry(formValues[item.name])">
                        <el-icon><vab-icon icon="external-link-line" /></el-icon>
                        测试访问
                      </el-button>
                    </div>
                    <el-alert
                      v-if="formValues[item.name] && formValues[item.name] !== 'admin'"
                      type="success"
                      :closable="false"
                      show-icon
                      title="安全入口已混淆启用"
                      description="访问默认 /admin 将被拦截隐藏返回 404，大幅提升抗自动化扫描安全性"
                    />
                    <el-alert
                      v-else
                      type="warning"
                      :closable="false"
                      show-icon
                      title="当前正在使用默认入口 /admin"
                      description="建议修改为自定义路径（如 myconsole、ops_center），防止自动化漏洞扫描器探测"
                    />
                  </div>

                  <!-- PC 首页模式专用渲染 -->
                  <div v-else-if="item.name === 'home_mode'" style="display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 500px;">
                    <el-select
                      v-model="formValues[item.name]"
                      placeholder="请选择首页模式"
                      style="width: 100%"
                    >
                      <el-option
                        v-for="opt in parseOptions(item.options || item.content)"
                        :key="opt.value"
                        :label="opt.label"
                        :value="opt.value"
                      />
                    </el-select>
                    <div v-if="formValues[item.name] === 'portal'" style="font-size: 13px; color: var(--el-color-primary); line-height: 1.4;">
                      💡 访问根域名 (/) 将展示现代科技蓝品牌展示门户页，对外部访客隐藏后台入口。
                    </div>
                    <div v-else-if="formValues[item.name] === 'cms'" style="font-size: 13px; color: var(--el-color-success); line-height: 1.4;">
                      📰 访问根域名 (/) 将由 CMS 插件接管，展示全功能内容资讯前台门户。
                    </div>
                    <div v-else-if="formValues[item.name] === 'custom_plugin'" style="font-size: 13px; color: var(--el-color-warning); line-height: 1.4;">
                      🧩 访问根域名 (/) 将由下方指定的【接管首页的插件标识】模板接管展示。
                    </div>
                    <div v-else-if="formValues[item.name] === 'redirect_admin'" style="font-size: 13px; color: var(--el-color-danger); line-height: 1.4;">
                      ⚠️ 访问根域名 (/) 将自动重定向至管理后台登录入口。
                    </div>
                  </div>

                  <!-- 后台登录验证码开关专用渲染 -->
                  <div v-else-if="item.name === 'login_captcha'" style="display: flex; align-items: center; gap: 14px;">
                    <el-switch
                      v-model="formValues[item.name]"
                      :active-value="1"
                      :inactive-value="0"
                      active-text="开启图形验证码"
                      inactive-text="关闭"
                    />
                    <el-tag :type="Number(formValues[item.name]) === 1 ? 'success' : 'info'" effect="plain">
                      {{ Number(formValues[item.name]) === 1 ? '已启用防爆破防护' : '登录无需验证码' }}
                    </el-tag>
                  </div>

                  <!-- String / 单行文本 -->
                  <el-input
                    v-else-if="item.type === 'string'"
                    v-model="formValues[item.name]"
                    :placeholder="item.tip || `请输入${item.title}`"
                    style="max-width: 500px"
                  />

                  <!-- Text / 多行文本 -->
                  <el-input
                    v-else-if="item.type === 'text'"
                    v-model="formValues[item.name]"
                    type="textarea"
                    :rows="3"
                    :placeholder="item.tip || `请输入${item.title}`"
                    style="max-width: 500px"
                  />

                  <!-- Number / 数字 -->
                  <el-input-number
                    v-else-if="item.type === 'number'"
                    v-model="formValues[item.name]"
                    style="width: 200px"
                  />

                  <!-- Switch / 开关 -->
                  <el-switch
                    v-else-if="item.type === 'switch'"
                    v-model="formValues[item.name]"
                    :active-value="1"
                    :inactive-value="0"
                    active-text="开启"
                    inactive-text="关闭"
                  />

                  <!-- Select / 下拉选择 -->
                  <div v-else-if="item.type === 'select'" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px">
                    <el-select
                      v-model="formValues[item.name]"
                      placeholder="请选择"
                      style="max-width: 320px; width: 100%"
                    >
                      <el-option
                        v-for="opt in parseOptions(item.options || item.content)"
                        :key="opt.value"
                        :label="opt.label"
                        :value="opt.value"
                      />
                    </el-select>
                  </div>

                  <!-- Image / 附件图片 -->
                  <div v-else-if="item.type === 'image'" class="image-uploader-box">
                    <el-input
                      v-model="formValues[item.name]"
                      :placeholder="defaultBrandImages[item.name] ? '留空使用默认素材，或填写图片 URL' : '图片 URL 地址'"
                      style="max-width: 360px; margin-right: 12px"
                      clearable
                    />
                    <el-upload
                      action="#"
                      :show-file-list="false"
                      :http-request="(opts) => handleUploadImage(opts, item.name)"
                      accept="image/*"
                    >
                      <el-button type="primary" plain>
                        <el-icon><vab-icon icon="upload-cloud-line" /></el-icon>
                        上传图片
                      </el-button>
                    </el-upload>
                    <div v-if="formValues[item.name] || defaultBrandImages[item.name]" class="image-preview-wrap">
                      <el-image
                        :src="resolveUrl(formValues[item.name] || defaultBrandImages[item.name])"
                        :preview-src-list="[resolveUrl(formValues[item.name] || defaultBrandImages[item.name])]"
                        preview-teleported
                        style="width: 36px; height: 36px; border-radius: 4px; margin-left: 12px; border: 1px solid var(--el-border-color)"
                        fit="cover"
                      >
                        <template #error>
                          <div class="image-error-slot">
                            <el-icon><vab-icon icon="image-line" /></el-icon>
                          </div>
                        </template>
                      </el-image>
                      <span v-if="!formValues[item.name] && defaultBrandImages[item.name]" class="image-preview-label">
                        默认素材
                      </span>
                      <el-button
                        v-if="defaultBrandImages[item.name]"
                        type="danger"
                        link
                        size="small"
                        style="margin-left: 8px"
                        :disabled="!formValues[item.name]"
                        @click="formValues[item.name] = ''"
                      >
                        清空
                      </el-button>
                    </div>
                  </div>

                  <!-- 兜底输入 -->
                  <el-input
                    v-else
                    v-model="formValues[item.name]"
                    style="max-width: 500px"
                  />

                  <div v-if="item.tip" class="config-tip">{{ item.tip }}</div>
                  <div class="config-name-tag">
                    <code>{{ item.name }}</code>
                  </div>

                  <!-- 删除单项配置 -->
                  <el-button
                    type="danger"
                    link
                    size="small"
                    class="btn-del-item"
                    @click="handleDeleteItem(item)"
                  >
                    删除项
                  </el-button>
                </el-form-item>
              </div>

              <!-- 安全加固与入口防护监控面板（仅在安全配置分组展示） -->
              <el-card
                v-if="activeGroup === 'security'"
                shadow="never"
                class="security-status-card"
                style="margin: 24px 0; border-radius: 8px; background: var(--el-fill-color-lighter);"
              >
                <template #header>
                  <div style="width: 100%; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <span style="font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                      <el-icon color="#38bdf8"><vab-icon icon="shield-keyhole-line" /></el-icon>
                      系统安全加固与入口防护监控
                    </span>
                    <el-tag :type="formValues['admin_entry'] && formValues['admin_entry'] !== 'admin' && Number(formValues['login_captcha']) === 1 ? 'success' : 'warning'" effect="dark">
                      {{ formValues['admin_entry'] && formValues['admin_entry'] !== 'admin' && Number(formValues['login_captcha']) === 1 ? '高安全防护级别' : '标准安全状态' }}
                    </el-tag>
                  </div>
                </template>
                <el-row :gutter="20">
                  <el-col :xs="24" :sm="8">
                    <div class="sec-stat-item">
                      <div class="sec-stat-label">后台入口路径隐藏状态</div>
                      <div class="sec-stat-val">
                        <el-tag v-if="formValues['admin_entry'] && formValues['admin_entry'] !== 'admin'" type="success">
                          已隐藏拦截 (/{{ formValues['admin_entry'] }}/)
                        </el-tag>
                        <el-tag v-else type="warning">默认公开路径 (/admin/)</el-tag>
                      </div>
                      <div class="sec-stat-tip">
                        {{ formValues['admin_entry'] && formValues['admin_entry'] !== 'admin' ? '原 /admin 入口已被阻断返回 404，外部扫描器无法嗅探' : '建议修改自定义路径防止自动化漏洞扫描' }}
                      </div>
                    </div>
                  </el-col>
                  <el-col :xs="24" :sm="8">
                    <div class="sec-stat-item">
                      <div class="sec-stat-label">登录验证码防护状态</div>
                      <div class="sec-stat-val">
                        <el-tag v-if="Number(formValues['login_captcha']) === 1" type="success">
                          SVG 矢量防护生效中
                        </el-tag>
                        <el-tag v-else type="danger">未开启防爆破校验</el-tag>
                      </div>
                      <div class="sec-stat-tip">
                        {{ Number(formValues['login_captcha']) === 1 ? '每次登录均需校验动态矢量噪点验证码' : '存在密码字典暴力破解风险' }}
                      </div>
                    </div>
                  </el-col>
                  <el-col :xs="24" :sm="8">
                    <div class="sec-stat-item">
                      <div class="sec-stat-label">PC 首页展示策略</div>
                      <div class="sec-stat-val">
                        <el-tag v-if="formValues['home_mode'] === 'portal'" type="primary">品牌展示门户页</el-tag>
                        <el-tag v-else-if="formValues['home_mode'] === 'cms'" type="success">CMS 前台门户接管</el-tag>
                        <el-tag v-else-if="formValues['home_mode'] === 'custom_plugin'" type="warning">插件接管 ({{ formValues['home_plugin'] || '无' }})</el-tag>
                        <el-tag v-else type="info">重定向至管理后台</el-tag>
                      </div>
                      <div class="sec-stat-tip">根路径 (/) 对外访问呈现模式</div>
                    </div>
                  </el-col>
                </el-row>
              </el-card>

              <el-form-item class="form-submit-row">
                <el-button
                  type="primary"
                  size="large"
                  :loading="saveLoading"
                  @click="handleSaveConfig"
                >
                  <el-icon><vab-icon icon="save-line" /></el-icon>
                  保存当前配置
                </el-button>
              </el-form-item>
            </el-form>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 新增配置项弹窗 -->
    <el-dialog
      v-model="addDialogVisible"
      title="新增系统配置项"
      width="560px"
      destroy-on-close
      @closed="resetAddItemForm"
    >
      <el-form
        ref="addItemFormRef"
        :model="addItemForm"
        :rules="addItemRules"
        label-width="100px"
      >
        <el-form-item label="所属分组" prop="group">
          <el-select v-model="addItemForm.group" style="width: 100%">
            <el-option
              v-for="g in groupList"
              :key="g.key"
              :label="g.title"
              :value="g.key"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="变量标识" prop="name">
          <el-input v-model="addItemForm.name" placeholder="英文小写字母与下划线，例如 site_name" />
        </el-form-item>

        <el-form-item label="配置标题" prop="title">
          <el-input v-model="addItemForm.title" placeholder="例如：站点名称" />
        </el-form-item>

        <el-form-item label="控件类型" prop="type">
          <el-select v-model="addItemForm.type" style="width: 100%">
            <el-option label="单行文本 (string)" value="string" />
            <el-option label="多行文本 (text)" value="text" />
            <el-option label="数字数值 (number)" value="number" />
            <el-option label="开关状态 (switch)" value="switch" />
            <el-option label="下拉选择 (select)" value="select" />
            <el-option label="单张图片 (image)" value="image" />
          </el-select>
        </el-form-item>

        <el-form-item v-if="addItemForm.type === 'select'" label="选项内容" prop="content">
          <el-input
            v-model="addItemForm.content"
            type="textarea"
            :rows="2"
            placeholder="键值对，例如: 1=开启,0=关闭"
          />
        </el-form-item>

        <el-form-item label="默认配置值" prop="value">
          <el-input v-model="addItemForm.value" placeholder="配置初始默认值" />
        </el-form-item>

        <el-form-item label="提示说明" prop="tip">
          <el-input v-model="addItemForm.tip" placeholder="配置项下方辅助提示文字" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="addDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="addLoading" @click="handleSubmitAddItem">立即创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { openExternalUrl, serverOrigin } from '/@/utils/platform'
import { ref, reactive, computed, onMounted } from 'vue'
import DeploymentPanel from './DeploymentPanel.vue'
import { useAclStore } from '/@/store/modules/acl'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { getConfigList, saveConfigValues, addConfigItem, delConfigItem, uploadAttachment } from '/@/api/hwadmin'
import { useSettingsStore } from '/@/store/modules/settings'
import getPageTitle from '/@/utils/pageTitle'
import { defaultBrandImages, resolveConfiguredAsset } from '/@/utils/branding'

defineOptions({
  name: 'SystemConfig',
})

const route = useRoute()
const deploymentVisible = ref(false)
const aclStore = useAclStore()
const router = useRouter()
const settingsStore = useSettingsStore()

const loading = ref(false)
const saveLoading = ref(false)
const addLoading = ref(false)
const addDialogVisible = ref(false)
const activeGroup = ref('basic')

const groupList = [
  { key: 'basic', title: '基础配置 (Basic)' },
  { key: 'security', title: '安全配置 (Security)' },
  { key: 'upload', title: '上传设置 (Upload)' },
  { key: 'user', title: '会员配置 (User)' },
]

const originHost = typeof window !== 'undefined' ? serverOrigin : ''
const previousAdminEntry = ref('')

const previewAdminEntry = (entry: string) => {
  const path = entry ? `/${entry.replace(/^\/+|\/+$/g, '')}/` : '/admin/'
  void openExternalUrl(path)
}

const rawItems = ref<any[]>([])
const formValues = reactive<Record<string, any>>({})

const resolveUrl = (url: string) => {
  return resolveConfiguredAsset(url)
}

const handleUploadImage = async (options: any, fieldName: string) => {
  const { file } = options
  try {
    const res = await uploadAttachment(file)
    const url = res?.data?.url || res?.data?.fullurl
    if (url) {
      formValues[fieldName] = url
      ElMessage.success('图片上传成功')
    } else {
      ElMessage.error('上传返回数据异常')
    }
  } catch (err: any) {
    ElMessage.error(err?.msg || err?.message || '图片上传失败')
  }
}

const addItemFormRef = ref<FormInstance>()
const addItemForm = reactive({
  group: 'basic',
  name: '',
  title: '',
  type: 'string',
  value: '',
  content: '',
  tip: '',
})

const addItemRules = reactive<FormRules>({
  name: [{ required: true, message: '请输入变量标识', trigger: 'blur' }],
  title: [{ required: true, message: '请输入配置标题', trigger: 'blur' }],
  type: [{ required: true, message: '请选择控件类型', trigger: 'change' }],
})

const currentGroupItems = computed(() => {
  return rawItems.value.filter((item) => (item.group || 'basic') === activeGroup.value)
})

const parseOptions = (raw: any): Array<{ value: string; label: string }> => {
  if (!raw) return []
  if (Array.isArray(raw)) {
    return raw.map((item: any) => {
      if (item && typeof item === 'object') {
        const val = String(item.value ?? item.key ?? '')
        const lbl = String(item.label ?? item.title ?? val)
        return { value: val, label: lbl }
      }
      return { value: String(item), label: String(item) }
    })
  }
  if (typeof raw === 'object') {
    return Object.entries(raw).map(([key, label]) => ({
      value: String(key),
      label: String(label),
    }))
  }
  if (typeof raw === 'string') {
    const trimmed = raw.trim()
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      try {
        const parsed = JSON.parse(trimmed)
        return parseOptions(parsed)
      } catch {
        // 继续降级按换行解析
      }
    }
    const res: Array<{ value: string; label: string }> = []
    const lines = trimmed.split(/[\r\n,]+/)
    for (const line of lines) {
      const parts = line.split('=')
      if (parts.length >= 2) {
        res.push({
          value: parts[0].trim(),
          label: parts[1].trim(),
        })
      }
    }
    return res
  }
  return []
}

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await getConfigList()
    const items = res?.data?.list || res?.data || []
    if (Array.isArray(items) && items.length) {
      rawItems.value = items
      items.forEach((item: any) => {
        let val: any = item.value
        if (item.type === 'switch') {
          val = Number(val) || 0
        } else if (item.type === 'number') {
          val = Number(val) || 0
        }
        formValues[item.name] = val
      })
      previousAdminEntry.value = String(formValues['admin_entry'] || 'admin')
    } else {
      // 优雅降级默认初始配置项
      initMockConfigs()
    }
  } catch (e) {
    console.warn('获取配置失败，加载初始预设', e)
    initMockConfigs()
  } finally {
    loading.value = false
  }
}

const initMockConfigs = () => {
  rawItems.value = [
    { id: 1, group: 'basic', name: 'site_name', title: '站点名称', type: 'string', value: 'HwAdmin 现代化智能框架', tip: '后台与系统的对外统一名称' },
    { id: 2, group: 'basic', name: 'site_logo', title: '站点 Logo', type: 'image', value: '', tip: '支持图片上传或输入图片 URL' },
    { id: 3, group: 'basic', name: 'site_favicon', title: '浏览器图标 (Favicon)', type: 'image', value: '', tip: '展示于浏览器标签页的 Favicon 图标' },
    { id: 4, group: 'basic', name: 'login_image', title: '登录页插图', type: 'image', value: '', tip: '展示于后台登录、找回密码与注册页面左侧的主插图' },
    { id: 5, group: 'basic', name: 'default_avatar', title: '默认头像', type: 'image', value: '', tip: '管理员或会员未设置个性头像时的全局默认头像' },
    { id: 6, group: 'basic', name: 'home_mode', title: 'PC前端首页模式', type: 'select', value: 'portal', options: '{"portal":"系统默认门户模板","cms":"CMS内容管理门户(插件)","custom_plugin":"自定义插件接管","redirect_admin":"自动跳转管理后台"}', tip: '设置用户访问根域名(/)时的展示策略，选择门户或插件接管可自动隐藏后台真实入口' },
    { id: 7, group: 'basic', name: 'home_plugin', title: '接管首页的插件标识', type: 'string', value: 'cms', tip: '当首页模式为插件接管时生效，如 cms（对应插件前端或门户模板），也可填写自定义模板路径标识' },
    { id: 8, group: 'security', name: 'admin_entry', title: '管理后台安全入口路径', type: 'string', value: 'admin', tip: '默认通过 /admin 访问后台；修改为其他英文数字路径（如 myconsole）后，访问原 /admin 将被拦截隐藏，大幅提升安全性' },
    { id: 9, group: 'security', name: 'login_captcha', title: '后台登录验证码开关', type: 'switch', value: 1, tip: '开启后管理后台登录必须通过图形验证码安全校验，防止暴力破解' },
    { id: 10, group: 'basic', name: 'beian', title: '备案号', type: 'string', value: '', tip: '工信部 ICP 备案号' },
    { id: 11, group: 'basic', name: 'site_copyright', title: '底部版权信息', type: 'string', value: 'Copyright © 2026 HwAdmin. All rights reserved.', tip: '展示于管理后台底部 Footer 区域' },
    { id: 12, group: 'upload', name: 'upload_storage', title: '存储方式', type: 'select', value: 'local', content: 'local=本地', tip: '对象存储由插件扩展' },
    { id: 13, group: 'upload', name: 'upload_allow_ext', title: '允许上传格式', type: 'text', value: 'jpg,jpeg,png,gif,webp,bmp,ico,mp4,webm,mov,m4v,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv,md', tip: '常用扩展名白名单，半角逗号分隔' },
    { id: 14, group: 'upload', name: 'upload_max_size', title: '单文件最大限制 (MB)', type: 'number', value: 50, tip: '单位：MB，如 50 代表最大 50MB' },
    { id: 15, group: 'user', name: 'user_register', title: '开放会员注册', type: 'switch', value: 1, tip: '关闭后前台将隐藏注册通道' },
  ]
  rawItems.value.forEach((item) => {
    formValues[item.name] = item.value
  })
  previousAdminEntry.value = String(formValues['admin_entry'] || 'admin')
}

const handleSaveConfig = async () => {
  saveLoading.value = true
  try {
    await saveConfigValues(formValues)
    ElMessage.success('配置已成功保存更新')

    const newAdminEntry = String(formValues['admin_entry'] || 'admin')
    if (previousAdminEntry.value && previousAdminEntry.value !== newAdminEntry) {
      ElMessageBox.alert(
        `管理后台安全入口路径已修改为：/${newAdminEntry}/。\n访问原 /admin 将被拦截隐藏返回 404。请收藏并记住新入口地址！`,
        '后台安全入口已更新',
        {
          confirmButtonText: '确定并前往新入口',
          cancelButtonText: '我知道了',
          showCancelButton: true,
          type: 'warning',
        }
      ).then(() => {
        window.location.href = `/${newAdminEntry}/`
      }).catch(() => {})
      previousAdminEntry.value = newAdminEntry
    }

    if (activeGroup.value === 'basic' || activeGroup.value === 'security') {
      if (formValues['site_name']) {
        settingsStore.updateTitle(formValues['site_name'])
      }
      if (formValues['site_logo'] !== undefined) {
        settingsStore.updateLogo(formValues['site_logo'])
      }
      if (formValues['site_copyright']) {
        settingsStore.updateCopyright(formValues['site_copyright'])
      }
      if (formValues['beian'] !== undefined) {
        settingsStore.updateBeian(formValues['beian'])
      }
      if (formValues['site_favicon'] !== undefined) {
        settingsStore.updateFavicon(formValues['site_favicon'])
      }
      if (formValues['login_image'] !== undefined) {
        settingsStore.updateLoginImage(formValues['login_image'])
      }
      if (formValues['default_avatar'] !== undefined) {
        settingsStore.updateDefaultAvatar(formValues['default_avatar'])
      }
      if (route.meta && route.meta.title) {
        document.title = getPageTitle(route.meta.title as string)
      }
    }
  } catch (e) {
    console.error(e)
  } finally {
    saveLoading.value = false
  }
}

const handleAddConfigItem = () => {
  addItemForm.group = activeGroup.value
  addDialogVisible.value = true
}

const resetAddItemForm = () => {
  addItemForm.name = ''
  addItemForm.title = ''
  addItemForm.type = 'string'
  addItemForm.value = ''
  addItemForm.content = ''
  addItemForm.tip = ''
  if (addItemFormRef.value) addItemFormRef.value.clearValidate()
}

const handleSubmitAddItem = async () => {
  if (!addItemFormRef.value) return
  await addItemFormRef.value.validate(async (valid) => {
    if (!valid) return
    addLoading.value = true
    try {
      await addConfigItem({ ...addItemForm })
      ElMessage.success('配置项添加成功')
      addDialogVisible.value = false
      fetchConfig()
    } catch (e) {
      console.error(e)
    } finally {
      addLoading.value = false
    }
  })
}

const handleDeleteItem = (item: any) => {
  ElMessageBox.confirm(`确定要删除配置项 "${item.title || item.name}" 吗？`, '安全提醒', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await delConfigItem(item.id || item.name)
      ElMessage.success('配置项已删除')
      fetchConfig()
    } catch (e) {
      console.error(e)
    }
  })
}

onMounted(() => {
  fetchConfig()
})
</script>

<style lang="scss" scoped>
.system-config-container {
  padding: 20px;

  .config-card {
    border-radius: 8px;

    .card-header {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px 14px;

      .header-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 16px;
        font-weight: 600;
      }

      .header-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px 10px;
      }
    }

    .config-tabs {
      margin-top: 10px;
    }

    .config-dynamic-form {
      padding: 20px 0;

      .config-form-row {
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 1px dashed var(--el-border-color-lighter);

        &:last-of-type {
          border-bottom: none;
        }

        .config-tip {
          font-size: 12px;
          color: var(--el-text-color-secondary);
          margin-top: 4px;
        }

        .config-name-tag {
          margin-top: 2px;
          code {
            padding: 1px 5px;
            background: var(--el-fill-color-light);
            border-radius: 3px;
            font-size: 11px;
            color: var(--el-color-info);
          }
        }

        .btn-del-item {
          margin-left: 14px;
        }

        .image-uploader-box {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 8px;

          .image-preview-wrap {
            display: flex;
            align-items: center;
          }

          .image-preview-label {
            margin-left: 6px;
            font-size: 12px;
            color: var(--el-text-color-secondary);
          }

          .image-error-slot {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            background: var(--el-fill-color-light);
            color: var(--el-text-color-secondary);
            font-size: 16px;
          }
        }
      }

      .security-status-card {
        .sec-stat-item {
          padding: 8px 4px;

          .sec-stat-label {
            font-size: 13px;
            color: var(--el-text-color-secondary);
            margin-bottom: 8px;
          }

          .sec-stat-val {
            margin-bottom: 6px;
          }

          .sec-stat-tip {
            font-size: 12px;
            color: var(--el-text-color-placeholder);
            line-height: 1.4;
          }
        }
      }

      .form-submit-row {
        margin-top: 30px;
        padding-top: 20px;
      }
    }
  }

  @media (max-width: 768px) {
    padding: 12px;

    .config-card .tab-content .config-dynamic-form {
      padding: 12px 0;

      :deep(.el-form-item) {
        flex-direction: column;
        align-items: stretch;

        .el-form-item__label {
          width: 100% !important;
          justify-content: flex-start;
          text-align: left;
          margin-bottom: 6px;
        }
      }
    }
  }
}
</style>
