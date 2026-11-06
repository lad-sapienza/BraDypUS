import test from 'node:test'
import assert from 'node:assert/strict'
import { validateField } from '../src/utils/fieldValidation.js'

test('int accepts whole numbers only', () => {
  const s = { check: ['int'] }
  assert.equal(validateField(s, '10'), null)
  assert.equal(validateField(s, '-3'), null)
  assert.deepEqual(validateField(s, '10.5'), ['value_must_be_integer'])
  assert.deepEqual(validateField(s, 'abc'), ['value_must_be_integer'])
})

test('real accepts whole and decimal numbers with a dot', () => {
  const s = { check: ['real'] }
  for (const ok of ['10', '10.5', '-0.25', 3, 2.5]) assert.equal(validateField(s, ok), null, String(ok))
  for (const bad of ['10,5', 'abc', '1e3', '10.', '.5']) assert.deepEqual(validateField(s, bad), ['value_must_be_real'], bad)
})

test('empty optional values are valid, empty required are not', () => {
  assert.equal(validateField({ check: ['real'] }, ''), null)
  assert.deepEqual(validateField({ required: true }, null), ['field_required'])
})

test('min / max / max_length / pattern / email / dates', () => {
  assert.deepEqual(validateField({ min: 5 }, '3'), ['value_too_small', 5])
  assert.deepEqual(validateField({ max: 5 }, '9'), ['value_too_large', 5])
  assert.deepEqual(validateField({ type: 'date', min: '2000-01-01' }, '1999-12-31'), ['value_too_small', '2000-01-01'])
  assert.deepEqual(validateField({ max_length: 2 }, 'abc'), ['value_too_long', 2])
  assert.deepEqual(validateField({ pattern: '^[A-Z]{3}$' }, 'ab'), ['invalid_format'])
  assert.deepEqual(validateField({ check: ['email'] }, 'x'), ['invalid_email'])
  assert.equal(validateField({ pattern: '(' }, 'x'), null) // bad regex ignored
})
