<template>
  <div class="personal-center-container no-background-container auto-height-container">
    <el-row :gutter="20">
      <el-col :lg="8" :md="12" :sm="24" :xl="8" :xs="24">
        <vab-card class="auto-height-card">
          <el-scrollbar>
            <div class="user-info">
              <el-upload
                accept="image/*"
                action=""
                class="avatar-uploader"
                :http-request="handleAvatarUpload"
                :show-file-list="false"
              >
                <div class="avatar-box">
                  <el-avatar :size="104" :src="normalizeAvatar(userInfo.avatar) || settingsStore.defaultAvatar || defaultAvatar" />
                  <div class="avatar-hover-mask">
                    <vab-icon icon="camera-line" />
                    <span>更换头像</span>
                  </div>
                  <div class="avatar-camera-badge" title="点击更换头像">
                    <vab-icon icon="camera-fill" />
                  </div>
                </div>
                <div class="avatar-uploader-tip">点击更换头像</div>
              </el-upload>
              <div class="user-info-full-name">
                {{ userInfo.nickname || userInfo.username || '管理员' }}
              </div>
              <div class="user-info-description">
                <el-tag effect="plain" type="primary">{{ userInfo.username }}</el-tag>
              </div>

              <ul class="user-info-list">
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="user-3-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">用户名</span>
                    <span class="item-value">{{ userInfo.username }}</span>
                  </div>
                </li>
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="mail-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">邮箱</span>
                    <span class="item-value">{{ userInfo.email || '未绑定' }}</span>
                  </div>
                </li>
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="smartphone-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">手机号</span>
                    <span class="item-value">{{ userInfo.mobile || '未绑定' }}</span>
                  </div>
                </li>
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="time-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">最后登录</span>
                    <span class="item-value">{{ userInfo.login_time || userInfo.logintime || '暂无记录' }}</span>
                  </div>
                </li>
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="map-pin-2-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">登录IP</span>
                    <span class="item-value">{{ userInfo.login_ip || userInfo.loginip || '127.0.0.1' }}</span>
                  </div>
                </li>
                <li class="info-item">
                  <div class="item-icon">
                    <vab-icon icon="calendar-line" />
                  </div>
                  <div class="item-content">
                    <span class="item-label">创建时间</span>
                    <span class="item-value">{{ userInfo.create_time || userInfo.createtime || '暂无记录' }}</span>
                  </div>
                </li>
              </ul>
            </div>
          </el-scrollbar>
        </vab-card>
      </el-col>
      <el-col :lg="16" :md="12" :sm="24" :xl="16" :xs="24">
        <vab-card class="auto-height-card">
          <el-tabs v-model="activeName">
            <el-tab-pane label="基本资料" name="profile">
              <el-form ref="profileFormRef" label-width="100px" :model="profileForm" :rules="profileRules" style="max-width: 500px; padding-top: 20px;">
                <el-form-item label="用户名">
                  <el-input disabled :value="userInfo.username" />
                </el-form-item>
                <el-form-item label="昵称" prop="nickname">
                  <el-input v-model.trim="profileForm.nickname" placeholder="请输入管理员昵称" />
                </el-form-item>
                <el-form-item label="电子邮箱" prop="email">
                  <el-input v-model.trim="profileForm.email" placeholder="请输入电子邮箱" />
                </el-form-item>
                <el-form-item label="手机号码" prop="mobile">
                  <el-input v-model.trim="profileForm.mobile" placeholder="请输入手机号码" />
                </el-form-item>
                <el-form-item>
                  <el-button :loading="savingProfile" type="primary" @click="handleSaveProfile">
                    保存资料
                  </el-button>
                </el-form-item>
              </el-form>
            </el-tab-pane>

            <el-tab-pane label="修改密码" name="password">
              <el-form ref="passwordFormRef" label-width="100px" :model="passwordForm" :rules="passwordRules" style="max-width: 500px; padding-top: 20px;">
                <el-form-item label="当前密码" prop="old_password">
                  <el-input
                    v-model.trim="passwordForm.old_password"
                    placeholder="请输入当前密码"
                    show-password
                    type="password"
                  />
                </el-form-item>
                <el-form-item label="新密码" prop="password">
                  <el-input
                    v-model.trim="passwordForm.password"
                    placeholder="请输入新密码（不少于8位）"
                    show-password
                    type="password"
                  />
                </el-form-item>
                <el-form-item label="确认新密码" prop="confirm_password">
                  <el-input
                    v-model.trim="passwordForm.confirm_password"
                    placeholder="请再次输入新密码"
                    show-password
                    type="password"
                  />
                </el-form-item>
                <el-form-item>
                  <el-button :loading="savingPassword" type="primary" @click="handleSavePassword">
                    修改密码
                  </el-button>
                </el-form-item>
              </el-form>
            </el-tab-pane>

            <el-tab-pane label="锁屏密码设置" name="lockPassword">
              <div style="max-width: 600px; padding-top: 20px;">
                <el-alert
                  show-icon
                  :closable="false"
                  style="margin-bottom: 20px;"
                  title="锁屏解锁说明与便捷性提示"
                  type="info"
                >
                  <template #default>
                    <div style="margin-top: 6px; line-height: 1.6; font-size: 13px;">
                      <div>1. <b>默认便捷解锁</b>：未设置个性锁屏密码时，系统默认使用您的<b>管理员后台登录密码</b>进行解锁，简单高效无额外记忆负担。</div>
                      <div>2. <b>个性锁屏密码</b>：若您需要更快捷的解锁体验（例如简短密码）或更高安全私密性，可在下方设置专属解锁密码。</div>
                      <div>3. <b>解锁优先级</b>：若已设置个性锁屏密码，系统将优先以个性密码解锁；若遗忘个性密码，您依然可以使用本人后台登录密码直接解锁。</div>
                    </div>
                  </template>
                </el-alert>

                <div class="lock-status-card" style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: var(--el-fill-color-light); border-radius: 8px; border: 1px solid var(--el-border-color-lighter);">
                  <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-weight: 500; font-size: 14px; color: var(--el-text-color-primary);">当前解锁密码状态：</span>
                    <el-tag v-if="hasCustomLockPassword" effect="light" type="success">
                      <vab-icon icon="shield-check-line" style="margin-right: 4px;" /> 已设置个性锁屏密码
                    </el-tag>
                    <el-tag v-else effect="light" type="info">
                      <vab-icon icon="key-2-line" style="margin-right: 4px;" /> 默认使用后台登录密码
                    </el-tag>
                  </div>
                  <el-button
                    v-if="hasCustomLockPassword"
                    link
                    size="small"
                    type="warning"
                    @click="handleResetLockPassword"
                  >
                    恢复默认（使用后台登录密码）
                  </el-button>
                </div>

                <el-form
                  ref="lockFormRef"
                  label-width="120px"
                  :model="lockForm"
                  :rules="lockRules"
                >
                  <el-form-item label="当前登录密码" prop="current_password">
                    <el-input
                      v-model.trim="lockForm.current_password"
                      placeholder="请输入当前后台登录密码以验证身份"
                      show-password
                      type="password"
                    />
                  </el-form-item>
                  <el-form-item label="新锁屏密码" prop="lock_password">
                    <el-input
                      v-model.trim="lockForm.lock_password"
                      placeholder="请输入个性锁屏密码（不少于4位）"
                      show-password
                      type="password"
                    />
                  </el-form-item>
                  <el-form-item label="确认锁屏密码" prop="confirm_lock_password">
                    <el-input
                      v-model.trim="lockForm.confirm_lock_password"
                      placeholder="请再次输入新锁屏密码"
                      show-password
                      type="password"
                    />
                  </el-form-item>
                  <el-form-item>
                    <el-button :loading="savingLockPassword" type="primary" @click="handleSaveLockPassword">
                      保存锁屏密码
                    </el-button>
                    <el-button
                      v-if="hasCustomLockPassword"
                      type="default"
                      @click="handleResetLockPassword"
                    >
                      恢复默认
                    </el-button>
                  </el-form-item>
                </el-form>
              </div>
            </el-tab-pane>
          </el-tabs>
        </vab-card>
      </el-col>
    </el-row>
  </div>
