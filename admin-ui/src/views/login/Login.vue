<template>
  <login-container v-slot="{ alignType }">
    <div class="login-container">
      <div class="login-form" :class="`align-${alignType}`">
        <img alt="" class="left-img" :src="resolveConfiguredAsset(settingsStore.loginImage) || leftImg" />
        <el-form ref="formRef" label-position="left" :model="form" :rules="rules" @submit.prevent>
          <div class="title">hello !</div>
          <div class="title-tips">{{ translate('欢迎来到') }} {{ title }}</div>
          <el-form-item prop="account">
            <el-input v-model.trim="form.account" v-focus clearable :placeholder="translate('请输入用户名、邮箱或手机号')" type="text">
              <template #prefix>
                <vab-icon icon="user-line" />
              </template>
            </el-input>
          </el-form-item>
          <el-form-item prop="password">
            <el-input
              ref="passwordRef"
              v-model.trim="form.password"
              clearable
              :placeholder="translate('请输入密码')"
              show-password
              :type="passwordType"
              @keyup.enter="handleEnterKey"
            >
              <template #prefix>
                <vab-icon icon="lock-line" />
              </template>
            </el-input>
          </el-form-item>
          <el-form-item v-if="captchaEnabled" prop="captcha_code">
            <div class="captcha-wrapper">
              <el-input
                v-model.trim="form.captcha_code"
                clearable
                :placeholder="translate('请输入图形验证码')"
                type="text"
                maxlength="6"
                @keyup.enter="handleLogin"
              >
                <template #prefix>
                  <vab-icon icon="shield-check-line" />
                </template>
              </el-input>
              <el-tooltip :content="translate('看不清？点击刷新')" placement="top">
                <div class="captcha-img-box" @click="refreshCaptcha">
                  <img v-if="captchaImage" :src="captchaImage" alt="captcha" class="captcha-img" />
                  <div v-else class="captcha-placeholder">
                    <vab-icon icon="refresh-line" :class="{ 'is-loading': captchaLoading }" />
                  </div>
                </div>
              </el-tooltip>
            </div>
          </el-form-item>
          <el-button class="login-btn" :loading="loading" type="primary" @click="handleLogin">
            {{ translate('登录') }}
          </el-button>
        </el-form>
      </div>
    </div>
  </login-container>
</template>

<script lang="ts" setup>
import type { FormInstance, FormRules, InputInstance } from 'element-plus'
import { defaultLoginImage as leftImg, resolveConfiguredAsset } from '/@/utils/branding'
import { translate } from '/@/i18n'
import { useSettingsStore } from '/@/store/modules/settings'
import { useUserStore } from '/@/store/modules/user'
import { isPassword } from '/@/utils/validate'
import { getPublicConfig } from '/@/api/hwadmin'
import { getCaptcha } from '/@/api/user'

defineOptions({
  name: 'Login',
})

interface FormType {
  account: string
  password: string
  captcha_key?: string
  captcha_code?: string
}

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const settingsStore = useSettingsStore()
const { title } = storeToRefs(settingsStore)
const login = (form: FormType) => userStore.login(form)
const loading = ref<boolean>(false)
const passwordType = ref<string>('password')
const redirect = ref<any>(undefined)
const formRef = ref<FormInstance>()
const passwordRef = ref<InputInstance>()

const captchaEnabled = ref<boolean>(false)
const captchaKey = ref<string>('')
const captchaImage = ref<string>('')
const captchaLoading = ref<boolean>(false)

const form = reactive<FormType>({
  account: '',
  password: '',
  captcha_key: '',
  captcha_code: '',
})

const validateUsername = (rule: any, value: any, callback: any) => {
  if ('' === value) callback(new Error(translate('用户名不能为空')))
  else callback()
}
const validatePassword = (rule: any, value: any, callback: any) => {
  if (isPassword(value)) {
    callback()
  } else {
    callback(new Error(translate('密码不能少于6位')))
  }
}
const validateCaptcha = (rule: any, value: any, callback: any) => {
  if (captchaEnabled.value && !value) {
    callback(new Error(translate('请输入图形验证码')))
  } else {
    callback()
  }
}

const rules = reactive<FormRules<FormType>>({
  account: [
    {
      required: true,
      trigger: 'blur',
      validator: validateUsername,
    },
  ],
  password: [
    {
      required: true,
      trigger: 'blur',
      validator: validatePassword,
    },
  ],
  captcha_code: [
    {
      required: true,
      trigger: 'blur',
      validator: validateCaptcha,
    },
  ],
})

const refreshCaptcha = async () => {
  if (!captchaEnabled.value) return
  try {
    captchaLoading.value = true
    const res = await getCaptcha()
    const data = res?.data || {}
    if (data.captcha_image) {
      captchaImage.value = data.captcha_image
      captchaKey.value = data.captcha_key || ''
      form.captcha_key = data.captcha_key || ''
      form.captcha_code = ''
    }
  } catch (e) {
    console.warn('获取验证码失败', e)
  } finally {
    captchaLoading.value = false
  }
}

const handleEnterKey = () => {
  if (captchaEnabled.value && !form.captcha_code) {
    // 若开启验证码且未填写，焦点提示或直接验证
    formRef.value?.validateField('captcha_code')
    return
  }
  handleLogin()
}

const handleRoute = () => {
  return redirect.value === '/404' || redirect.value === '/403' ? '/' : redirect.value
}

const handleLogin = async () => {
  if (loading.value) return
  if (!form.account || !isPassword(form.password) || (captchaEnabled.value && !form.captcha_code)) {
    await formRef.value?.validate().catch(() => {})
    return
  }
  try {
    loading.value = true
    await login(form)
    await router.push(handleRoute())
  } catch (error) {
    console.error('登录失败', error)
    if (captchaEnabled.value) {
      await refreshCaptcha()
    }
  } finally {
    loading.value = false
  }
}

watchEffect(() => {
  redirect.value = (route.query && route.query.redirect) || '/'
})

onMounted(async () => {
  try {
    const res = await getPublicConfig()
    const cfg = res?.data || {}
    if (cfg.site_name) settingsStore.updateTitle(cfg.site_name)
    if (cfg.site_logo !== undefined) settingsStore.updateLogo(cfg.site_logo)
    if (cfg.site_favicon !== undefined) settingsStore.updateFavicon(cfg.site_favicon)
    if (cfg.login_image !== undefined) settingsStore.updateLoginImage(cfg.login_image)
    if (cfg.default_avatar !== undefined) settingsStore.updateDefaultAvatar(cfg.default_avatar)
    if (cfg.site_copyright) settingsStore.updateCopyright(cfg.site_copyright)
    if (cfg.beian !== undefined) settingsStore.updateBeian(cfg.beian)

    captchaEnabled.value = Boolean(cfg.login_captcha)
    if (captchaEnabled.value) {
      await refreshCaptcha()
    }
  } catch (e) {
    // 忽略加载错误
  }
})

onBeforeRouteLeave((to, from, next) => {
  next()
})
</script>

<style lang="scss" scoped>
.captcha-wrapper {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
}

.captcha-img-box {
  flex-shrink: 0;
  height: 40px;
  width: 120px;
  border-radius: var(--el-border-radius-base);
  overflow: hidden;
  cursor: pointer;
  border: 1px solid var(--el-border-color);
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--el-fill-color-light);
  user-select: none;
  transition: all 0.2s ease;

  &:hover {
    border-color: var(--el-color-primary);
    box-shadow: 0 0 6px rgba(56, 189, 248, 0.25);
  }

  .captcha-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .captcha-placeholder {
    color: var(--el-text-color-secondary);
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}
</style>
