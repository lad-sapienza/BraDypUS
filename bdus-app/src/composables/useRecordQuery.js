import { ref, computed, onScopeDispose } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from '@/i18n'
import { SEARCH, searchParams as buildSearchParams } from '@/utils/recordQuery'
import { emptyTree, cloneTree, countActive, formula, treeToFilter, serializeTree, restoreTree } from '@/utils/filterTree'

// The text search is a LIKE over every preview field: no point firing it for
// one letter, or on every keystroke.
const FAST_MIN_CHARS   = 2
const FAST_DEBOUNCE_MS = 300

/**
 * The record list's single query entry point: what the user is searching for,
 * how it is shown (input, Filters panel, chips) and how it survives in the URL.
 *
 * One search at a time — the text box, the query builder ('advanced'), raw SQL
 * ('expert') or a ready-made filter ('filter', from a link or a saved search).
 * Opening the Filters panel empties and disables the text box, and it stays
 * disabled while a filter is applied.
 *
 * @param {object} deps
 * @param {import('vue').Ref<{name: string}|null>} deps.table  the selected table
 * @param {ReturnType<import('./useSearchConfig').useSearchConfig>} deps.config
 * @param {() => void} deps.onApply     the query changed: go to page 1 and refetch
 * @param {() => {sortField: string|null, sortDir: string}} deps.getSort  for saved searches
 */
