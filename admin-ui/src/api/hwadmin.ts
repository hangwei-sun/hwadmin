import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const API_BASE = projectConfig.apiBase || '/adminapi'

// ==================== 控制台 ====================
export const getDashboard = () => {
  return request({
    url: `${API_BASE}/dashboard/index`,
    method: 'get',
  })
}

export const getNotices = () => {
  return request({
    url: `${API_BASE}/dashboard/notices`,
    method: 'get',
  })
}

export const readNotice = (data?: { id?: string; clear_all?: boolean }) => {
  return request({
    url: `${API_BASE}/dashboard/readNotice`,
    method: 'post',
    data,
  })
}

export const clearCache = (type: 'all' | 'data' | 'temp' | 'plugin' = 'all') => {
  return request({
    url: `${API_BASE}/dashboard/clearCache`,
    method: 'post',
    data: { type },
  })
}

// ==================== 管理员管理 ====================
export const getAdminList = (params?: any) => {
  return request({
    url: `${API_BASE}/auth.admin/index`,
    method: 'get',
    params,
  })
}

export const addAdmin = (data: any) => {
  return request({
    url: `${API_BASE}/auth.admin/add`,
    method: 'post',
    data,
  })
}

export const editAdmin = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/auth.admin/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delAdmin = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/auth.admin/del`,
    method: 'post',
    data: { ids },
  })
}

export const getProfile = () => {
  return request({
    url: `${API_BASE}/auth.admin/profile`,
    method: 'get',
  })
}

export const saveProfile = (data: any) => {
  return request({
    url: `${API_BASE}/auth.admin/profile`,
    method: 'post',
    data,
  })
}

export const unlockScreen = (password: string) => {
  return request({
    url: `${API_BASE}/auth.admin/unlock`,
    method: 'post',
    data: { password },
  })
}

export const setLockPassword = (data: { current_password: string; lock_password?: string; reset_lock_password?: boolean }) => {
  return request({
    url: `${API_BASE}/auth.admin/profile`,
    method: 'post',
    data,
  })
}

// ==================== 角色组管理 ====================
export const getGroupList = (params?: any) => {
  return request({
    url: `${API_BASE}/auth.group/index`,
    method: 'get',
    params,
  })
}

export const addGroup = (data: any) => {
  return request({
    url: `${API_BASE}/auth.group/add`,
    method: 'post',
    data,
  })
}

export const editGroup = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/auth.group/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delGroup = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/auth.group/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 菜单规则管理 ====================
export const getRuleList = (params?: any) => {
  return request({
    url: `${API_BASE}/auth.rule/index`,
    method: 'get',
    params,
  })
}

export const addRule = (data: any) => {
  return request({
    url: `${API_BASE}/auth.rule/add`,
    method: 'post',
    data,
  })
}

export const editRule = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/auth.rule/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delRule = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/auth.rule/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 操作日志管理 ====================
export const getAdminLogList = (params?: any) => {
  return request({
    url: `${API_BASE}/auth.admin_log/index`,
    method: 'get',
    params,
  })
}

export const delAdminLog = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/auth.admin_log/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 会员与会员组 ====================
export const getUserList = (params?: any) => {
  return request({
    url: `${API_BASE}/user.user/index`,
    method: 'get',
    params,
  })
}

export const addUser = (data: any) => {
  return request({
    url: `${API_BASE}/user.user/add`,
    method: 'post',
    data,
  })
}

export const editUser = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/user.user/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delUser = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/user.user/del`,
    method: 'post',
    data: { ids },
  })
}

export const getUserGroupList = (params?: any) => {
  return request({
    url: `${API_BASE}/user.group/index`,
    method: 'get',
    params,
  })
}

export const addUserGroup = (data: any) => {
  return request({
    url: `${API_BASE}/user.group/add`,
    method: 'post',
    data,
  })
}

export const editUserGroup = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/user.group/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delUserGroup = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/user.group/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 系统配置 ====================
export const getConfigList = (params?: any) => {
  return request({
    url: `${API_BASE}/system.config/index`,
    method: 'get',
    params,
  })
}

export const saveConfigValues = (values: Record<string, any>) => {
  return request({
    url: `${API_BASE}/system.config/save`,
    method: 'post',
    data: { values },
  })
}

export const addConfigItem = (data: any) => {
  return request({
    url: `${API_BASE}/system.config/add`,
    method: 'post',
    data,
  })
}

