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
  },
  {
    path: '/vocabularies',
    component: () => import('@/views/VocabulariesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/data',
    component: () => import('@/views/DataView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/log',
    component: () => import('@/views/LogView.vue'),
    meta: { requiresAuth: true }
  },
  {
    // :id is either a numeric record id or the literal string 'new'
    path: '/record/:tb/:id',
    component: () => import('@/views/RecordView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/record/:tb',
    redirect: to => ({ path: `/record/${to.params.tb}/new` })
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