export function useRecordQuery({ table, config, onApply, getSort }) {
  const { t }  = useI18n()
  const route  = useRoute()
  const router = useRouter()

  // ── State ───────────────────────────────────────────────────────────────
  const activeSearch = ref(null)   // null | 'fast' | 'advanced' | 'expert' | 'filter'
  const openPanel    = ref(null)   // null | 'advanced' | 'expert' — the open Filters tab
  const fastSearch   = ref('')
  const expertQuery  = ref('')
  const activeFilter = ref(null)   // the JsonFilter object behind 'advanced' / 'filter'
  const advTree      = ref(emptyTree())   // the builder's draft (utils/filterTree)
  const appliedTree  = ref(null)          // the tree the current results come from

  /** The search part of the list request for the current mode. */
  const searchParams = computed(() => buildSearchParams({
    activeSearch: activeSearch.value,
    fastSearch:   fastSearch.value,
    expertQuery:  expertQuery.value,
    activeFilter: activeFilter.value,
  }))

  // ── What the bar shows ──────────────────────────────────────────────────
  const filtersOpen = computed(() => openPanel.value !== null)
  const inputLocked = computed(() =>
    filtersOpen.value || [SEARCH.ADVANCED, SEARCH.EXPERT, SEARCH.FILTER].includes(activeSearch.value)
  )

  /** Conditions behind the current results — the badge on the Filters button. */
  const appliedCount = computed(() => {
    if (activeSearch.value === SEARCH.ADVANCED) return appliedTree.value ? countActive(appliedTree.value) : 0
    if (activeSearch.value === SEARCH.EXPERT || activeSearch.value === SEARCH.FILTER) return 1
    return 0
  })

  const inputPlaceholder = computed(() => {
    if (filtersOpen.value) return t('qb_use_filters')
    if (activeSearch.value === SEARCH.ADVANCED) {
      return appliedCount.value === 1 ? t('qb_active_condition') : t('qb_active_conditions', { n: appliedCount.value })
    }
    if (activeSearch.value === SEARCH.EXPERT) return t('qb_active_sql')
    if (activeSearch.value === SEARCH.FILTER) return t('qb_active_linked')
    return t('fast_search')
  })

  const filtersTabs = computed(() => [
    { value: 'advanced', label: t('qb_tab_builder') },
    { value: 'expert',   label: t('qb_tab_sql') },
  ])

  /** Removable chips for what is applied; a group is one chip, with parentheses. */
  const chips = computed(() => {
    if (activeSearch.value === SEARCH.EXPERT) return [{ key: 'sql', label: `SQL: ${expertQuery.value}` }]
    if (activeSearch.value === SEARCH.FILTER) return [{ key: 'filter', label: t('linked_records') }]
    if (activeSearch.value !== SEARCH.ADVANCED || !appliedTree.value) return []
    const ctx = {
      fieldLabel:    fld => config.fields.value.find(f => f.value === fld)?.label ?? fld,
      operatorLabel: op  => (config.operatorOptions.value.find(o => o.value === op)?.label ?? op).toLowerCase(),
      and: t('qb_and'), or: t('qb_or'),
    }
    return appliedTree.value.c
      .map((child, index) => ({ child, index }))
      .filter(({ child }) => countActive(child) > 0)
      .map(({ child, index }) => {
        const text = formula(child, ctx)
        return { key: `n${index}`, index, label: child.t === 'g' && countActive(child) > 1 ? `(${text})` : text }
      })
  })
  const chipJoin = computed(() => (appliedTree.value?.op === 'OR' ? t('qb_or') : t('qb_and')))

  // ── Saved searches / charts: the payload for what is applied ────────────
  /** The applied search as a stored/forwardable payload, or null when there is none worth keeping. */
  const currentSearch = computed(() => {
    const { sortField, sortDir } = getSort()
    const sort = { sort_field: sortField ?? '', sort_dir: sortDir }
    if (activeSearch.value === SEARCH.ADVANCED) {
      return activeFilter.value ? { filter: activeFilter.value, ...sort } : null   // what is applied, not the draft
    }
    if (activeSearch.value === SEARCH.EXPERT && expertQuery.value.trim()) {
      return { search_type: 'sqlExpert', querytext: expertQuery.value, join: '', ...sort }
    }
    return null
  })

  // ── URL persistence ─────────────────────────────────────────────────────
  // The last params we applied, to ignore the route change our own
  // router.replace() causes (the guard compares all four).
  let applied = { tb: null, filter: null, qt: null, q: null }

  /**
   * Push the current search into the URL (bookmarks, back-navigation) with
   * router.replace — no history entry. Pre-sets `applied` so the watcher's
   * guard skips the resulting change.
   */
  function updateUrl(type, value) {
    const query = { tb: route.query.tb }
    if (route.query.filter) query.filter = route.query.filter   // keep a link filter while switching
    if (type && value != null) { query.qt = type; query.q = value }
    applied.qt = query.qt ?? null
    applied.q  = query.q  ?? null
    router.replace({ query })
  }

  // ── Actions ─────────────────────────────────────────────────────────────
  let fastTimer = null
  onScopeDispose(() => clearTimeout(fastTimer))

  function reset() {
    clearTimeout(fastTimer)
    fastSearch.value   = ''
    expertQuery.value  = ''
    activeFilter.value = null
    advTree.value      = emptyTree()
    appliedTree.value  = null
    activeSearch.value = null
    openPanel.value    = null
    applied.filter     = null
    router.replace({ query: { tb: route.query.tb } })   // clears filter and qt/q
    onApply()
  }

  /** Text box: runs while typing (debounced, from 2 characters); emptying it clears the search. */
  function onFastInput() {
    clearTimeout(fastTimer)
    const v = (fastSearch.value ?? '').trim()
    if (!v) {
      if (activeSearch.value === SEARCH.FAST) reset()
      return
    }
    if (v.length < FAST_MIN_CHARS) return
    fastTimer = setTimeout(runFast, FAST_DEBOUNCE_MS)
  }

  /** Enter: no waiting. */
  function onFastEnter() {
    clearTimeout(fastTimer)
    runFast()
  }

  function runFast() {
    if (!fastSearch.value.trim()) return reset()
    activeSearch.value = SEARCH.FAST
    openPanel.value    = null
    updateUrl(SEARCH.FAST, fastSearch.value)
    onApply()
  }

  /** The builder's tree as a JsonFilter (null when no condition is complete). */
  function buildAdvFilter() {
    return treeToFilter(advTree.value, {
      mainTb: table.value?.name,
      fieldMeta: fld => config.fields.value.find(f => f.value === fld),
    })
  }

  function runAdvanced() {
    const filter = buildAdvFilter()
    if (!filter) return reset()
    activeFilter.value = filter
    appliedTree.value  = cloneTree(advTree.value)
    fastSearch.value   = ''
    activeSearch.value = SEARCH.ADVANCED
    openPanel.value    = null
    updateUrl(SEARCH.ADVANCED, JSON.stringify({ tree: serializeTree(advTree.value), filter }))
    onApply()
  }

  function runExpert() {
    if (!expertQuery.value.trim()) return reset()
    fastSearch.value   = ''
    activeSearch.value = SEARCH.EXPERT
    openPanel.value    = null
    updateUrl(SEARCH.EXPERT, expertQuery.value)
    onApply()
  }

  /** Apply the open tab. */
  function apply() {
    return openPanel.value === 'advanced' ? runAdvanced() : runExpert()
  }

  async function openFilters() {
    // The text box and the filters are alternatives: a running text search ends.
    if (activeSearch.value === SEARCH.FAST) reset()
    fastSearch.value = ''
    // Start from what is applied, dropping an abandoned draft.
    if (appliedTree.value) advTree.value = cloneTree(appliedTree.value)
    openPanel.value = activeSearch.value === SEARCH.EXPERT ? 'expert' : 'advanced'
    if (openPanel.value === 'advanced') await config.load()
  }

  function closeFilters() {
    openPanel.value = null
    if (appliedTree.value) advTree.value = cloneTree(appliedTree.value)
  }

  function toggleFilters() {
    return filtersOpen.value ? closeFilters() : openFilters()
  }

  async function setTab(tab) {
    openPanel.value = tab
    if (tab === 'advanced') await config.load()
  }

  /** Chip ×: drop one top-level condition or group, and run what is left. */
  function removeChip(chip) {
    if (chip.index === undefined) return reset()   // the SQL / linked-records chips
    const tree = cloneTree(appliedTree.value)
    tree.c.splice(chip.index, 1)
    if (countActive(tree) === 0) return reset()
    advTree.value = tree
    runAdvanced()
  }

  /**
   * Run a stored search (SavedQueriesPanel).
   * @returns {boolean} whether the payload was something we can run
   */
  function loadSaved(payload) {
    if (!payload?.search_type && !payload?.filter) return false
    if (payload.search_type === 'sqlExpert' && payload.querytext) {
      expertQuery.value  = payload.querytext
      fastSearch.value   = ''
      activeSearch.value = SEARCH.EXPERT
      openPanel.value    = null
      updateUrl(SEARCH.EXPERT, payload.querytext)
      onApply()
      return true
    }
    if (payload.filter && typeof payload.filter === 'object') {
      activeFilter.value = payload.filter
      fastSearch.value   = ''
      activeSearch.value = SEARCH.FILTER
      openPanel.value    = null
      updateUrl(SEARCH.FILTER, JSON.stringify(payload.filter))
      onApply()
      return true
    }
    return false
  }

  /**
   * Apply `tb`, `filter`, `qt` and `q` from the URL: on mount, and whenever the
   * route query changes. `filter` is a JsonFilter object (links from a record);
   * `qt`/`q` carry the text, builder and SQL searches.
   *
   * @param {object} opts
   * @param {Array<{name: string}>} opts.tables         the tables the user can see
   * @param {(tb: string) => void}   opts.onTableChanged opened another table: restore its columns, reload its config
   */
  function syncFromRoute({ tables, onTableChanged }) {
    const tb     = route.query.tb
    const filter = route.query.filter ?? null
    const qt     = route.query.qt ?? null
    const q      = route.query.q  ?? null

    if (!tb) return
    if (tb === applied.tb && filter === applied.filter && qt === applied.qt && q === applied.q) return
    if (!tables.some(tbl => tbl.name === tb)) return   // not allowed, or a typo

    const tableChanged = applied.tb !== tb
    applied = { tb, filter, qt, q }
    if (tableChanged) onTableChanged(tb)

    if (filter) {
      try { activeFilter.value = JSON.parse(filter) } catch { activeFilter.value = null }
      activeSearch.value = SEARCH.FILTER
      openPanel.value    = null
      onApply()
      return
    }

    if (qt && q != null) {
      if (qt === 'fast') {
        fastSearch.value   = q
        activeSearch.value = SEARCH.FAST
        openPanel.value    = null
      } else if (qt === 'expert') {
        expertQuery.value  = q
        fastSearch.value   = ''
        activeSearch.value = SEARCH.EXPERT
        openPanel.value    = null
      } else if (qt === 'advanced') {
        try {
          const parsed = JSON.parse(q)
          advTree.value      = restoreTree(parsed)   // the new { tree }, or the previous { rows }
          appliedTree.value  = cloneTree(advTree.value)
          activeFilter.value = parsed.filter ?? null
        } catch { activeFilter.value = null; appliedTree.value = null }
        fastSearch.value   = ''
        activeSearch.value = SEARCH.ADVANCED
        openPanel.value    = null
        config.load()
      } else if (qt === 'filter') {
        try { activeFilter.value = JSON.parse(q) } catch { activeFilter.value = null }
        activeSearch.value = SEARCH.FILTER
        openPanel.value    = null
      } else {
        return
      }
      onApply()
      return
    }

    if (tableChanged) reset()   // a fresh table: no search carried over (also refetches)
  }

  return {
    // state
    activeSearch, openPanel, fastSearch, expertQuery, activeFilter, advTree, appliedTree,
    // derived
    searchParams, filtersOpen, inputLocked, appliedCount, inputPlaceholder, filtersTabs,
    chips, chipJoin, currentSearch,
    // actions
    onFastInput, onFastEnter, apply, reset, openFilters, closeFilters, toggleFilters,
    setTab, removeChip, loadSaved, syncFromRoute,
  }
}
