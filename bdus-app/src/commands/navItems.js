import { PRIVILEGE } from '@/commands/privilege'

/**
 * buildNavGroups — static navigation tree for the app shell.
 *
 * Single source of truth shared by the sidebar (AppLayout.vue) and the
 * command palette (useCommands.js), so the two never drift apart.
 *
 * minPrivilege mirrors the actual backend enforcement (see Authorization::can()
 * calls in the corresponding bdus-api controllers), so the UI never offers a
 * destination the backend will then reject. Three exceptions — app_log,
 * vocabulary_mng, assemblage_analysis — have NO privilege check at all on the
 * backend today; their minPrivilege here follows lib/Bdus/Router.php's
 * documented intent rather than actual enforcement (a real gap tracked
 * separately, not something this UI-only mapping can fix).
 */
export function buildNavGroups(app) {
  const a = `/${app}`
  return [
    {
      labelKey: 'nav_general',
      items: [
        { labelKey: 'dashboard', icon: 'pi-home', to: a, minPrivilege: PRIVILEGE.ENTER },
      ],
    },
    {
      labelKey: 'nav_data',
      items: [
        { labelKey: 'data_mng',             icon: 'pi-database',    to: `${a}/data`,            minPrivilege: PRIVILEGE.READER },
        { labelKey: 'files_mng',            icon: 'pi-images',      to: `${a}/files`,           minPrivilege: PRIVILEGE.READER },
        { labelKey: 'find_replace',         icon: 'pi-search-plus', to: `${a}/find-replace`,    minPrivilege: PRIVILEGE.ADMIN },
        { labelKey: 'vocabulary_mng',       icon: 'pi-book',        to: `${a}/vocabularies`,    minPrivilege: PRIVILEGE.READER },
        { labelKey: 'assemblage_analysis',  icon: 'pi-th-large',    to: `${a}/assemblages`,     minPrivilege: PRIVILEGE.READER },
        { labelKey: 'history',              icon: 'pi-history',     to: `${a}/history`,         minPrivilege: PRIVILEGE.READER },
        { labelKey: 'deleted_records',      icon: 'pi-trash',       to: `${a}/deleted-records`, minPrivilege: PRIVILEGE.READER },
        { labelKey: 'import_data',          icon: 'pi-upload',      to: `${a}/import`,          minPrivilege: PRIVILEGE.WRITER },
        { labelKey: 'backup',               icon: 'pi-save',        to: `${a}/backups`,         minPrivilege: PRIVILEGE.READER },
      ],
    },
    {
      labelKey: 'nav_admin',
      items: [
        { labelKey: 'user_mng',         icon: 'pi-users',   to: `${a}/users`,     minPrivilege: PRIVILEGE.ADMIN },
        { labelKey: 'sys_config',       icon: 'pi-cog',     to: `${a}/config`,    minPrivilege: PRIVILEGE.SUPER_ADMIN },
        { labelKey: 'design_templates', icon: 'pi-palette', to: `${a}/templates`, minPrivilege: PRIVILEGE.SUPER_ADMIN },
        { labelKey: 'free_sql',         icon: 'pi-code',    to: `${a}/free-sql`,  minPrivilege: PRIVILEGE.SUPER_ADMIN },
      ],
    },
    {
      labelKey: 'nav_system',
      items: [
        { labelKey: 'app_log',    icon: 'pi-list',        to: `${a}/log`,        minPrivilege: PRIVILEGE.ADMIN },
        { labelKey: 'migrations', icon: 'pi-database',    to: `${a}/migrations`, minPrivilege: PRIVILEGE.ADMIN },
        { labelKey: 'info',       icon: 'pi-info-circle', to: `${a}/info`,       minPrivilege: PRIVILEGE.READER },
      ],
    },
  ]
}
