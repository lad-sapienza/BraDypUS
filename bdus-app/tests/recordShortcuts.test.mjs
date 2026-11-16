import test from 'node:test'
import assert from 'node:assert/strict'
import { isEditShortcut } from '../src/utils/recordShortcuts.js'

const ok = { mode: 'read', canEdit: true, hasRecord: true, focusFree: true }
const key = (over = {}) => ({ key: 'e', ...over })

test('E opens edit mode on a readable, editable record with the focus free', () => {
  assert.equal(isEditShortcut(key(), ok), true)
  assert.equal(isEditShortcut(key({ key: 'E' }), ok), true)   // caps lock
})

test('modifiers, key repeat and IME composition are ignored', () => {
  for (const m of ['metaKey', 'ctrlKey', 'altKey', 'shiftKey', 'repeat', 'isComposing']) {
    assert.equal(isEditShortcut(key({ [m]: true }), ok), false, m)
  }
})

test('other keys do nothing', () => {
  assert.equal(isEditShortcut({ key: 'r' }, ok), false)
  assert.equal(isEditShortcut({ key: 'Enter' }, ok), false)
})

test('not when already editing, without permission, without a record or while typing', () => {
  assert.equal(isEditShortcut(key(), { ...ok, mode: 'edit' }), false)
  assert.equal(isEditShortcut(key(), { ...ok, canEdit: false }), false)
  assert.equal(isEditShortcut(key(), { ...ok, hasRecord: false }), false)
  assert.equal(isEditShortcut(key(), { ...ok, focusFree: false }), false)
})
