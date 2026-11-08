import test from 'node:test'
import assert from 'node:assert/strict'
import { layoutsToRows, rowsToLayouts, resolveTemplateChoice } from '../src/utils/groupLayouts.js'

test('layouts round-trip through rows', () => {
  const layouts = { items: ['a', 'b'], tags: ['c'] }
  assert.deepEqual(rowsToLayouts(layoutsToRows(layouts)), { layouts })
  assert.deepEqual(layoutsToRows(null), [])
})

test('rowsToLayouts: empty rows ignored, bad rows reported', () => {
  assert.deepEqual(rowsToLayouts([{ table: '', templates: [] }]), { layouts: {} })
  assert.deepEqual(rowsToLayouts([{ table: '', templates: ['a'] }]), { error: 'grp_layout_no_table' })
  assert.deepEqual(rowsToLayouts([{ table: 'items', templates: [] }]), { error: 'grp_layout_no_template' })
  assert.deepEqual(
    rowsToLayouts([{ table: 'items', templates: ['a'] }, { table: 'items', templates: ['b'] }]),
    { error: 'grp_layout_duplicate' }
  )
  assert.deepEqual(rowsToLayouts([{ table: 'items', templates: ['a', 'a'] }]), { layouts: { items: ['a'] } })
})

test('no assignment: behaviour as before (default offered, saved choice kept)', () => {
  const res = { templates: ['a', 'b'], assigned: false, locked: false }
  assert.deepEqual(resolveTemplateChoice(res, 'b'), { templates: ['a', 'b'], selected: 'b', allowDefault: true, showSelect: true })
  assert.equal(resolveTemplateChoice(res, null).selected, null)
  assert.equal(resolveTemplateChoice(res, 'gone').selected, null)
  assert.equal(resolveTemplateChoice({ templates: [] }, null).showSelect, false)
})

test('one assigned layout: forced, no menu, even against a saved choice', () => {
  const r = resolveTemplateChoice({ templates: ['a'], assigned: true, locked: true }, 'x')
  assert.deepEqual(r, { templates: ['a'], selected: 'a', allowDefault: false, showSelect: false })
})

test('several assigned layouts: menu without default, first one when nothing valid saved', () => {
  const res = { templates: ['a', 'b'], assigned: true, locked: false }
  assert.deepEqual(resolveTemplateChoice(res, null), { templates: ['a', 'b'], selected: 'a', allowDefault: false, showSelect: true })
  assert.equal(resolveTemplateChoice(res, 'b').selected, 'b')
  assert.equal(resolveTemplateChoice(res, 'gone').selected, 'a')
})