</template>

<script lang="ts" setup>
import { ElMessageBox, type FormInstance, type FormRules, type UploadRequestOptions } from 'element-plus'
import { defaultAvatar, normalizeAvatar } from '/@/utils/branding'
import { getProfile, saveProfile, uploadAttachment } from '/@/api/hwadmin'
import { useUserStore } from '/@/store/modules/user'
import { useSettingsStore } from '/@/store/modules/settings'

defineOptions({
  name: 'PersonalCenter',
})

const userStore = useUserStore()
const settingsStore = useSettingsStore()
const router = useRouter()
const activeName = ref('profile')
const savingProfile = ref(false)
const savingPassword = ref(false)
const savingLockPassword = ref(false)
const hasCustomLockPassword = ref(false)

const profileFormRef = ref<FormInstance>()
const passwordFormRef = ref<FormInstance>()
const lockFormRef = ref<FormInstance>()

const userInfo = ref<Record<string, any>>({
  username: '',
  nickname: '',
  email: '',
  mobile: '',
  avatar: '',
  login_time: '',
  login_ip: '',
  create_time: '',
})

const profileForm = reactive({
  nickname: '',
  email: '',
  mobile: '',
  avatar: '',
})

const passwordForm = reactive({
  old_password: '',
  password: '',
  confirm_password: '',
})

