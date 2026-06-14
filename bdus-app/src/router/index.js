import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  // ── Root redirect ──────────────────────────────────────────────────────────
  {
    path: '/',
    redirect: '/login'
  },

  // ── Public routes — no app prefix, no auth required ───────────────────────
  {
    path: '/login',
    component: () => import('@/views/LoginView.vue')
  },
  {
    path: '/oauth-callback',
    component: () => import('@/views/OAuthCallbackView.vue')
  },
  {
    path: '/new-app',
    component: () => import('@/views/NewAppView.vue')
  },

  // ── App routes — all under /:app ───────────────────────────────────────────
  {
    path: '/:app',
    component: () => import('@/views/HomeView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/users',
    component: () => import('@/views/UsersView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/vocabularies',
    component: () => import('@/views/VocabulariesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/data',
    component: () => import('@/views/DataView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/log',
    component: () => import('@/views/LogView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/backups',
    component: () => import('@/views/BackupView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/info',
    component: () => import('@/views/InfoView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/config/:panel?/:tb?',
    component: () => import('@/views/ConfigView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/templates',
    component: () => import('@/views/TemplatesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/history',
    component: () => import('@/views/HistoryView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/deleted-records',
    component: () => import('@/views/DeletedRecordsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/files',
    component: () => import('@/views/FilesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/find-replace',
    component: () => import('@/views/SearchReplaceView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/matrix/:tb',
    component: () => import('@/views/MatrixView.vue'),
    meta: { requiresAuth: true }
  },
  {
    // :id is either a numeric record id or the literal string 'new'
    path: '/:app/record/:tb/:id',
    component: () => import('@/views/RecordView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/record/:tb',
    redirect: to => ({ path: `/${to.params.app}/record/${to.params.tb}/new` })
  },
  {
    path: '/:app/geoface/:tb',
    component: () => import('@/views/GeofaceView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/import',
    component: () => import('@/views/ImportView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/free-sql',
    component: () => import('@/views/FreeSqlView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/assemblages',
    component: () => import('@/views/AssemblagesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/chrono/:tb',
    component: () => import('@/views/ChronoTimelineView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/migrations',
    component: () => import('@/views/MigrationsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/:app/upgrade',
    component: () => import('@/views/MinorUpgradeView.vue'),
    meta: { requiresAuth: true }
  },

  // ── Catch-all — no route matched ──────────────────────────────────────────
  {
    path: '/:pathMatch(.*)*',
    redirect: to => {
      console.warn('[router] No route matched:', to.fullPath, '— redirecting to /login')
      return '/login'
    }
  }
]

const router = createRouter({
  // Hash history avoids conflicts with PHP routing on the same origin
  history: createWebHashHistory(),
  routes
})

router.beforeEach((to) => {
  if (!to.meta.requiresAuth) return true

  // Token is validated client-side (expiry check). Signature verification
  // happens server-side on every API call; a tampered token gets a 401.
  const auth = useAuthStore()
  if (!auth.isAuthenticated()) {
    return { path: '/login' }
  }

  // Silently correct a stale or mistyped app name in the URL so the JWT
  // is always the source of truth for which app is active.
  if (to.params.app && to.params.app !== auth.user?.app) {
    const rest = to.path.slice(to.params.app.length + 1)
    return { path: `/${auth.user.app}${rest}`, query: to.query }
  }

  return true
})

export default router
