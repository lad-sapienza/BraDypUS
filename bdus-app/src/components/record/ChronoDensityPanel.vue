<template>
  <fieldset v-if="!loading && sources.length > 0" class="record-section">
    <legend>{{ t('chrono_density') }}</legend>

    <div class="density-wrapper">
      <!-- Global extent axis -->
      <div class="density-axis">
        <span>{{ formatYear(extent.min) }}</span>
        <span class="density-axis-mid">{{ formatYear(extent.mid) }}</span>
        <span>{{ formatYear(extent.max) }}</span>
      </div>

      <!-- One density row per related table -->
      <div v-for="src in sources" :key="src.tb_id" class="density-row">
        <div class="density-label" :title="src.tb_label">{{ src.tb_label }}</div>

        <div class="density-col">
          <router-link
            :to="`/${app}/data?tb=${src.tb_id}&filter=${encodeFilter(src)}`"
            class="density-bar-link"
          >
            <div class="density-bar">
              <div
                v-for="(bin, i) in src.bins"
                :key="i"
                class="density-bin"
                :style="binStyle(bin, src.maxCount, src.color)"
              />
            </div>
          </router-link>
          <div v-if="src.peakFrom !== null" class="peak-axis">
            <span class="peak-mark" :style="{ left: peakToPercent(src.peakFrom) + '%' }">
              {{ formatYear(src.peakFrom) }}
            </span>
            <span class="peak-mark" :style="{ left: peakToPercent(src.peakTo) + '%' }">
              {{ formatYear(src.peakTo) }}
            </span>
          </div>
        </div>

        <Tag :value="String(src.records.length)" severity="secondary" rounded class="density-count" />
      </div>
    </div>
  </fieldset>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Tag from 'primevue/tag'
import { useRoute } from 'vue-router'
import { useI18n } from '@/i18n'
import { api } from '@/api'

const { t } = useI18n()
const route = useRoute()

const props = defineProps({
  tb: { type: String, required: true },
  id: { type: [Number, String], required: true },
})

const COLORS = ['#3b82f6', '#f97316', '#22c55e', '#a855f7', '#ef4444', '#14b8a6', '#eab308', '#ec4899']
const N_BINS = 60

const loading    = ref(false)
const rawSources = ref([])

const app = computed(() => route.params.app)

async function fetchData() {
  if (!props.id || props.id <= 0) return
  loading.value = true
  rawSources.value = []
  try {
    const res = await api.get(`/api/chrono/related/${props.tb}/${props.id}`)
    rawSources.value = res?.sources ?? []
  } finally {
    loading.value = false
  }
}

watch(() => [props.tb, props.id], fetchData, { immediate: true })

// ── Global time extent across all sources ─────────────────────────────────────

const extent = computed(() => {
  let min = Infinity, max = -Infinity
  for (const src of rawSources.value) {
    for (const r of src.records) {
      if (r.from != null && r.from < min) min = r.from
      if (r.to   != null && r.to   > max) max = r.to
    }
  }
  if (!isFinite(min)) min = 0
  if (!isFinite(max)) max = 0
  if (min === max) { min -= 50; max += 50 }
  return { min, max, mid: Math.round((min + max) / 2) }
})

// ── Per-source density bins ───────────────────────────────────────────────────

const sources = computed(() =>
  rawSources.value.map((src, idx) => {
    const { min, max } = extent.value
    const range = max - min || 1
    const bins = Array.from({ length: N_BINS }, (_, i) => {
      const s = min + (i / N_BINS) * range
      const e = min + ((i + 1) / N_BINS) * range
      const count = src.records.reduce((n, r) => {
        const f = r.from ?? min
        const to = r.to  ?? max
        return n + (f <= e && to >= s ? 1 : 0)
      }, 0)
      return { count }
    })
    const maxCount = Math.max(...bins.map(b => b.count), 0)

    // Peak: contiguous plateau of bins at maxCount
    let peakFrom = null, peakTo = null
    if (maxCount > 0) {
      const first = bins.findIndex(b => b.count === maxCount)
      let last = first
      for (let j = first + 1; j < N_BINS; j++) {
        if (bins[j].count === maxCount) last = j
        else break
      }
      peakFrom = Math.round(min + (first / N_BINS) * range)
      peakTo   = Math.round(min + ((last + 1) / N_BINS) * range)
    }

    return { ...src, color: COLORS[idx % COLORS.length], bins, maxCount, peakFrom, peakTo }
  })
)

function peakToPercent(year) {
  const { min, max } = extent.value
  return ((year - min) / (max - min || 1) * 100).toFixed(1)
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatYear(y) {
  if (y == null) return '?'
  return y < 0 ? `${Math.abs(y)} ${t('bce')}` : `${y} ${t('ce')}`
}

function binStyle(bin, maxCount, color) {
  if (bin.count === 0 || maxCount === 0) return { background: 'transparent' }
  return { background: color, opacity: 0.15 + 0.85 * (bin.count / maxCount) }
}

function encodeFilter(src) {
  return encodeURIComponent(JSON.stringify({ [src.fk_col]: { _eq: props.id } }))
}
</script>

<style scoped>
.density-wrapper   { display: flex; flex-direction: column; gap: .35rem; }

.density-axis      { display: flex; justify-content: space-between; font-size: .68rem; color: var(--p-text-muted-color); }
.density-axis-mid  { opacity: .6; }

.density-row       { display: flex; align-items: center; gap: .4rem; }
.density-label     {
  font-size: .72rem;
  min-width: 4.5rem;
  max-width: 4.5rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.density-col       { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.density-bar-link  { text-decoration: none; }
.density-bar-link:hover .density-bar { outline: 1px solid var(--p-primary-color); border-radius: 3px; }

.peak-axis  { position: relative; height: 13px; padding-top: 3px; }
.peak-mark  {
  position: absolute;
  transform: translateX(-50%);
  font-size: 0.58rem;
  color: var(--p-primary-color);
  font-weight: 700;
  white-space: nowrap;
  line-height: 1;
}

.density-bar       { height: 14px; display: flex; border-radius: 3px; overflow: hidden; background: var(--p-surface-100); }
.density-bin       { flex: 1; }
.density-count     { font-size: .65rem; flex-shrink: 0; }
</style>
