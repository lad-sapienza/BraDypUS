import test from 'node:test'
import assert from 'node:assert/strict'
import { itemSchema, isItemHidden } from '../src/components/record/templateUtils.js'

const base = { name: 'stato', label: 'Stato', type: 'text', readonly: false }

test('itemSchema leaves the schema alone when the item has no options', () => {
  assert.deepEqual(itemSchema(base, { field: 'stato', width: '1/2' }), base)
  assert.deepEqual(itemSchema(base, null), base)
})

test('itemSchema: label overrides, empty or blank label keeps the original', () => {
  assert.equal(itemSchema(base, { label: 'State of the file' }).label, 'State of the file')
  assert.equal(itemSchema(base, { label: '' }).label, 'Stato')
  assert.equal(itemSchema(base, { label: '   ' }).label, 'Stato')
  assert.equal(itemSchema(base, { label: 5 }).label, 'Stato')
})

test('itemSchema: readonly only switches on, never off, and does not mutate the input', () => {
  assert.equal(itemSchema(base, { readonly: true }).readonly, true)
  assert.equal(itemSchema({ ...base, readonly: true }, { readonly: false }).readonly, true)
  assert.equal(itemSchema(base, { readonly: 'yes' }).readonly, false)
  itemSchema(base, { readonly: true, label: 'x' })
  assert.deepEqual(base, { name: 'stato', label: 'Stato', type: 'text', readonly: false })
})

test('isItemHidden is true only for hidden: true', () => {
  assert.equal(isItemHidden({ hidden: true }), true)
  assert.equal(isItemHidden({ hidden: false }), false)
  assert.equal(isItemHidden({ hidden: 'true' }), false)
  assert.equal(isItemHidden({}), false)
})
