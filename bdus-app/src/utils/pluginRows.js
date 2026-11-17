/**
 * Plugin rows as the record page edits them.
 */

// Columns the server manages itself: never part of an edited row.
const SYSTEM_KEYS = ['id', 'table_link', 'id_link', 'sort']

/**
 * The values of a plugin row that can be sent back on save.
 *
 * A row read from the server carries more than the plugin's own fields: for each
 * lookup field (`id_from_tb`) it also carries the fields of the referenced record,
 * under their plain names. Sending those back is refused by the server
 * ("unknown_field"), so only the fields of the plugin's schema are kept. With no
 * schema at all, every non-system key is kept (nothing is known to filter by).
 *
 * @param {Object<string,{val:any}>} row         row as read from the server
 * @param {Array<{name:string}>|undefined} schemaFields  fields of the plugin table
 * @returns {Object<string,any>}
 */
export function editableRowFields(row, schemaFields) {
  const allowed = Array.isArray(schemaFields) && schemaFields.length
    ? new Set(schemaFields.map(f => f.name))
    : null
  const fields = {}
  for (const k of Object.keys(row)) {
    if (SYSTEM_KEYS.includes(k)) continue
    if (allowed && !allowed.has(k)) continue
    fields[k] = row[k]?.val ?? null
  }
  return fields
}
