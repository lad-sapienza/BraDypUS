import test from 'node:test'
import assert from 'node:assert/strict'
import { originalPath, thumbPath, hasUuid, DERIVABLE } from '../src/utils/filePaths.js'

const UUID = '3f2b8c1e-5d4a-4b7e-9a10-6c2d8e4f1a77'

test('a file is addressed by its uuid, never by its integer id', () => {
  const f = { id: 42, uuid: UUID, ext: 'jpg' }
  assert.equal(originalPath('demo', f), `projects/demo/files/${UUID}.jpg`)
  assert.equal(thumbPath(f), `/api/file/thumb/${UUID}.webp`)
  assert.ok(!originalPath('demo', f).includes('/42.'))
  assert.ok(!thumbPath(f).includes('/42.'))
})

test('the extension is lower-cased and may be absent', () => {
  assert.equal(originalPath('demo', { uuid: UUID, ext: 'JPG' }), `projects/demo/files/${UUID}.jpg`)
  assert.equal(originalPath('demo', { uuid: UUID, ext: '' }), `projects/demo/files/${UUID}`)
})

test('without a valid uuid there is no address (the caller shows an icon, never a wrong URL)', () => {
  for (const f of [{ id: 1, ext: 'jpg' }, { id: 1, uuid: null, ext: 'jpg' }, { id: 1, uuid: '42', ext: 'jpg' }, { uuid: '../x', ext: 'jpg' }, null, undefined]) {
    assert.equal(hasUuid(f), false)
    assert.equal(originalPath('demo', f), null)
    assert.equal(thumbPath(f), null)
  }
})

test('without an app there is no original path', () => {
  assert.equal(originalPath(undefined, { uuid: UUID, ext: 'jpg' }), null)
})

test('only derivable image types get a thumbnail', () => {
  for (const ext of DERIVABLE) assert.ok(thumbPath({ uuid: UUID, ext }))
  for (const ext of ['svg', 'avif', 'pdf', 'txt', '']) assert.equal(thumbPath({ uuid: UUID, ext }), null)
})
