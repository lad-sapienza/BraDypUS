<template>
  <fieldset class="record-section rs-section">
    <legend>
      {{ t('rs') }}
      <button
        v-if="selfId"
        class="rs-matrix-link"
        :title="t('open_matrix')"
        @click="openMatrix"
      >
        <i class="pi pi-share-alt" />
      </button>
    </legend>

    <!-- Empty state -->
    <div v-if="!selfId" class="rs-no-id">
      <i class="pi pi-info-circle" />
      {{ t('rs_identifier') }}: —
    </div>

    <template v-else>

      <!-- ── Harris Matrix 3-row grid ────────────────────────────── -->
      <div class="rs-grid">

        <!-- Row 1: relations 1–4 (above / cuts / carries / fills) -->
        <div class="rs-cell r1">
          <div class="rs-cell-label">{{ t('is_covered_by') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[1]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[1]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r2">
          <div class="rs-cell-label">{{ t('is_cut_by') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[2]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[2]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r3">
          <div class="rs-cell-label">{{ t('carries') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[3]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[3]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r4">
          <div class="rs-cell-label">{{ t('is_filled_by') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[4]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[4]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <!-- Row 2: symmetric + self node -->
        <div class="rs-cell r9">
          <div class="rs-cell-label">{{ t('is_the_same_as') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[9]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[9]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell rs-self">
          <span class="rs-self-label">{{ selfId }}</span>
        </div>

        <div class="rs-cell r10">
          <div class="rs-cell-label">{{ t('is_bound_to') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[10]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[10]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <!-- Row 3: relations 5–8 (below / cut by / leans / fills) -->
        <div class="rs-cell r5">
          <div class="rs-cell-label">{{ t('covers') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[5]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[5]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r6">
          <div class="rs-cell-label">{{ t('cuts') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[6]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[6]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r7">
          <div class="rs-cell-label">{{ t('leans_against') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[7]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[7]?.length" class="rs-empty">—</span>
          </div>
        </div>

        <div class="rs-cell r8">
          <div class="rs-cell-label">{{ t('fills') }}</div>
          <div class="rs-cell-items">
            <div v-for="item in byRelation[8]" :key="item.rowId" class="rs-item">
              <span class="rs-ident">{{ item.other }}</span>
              <button
                v-if="mode === 'edit'"
                class="rs-delete-btn"
                :title="t('delete')"
                @click="deleteRelation(item.rowId)"
              ><i class="pi pi-times" /></button>
            </div>
            <span v-if="!byRelation[8]?.length" class="rs-empty">—</span>
          </div>
        </div>

      </div><!-- /.rs-grid -->

      <!-- ── Add relation form (edit mode only) ──────────────────── -->
      <div v-if="mode === 'edit'" class="rs-add-form">
        <Select
          v-model="newRelation"
          :options="relationOptions"
          optionLabel="label"
          optionValue="value"
          :placeholder="t('rs_select_relation')"
          class="rs-add-select"
        />
        <InputText
          v-model="newOther"
          :placeholder="t('rs_identifier')"
          class="rs-add-input"
          @keyup.enter="addRelation"
        />
        <Button
          :label="t('rs_add_relation')"
          icon="pi pi-plus"
          size="small"
          :loading="adding"
          :disabled="!newRelation || !newOther.trim()"
          @click="addRelation"
        />
      </div>

    </template>
  </fieldset>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter }  from 'vue-router'
import { useToast }   from 'primevue/usetoast'
import Select         from 'primevue/select'
import InputText      from 'primevue/inputtext'
import Button         from 'primevue/button'
import { api }                from '@/api'
import { useI18n }           from '@/i18n'
import { REL_KEYS, buildRelationOptions } from '@/composables/useRsRelations'

const { t }  = useI18n()
const toast  = useToast()
const route  = useRoute()
const router = useRouter()

const props = defineProps({
  /** Raw RS object from getRecord(): { "rowId": {id, first, second, relation} } */
  rs:     { type: Object, default: () => ({}) },
  /** Table schema (needs schema.rs_field) */
  schema: { type: Object, required: true },
  /** Core data object (needs [rs_field].val for selfId) */
  core:   { type: Object, required: true },
  /** 'read' | 'edit' */
  mode:   { type: String, default: 'read' },
  /** Full table id (e.g. 'myapp__su') */
  tb:        { type: String, required: true },
  /** Database primary key of the current record (for "back to record" link) */
  record_id: { type: [Number, String], default: null },
})

const emit = defineEmits(['rs-updated'])

// ── Identity ─────────────────────────────────────────────────────
/** The identifier value of the current record in the RS domain. */
const selfId = computed(() => {
  const fld = props.schema.rs_field
  if (!fld) return null
  const v = props.core[fld]
  return (v?.val_label ?? v?.val ?? v) || null
})

// ── Matrix navigation ─────────────────────────────────────────────
/** Open MatrixView filtered to this record, with highlight and back-link. */
function openMatrix() {
  if (!selfId.value) return
  router.push({
    path:  `/${route.params.app}/matrix/${encodeURIComponent(props.tb)}`,
    query: {
      filter:    JSON.stringify({ [props.schema.rs_field]: { _eq: selfId.value } }),
      highlight: String(selfId.value),
      ...(props.record_id != null ? { from_id: String(props.record_id) } : {}),
    },
  })
}

// ── Polarity inversion ────────────────────────────────────────────
/**
 * Given a raw RS row and the current record's identifier, return the
 * display relation slot (1-10) and the other party's identifier.
 *
 * When the current record is `first`, the relation is stored as-is.
 * When it is `second`, the relation is inverted:
 *   relations 1-4 ↔ 5-8  (each pair offset by 4)
 *   relations 9, 10 are symmetric — no inversion needed.
 */
function resolveDisplay(row) {
  const self = String(selfId.value)
  const rel  = parseInt(row.relation, 10)

  if (String(row.first) === self) {
    return { displayRel: rel, other: String(row.second), rowId: parseInt(row.id, 10) }
  }
  // current record is `second`
  let displayRel = rel
  if (rel >= 1 && rel <= 4) displayRel = rel + 4
  else if (rel >= 5 && rel <= 8) displayRel = rel - 4
  // 9, 10 stay as-is
  return { displayRel, other: String(row.first), rowId: parseInt(row.id, 10) }
}

/** RS rows grouped by display relation slot (1-10). */
const byRelation = computed(() => {
  const map = {}
  for (let i = 1; i <= 10; i++) map[i] = []

  for (const row of Object.values(props.rs)) {
    const { displayRel, other, rowId } = resolveDisplay(row)
    if (map[displayRel]) map[displayRel].push({ other, rowId })
  }
  return map
})

// ── Relation options for Select ───────────────────────────────────
const relationOptions = computed(() => buildRelationOptions(t))

// ── Add relation ──────────────────────────────────────────────────
const newRelation = ref(null)
const newOther    = ref('')
const adding      = ref(false)

async function addRelation() {
  if (!newRelation.value || !newOther.value.trim() || !selfId.value) return
  adding.value = true
  try {
    const res = await api.post(`/api/record/${props.tb}/rs`, {
      first:    String(selfId.value),
      relation: newRelation.value,
      second:   newOther.value.trim(),
    })
    if (res.status === 'success') {
      newOther.value    = ''
      newRelation.value = null
      emit('rs-updated')
    } else {
      toast.add({
        severity: 'warn',
        summary:  t('rs'),
        detail:   t(res.code ?? 'error'),
        life:     4000,
      })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('rs'), detail: t('error'), life: 4000 })
  } finally {
    adding.value = false
  }
}

// ── Delete relation ───────────────────────────────────────────────
async function deleteRelation(rowId) {
  try {
    const res = await api.delete(`/api/rs/${rowId}`)
    if (res.status === 'success') {
      emit('rs-updated')
    } else {
      toast.add({
        severity: 'warn',
        summary:  t('rs'),
        detail:   t(res.code ?? 'error'),
        life:     4000,
      })
    }
  } catch {
    toast.add({ severity: 'error', summary: t('rs'), detail: t('error'), life: 4000 })
  }
}
</script>

<style scoped>
.rs-section legend {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.rs-matrix-link {
  background: none;
  border: none;
  cursor: pointer;
  padding: 0 0.1rem;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
  line-height: 1;
  transition: color 0.15s;
}
.rs-matrix-link:hover { color: var(--p-primary-color); }

.rs-no-id {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

/* ── Grid layout ─────────────────────────────────────────────── */
.rs-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  grid-template-rows: auto auto auto;
  gap: 2px;
  background: var(--p-surface-border);
  border: 1px solid var(--p-surface-border);
  border-radius: 6px;
  overflow: hidden;
}

.rs-cell {
  background: var(--p-surface-card);
  padding: 0.5rem 0.6rem;
  min-height: 3.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

/* Row 1: slots 1-4 */
.r1  { grid-column: 1; grid-row: 1; }
.r2  { grid-column: 2; grid-row: 1; }
.r3  { grid-column: 3; grid-row: 1; }
.r4  { grid-column: 4; grid-row: 1; }

/* Row 2: slot 9, self (span 2), slot 10 */
.r9      { grid-column: 1;   grid-row: 2; }
.rs-self { grid-column: 2/4; grid-row: 2; display: flex; align-items: center; justify-content: center; background: var(--p-primary-50); }
.r10     { grid-column: 4;   grid-row: 2; }

/* Row 3: slots 5-8 */
.r5  { grid-column: 1; grid-row: 3; }
.r6  { grid-column: 2; grid-row: 3; }
.r7  { grid-column: 3; grid-row: 3; }
.r8  { grid-column: 4; grid-row: 3; }

.rs-cell-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-surface-border);
  padding-bottom: 0.2rem;
  margin-bottom: 0.15rem;
}

.rs-cell-items {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  flex: 1;
}

.rs-self-label {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--p-primary-color);
}

.rs-empty {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.rs-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.25rem;
}

.rs-ident {
  font-size: 0.8rem;
  font-weight: 500;
}

.rs-delete-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 0 0.1rem;
  color: var(--p-red-400);
  font-size: 0.7rem;
  line-height: 1;
  opacity: 0.7;
  transition: opacity 0.15s;
  flex-shrink: 0;
}
.rs-delete-btn:hover { opacity: 1; }

/* ── Add form ─────────────────────────────────────────────────── */
.rs-add-form {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
  flex-wrap: wrap;
}

.rs-add-select { min-width: 180px; }
.rs-add-input  { flex: 1; min-width: 120px; }
</style>
