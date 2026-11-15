// Run with: npm test
// The relation codes of the Harris matrix exist twice: in the interface (useRsRelations.js)
// and on the server (Record\Rs::RELATIONS, which the API and MCP use). They must agree.
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { REL_KEYS, REL_INVERSE } from '../src/composables/useRsRelations.js'

// Mirrors bdus-api/lib/Record/Rs.php, RELATIONS (code => name) and inverse().
const SERVER = {
  1: 'is_covered_by', 2: 'is_cut_by', 3: 'carries', 4: 'is_filled_by', 5: 'covers',
  6: 'cuts', 7: 'leans_against', 8: 'fills', 9: 'is_the_same_as', 10: 'is_bound_to',
}
const SERVER_INVERSE = { 1: 5, 2: 6, 3: 7, 4: 8, 5: 1, 6: 2, 7: 3, 8: 4, 9: 9, 10: 10 }

test('the interface and the server name the same ten relations', () => {
  assert.deepEqual(REL_KEYS, SERVER)
  assert.deepEqual(REL_INVERSE, SERVER_INVERSE)
})

test('every relation name is translated in both languages', () => {
  for (const lang of ['en', 'it']) {
    const messages = JSON.parse(readFileSync(new URL(`../src/locale/${lang}.json`, import.meta.url), 'utf8'))
    for (const key of Object.values(SERVER)) {
      assert.ok(messages[key], `${lang}: no translation for ${key}`)
    }
  }
})
