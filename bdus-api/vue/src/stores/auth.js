import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)

  /**
   * Attempt login with email + password.
   * PHP sets the session cookie; we store the returned user info locally.
   */
  async function login(email, password, appName) {
    const res = await api.post('login_ctrl', 'auth', {
      email,
      password,
      app: appName
    })
    if (res.status !== 'success') throw new Error(res.text)
    // After successful login, fetch user data
    await fetchMe()
  }

  /**
   * Fetch current session user data.
   * Returns null if not authenticated.
   */
  async function fetchMe() {
    try {
      const res = await api.get('user_ctrl', 'showList')
      if (res.users && res.users.length > 0) {
        user.value = res.users[0]
      }
    } catch {
      user.value = null
    }
  }

  async function logout() {
    await api.get('login_ctrl', 'out')
    user.value = null
  }

  const isAuthenticated = () => !!(user.value?.id)

  return { user, login, fetchMe, logout, isAuthenticated }
})
