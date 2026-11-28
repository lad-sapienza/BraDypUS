import test from 'node:test'
import assert from 'node:assert/strict'
import {
  columnFields, defaultVisible, visibleColumns, editableNames, snapshotOf, changedFields, stripExt,
  cardLines, deletable, updatable, loadPrefs, savePrefs, normalizeView, isTextual,
} from '../src/utils/filesList.js'

const f = (name, type = 'text', over = {}) => ({ name, label: name.toUpperCase(), type, editable: true, system: false, ...over })
const FIELDS = [
  f('id', 'text', { editable: false, system: true }),
  f('uuid', 'text', { editable: false, system: true }),
  f('ext', 'text', { editable: false, system: true }),
  f('filename', 'text', { system: true }),
  f('description', 'long_text', { system: true }),
  f('keywords', 'text', { system: true }),
  f('attribution', 'text', { system: true }),
  f('public', 'boolean', { system: true }),
  f('licence', 'text'),
  f('campaign', 'select'),
]

test('id, uuid and ext never get a column: the extension is shown with the filename', () => {
  const names = columnFields(FIELDS).map(x => x.name)
  assert.ok(!['id', 'uuid', 'ext'].some(n => names.includes(n)))
  assert.ok(names.includes('filename') && names.includes('licence'))
})

test('by default: the descriptive system fields and every custom field, not the technical flags', () => {
  assert.deepEqual(defaultVisible(FIELDS), ['filename', 'description', 'keywords', 'attribution', 'licence', 'campaign'])
})

test('a saved choice wins, in the configured order, and forgets removed fields', () => {
  const cols = visibleColumns(FIELDS, ['licence', 'filename', 'gone', 'public'])
  assert.deepEqual(cols.map(x => x.name), ['filename', 'public', 'licence'])
  assert.deepEqual(visibleColumns(FIELDS, []).map(x => x.name), defaultVisible(FIELDS), 'empty: the default')
})

test('only fields the server says are editable are ever sent', () => {
  assert.deepEqual(editableNames(FIELDS), ['filename', 'description', 'keywords', 'attribution', 'public', 'licence', 'campaign'])
  const file = { id: 1, uuid: 'u', ext: 'png', filename: 'a', licence: null }
  const snap = snapshotOf(file, FIELDS)
  assert.ok(!('uuid' in snap) && !('id' in snap) && !('ext' in snap))
  file.uuid = 'changed'; file.ext = 'html'
  assert.deepEqual(changedFields(file, snap, FIELDS), {}, 'the binary columns are never part of a change')
})

test('a change is exactly the fields that differ; a no-op sends nothing', () => {
  const file = { filename: 'a', description: 'd', licence: 'CC0', public: true }
  const snap = snapshotOf(file, FIELDS)
  assert.deepEqual(changedFields(file, snap, FIELDS), {})
  file.licence = 'CC BY'
  file.description = null
  assert.deepEqual(changedFields(file, snap, FIELDS), { licence: 'CC BY', description: null })
  file.licence = 'CC0'
  assert.deepEqual(changedFields(file, snap, FIELDS), { description: null }, 'back to the saved value: not a change')
})

test('undefined and null are the same "empty"', () => {
  const snap = snapshotOf({ filename: 'a' }, FIELDS)
  assert.deepEqual(changedFields({ filename: 'a', licence: null }, snap, FIELDS), {})
})

test('the extension is stripped from an edited name only when it is the real one', () => {
  assert.equal(stripExt('photo.jpg', 'jpg'), 'photo')
  assert.equal(stripExt('photo.JPG', 'jpg'), 'photo')
  assert.equal(stripExt('photo.png', 'jpg'), 'photo.png')
  assert.equal(stripExt('photo', ''), 'photo')
  assert.equal(stripExt(null, 'jpg'), '')
})

test('a card shows the first few non-empty text lines, not the filename nor booleans', () => {
  const file = { filename: 'a', description: 'a long description', keywords: '', attribution: 'Jane', licence: 'CC0', public: true }
  const lines = cardLines(file, FIELDS, [], 2)
  assert.deepEqual(lines, [{ label: 'DESCRIPTION', value: 'a long description' }, { label: 'ATTRIBUTION', value: 'Jane' }])
  assert.equal(cardLines(file, FIELDS, [], 10).length, 3, 'empty values are skipped')
})

test('bulk actions touch only what the principal may', () => {
  const files = [
    { id: 1, can_delete: true,  can_update: true },
    { id: 2, can_delete: false, can_update: true },
    { id: 3, can_delete: true,  can_update: false },
  ]
  assert.deepEqual(deletable(files, [1, 2, 3]).map(x => x.id), [1, 3])
  assert.deepEqual(updatable(files, [1, 2]).map(x => x.id), [1, 2])
  assert.deepEqual(deletable(files, []), [])
})

test('the view and the columns are remembered per application, and junk is ignored', () => {
  const mem = new Map()
  const storage = { getItem: k => mem.get(k) ?? null, setItem: (k, v) => mem.set(k, v) }
  assert.deepEqual(loadPrefs(storage, 'demo'), { view: 'cards', visible: [] })
  savePrefs(storage, 'demo', { view: 'cards', visible: ['filename', 'licence'] })
  assert.deepEqual(loadPrefs(storage, 'demo'), { view: 'cards', visible: ['filename', 'licence'] })
  assert.deepEqual(loadPrefs(storage, 'other'), { view: 'cards', visible: [] }, 'another application')

  mem.set('bdus_files_page:demo', '{not json')
  assert.deepEqual(loadPrefs(storage, 'demo'), { view: 'cards', visible: [] })
  mem.set('bdus_files_page:demo', JSON.stringify({ view: 'gallery', visible: ['a', 1, null] }))
  assert.deepEqual(loadPrefs(storage, 'demo'), { view: 'cards', visible: ['a'] })
  assert.deepEqual(loadPrefs(undefined, 'demo'), { view: 'cards', visible: [] }, 'no storage at all')
  assert.doesNotThrow(() => savePrefs(undefined, 'demo', { view: 'cards' }))
})

test('views and types', () => {
  assert.equal(normalizeView('cards'), 'cards')
  assert.equal(normalizeView('x'), 'cards')
  assert.ok(isTextual('long_text') && isTextual('md') && !isTextual('boolean') && !isTextual('select'))
})
