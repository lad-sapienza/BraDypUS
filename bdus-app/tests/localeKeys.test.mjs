import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'src')
const en = JSON.parse(readFileSync(join(root, 'locale', 'en.json'), 'utf8'))
const it = JSON.parse(readFileSync(join(root, 'locale', 'it.json'), 'utf8'))

function walk(dir, out = []) {
  for (const f of readdirSync(dir)) {
    const p = join(dir, f)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (/\.(vue|js|mjs)$/.test(f)) out.push(p)
  }
  return out
}

// Literal keys only (a closing quote then `,` or `)`: t('fld_' + x) is dynamic): t('key') / t("key") not preceded by an identifier char or dot
// (so `format('x')`, `obj.t('x')` and `$t(` variants are not mistaken for it).
const LITERAL = /(?<![\w.$])t\(\s*(['"])([A-Za-z0-9_]+)\1\s*[,)]/g

const used = new Map()
for (const file of walk(root)) {
  if (file.includes(`${join('src', 'locale')}`)) continue
  const src = readFileSync(file, 'utf8')
  for (const m of src.matchAll(LITERAL)) {
    if (!used.has(m[2])) used.set(m[2], file)
  }
}

test('every literal t() key exists in en.json', () => {
  const missing = [...used].filter(([k]) => !(k in en)).map(([k, f]) => `${k} (${f.replace(root, 'src')})`)
  assert.deepEqual(missing, [])
})

test('every literal t() key exists in it.json', () => {
  const missing = [...used].filter(([k]) => !(k in it)).map(([k, f]) => `${k} (${f.replace(root, 'src')})`)
  assert.deepEqual(missing, [])
})
