<template>
  <div class="sms-workbench-container">
    <!-- 顶部状态指示面板 -->
    <el-row :gutter="16" class="status-overview-row">
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">运行模式</div>
            <div class="stat-value">
              <el-tag v-if="serviceStatus.is_mock" type="success" size="large" effect="dark">
                <el-icon><vab-icon icon="flask-line" /></el-icon>
                沙盒模拟模式
              </el-tag>
              <el-tag v-else type="primary" size="large" effect="dark">
                <el-icon><vab-icon icon="cloud-line" /></el-icon>
                生产真实发送
              </el-tag>
            </div>
            <div class="stat-tip">
              {{ serviceStatus.is_mock ? '无需真实密钥，免资费安全联调' : '直连腾讯云短信生产 API 网关' }}
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">API 凭据状态</div>
            <div class="stat-value stat-code">
              {{ serviceStatus.masked_secret || '未配置' }}
            </div>
            <div class="stat-tip">
              SdkAppId: <strong>{{ serviceStatus.sdk_app_id || '-' }}</strong>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">默认短信签名</div>
            <div class="stat-value">
              <el-tag type="info" size="large">【{{ serviceStatus.sign_name || 'HwAdmin' }}】</el-tag>
            </div>
            <div class="stat-tip">腾讯云控制台已报备审核签名</div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">今日调用成功率</div>
            <div class="stat-value text-primary">
              {{ serviceStatus.statistics?.success_rate ?? 100 }}%
            </div>
            <div class="stat-tip">
              今日共发 {{ serviceStatus.statistics?.today_total ?? 0 }} 条，
              成功 {{ serviceStatus.statistics?.today_success ?? 0 }}，
              失败 {{ serviceStatus.statistics?.today_fail ?? 0 }}
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 主调试区 -->
    <el-row :gutter="16" class="main-debug-row">
      <!-- 左侧：在线发送表单 -->
      <el-col :xs="24" :lg="13">
        <el-card shadow="never" class="debug-form-card">
          <template #header>
            <div class="card-header">
              <span class="card-title">
                <el-icon><vab-icon icon="send-plane-2-line" /></el-icon>
                在线短信发送与调试
              </span>
              <el-button text type="primary" size="small" @click="fillQuickPhone('13800138000')">
                填入测试手机号
              </el-button>
            </div>
          </template>

          <el-form
            ref="formRef"
            :model="sendForm"
            :rules="rules"
            label-width="110px"
            status-icon
          >
            <el-form-item label="接收手机号码" prop="mobile">
              <el-input
                v-model="sendForm.mobile"
                placeholder="例如 13800138000 或国际号码 +86138..."
                clearable
              >
                <template #prepend>
                  <el-select v-model="countryCode" style="width: 90px">
                    <el-option label="+86 中国" value="+86" />
                    <el-option label="+852 香港" value="+852" />
                    <el-option label="+1 美国" value="+1" />
                  </el-select>
                </template>
              </el-input>
            </el-form-item>

            <el-form-item label="业务场景" prop="event">
              <el-radio-group v-model="sendForm.event" @change="handleEventChange">
                <el-radio-button value="register">用户注册</el-radio-button>
                <el-radio-button value="login">安全登录</el-radio-button>
                <el-radio-button value="reset">重置密码</el-radio-button>
                <el-radio-button value="test">自定义测试</el-radio-button>
              </el-radio-group>
            </el-form-item>

            <el-form-item label="短信模板 ID" prop="template_id">
              <el-input
                v-model="sendForm.template_id"
                placeholder="腾讯云模板 ID（留空则使用该场景默认模板）"
              />
            </el-form-item>

            <el-form-item label="短信签名" prop="sign_name">
              <el-input
                v-model="sendForm.sign_name"
                placeholder="短信签名（留空则使用默认配置签名）"
              />
            </el-form-item>

            <el-form-item label="模板参数列表" prop="params">
              <el-input
                v-model="sendForm.params"
                placeholder="多个参数用英文逗号隔开，例如：888666,5（留空则自动生成6位验证码）"
              />
              <div class="form-item-tip">
                通常参数 1 为 6 位数字验证码，参数 2 为有效分钟数。如留空将自动生成随机验证码并填充。
              </div>
            </el-form-item>

            <el-form-item>
              <el-button
                type="primary"
                size="large"
                :loading="sending"
                @click="handleSendSms"
              >
                <el-icon><vab-icon icon="send-plane-fill" /></el-icon>
                立即发送测试短信
              </el-button>
              <el-button size="large" @click="handleResetForm">重置表单</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>

      <!-- 右侧：即时响应结果与沙盒验证码提取 -->
      <el-col :xs="24" :lg="11">
        <el-card shadow="never" class="result-card">
          <template #header>
            <div class="card-header">
              <span class="card-title">
                <el-icon><vab-icon icon="terminal-box-line" /></el-icon>
                即时请求响应结果
              </span>
              <el-tag v-if="lastResult" :type="lastResult.success ? 'success' : 'danger'" size="small">
                {{ lastResult.success ? '调用成功' : '调用异常' }}
              </el-tag>
            </div>
          </template>

          <div v-if="lastResult" class="result-content-wrapper">
            <!-- 提炼高亮的验证码卡片（沙盒或调试中） -->
            <div v-if="lastResult.code" class="highlight-code-box">
              <div class="code-box-header">
                <el-icon><vab-icon icon="key-2-line" /></el-icon>
                <span>本次发送验证码</span>
                <el-tag v-if="lastResult.mock" size="small" type="success">沙盒直出</el-tag>
              </div>
              <div class="code-number-display">
                {{ lastResult.code }}
              </div>
              <div class="code-box-footer">
                <span>有效期 5 分钟 · 该验证码可直接在前台验证接口核销</span>
                <el-button text type="primary" size="small" @click="copyCode(lastResult.code)">
                  一键复制验证码
                </el-button>
              </div>
            </div>

            <!-- 完整返回 JSON 结构 -->
            <div class="json-inspect-title">接口原始响应报文：</div>
            <pre class="json-code-box"><code>{{ formattedResponseJson }}</code></pre>
          </div>

          <div v-else class="result-empty-state">
            <el-empty description="点击左侧「立即发送测试短信」即可在此实时查看接口返回详情与验证码" />
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 底部：腾讯云错误码速查指南 -->
    <el-card shadow="never" class="faq-card">
      <template #header>
        <div class="card-header">
          <span class="card-title">
            <el-icon><vab-icon icon="question-line" /></el-icon>
            腾讯云 SMS 常见状态码与排查指南
          </span>
        </div>
      </template>

      <el-collapse v-model="activeCollapse">
        <el-collapse-item title="1. 为什么沙盒模式下提示「模拟沙盒发送成功」？" name="1">
          <p>当系统检测到 <code>mock_mode = 1</code> 或者您尚未在插件配置中填写腾讯云生产 <code>SecretId</code> 和 <code>SecretKey</code> 时，系统会自动开启沙盒模式。在沙盒模式下，短信不会真正扣费或发送至运营商，但会完整执行入库、日志审计、防刷频与缓存核销链路，并直接将生成的验证码回传展示，极度便于开发与自动化测试。</p>
        </el-collapse-item>
        <el-collapse-item title="2. 常见报错：FailedOperation.SignatureIncorrectOrUnapproved" name="2">
          <p>短信签名不正确或尚未通过腾讯云审核。请登录腾讯云短信控制台，检查「国内短信 - 签名管理」中签名的实际文本（注意大小写与前后空格，不要带【】括号）。</p>
        </el-collapse-item>
        <el-collapse-item title="3. 常见报错：FailedOperation.TemplateIncorrectOrUnapproved" name="3">
          <p>模板 ID 不存在或审核未通过，或者填写的模板参数数量与腾讯云控制台中定义的变量个数（例如 <code>{1}</code>、<code>{2}</code>）不一致。</p>
        </el-collapse-item>
        <el-collapse-item title="4. 常见报错：LimitExceeded.PhoneNumberDailyLimit" name="4">
          <p>单个手机号每日接收短信量超出腾讯云或运营商设定的安全频次上限（通常单个手机号每日最多 10~30 条），请更换测试手机号或调整腾讯云频率限额。</p>
        </el-collapse-item>
      </el-collapse>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { getSmsStatus, sendSmsTest } from '/@/api/hwadmin'