export const delConfigItem = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/system.config/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 附件管理 ====================
export const getAttachmentList = (params?: any) => {
  return request({
    url: `${API_BASE}/system.attachment/index`,
    method: 'get',
    params,
  })
}

export const getPublicConfig = () => {
  return request({
    url: `${API_BASE}/index/config`,
    method: 'get',
  })
}

export const uploadAttachment = (file: File | FormData) => {
  const data = file instanceof FormData ? file : (() => {
    const fd = new FormData()
    fd.append('file', file)
    return fd
  })()
  return request({
    url: `${API_BASE}/system.attachment/upload`,
    method: 'post',
    data,
  })
}

export const delAttachment = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/system.attachment/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 插件管理 ====================
export const getPluginList = () => {
  return request({
    url: `${API_BASE}/system.plugin/index`,
    method: 'get',
  })
}

export const installPlugin = (name: string, enable = true) => {
  return request({
    url: `${API_BASE}/system.plugin/install`,
    method: 'post',
    data: { name, enable },
  })
}

export const uninstallPlugin = (name: string) => {
  return request({
    url: `${API_BASE}/system.plugin/uninstall`,
    method: 'post',
    data: { name },
  })
}

export const enablePlugin = (name: string) => {
  return request({
    url: `${API_BASE}/system.plugin/enable`,
    method: 'post',
    data: { name },
  })
}

export const disablePlugin = (name: string) => {
  return request({
    url: `${API_BASE}/system.plugin/disable`,
    method: 'post',
    data: { name },
  })
}

export const upgradePlugin = (name: string) => {
  return request({
    url: `${API_BASE}/system.plugin/upgrade`,
    method: 'post',
    data: { name },
  })
}

export const getPluginConfig = (name: string) => {
  return request({
    url: `${API_BASE}/system.plugin/config`,
    method: 'get',
    params: { name },
  })
}

export const savePluginConfig = (name: string, config: Record<string, any>) => {
  return request({
    url: `${API_BASE}/system.plugin/config`,
    method: 'post',
    data: { name, config },
  })
}

export const getPluginMarketList = (params?: any) => {
  return request({
    url: `${API_BASE}/system.plugin/market`,
    method: 'get',
    params,
  })
}

export const downloadMarketPlugin = (
  nameOrPayload: string | { name: string; license_key?: string; action_type?: string },
  license_key?: string
) => {
  const data = typeof nameOrPayload === 'string'
    ? { name: nameOrPayload, license_key }
    : nameOrPayload
  return request({
    url: `${API_BASE}/system.plugin/downloadMarket`,
    method: 'post',
    data,
  })
}

export const saveMarketUrl = (market_server_url: string) => {
  return request({
    url: `${API_BASE}/system.plugin/saveMarketUrl`,
    method: 'post',
    data: { market_server_url },
  })
}

// ==================== 官方插件市场通行证 & 商业授权接口 ====================
export const getMarketAccount = () => {
  return request({
    url: `${API_BASE}/system.plugin/marketAccount`,
    method: 'get',
  })
}

export const loginMarketAccount = (data: { username: string; password?: string; site_domain?: string }) => {
  return request({
    url: `${API_BASE}/system.plugin/loginMarketAccount`,
    method: 'post',
    data,
  })
}

export const syncMarketLicenses = () => {
  return request({
    url: `${API_BASE}/system.plugin/syncMarketLicenses`,
    method: 'post',
  })
}

export const claimMarketLicense = (data: { plugin_name: string; client_domain?: string }) => {
  return request({
    url: `${API_BASE}/system.plugin/claimLicense`,
    method: 'post',
    data,
  })
}

export const logoutMarketAccount = () => {
  return request({
    url: `${API_BASE}/system.plugin/logoutMarketAccount`,
    method: 'post',
  })
}

// ==================== CMS 插件后台接口 ====================
export const getCmsSetting = () => {
  return request({
    url: `${API_BASE}/plugin/cms/setting/index`,
    method: 'get',
  })
}

export const saveCmsSetting = (data: { active_template?: string; set_as_home?: boolean | number; config?: Record<string, any> }) => {
  return request({
    url: `${API_BASE}/plugin/cms/setting/save`,
    method: 'post',
    data,
  })
}

