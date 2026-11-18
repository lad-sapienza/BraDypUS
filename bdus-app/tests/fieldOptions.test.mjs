import { test } from 'node:test'
import assert from 'node:assert/strict'
import { normalizeOptions, selectedValues, withSelected } from '../src/utils/fieldOptions.js'

test('normalizeOptions turns values into strings and tolerates junk', () => {
  assert.deepEqual(normalizeOptions([{ value: 3, label: 'a' }, { value: null, label: 'n' }]),
    [{ value: '3', label: 'a' }, { value: null, label: 'n' }])
  assert.deepEqual(normalizeOptions(undefined), [])
})

test('selectedValues: single, multi, empty', () => {
  assert.deepEqual(selectedValues(7, 'select'), ['7'])
  assert.deepEqual(selectedValues('a; b ;;c', 'multi_select'), ['a', 'b', 'c'])
  assert.deepEqual(selectedValues('a;b', 'select'), ['a;b'])
  assert.deepEqual(selectedValues(null, 'select'), [])
  assert.deepEqual(selectedValues('', 'multi_select'), [])
})

test('withSelected keeps selected values that fell outside the list', () => {
  const known = new Map([['2', { value: '2', label: 'Metal' }]])
  const out = withSelected([{ value: '1', label: 'Ceramics' }], known, ['1', '2', '9'])
  assert.deepEqual(out.map(o => o.label), ['Ceramics', 'Metal', '9'])
})

test('withSelected returns the same list when nothing is missing', () => {
  const opts = [{ value: '1', label: 'a' }]
  assert.equal(withSelected(opts, new Map(), ['1']), opts)
})
