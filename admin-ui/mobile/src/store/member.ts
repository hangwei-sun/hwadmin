import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getProfile, login, logout, type Member } from '@/api/member'
import { TOKEN_KEY } from '@/http'

export const useMemberStore = defineStore('mobile-member', () => {
  const token = ref(String(uni.getStorageSync(TOKEN_KEY) || ''))
  const profile = ref<Member | null>(null)
  function clear() {
    token.value = ''
    profile.value = null
    uni.removeStorageSync(TOKEN_KEY)
  }
  uni.$on('hwadmin:unauthorized', clear)
  async function signIn(account: string, password: string) {
    const result = await login(account, password)
    token.value = result.token
    profile.value = result.user
    uni.setStorageSync(TOKEN_KEY, result.token)
  }
  async function refresh() {
    profile.value = await getProfile()
  }
  async function signOut() {
    // Keep the session when revocation fails, allowing a retry.
    await logout()
    clear()
  }
  return { token, profile, signIn, refresh, signOut }
})