defineOptions({
  name: 'SmsConsole',
})

const countryCode = ref('+86')
const sending = ref(false)
const formRef = ref<FormInstance>()
const activeCollapse = ref(['1'])

const serviceStatus = ref<any>({
  mode: 'mock',
  is_mock: true,
  has_key: false,
  masked_secret: '',
  sdk_app_id: '1400000000',
  sign_name: 'HwAdmin',
  templates: {
    register: '100001',
    login: '100002',
    reset: '100003',
  },
  statistics: {
    today_total: 0,
    today_success: 0,
    today_fail: 0,
    success_rate: 100,
  },
})

const sendForm = reactive({
  mobile: '',
  event: 'register',
  template_id: '',
  sign_name: '',
  params: '',
})

const lastResult = ref<any>(null)

const formattedResponseJson = computed(() => {
  if (!lastResult.value) return ''
  try {
    return JSON.stringify(lastResult.value, null, 2)
  } catch {
    return String(lastResult.value)
  }
})

const rules = reactive<FormRules>({
  mobile: [
    { required: true, message: '请输入手机号码', trigger: 'blur' },
    {
      pattern: /^(?:\+?\d{6,15}|1[3-9]\d{9})$/,
      message: '请输入合法的手机号码格式',
      trigger: 'blur',
    },
  ],
})

