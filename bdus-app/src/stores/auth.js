import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const app = ref(null)

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
   * Select the working application (sets app in PHP session).
   */
  async function selectApp(appName) {
    const res = await api.get('login_ctrl', 'select_app', { app: appName })
    app.value = appName
    return res
  }

  /**
   * Fetch current session user data.
   * Returns null if not authenticated.
   */
  async function fetchMe() {
    try {
      // user_ctrl::showList returns the current user if not admin
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
    app.value = null
  }

  const isAuthenticated = () => !!(user.value?.id)

  return { user, app, login, selectApp, fetchMe, logout, isAuthenticated }
})
