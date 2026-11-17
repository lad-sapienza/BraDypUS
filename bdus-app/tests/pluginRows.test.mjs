import test from 'node:test'
import assert from 'node:assert/strict'
import { editableRowFields } from '../src/utils/pluginRows.js'

// graves_bibliography.graves_id -> graves: the server adds the graves fields to the row
const row = {
  id:         { val: 7 },
  id_link:    { val: 3 },
  graves_id:  { val: 3 },
  zotero:     { val: 'ABC' },
  notes:      { val: null },
  creator:    { val: 1 },          // joined from the referenced record
  grave_id:   { val: 'T-12' },     // joined from the referenced record
}
const schema = [{ name: 'id' }, { name: 'id_link' }, { name: 'graves_id' }, { name: 'zotero' }, { name: 'notes' }]

test('only the plugin\'s own fields are kept, system keys are dropped', () => {
  assert.deepEqual(editableRowFields(row, schema), { graves_id: 3, zotero: 'ABC', notes: null })
})

test('without a schema every non-system key is kept', () => {
  assert.deepEqual(Object.keys(editableRowFields(row, undefined)).sort(),
    ['creator', 'grave_id', 'graves_id', 'notes', 'zotero'])
  assert.deepEqual(Object.keys(editableRowFields(row, [])).length, 5)
})

test('a missing value becomes null', () => {
  assert.deepEqual(editableRowFields({ zotero: {} }, [{ name: 'zotero' }]), { zotero: null })
})