const fetchStatus = async () => {
  try {
    const res = await getSmsStatus()
    if (res?.data) {
      serviceStatus.value = res.data
      if (!sendForm.sign_name && res.data.sign_name) {
        sendForm.sign_name = res.data.sign_name
      }
      handleEventChange(sendForm.event)
    }
  } catch (e) {
    console.error('获取短信服务状态失败', e)
  }
}

const handleEventChange = (val: string | number | boolean | undefined) => {
  const templates = serviceStatus.value?.templates || {}
  if (val === 'register') {
    sendForm.template_id = templates.register || '100001'
  } else if (val === 'login') {
    sendForm.template_id = templates.login || '100002'
  } else if (val === 'reset') {
    sendForm.template_id = templates.reset || '100003'
  } else {
    sendForm.template_id = ''
  }
}

const fillQuickPhone = (phone: string) => {
  sendForm.mobile = phone
}

const handleResetForm = () => {
  sendForm.mobile = ''
  sendForm.params = ''
  handleEventChange(sendForm.event)
  if (formRef.value) formRef.value.clearValidate()
}

const handleSendSms = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    sending.value = true
    try {
      const fullMobile =
        countryCode.value !== '+86' && !sendForm.mobile.startsWith('+')
          ? `${countryCode.value}${sendForm.mobile}`
          : sendForm.mobile

      const res = await sendSmsTest({
        mobile: fullMobile,
        event: sendForm.event,
        template_id: sendForm.template_id,
        sign_name: sendForm.sign_name,
        params: sendForm.params,
      })

      lastResult.value = res?.data || res
      ElMessage.success('短信发送成功！')
      fetchStatus()
    } catch (e: any) {
      lastResult.value = e?.response?.data || { success: false, message: e.message }
      ElMessage.error(e?.message || '短信发送失败')
    } finally {
      sending.value = false
    }
  })
}

const copyCode = (code: string) => {
  navigator.clipboard.writeText(code).then(() => {
    ElMessage.success('验证码已复制到剪贴板')
  })
}

onMounted(() => {
  fetchStatus()
})
</script>

<style lang="scss" scoped>
.sms-workbench-container {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;

  .status-overview-row {
    .stat-card {
      border-radius: 8px;
      margin-bottom: 8px;

      :deep(.el-card__body) {
        padding: 16px;
      }

      .stat-content {
        display: flex;
        flex-direction: column;
        gap: 6px;

        .stat-label {
          font-size: 13px;
          color: var(--el-text-color-secondary);
        }

        .stat-value {
          font-size: 20px;
          font-weight: 700;
          display: flex;
          align-items: center;
          gap: 6px;

          &.stat-code {
            font-family: monospace;
            font-size: 16px;
          }

          &.text-primary {
            color: var(--el-color-primary);
          }
        }

        .stat-tip {
          font-size: 12px;
          color: var(--el-text-color-placeholder);
          line-height: 1.4;
        }
      }
    }
  }

  .main-debug-row {
    .debug-form-card,
    .result-card {
      border-radius: 8px;
      min-height: 480px;

      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;

        .card-title {
          font-weight: 600;
          font-size: 15px;
          display: flex;
          align-items: center;
          gap: 6px;
        }
      }
    }

    .form-item-tip {
      font-size: 12px;
      color: var(--el-text-color-placeholder);
      line-height: 1.4;
      margin-top: 4px;
    }

    .result-content-wrapper {
      display: flex;
      flex-direction: column;
      gap: 12px;

      .highlight-code-box {
        background: linear-gradient(135deg, var(--el-color-primary-light-9), var(--el-fill-color-blank));
        border: 1px solid var(--el-color-primary-light-7);
        border-radius: 8px;
        padding: 16px;
        text-align: center;

        .code-box-header {
          display: flex;
          justify-content: center;
          align-items: center;
          gap: 6px;
          font-size: 13px;
          color: var(--el-text-color-secondary);
        }

        .code-number-display {
          font-size: 36px;
          font-weight: 800;
          font-family: 'Fira Code', Consolas, monospace;
          color: var(--el-color-primary);
          letter-spacing: 6px;
          margin: 8px 0;
        }

        .code-box-footer {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 12px;
          color: var(--el-text-color-secondary);
          border-top: 1px dashed var(--el-border-color-lighter);
          padding-top: 8px;
        }
      }

      .json-inspect-title {
        font-size: 13px;
        font-weight: 600;
        color: var(--el-text-color-secondary);
      }

      .json-code-box {
        background: #1e293b;
        color: #38bdf8;
        padding: 14px;
        border-radius: 6px;
        font-family: 'Fira Code', Consolas, monospace;
        font-size: 12px;
        line-height: 1.5;
        max-height: 280px;
        overflow-y: auto;
      }
    }

    .result-empty-state {
      padding: 60px 0;
    }
  }

  .faq-card {
    border-radius: 8px;

    .card-header {
      font-weight: 600;
      font-size: 15px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    p {
      margin: 4px 0;
      color: var(--el-text-color-regular);
      line-height: 1.6;
    }
  }
}
</style>
