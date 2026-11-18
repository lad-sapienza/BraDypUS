import { test } from 'node:test'
import assert from 'node:assert/strict'
import { paletteToText, textToPalette, charMapItems, CHAR_MAP_NAME } from '../src/utils/charPaletteConfig.js'

test('paletteToText: strings as they are, structures as JSON, empty as empty', () => {
  assert.equal(paletteToText('greek-polytonic'), 'greek-polytonic')
  assert.equal(paletteToText(['α', 'β']), '["α","β"]')
  assert.equal(paletteToText({ a: 'α' }), '{"a":"α"}')
  assert.equal(paletteToText(null), '')
  assert.equal(paletteToText(undefined), '')
  assert.equal(paletteToText(''), '')
})

test('textToPalette: empty text means no palette', () => {
  assert.deepEqual(textToPalette(''), { value: null })
  assert.deepEqual(textToPalette('   \n '), { value: null })
  assert.deepEqual(textToPalette('[]'), { value: null })
  assert.deepEqual(textToPalette('{}'), { value: null })
})

test('textToPalette: preset name or characters stay a trimmed string', () => {
  assert.deepEqual(textToPalette(' greek-polytonic '), { value: 'greek-polytonic' })
  assert.deepEqual(textToPalette('α β γ'), { value: 'α β γ' })
})

test('textToPalette: JSON lists and maps are parsed', () => {
  assert.deepEqual(textToPalette('["α", {"label":"η","value":"ῆ"}]'), { value: ['α', { label: 'η', value: 'ῆ' }] })
  assert.deepEqual(textToPalette('{"a=":"ᾱ"}'), { value: { 'a=': 'ᾱ' } })
})

test('textToPalette: text that starts like JSON but is not is an error', () => {
  assert.deepEqual(textToPalette('{"a": '), { error: 'invalid_json' })
  assert.deepEqual(textToPalette('[α, β]'), { error: 'invalid_json' })
})

test('what is stored comes back as the same text', () => {
  for (const v of ['greek-polytonic', 'α β', ['α'], { a: 'α' }]) {
    assert.deepEqual(textToPalette(paletteToText(v)), { value: v })
  }
})

test('charMapItems: strings, lists and maps give buttons like the server does', () => {
  assert.deepEqual(charMapItems(' α  β '), [{ label: 'α', value: 'α' }, { label: 'β', value: 'β' }])
  assert.deepEqual(charMapItems(['α', { label: 'eta', value: 'η' }, { value: 'ω' }]),
    [{ label: 'α', value: 'α' }, { label: 'eta', value: 'η' }, { label: 'ω', value: 'ω' }])
  assert.deepEqual(charMapItems({ 'a=': 'ᾱ' }), [{ label: 'a=', value: 'ᾱ' }])
})

test('charMapItems: junk is skipped, nothing usable gives an empty list', () => {
  assert.deepEqual(charMapItems(['α', 3, null, { value: ['x'] }, 'β']).map(i => i.value), ['α', 'β'])
  assert.deepEqual(charMapItems(null), [])
  assert.deepEqual(charMapItems(''), [])
  assert.deepEqual(charMapItems({}), [])
  assert.equal(charMapItems('x'.repeat(100)).length, 0)
})

test('CHAR_MAP_NAME accepts lowercase names with digits and hyphens only', () => {
  for (const ok of ['greek-polytonic', 'ab', 'epigrafia2']) assert.ok(CHAR_MAP_NAME.test(ok), ok)
  for (const bad of ['a', 'Greek', 'my map', '1abc', '-ab', 'x'.repeat(41)]) assert.ok(!CHAR_MAP_NAME.test(bad), bad)
})
