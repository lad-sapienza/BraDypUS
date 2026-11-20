import test from 'node:test'
import assert from 'node:assert/strict'
import { parseCondition, evalVisibleIf, isItemVisible } from '../src/components/record/templateUtils.js'

const ev = (e, v, k = null) => evalVisibleIf(e, v, k)

test('> >= < <= compare numbers as numbers, text otherwise, and never match an empty value', () => {
  assert.equal(ev('year > 1200', { year: '1500' }), true)
  assert.equal(ev('year > 1200', { year: '900' }), false)     // 900 < 1200 as numbers, not as text
  assert.equal(ev('year>=1200 and year<=1300', { year: 1250 }), true)
  assert.equal(ev('year < -50', { year: '-100' }), true)
  assert.equal(ev('year < 2.5', { year: '2.25' }), true)
  assert.equal(ev('year > 1200', { year: '' }), false)
  assert.equal(ev('year < 1200', { year: null }), false)
  assert.equal(ev('year > 1200', {}), false)
  assert.equal(ev("d >= '2020-01-01'", { d: '2021-05-02' }), true)   // ISO dates compare as text
  assert.equal(ev("d < '2020-01-01'", { d: '2021-05-02' }), false)
  assert.equal(ev('year > 1200 or year < 100', { year: 50 }), true)
})

test('= and != compare as text, quoted or bare', () => {
  assert.equal(ev("material = 'ceramic'", { material: 'ceramic' }), true)
  assert.equal(ev('material=ceramic', { material: 'bone' }), false)
  assert.equal(ev('n = 5', { n: 5 }), true)
  assert.equal(ev("material != 'ceramic'", { material: 'bone' }), true)
  assert.equal(ev("material != 'ceramic'", {}), true)
})

test('regex matches anywhere; anchors work', () => {
  assert.equal(ev("period regex '^Roman'", { period: 'Roman imperial' }), true)
  assert.equal(ev("period regex '^Roman'", { period: 'Late Roman' }), false)
})

test('in, empty, not empty', () => {
  assert.equal(ev("c in ('a', 'b', c2)", { c: 'c2' }), true)
  assert.equal(ev("c in ('a','b')", { c: 'z' }), false)
  assert.equal(ev('c empty', { c: '' }), true)
  assert.equal(ev('c empty', { c: null }), true)
  assert.equal(ev('c not empty', { c: 'x' }), true)
  assert.equal(ev('c NOT EMPTY', { c: '  ' }), false)
})

test('and/or precedence and parentheses', () => {
  const v = { a: '1', b: '2', c: '3' }
  assert.equal(ev("a = 1 or b = 9 and c = 9", v), true)   // a=1 or (b=9 and c=9)
  assert.equal(ev("(a = 1 or b = 9) and c = 9", v), false)
  assert.equal(ev("a = 1 and b = 2 and c = 3", v), true)
})

test('values with { val } objects are unwrapped', () => {
  assert.equal(ev("a = 'x'", { a: { val: 'x', label: 'X' } }), true)
})

test('invalid expressions fail safe (visible) and parse throws', () => {
  const warn = console.warn; console.warn = () => {}
  try {
    for (const bad of ['a >', 'a > >', 'a <= ', 'a =', 'a ~ b', "a regex '('", '(a = 1', 'a = 1 b', 'a in 1', 'a not x']) {
      assert.throws(() => parseCondition(bad), bad)
      assert.equal(ev(bad, { a: '2' }), true, bad)
    }
  } finally { console.warn = warn }
})

test('unknown field name fails safe when the field list is given', () => {
  const warn = console.warn; console.warn = () => {}
  try {
    assert.equal(ev("nope = 'x'", { a: 'y' }, ['a']), true)
    assert.equal(ev("a = 'x'", { a: 'y' }, ['a']), false)
  } finally { console.warn = warn }
})

test('isItemVisible combines hidden and visible_if', () => {
  assert.equal(isItemVisible({ field: 'a' }, {}), true)
  assert.equal(isItemVisible({ field: 'a', hidden: true }, {}), false)
  assert.equal(isItemVisible({ field: 'a', visible_if: "m = 'x'" }, { m: 'x' }), true)
  assert.equal(isItemVisible({ field: 'a', visible_if: "m = 'x'" }, { m: 'y' }), false)
  assert.equal(isItemVisible({ field: 'a', visible_if: '' }, {}), true)
})
