<template>
  <div class="gp">
    <!-- ── Left: the built-in levels and the groups ─────────────────── -->
    <aside class="gp-list">
      <AButton type="primary" size="small" block @click="startNew">
        <template #icon><PlusOutlined /></template>
        {{ t('grp_new_group') }}
      </AButton>

      <ul class="gp-items">
        <li :class="{ active: selected === 'builtin' }" @click="select('builtin')">
          <LockOutlined /> <span>{{ t('grp_builtin_levels') }}</span>
        </li>
        <li v-for="g in groups" :key="g.id" :class="{ active: selected === g.id }" @click="select(g.id)">
          <TeamOutlined />
          <span class="gp-name">{{ g.name }}</span>
          <small :title="t('grp_counts', g.members, g.rules)">{{ g.members }}·{{ g.rules }}</small>
        </li>
      </ul>
      <p v-if="!loading && !groups.length" class="gp-none">{{ t('grp_no_groups') }}</p>
    </aside>

    <!-- ── Right: the selected item ─────────────────────────────────── -->
    <section class="gp-detail">
      <BuiltinTiers v-if="selected === 'builtin'" />

      <div v-else-if="isNew || group" class="gp-form">
        <!-- Details -->
        <div class="gp-row">
          <label>{{ t('grp_name') }}
            <AInput v-model:value="draft.name" :maxlength="100" />
          </label>
          <label class="gp-grow">{{ t('grp_description') }}
            <AInput v-model:value="draft.description" />
          </label>
        </div>
        <div class="gp-actions">
          <AButton type="primary" :disabled="!draft.name.trim()" :loading="busy.details" @click="saveDetails">
            {{ isNew ? t('grp_create_group') : t('save') }}
          </AButton>
          <AButton v-if="!isNew" danger @click="confirmDelete">
            <template #icon><DeleteOutlined /></template>
            {{ t('delete') }}
          </AButton>
        </div>

        <template v-if="!isNew && group">
          <!-- Members -->
          <h4>{{ t('grp_members') }}</h4>
          <div class="gp-row">
            <ASelect
              v-model:value="memberIds"
              mode="multiple"
              class="gp-grow"
              :options="userOptions"
              :placeholder="t('grp_members_placeholder')"
              option-filter-prop="label"
            />
            <AButton :disabled="!membersDirty" :loading="busy.members" @click="saveMembers">
              {{ t('grp_save_members') }}
            </AButton>
          </div>

          <!-- Permissions -->
          <h4>{{ t('grp_permissions') }}</h4>
          <GroupPermissionsGrid
            :tables="dataTables"
            :rules="group.rules"
            :saving="busy.rules"
            :error="rulesError"
            @save="saveRules"
            @clear-error="rulesError = ''"
          />
        </template>
      </div>

      <AEmpty v-else :description="t('grp_select_group')" />
    </section>
  </div>
</template>

<script setup>
// Groups: the list on the left (built-in levels first), the selected group on the
// right — its details, members and the permissions grid.
import { computed, onMounted, reactive, ref } from 'vue'
import { DeleteOutlined, LockOutlined, PlusOutlined, TeamOutlined } from '@ant-design/icons-vue'
import { Button as AButton, Empty as AEmpty, Input as AInput, Select as ASelect } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { useConfirm, useToast } from '@/composables/useNotify'
import { useGroups } from '@/composables/useGroups'
import { useTables } from '@/composables/useTables'
import BuiltinTiers from './BuiltinTiers.vue'
import GroupPermissionsGrid from './GroupPermissionsGrid.vue'

const props = defineProps({
  users: { type: Array, default: () => [] },     // [{ id, name, email }] — for the member picker
})
const emit = defineEmits(['changed'])            // groups or memberships changed: the users list is stale

const { t }     = useI18n()
const toast     = useToast()
const confirm   = useConfirm()
const api       = useGroups()
const { tables, loadTables } = useTables()

const groups   = ref([])
const loading  = ref(false)
const selected = ref('builtin')     // 'builtin' | group id | 'new'
const group    = ref(null)          // the selected group, with members and rules
const draft    = reactive({ name: '', description: '' })
const memberIds = ref([])
const memberBaseline = ref('[]')
const rulesError = ref('')
const busy = reactive({ details: false, members: false, rules: false })

