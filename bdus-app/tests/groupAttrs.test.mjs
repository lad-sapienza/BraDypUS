import test from 'node:test'
import assert from 'node:assert/strict'
import {
  parseValues, valuesToText, attrsToRows, rowsToAttrs, enforceToRows, rowsToEnforce,
} from '../src/utils/groupAttrs.js'

test('parseValues: one value stays a string, several become a list, empty is null', () => {
  assert.equal(parseValues(' Ostia '), 'Ostia')
  assert.deepEqual(parseValues('Ostia, Cuma ,'), ['Ostia', 'Cuma'])
  assert.equal(parseValues('  , '), null)
  assert.equal(parseValues(null), null)
})

test('valuesToText round-trips with parseValues', () => {
  assert.equal(valuesToText(['a', 'b']), 'a, b')
  assert.equal(valuesToText('$GROUP.site'), '$GROUP.site')
  assert.equal(valuesToText(null), '')
  assert.deepEqual(parseValues(valuesToText(['a', 'b'])), ['a', 'b'])
})

test('rowsToAttrs builds the object, ignores empty rows and refuses bad ones', () => {
  assert.deepEqual(rowsToAttrs([{ key: 'site', values: 'Ostia' }, { key: '', values: '' }]), { attrs: { site: 'Ostia' } })
  assert.deepEqual(rowsToAttrs([]), { attrs: null })
  assert.equal(rowsToAttrs([{ key: 'bad key', values: 'x' }]).error, 'grp_attr_bad_key')
  assert.equal(rowsToAttrs([{ key: 'site', values: '' }]).error, 'grp_attr_no_value')
  assert.equal(rowsToAttrs([{ key: 'a', values: '1' }, { key: 'a', values: '2' }]).error, 'grp_attr_duplicate')
  assert.deepEqual(attrsToRows({ site: ['A', 'B'] }), [{ key: 'site', values: 'A, B' }])
})

test('rowsToEnforce builds the map and refuses half-filled rows', () => {
  assert.deepEqual(rowsToEnforce([{ field: 'sito', values: '$GROUP.sito' }]), { enforce: { sito: '$GROUP.sito' } })
  assert.deepEqual(rowsToEnforce([{ field: 'tipo', values: 'a, b' }]), { enforce: { tipo: ['a', 'b'] } })
  assert.deepEqual(rowsToEnforce([]), { enforce: null })
  assert.equal(rowsToEnforce([{ field: 'sito', values: '' }]).error, 'grp_enforce_incomplete')
  assert.equal(rowsToEnforce([{ field: '', values: 'x' }]).error, 'grp_enforce_incomplete')
  assert.deepEqual(enforceToRows({ sito: 'x' }), [{ field: 'sito', values: 'x' }])
})
