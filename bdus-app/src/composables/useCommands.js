/**
 * useCommands — builds the flat list of entries shown in the command palette.
 *
 * Four kinds of entries, merged into one list:
 *   'nav'        — static destinations, sourced from buildNavGroups (same data
 *                  the sidebar renders, so the two never drift apart)
 *   'table'      — one per project table (from the already-cached useTables()),
 *                  doubles as the answer to "show me all records of table X"
 *   'action'     — simple, no-argument commands (dark mode, locale, logout, profile)
 *   'parametric' — commands that need a table as argument (e.g. "new record in…").
 *                  CommandPalette runs these in a second step: selecting one
 *                  narrows the list to that command's own argOptions, then
 *                  calls command.run(value) on the chosen option.
 *
 * Every entry is filtered against the current user's privilege_value using the
 * same thresholds the backend actually enforces (see commands/privilege.js and
 * commands/navItems.js) — nothing is offered here that the API would reject.
 */
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from '@/i18n'
import { useTables } from '@/composables/useTables'
import { useDarkMode } from '@/composables/useDarkMode'
import { useAuthStore } from '@/stores/auth'
import { buildNavGroups } from '@/commands/navItems'
import { PRIVILEGE, hasPrivilege } from '@/commands/privilege'

export function useCommands({ onOpenProfile } = {}) {
  const router  = useRouter()
  const route   = useRoute()
  const confirm = useConfirm()
  const auth    = useAuthStore()
  const { t, locale, setLocale, availableLocales } = useI18n()
  const { tables, loadTables } = useTables()
  const { isDark, toggle: toggleDark } = useDarkMode()

  loadTables() // no-op if the sidebar already loaded them this session

  const app = computed(() => route.params.app)

  function allowed(threshold) {
    return hasPrivilege(auth.user?.privilege_value, threshold)
  }

  const navCommands = computed(() => {
    void locale.value // re-run when locale changes so labels re-translate
    return buildNavGroups(app.value)
      .flatMap(g => g.items)
      .filter(i => allowed(i.minPrivilege))
      .map(i => ({
        id:    `nav:${i.to}`,
        type:  'nav',
        label: t(i.labelKey),
        icon:  i.icon,
        run:   () => router.push(i.to),
      }))
  })

  // Jumping straight to a table's data view is part of "Gestione dati",
  // so it follows the same READER threshold as that nav item.
  const tableCommands = computed(() => {
    if (!allowed(PRIVILEGE.READER)) return []
    return tables.value.map(tbl => ({
      id:        `table:${tbl.name}`,
      type:      'table',
      label:     tbl.label,
      tableName: tbl.name,
      icon:      'pi-table',
      run:       () => router.push(`/${app.value}/data?tb=${encodeURIComponent(tbl.name)}`),
    }))
  })

  function doLogout() {
    confirm.require({
      message:     t('logout_confirm_message'),
      header:      t('logout'),
      icon:        'pi pi-sign-out',
      severity:    'danger',
      rejectProps: { label: t('cancel'), severity: 'secondary', outlined: true },
      acceptProps: { label: t('logout'), severity: 'danger' },
      accept: async () => {
        await auth.logout()
        window.location.hash = '/login'
      },
    })
  }

  const actionCommands = computed(() => {
    void locale.value
    const list = [
      {
        id:    'action:dark-mode',
        type:  'action',
        label: isDark.value ? t('light_mode') : t('dark_mode'),
        icon:  isDark.value ? 'pi-sun' : 'pi-moon',
        run:   toggleDark,
      },
      ...availableLocales
        .filter(l => l.code !== locale.value)
        .map(l => ({
          id:    `action:locale:${l.code}`,
          type:  'action',
          label: `${l.flag} ${l.label}`,
          icon:  'pi-language',
          run:   () => setLocale(l.code),
        })),
      {
        id:    'action:logout',
        type:  'action',
        label: t('logout'),
        icon:  'pi-sign-out',
        run:   doLogout,
      },
    ]
    if (onOpenProfile) {
      list.push({
        id:    'action:profile',
        type:  'action',
        label: t('user_profile'),
        icon:  'pi-user-edit',
        run:   onOpenProfile,
      })
    }
    return list
  })

  // Builds the argOptions list for a table-argument parametric command:
  // one entry per table matching `tableFilter`, carrying the raw table name
  // as `value` (what gets passed to the command's run()).
  function tableArgOptions(tableFilter = () => true) {
    return tables.value.filter(tableFilter).map(tbl => ({
      id:    `arg:${tbl.name}`,
      label: tbl.label,
      icon:  'pi-table',
      value: tbl.name,
    }))
  }

  const parametricCommands = computed(() => {
    void locale.value
    const list = []

    // saveRecord() enforces 'edit' (writer, ≤20) regardless of ownership —
    // self_writer (25) can never actually save despite can_add reporting true
    // (see Record.php:687). Gate at WRITER so the command always works.
    if (allowed(PRIVILEGE.WRITER)) {
      list.push({
        id:         'parametric:new-record',
        type:       'parametric',
        label:      t('command_new_record_in'),
        icon:       'pi-plus',
        argOptions: tableArgOptions(),
        run:        (tableName) => router.push(`/${app.value}/record/${tableName}/new`),
      })
    }

    if (allowed(PRIVILEGE.READER)) {
      list.push({
        id:         'parametric:matrix',
        type:       'parametric',
        label:      t('command_open_matrix_for'),
        icon:       'pi-sitemap',
        argOptions: tableArgOptions(tbl => tbl.rs),
        run:        (tableName) => router.push(`/${app.value}/matrix/${tableName}`),
      })
      list.push({
        id:         'parametric:chrono',
        type:       'parametric',
        label:      t('command_open_chrono_for'),
        icon:       'pi-clock',
        argOptions: tableArgOptions(tbl => tbl.fuzzy_date),
        run:        (tableName) => router.push(`/${app.value}/chrono/${tableName}`),
      })
      list.push({
        id:         'parametric:geoface',
        type:       'parametric',
        label:      t('command_open_map_for'),
        icon:       'pi-map',
        argOptions: tableArgOptions(),
        run:        (tableName) => router.push(`/${app.value}/geoface/${tableName}`),
      })
    }

    return list
  })

  const commands = computed(() => [
    ...navCommands.value,
    ...tableCommands.value,
    ...actionCommands.value,
    ...parametricCommands.value,
  ])

  return { commands }
}