export const saveCmsBanner = (data: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/setting/saveBanner`,
    method: 'post',
    data,
  })
}

export const delCmsBanner = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/plugin/cms/setting/delBanner`,
    method: 'delete',
    data: { ids },
    params: { ids },
  })
}

export const getCmsCategoryList = (params?: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/category/index`,
    method: 'get',
    params,
  })
}

export const addCmsCategory = (data: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/category/add`,
    method: 'post',
    data,
  })
}

export const editCmsCategory = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/category/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delCmsCategory = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/plugin/cms/category/del`,
    method: 'post',
    data: { ids },
  })
}

export const getCmsArticleList = (params?: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/article/index`,
    method: 'get',
    params,
  })
}

export const addCmsArticle = (data: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/article/add`,
    method: 'post',
    data,
  })
}

export const editCmsArticle = (id: number | string, data: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/article/edit?id=${id}`,
    method: 'post',
    data: { id, ...data },
  })
}

export const delCmsArticle = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/plugin/cms/article/del`,
    method: 'post',
    data: { ids },
  })
}

export const getCmsInquiryList = (params?: any) => {
  return request({
    url: `${API_BASE}/plugin/cms/inquiry/index`,
    method: 'get',
    params,
  })
}

export const replyCmsInquiry = (data: { id: number | string; reply_content: string; status?: number }) => {
  return request({
    url: `${API_BASE}/plugin/cms/inquiry/reply`,
    method: 'post',
    data,
  })
}

export const delCmsInquiry = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/plugin/cms/inquiry/del`,
    method: 'delete',
    data: { ids },
    params: { ids },
  })
}

// ==================== 富文本编辑器插件后台接口 ====================
export const getEditorDrafts = () => {
  return request({
    url: `${API_BASE}/plugin/editor/index/drafts`,
    method: 'get',
  })
}

export const saveEditorDraft = (data: { id?: number; title: string; content: string; word_count?: number }) => {
  return request({
    url: `${API_BASE}/plugin/editor/index/saveDraft`,
    method: 'post',
    data,
  })
}

export const deleteEditorDraft = (id: number) => {
  return request({
    url: `${API_BASE}/plugin/editor/index/deleteDraft`,
    method: 'post',
    data: { id },
  })
}

// ==================== 腾讯云短信插件后台接口 ====================
export const getSmsStatus = () => {
  return request({
    url: `${API_BASE}/plugin/sms/send/index`,
    method: 'get',
  })
}

export const sendSmsTest = (data: {
  mobile: string
  event?: string
  template_id?: string
  sign_name?: string
  params?: any
}) => {
  return request({
    url: `${API_BASE}/plugin/sms/send/send`,
    method: 'post',
    data,
  })
}

export const getSmsLogList = (params?: any) => {
  return request({
    url: `${API_BASE}/plugin/sms/log/index`,
    method: 'get',
    params,
  })
}

export const delSmsLog = (ids: (number | string)[] | string | number) => {
  return request({
    url: `${API_BASE}/plugin/sms/log/del`,
    method: 'post',
    data: { ids },
  })
}

// ==================== 框架与插件在线更新 ====================
export interface SystemBackupRecord {
  id: string
  type: 'framework' | 'plugin' | string
  name: string
  version: string
  created_at: string
  size: string
  path?: string
  has_frontend_backup?: boolean
}

export const checkSystemUpdates = () => {
  return request({
    url: `${API_BASE}/system.plugin/checkUpdates`,
    method: 'get',
  })
}

export const setSystemUpdateChannel = (data: { channel: 'stable' | 'beta' }) => {
  return request({
    url: `${API_BASE}/system.plugin/setUpdateChannel`,
    method: 'post',
    data,
  })
}

export const updateSystemFramework = (data?: {
  download_url?: string
  patch_sha256?: string
  preserve_frontend?: boolean
}) => {
  return request({
    url: `${API_BASE}/system.plugin/updateFramework`,
    method: 'post',
    data,
  })
}

export const updatePluginPackage = (data: { name: string }) => {
  return request({
    url: `${API_BASE}/system.plugin/updatePlugin`,
    method: 'post',
    data,
  })
}

export const rollbackSystemUpdate = (data: { backup_id: string }) => {
  return request({
    url: `${API_BASE}/system.plugin/rollbackUpdate`,
    method: 'post',
    data,
  })
}

export const getSystemBackups = () => {
  return request({
    url: `${API_BASE}/system.plugin/getBackups`,
    method: 'get',
  })
}

