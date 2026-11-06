// Pure helpers behind Config → Database objects and the table danger zone,
// kept out of the components so they can be tested without a browser.

/** Reasons the API gives for an object that cannot be activated (`reason`), with the locale key of each. */
export const DBO_REASONS = [
  'already_configured',
  'invalid_name',
  'missing_id_column',
  'missing_creator_column',
  'missing_in_database',
]

export const dboReasonKey = reason => `dbo_reason_${reason}`

/**
 * What a row of the Database objects list offers, from one entry of
 * GET /api/config/db-objects. Returns locale keys, not text.
 *
 *  - a deactivated table can be reactivated, have its configuration removed,
 *    and (tables only, never views) be dropped — the one irreversible step;
 *  - a `missing` entry (deactivated, but its database object is gone) can only
 *    have its saved configuration removed.
 */
export function dbObjectActions(o) {
  const inactive = !!o.inactive
  const kind     = o.kind
  return {
    preview:       kind !== 'missing',
    primary:       !!o.activatable,
    previewLabel:  inactive ? 'dbo_reactivate' : (o.activatable ? 'dbo_preview_activate' : 'dbo_details'),
    kindTag:       kind === 'missing' ? null : (kind === 'view' ? 'db_kind_view' : 'db_kind_table'),
    writableTag:   kind === 'view' ? (o.view_writable === 'none' ? 'view_read_only_badge' : 'view_writable_badge') : null,
    inactiveTag:   inactive,
    reasonKey:     o.activatable || !o.reason ? null : dboReasonKey(o.reason),
    removeConfig:  inactive,
    drop:          inactive && kind === 'table',
  }
}

/**
 * A plugin table, and a table that has plugins, cannot be deactivated on their
 * own (the server answers `table_not_deactivable`): the button is disabled.
 * `table` is the object of GET /api/config/table/{tb}.
 */
export function cannotDeactivate(table) {
  if (!table) return false
  const isPlugin = table.is_plugin === '1' || table.is_plugin === 1 || table.is_plugin === true
  const plugins  = (table.plugin ?? []).filter(Boolean)
  return isPlugin || plugins.length > 0
}

/** What the table settings and fields panels hide for a registered SQL view. */
export function viewRestrictions(isView) {
  return {
    canRename:       !isView,
    canAddField:     !isView,
    canEditStructure:!isView,
    showIndexes:     !isView,
    showSystemPlugins: !isView,
  }
}

/**
 * How a registered SQL view can be written, from its table config (`view_writable`
 * is `none` | `probe` | `trigger`). Anything but `none` means the database can write it.
 */
export function viewWriteState(table) {
  const level    = table?.view_writable || 'none'
  const writable = level !== 'none'
  return { level, writable, badgeKey: writable ? 'view_writable_badge' : 'view_read_only_badge' }
}
