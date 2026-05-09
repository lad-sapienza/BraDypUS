import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/',
    component: () => import('@/views/HomeView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/login',
    component: () => import('@/views/LoginView.vue')
  },
  {
    path: '/users',
    component: () => import('@/views/UsersView.vue'),
    meta: { requiresAuth: true }
  }
]

const router = createRouter({
  // Hash history avoids conflicts with PHP routing on the same origin
  history: createWebHashHistory(),
  routes
})

router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) return true

  const auth = useAuthStore()
  if (!auth.isAuthenticated()) {
    await auth.fetchMe()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated()) {
    return { path: '/login' }
  }

  return true
})

export default router
