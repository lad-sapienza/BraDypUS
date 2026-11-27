import test from 'node:test'
import assert from 'node:assert/strict'
import { choicesFor, setAll, filesParam, summary, KEEP, DELETE } from '../src/utils/deleteFiles.js'

const files = [{ id: 1 }, { id: 2 }, { id: 3 }]

test('the dialog starts on keep: nothing is lost without being asked', () => {
  assert.deepEqual(choicesFor(files), { 1: KEEP, 2: KEEP, 3: KEEP })
  assert.deepEqual(filesParam(files, choicesFor(files)), { 1: KEEP, 2: KEEP, 3: KEEP })
})

test('an unknown value is keep, never delete', () => {
  assert.deepEqual(choicesFor(files, 'burn'), { 1: KEEP, 2: KEEP, 3: KEEP })
  assert.deepEqual(filesParam(files, { 1: 'DELETE', 2: 'yes', 3: true }), { 1: KEEP, 2: KEEP, 3: KEEP })
})

test('the request names exactly the listed files, with the chosen answers', () => {
  const c = choicesFor(files)
  c[2] = DELETE
  assert.deepEqual(filesParam(files, c), { 1: KEEP, 2: DELETE, 3: KEEP })
  // a choice for a file that is not listed is not sent
  assert.deepEqual(filesParam(files, { ...c, 99: DELETE }), { 1: KEEP, 2: DELETE, 3: KEEP })
})

test('the "all" buttons set every file', () => {
  const c = choicesFor(files)
  assert.deepEqual(setAll(c, DELETE), { 1: DELETE, 2: DELETE, 3: DELETE })
  assert.deepEqual(setAll(c, KEEP), { 1: KEEP, 2: KEEP, 3: KEEP })
})

test('summary counts what goes and what stays', () => {
  assert.deepEqual(summary(files, { 1: DELETE, 2: KEEP, 3: DELETE }), { delete: 2, keep: 1, total: 3 })
  assert.deepEqual(summary([], {}), { delete: 0, keep: 0, total: 0 })
  assert.deepEqual(summary(undefined, undefined), { delete: 0, keep: 0, total: 0 })
})

test('no files: an empty request parameter', () => {
  assert.deepEqual(filesParam([], {}), {})
  assert.deepEqual(filesParam(undefined, undefined), {})
})