const isNew = computed(() => selected.value === 'new')
const dataTables = computed(() => tables.value.map(tb => ({ name: tb.name, label: tb.label })))
const userOptions = computed(() => props.users.map(u => ({ value: u.id, label: `${u.name} (${u.email})` })))
const membersDirty = computed(() => JSON.stringify([...memberIds.value].sort((a, b) => a - b)) !== memberBaseline.value)

function fail(e) {
  toast.add({ severity: 'error', summary: t('grp_groups'), detail: e.message, life: 5000 })
}
function ok(code) {
  toast.add({ severity: 'success', summary: t('grp_groups'), detail: t(code), life: 2500 })
}

async function loadList() {
  loading.value = true
  try { groups.value = await api.listGroups() } catch (e) { fail(e) } finally { loading.value = false }
}

async function select(id) {
  selected.value = id
  rulesError.value = ''
  group.value = null
  if (id === 'builtin') return
  try {
    group.value = await api.getGroup(id)
    draft.name = group.value.name
    draft.description = group.value.description ?? ''
    memberIds.value = group.value.members.map(m => m.id)
    memberBaseline.value = JSON.stringify([...memberIds.value].sort((a, b) => a - b))
  } catch (e) { fail(e) }
}

function startNew() {
  selected.value = 'new'
  group.value = null
  draft.name = ''
  draft.description = ''
}

async function saveDetails() {
  busy.details = true
  try {
    if (isNew.value) {
      const res = await api.createGroup(draft.name.trim(), draft.description.trim())
      ok('group_created')
      await loadList()
      await select(res.id)
    } else {
      await api.updateGroup(group.value.id, draft.name.trim(), draft.description.trim())
      ok('group_updated')
      await loadList()
      await select(group.value.id)
    }
    emit('changed')
  } catch (e) { fail(e) } finally { busy.details = false }
}

function confirmDelete() {
  const g = groups.value.find(x => x.id === group.value.id)
  confirm.require({
    message: t('grp_delete_confirm', g?.keys ?? 0),
    header:  t('confirm'),
    icon:    'pi pi-exclamation-triangle',
    rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('delete'), severity: 'danger' },
    accept: async () => {
      try {
        await api.deleteGroup(group.value.id)
        ok('group_deleted')
        group.value = null
        selected.value = 'builtin'
        await loadList()
        emit('changed')
      } catch (e) { fail(e) }
    },
  })
}

async function saveMembers() {
  busy.members = true
  try {
    await api.saveMembers(group.value.id, memberIds.value)
    ok('members_saved')
    memberBaseline.value = JSON.stringify([...memberIds.value].sort((a, b) => a - b))
    await loadList()
    emit('changed')
  } catch (e) { fail(e) } finally { busy.members = false }
}

async function saveRules(rules) {
  busy.rules = true
  rulesError.value = ''
  try {
    await api.saveRules(group.value.id, rules)
    ok('rules_saved')
    group.value = await api.getGroup(group.value.id)
    await loadList()
  } catch (e) {
    rulesError.value = e.message          // shown above the grid, which keeps the user's edits
  } finally { busy.rules = false }
}

onMounted(async () => {
  await Promise.all([loadList(), loadTables()])
})
</script>

<style scoped>
.gp { display: flex; gap: 1.25rem; padding: 0 1.5rem 1.5rem; min-height: 0; flex: 1; }
.gp-list { width: 250px; flex-shrink: 0; display: flex; flex-direction: column; gap: 0.75rem; }
.gp-items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.gp-items li {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.45rem 0.6rem; border-radius: 6px; cursor: pointer;
}
.gp-items li:hover { background: var(--p-content-hover-background, rgba(127,127,127,.08)); }
.gp-items li.active { background: var(--p-highlight-background, rgba(99,102,241,.14)); font-weight: 600; }
.gp-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gp-items small { color: var(--p-text-muted-color); }
.gp-none { color: var(--p-text-muted-color); font-size: 0.85rem; margin: 0; }
.gp-detail { flex: 1; min-width: 0; }
.gp-form { display: flex; flex-direction: column; gap: 0.85rem; }
.gp-form h4 { margin: 0.75rem 0 0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--p-text-muted-color); }
.gp-row { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; }
.gp-row label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.gp-grow { flex: 1; min-width: 180px; }
.gp-actions { display: flex; gap: 0.5rem; }

@media (max-width: 760px) {
  .gp { flex-direction: column; padding: 0 1rem 1rem; }
  .gp-list { width: 100%; }
}
</style>