const validateConfirmPass = (rule: any, value: string, callback: any) => {
  if (!value) {
    callback(new Error('请再次输入新密码'))
  } else if (value !== passwordForm.password) {
    callback(new Error('两次输入密码不一致'))
  } else {
    callback()
  }
}

const profileRules = reactive<FormRules>({
  nickname: [{ required: true, message: '请输入昵称', trigger: 'blur' }],
  email: [{ type: 'email', message: '请输入正确的邮箱地址', trigger: 'blur' }],
})

const passwordRules = reactive<FormRules>({
  old_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '新密码不能少于8位', trigger: 'blur' },
  ],
  confirm_password: [{ required: true, validator: validateConfirmPass, trigger: 'blur' }],
})

const lockForm = reactive({
  current_password: '',
  lock_password: '',
  confirm_lock_password: '',
})

const validateConfirmLockPass = (rule: any, value: string, callback: any) => {
  if (!value) {
    callback(new Error('请再次输入新锁屏密码'))
  } else if (value !== lockForm.lock_password) {
    callback(new Error('两次输入的锁屏密码不一致'))
  } else {
    callback()
  }
}

const lockRules = reactive<FormRules>({
  current_password: [{ required: true, message: '请输入当前后台登录密码以验证身份', trigger: 'blur' }],
  lock_password: [
    { required: true, message: '请输入个性锁屏密码', trigger: 'blur' },
    { min: 4, message: '锁屏密码不能少于4位', trigger: 'blur' },
  ],
  confirm_lock_password: [{ required: true, validator: validateConfirmLockPass, trigger: 'blur' }],
})

const fetchProfile = async () => {
  try {
    const res: any = await getProfile()
    const data = res.data || res
    userInfo.value = { ...data, avatar: normalizeAvatar(data.avatar) }
    hasCustomLockPassword.value = Boolean(data.has_custom_lock_password)
    profileForm.nickname = data.nickname || ''
    profileForm.email = data.email || ''
    profileForm.mobile = data.mobile || ''
    profileForm.avatar = normalizeAvatar(data.avatar)
    if (data.avatar) {
      userStore.setAvatar(normalizeAvatar(data.avatar))
    }
  } catch (err) {
    console.error('获取个人资料失败', err)
  }
}

const handleAvatarUpload = async (options: UploadRequestOptions) => {
  try {
    const res: any = await uploadAttachment(options.file)
    const url = res?.data?.url || res?.url
    if (url) {
      profileForm.avatar = url
      userInfo.value.avatar = url
      userStore.setAvatar(url)
      await saveProfile({ avatar: url })
      $baseMessage('头像上传成功', 'success', 'hey')
    }
  } catch (err: any) {
    console.error('头像上传失败', err)
    $baseMessage(err?.msg || err?.message || '头像上传失败', 'error', 'hey')
  }
}

const handleSaveProfile = async () => {
  if (!profileFormRef.value) return
  await profileFormRef.value.validate(async (valid) => {
    if (valid) {
      savingProfile.value = true
      try {
        await saveProfile({
          nickname: profileForm.nickname,
          email: profileForm.email,
          mobile: profileForm.mobile,
          avatar: profileForm.avatar,
        })
        userInfo.value.nickname = profileForm.nickname
        userInfo.value.email = profileForm.email
        userInfo.value.mobile = profileForm.mobile
        $baseMessage('个人资料更新成功', 'success', 'hey')
      } catch (err) {
        console.error('更新失败', err)
      } finally {
        savingProfile.value = false
      }
    }
  })
}

const handleSavePassword = async () => {
  if (!passwordFormRef.value) return
  await passwordFormRef.value.validate(async (valid) => {
    if (valid) {
      savingPassword.value = true
      try {
        const result: any = await saveProfile({
          old_password: passwordForm.old_password,
          password: passwordForm.password,
        })
        const token = result?.data?.token
        if (typeof token === 'string' && token) {
          userStore.setToken(token)
          $baseMessage('密码修改成功', 'success', 'hey')
        } else {
          await userStore.resetAll()
          $baseMessage('密码修改成功，请重新登录', 'success', 'hey')
          await router.replace('/login')
        }
        passwordForm.old_password = ''
        passwordForm.password = ''
        passwordForm.confirm_password = ''
        passwordFormRef.value?.resetFields()
      } catch (err) {
        console.error('密码修改失败', err)
      } finally {
        savingPassword.value = false
      }
    }
  })
}

