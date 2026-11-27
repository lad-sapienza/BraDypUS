import test from 'node:test'
import assert from 'node:assert/strict'
import { originalPath, thumbPath, hasUuid, DERIVABLE } from '../src/utils/filePaths.js'

const UUID = '3f2b8c1e-5d4a-4b7e-9a10-6c2d8e4f1a77'

test('a file is addressed by its uuid, never by its integer id', () => {
  const f = { id: 42, uuid: UUID, ext: 'jpg' }
  assert.equal(originalPath(f), `/api/file/${UUID}`)
  assert.equal(thumbPath(f), `/api/file/thumb/${UUID}.webp`)
  assert.ok(!originalPath(f).includes('42'))
  assert.ok(!thumbPath(f).includes('/42.'))
})

test('the original address does not depend on the extension (it is the file, not its name)', () => {
  assert.equal(originalPath({ uuid: UUID, ext: 'JPG' }), `/api/file/${UUID}`)
  assert.equal(originalPath({ uuid: UUID, ext: '' }), `/api/file/${UUID}`)
})

test('without a valid uuid there is no address (the caller shows an icon, never a wrong URL)', () => {
  for (const f of [{ id: 1, ext: 'jpg' }, { id: 1, uuid: null, ext: 'jpg' }, { id: 1, uuid: '42', ext: 'jpg' }, { uuid: '../x', ext: 'jpg' }, null, undefined]) {
    assert.equal(hasUuid(f), false)
    assert.equal(originalPath(f), null)
    assert.equal(thumbPath(f), null)
  }
})

test('only derivable image types get a thumbnail', () => {
  for (const ext of DERIVABLE) assert.ok(thumbPath({ uuid: UUID, ext }))
  for (const ext of ['svg', 'avif', 'pdf', 'txt', '']) assert.equal(thumbPath({ uuid: UUID, ext }), null)
})
