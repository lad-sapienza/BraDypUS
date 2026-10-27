<template>
  <div class="bt">
    <p class="bt-hint">{{ t('grp_builtin_hint') }}</p>
    <table class="bt-table">
      <thead>
        <tr>
          <th class="bt-lvl">{{ t('grp_level') }}</th>
          <th v-for="a in ACTIONS" :key="a">{{ t(`grp_action_${a}`) }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="lvl in LEVELS" :key="lvl.key" :class="{ 'bt-active': lvl.key === selected }">
          <td class="bt-lvl">
            <strong>{{ t(`grp_lvl_${lvl.key}`) }}</strong>
            <small>{{ lvl.value }}</small>
            <div class="bt-desc">{{ t(`grp_lvl_${lvl.key}_desc`) }}</div>
          </td>
          <td v-for="a in ACTIONS" :key="a" :class="`bt-${lvl.can[a]}`">
            <span v-if="lvl.can[a] === 'all'" :title="t('grp_mode_all')"><CheckOutlined /></span>
            <span v-else-if="lvl.can[a] === 'own'" class="bt-own">{{ t('grp_own') }}</span>
            <span v-else><MinusOutlined /></span>
          </td>
        </tr>
      </tbody>
    </table>
    <p class="bt-hint">{{ t('grp_builtin_locks') }}</p>
  </div>
</template>

<script setup>
// The built-in levels, read only: what each one allows on every table, before
// groups add anything. Mirrors Auth\Authorization (and Auth\Access): the tiers
// are fixed in the code, so this is documentation, not data.
import { CheckOutlined, MinusOutlined } from '@ant-design/icons-vue'
import { useI18n } from '@/i18n'

defineProps({ selected: { type: String, default: '' } })

const { t } = useI18n()

const ACTIONS = ['create', 'read', 'update', 'delete']
const ALL  = { create: 'all', read: 'all', update: 'all', delete: 'all' }
const LEVELS = [
  { key: 'super_admin', value: 1,  can: ALL },
  { key: 'admin',       value: 10, can: ALL },
  { key: 'writer',      value: 20, can: ALL },
  { key: 'self_writer', value: 25, can: { create: 'all', read: 'all', update: 'own', delete: 'own' } },
  { key: 'reader',      value: 30, can: { create: 'none', read: 'all', update: 'none', delete: 'none' } },
  { key: 'enter',       value: 39, can: { create: 'none', read: 'none', update: 'none', delete: 'none' } },
]
</script>

<style scoped>
.bt { display: flex; flex-direction: column; gap: 0.75rem; }
.bt-hint { margin: 0; color: var(--p-text-muted-color); font-size: 0.85rem; }
.bt-table { width: 100%; border-collapse: collapse; }
.bt-table th, .bt-table td {
  padding: 0.5rem 0.6rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: center;
  vertical-align: middle;
}
.bt-table th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--p-text-muted-color); font-weight: 600; }
.bt-table .bt-lvl { text-align: left; }
.bt-lvl small { margin-left: 0.4rem; color: var(--p-text-muted-color); }
.bt-desc { font-size: 0.82rem; color: var(--p-text-muted-color); margin-top: 0.15rem; }
.bt-all { color: var(--p-green-500, #22c55e); }
.bt-none { color: var(--p-text-muted-color); opacity: 0.6; }
.bt-own { font-size: 0.8rem; color: var(--p-orange-500, #f97316); }
.bt-active { background: var(--p-content-hover-background, rgba(127,127,127,.08)); }
</style>
