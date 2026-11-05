// Run with: npm test   (node's built-in runner — no dependencies)
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { DBO_REASONS, dboReasonKey, dbObjectActions, cannotDeactivate, viewRestrictions } from '../src/utils/dbObjects.js'

const table = (over = {}) => ({ name: 'sites', kind: 'table', activatable: true, inactive: false, reason: null, view_writable: null, ...over })
const view  = (over = {}) => ({ name: 'v', kind: 'view', activatable: true, inactive: false, reason: null, view_writable: 'none', ...over })

test('a new table offers preview and activate, and nothing destructive', () => {
  const a = dbObjectActions(table())
  assert.equal(a.previewLabel, 'dbo_preview_activate')
  assert.equal(a.primary, true)
  assert.equal(a.kindTag, 'db_kind_table')
  assert.equal(a.writableTag, null)
  assert.equal(a.removeConfig, false)
  assert.equal(a.drop, false)
  assert.equal(a.reasonKey, null)
})

test('an object that cannot be activated says why and is not the primary action', () => {
  const a = dbObjectActions(table({ activatable: false, reason: 'missing_id_column' }))
  assert.equal(a.previewLabel, 'dbo_details')
  assert.equal(a.primary, false)
  assert.equal(a.reasonKey, 'dbo_reason_missing_id_column')
})

test('views show read-only or writable, and are never droppable', () => {
  assert.equal(dbObjectActions(view()).writableTag, 'view_read_only_badge')
  assert.equal(dbObjectActions(view({ view_writable: 'probe' })).writableTag, 'view_writable_badge')
  assert.equal(dbObjectActions(view({ view_writable: 'trigger' })).writableTag, 'view_writable_badge')
  const deactivated = dbObjectActions(view({ inactive: true }))
  assert.equal(deactivated.removeConfig, true)
  assert.equal(deactivated.drop, false, 'a view is never dropped')
})

test('a deactivated table can be reactivated, unregistered or — only then — dropped', () => {
  const a = dbObjectActions(table({ inactive: true }))
  assert.equal(a.previewLabel, 'dbo_reactivate')
  assert.equal(a.inactiveTag, true)
  assert.equal(a.removeConfig, true)
  assert.equal(a.drop, true)
})

test('a deactivated table whose database object is gone can only be unregistered', () => {
  const a = dbObjectActions({ name: 'gone', kind: 'missing', inactive: true, activatable: false, reason: 'missing_in_database' })
  assert.equal(a.preview, false)
  assert.equal(a.kindTag, null)
  assert.equal(a.removeConfig, true)
  assert.equal(a.drop, false)
  assert.equal(a.reasonKey, 'dbo_reason_missing_in_database')
})

test('plugin tables and tables with plugins cannot be deactivated alone', () => {
  assert.equal(cannotDeactivate(null), false)
  assert.equal(cannotDeactivate({ is_plugin: '', plugin: [''] }), false, 'the form pads plugin with an empty string')
  assert.equal(cannotDeactivate({ is_plugin: '', plugin: [] }), false)
  assert.equal(cannotDeactivate({ is_plugin: '1', plugin: [] }), true)
  assert.equal(cannotDeactivate({ is_plugin: '', plugin: ['sites_radiocarbon'] }), true)
})

test('a view hides what the database owns', () => {
  const v = viewRestrictions(true)
  for (const k of Object.keys(v)) assert.equal(v[k], false, k)
  const t = viewRestrictions(false)
  for (const k of Object.keys(t)) assert.equal(t[k], true, k)
})

// ── every string the new screens show exists in both languages ───────────
const locale = lang => JSON.parse(readFileSync(new URL(`../src/locale/${lang}.json`, import.meta.url), 'utf8'))
const en = locale('en')
const it = locale('it')

const componentKeys = file => {
  const src = readFileSync(new URL(`../src/components/config/${file}`, import.meta.url), 'utf8')
  return [...src.matchAll(/\bt\('([a-z0-9_]+)'\)/g)].map(m => m[1])
}

test('every locale key used by the new/changed config screens exists in en and it', () => {
  const keys = new Set([
    ...componentKeys('ConfigDbObjects.vue'),
    ...componentKeys('ConfigTableForm.vue'),
    ...componentKeys('ConfigFieldList.vue'),
    ...componentKeys('ConfigSidebar.vue'),
    ...DBO_REASONS.map(dboReasonKey),
    // codes the API answers for these operations, shown by api.responseMessage()
    'view_not_alterable', 'view_read_only', 'table_deactivated', 'table_reactivated', 'table_not_deactivable',
    'db_object_activated', 'not_found', 'db_error', 'parameter_missing', 'tb_already_available',
    // direct error codes of POST /api/config/db-objects/{name}/activate and of the DBML preview
    ...DBO_REASONS.filter(r => r !== 'missing_in_database'), 'view_not_found', 'not_a_view',
    // keys returned by dbObjectActions()
    'dbo_reactivate', 'dbo_preview_activate', 'dbo_details', 'db_kind_view', 'db_kind_table',
    'view_read_only_badge', 'view_writable_badge',
  ])
  const missing = lang => [...keys].filter(k => !(k in locale(lang)))
  assert.deepEqual(missing('en'), [], 'missing in en.json')
  assert.deepEqual(missing('it'), [], 'missing in it.json')
})

test('the new keys are translated, not copied from English', () => {
  for (const k of ['db_objects', 'deactivate_table', 'remove_from_config', 'dbo_reactivate', 'view_read_only']) {
    assert.notEqual(it[k], en[k], k)
  }
})