const handleSaveLockPassword = async () => {
  if (!lockFormRef.value) return
  await lockFormRef.value.validate(async (valid) => {
    if (valid) {
      savingLockPassword.value = true
      try {
        await saveProfile({
          current_password: lockForm.current_password,
          lock_password: lockForm.lock_password,
        })
        $baseMessage('个性锁屏密码设置成功', 'success', 'hey')
        hasCustomLockPassword.value = true
        lockForm.current_password = ''
        lockForm.lock_password = ''
        lockForm.confirm_lock_password = ''
        lockFormRef.value?.resetFields()
      } catch (err) {
        console.error('设置锁屏密码失败', err)
      } finally {
        savingLockPassword.value = false
      }
    }
  })
}

const handleResetLockPassword = () => {
  ElMessageBox.prompt('请输入当前后台登录密码以确认恢复默认：', '恢复默认锁屏密码', {
    confirmButtonText: '确定恢复',
    cancelButtonText: '取消',
    inputType: 'password',
    inputPattern: /\S+/,
    inputErrorMessage: '请输入当前后台登录密码',
  })
    .then(async ({ value }) => {
      try {
        await saveProfile({
          current_password: value,
          reset_lock_password: true,
        })
        $baseMessage('已恢复为默认使用后台登录密码', 'success', 'hey')
        hasCustomLockPassword.value = false
        lockForm.current_password = ''
        lockForm.lock_password = ''
        lockForm.confirm_lock_password = ''
        lockFormRef.value?.resetFields()
      } catch (err) {
        console.error('恢复默认锁屏密码失败', err)
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchProfile()
})
</script>

<style lang="scss" scoped>
.personal-center-container {
  .user-info {
    padding: 24px 8px 12px;
    text-align: center;

    .avatar-uploader {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      cursor: pointer;

      :deep(.el-upload) {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
      }

      .avatar-box {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 108px;
        height: 108px;
        padding: 3px;
        background: linear-gradient(135deg, var(--el-color-primary-light-5), var(--el-color-primary-light-8));
        border-radius: 50%;
        box-shadow: 0 8px 20px -6px rgba(64, 158, 255, 0.28);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

        :deep(.el-avatar) {
          width: 100%;
          height: 100%;
          border: 3px solid var(--el-bg-color-overlay);
          transition: transform 0.3s ease;
        }

        .avatar-hover-mask {
          position: absolute;
          inset: 3px;
          display: flex;
          flex-direction: column;
          gap: 4px;
          align-items: center;
          justify-content: center;
          font-size: 12px;
          font-weight: 500;
          color: #fff;
          background: rgba(15, 23, 42, 0.52);
          backdrop-filter: blur(2px);
          border-radius: 50%;
          opacity: 0;
          transition: opacity 0.25s ease;

          i {
            font-size: 20px;
          }
        }

        .avatar-camera-badge {
          position: absolute;
          right: 2px;
          bottom: 2px;
          display: flex;
          align-items: center;
          justify-content: center;
          width: 28px;
          height: 28px;
          font-size: 14px;
          color: #fff;
          background: var(--el-color-primary);
          border: 2px solid var(--el-bg-color-overlay);
          border-radius: 50%;
          box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
          transition: transform 0.25s ease;
        }

        &:hover {
          transform: translateY(-2px);
          box-shadow: 0 12px 24px -6px rgba(64, 158, 255, 0.38);

          .avatar-hover-mask {
            opacity: 1;
          }

          .avatar-camera-badge {
            transform: scale(1.08);
          }
        }
      }

      &-tip {
        margin-top: 10px;
        font-size: 12px;
        line-height: 1.4;
        color: var(--el-text-color-secondary);
        transition: color 0.2s ease;
      }

      &:hover .avatar-uploader-tip {
        color: var(--el-color-primary);
      }
    }

    &-full-name {
      margin-top: 14px;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.2px;
      color: var(--el-text-color-primary);
    }

    &-description {
      margin-top: 8px;
    }

    &-list {
      padding: 16px 6px 0;
      margin: 20px 0 0;
      text-align: left;
      list-style: none;
      border-top: 1px solid var(--el-border-color-lighter);

      .info-item {
        display: flex;
        align-items: center;
        padding: 10px 8px;
        border-radius: 8px;
        transition: background-color 0.2s ease;

        &:hover {
          background-color: var(--el-fill-color-light);
        }

        .item-icon {
          display: inline-flex;
          flex-shrink: 0;
          align-items: center;
          justify-content: center;
          width: 32px;
          height: 32px;
          margin-right: 10px;
          font-size: 16px;
          color: var(--el-color-primary);
          background: var(--el-color-primary-light-9);
          border-radius: 8px;
        }

        .item-content {
          display: flex;
          flex: 1;
          align-items: center;
          justify-content: space-between;
          font-size: 14px;

          .item-label {
            color: var(--el-text-color-regular);
          }

          .item-value {
            font-weight: 500;
            color: var(--el-text-color-primary);
          }
        }
      }
    }
  }
}
</style>
