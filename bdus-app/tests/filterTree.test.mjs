// Run with: npm test   (node's built-in runner — no dependencies)
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  MAX_DEPTH, NO_VALUE_OPS, group, condition, emptyTree, cloneTree, isActive, countActive,
  nodeAt, normalize, addCondition, addGroup, canAddGroup, setOperator, remove, ungroup,
  canIndent, indent, canOutdent, outdent, treeToFilter, formula, serializeTree, rowsToTree, restoreTree,
} from '../src/utils/filterTree.js'

const leaf = (fld, value, operator = '_eq') => condition(fld, operator, value)
const ctx = {
  mainTb: 'us',
  fieldMeta: fld => (fld === 'us:cat_ref' ? { ref_tb: 'categories', ref_field: 'name' } : undefined),
}
const fmtCtx = {
  fieldLabel: f => ({ 'us:site': 'Site', 'us:type': 'Type', 'us:period': 'Period' }[f] ?? f),
  operatorLabel: o => ({ _eq: '=', _neq: '≠', _icontains: 'contains', _empty: 'is empty' }[o] ?? o),
}

// ── The previous builder's conversion, kept verbatim as the oracle ───────
function legacyBuildFilterFromRows(rows, mainTb, metaOf) {
  const active = rows.filter(r => r.fld && (r.value !== '' || NO_VALUE_OPS.includes(r.operator)))
  if (!active.length) return null
  const toCond = r => {
    const [tb, field] = r.fld.split(':')
    const val = NO_VALUE_OPS.includes(r.operator) ? true : r.value
    const meta = metaOf(r.fld)
    let cond = { [r.operator]: val }
    if (meta?.ref_tb && meta?.ref_field) cond = { [meta.ref_field]: cond }
    return tb === mainTb ? { [field]: cond } : { [tb]: { [field]: cond } }
  }
  if (active.length === 1) return toCond(active[0])
  const groups = [[toCond(active[0])]]
  for (let i = 1; i < active.length; i++) {
    const c = toCond(active[i])
    if (active[i].connector === 'OR') groups.push([c])
    else groups[groups.length - 1].push(c)
  }
  const orParts = groups.map(g => (g.length === 1 ? g[0] : { _and: g }))
  return orParts.length === 1 ? orParts[0] : { _or: orParts }
}

function rng(seed) {                       // deterministic PRNG, so failures reproduce
  return () => {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

test('flat rows → tree → filter equals the previous builder, on 1000 random inputs', () => {
  const rand = rng(20261002)
  const pick = a => a[Math.floor(rand() * a.length)]
  const FIELDS = ['us:site', 'us:type', 'us:cat_ref', 'sites:code', '']
  const OPS = ['_eq', '_icontains', '_neq', '_empty', '_nempty', '_null', '_gt']
  const VALUES = ['', 'a', 'Romano', '12']
  for (let n = 0; n < 1000; n++) {
    const len = 1 + Math.floor(rand() * 6)
    const rows = Array.from({ length: len }, () => ({
      connector: pick(['AND', 'OR', 'XOR']),
      fld: pick(FIELDS), operator: pick(OPS), value: pick(VALUES),
    }))
    assert.deepEqual(
      treeToFilter(rowsToTree(rows), ctx),
      legacyBuildFilterFromRows(rows, ctx.mainTb, ctx.fieldMeta),
      `case ${n}: ${JSON.stringify(rows)}`
    )
  }
})

test('rowsToTree: AND binds tighter than OR, and a lone run stays a flat AND group', () => {
  const rows = [
    { connector: 'AND', fld: 'us:site', operator: '_eq', value: 'A' },
    { connector: 'AND', fld: 'us:type', operator: '_eq', value: 'B' },
    { connector: 'OR',  fld: 'us:period', operator: '_eq', value: 'C' },
  ]
  const t = rowsToTree(rows)
  assert.equal(t.op, 'OR')
  assert.equal(t.c.length, 2)
  assert.equal(t.c[0].op, 'AND')
  assert.equal(t.c[1].t, 'c')
  assert.equal(rowsToTree(rows.slice(0, 2)).op, 'AND')
  assert.equal(rowsToTree([]).c.length, 1)               // empty → starting tree
})

test('treeToFilter: nesting, collapsing, empty', () => {
  const tree = group('AND', [
    leaf('us:site', 'Colle Oppio'),
    group('OR', [leaf('us:type', 'Fill'), leaf('us:type', 'Layer')]),
  ])
  assert.deepEqual(treeToFilter(tree, ctx), {
    _and: [
      { site: { _eq: 'Colle Oppio' } },
      { _or: [{ type: { _eq: 'Fill' } }, { type: { _eq: 'Layer' } }] },
    ],
  })
  // a group with one usable child collapses into it
  assert.deepEqual(treeToFilter(group('OR', [leaf('us:site', 'x'), leaf('us:type', '')]), ctx), { site: { _eq: 'x' } })
  assert.equal(treeToFilter(emptyTree(), ctx), null)
  // lookup traversal and cross-table
  assert.deepEqual(treeToFilter(group('AND', [leaf('us:cat_ref', 'Ceramics')]), ctx), { cat_ref: { name: { _eq: 'Ceramics' } } })
  assert.deepEqual(treeToFilter(group('AND', [leaf('sites:code', 'S1')]), ctx), { sites: { code: { _eq: 'S1' } } })
  // no-value operators
  assert.deepEqual(treeToFilter(group('AND', [condition('us:site', '_empty', '')]), ctx), { site: { _empty: true } })
})

test('indent: with the row above → new group of the opposite operator', () => {
  const t = group('AND', [leaf('us:site', 'A'), leaf('us:type', 'B'), leaf('us:period', 'C')])
  assert.equal(canIndent(t, [0]), false)                 // nothing above the first row
  assert.ok(indent(t, [1]))
  assert.equal(t.c.length, 2)
  assert.equal(t.c[0].t, 'g')
  assert.equal(t.c[0].op, 'OR')                          // parent AND → child OR
  assert.deepEqual(t.c[0].c.map(c => c.value), ['A', 'B'])
})

test('indent: a row below a group joins that group', () => {
  const t = group('AND', [group('OR', [leaf('us:site', 'A'), leaf('us:site', 'B')]), leaf('us:type', 'C')])
  assert.ok(indent(t, [1]))
  assert.equal(t.c.length, 1)
  assert.deepEqual(t.c[0].c.map(c => c.value), ['A', 'B', 'C'])
})

test('outdent: leaves the group; a group left with one row dissolves', () => {
  const t = group('AND', [leaf('us:site', 'A'), group('OR', [leaf('us:type', 'B'), leaf('us:type', 'C')])])
  assert.equal(canOutdent([0]), false)
  assert.ok(outdent(t, [1, 1]))
  // group had B and C; C left → group has only B → dissolves into B
  assert.deepEqual(t.c.map(c => c.t + ':' + c.value), ['c:A', 'c:B', 'c:C'])
})

test('ungroup, remove and normalize keep the tree valid', () => {
  const t = group('AND', [leaf('us:site', 'A'), group('OR', [leaf('us:type', 'B'), leaf('us:type', 'C')])])
  ungroup(t, [1])
  assert.deepEqual(t.c.map(c => c.value), ['A', 'B', 'C'])
  const only = group('AND', [leaf('us:site', 'A')])
  remove(only, [0])
  assert.equal(only.c.length, 1)                         // root is never left empty
  assert.equal(isActive(only.c[0]), false)
})

test('depth limit: groups and indenting stop at MAX_DEPTH', () => {
  const root = emptyTree()
  let path = []
  for (let d = 0; d < MAX_DEPTH; d++) {
    assert.equal(canAddGroup(path), true)
    assert.ok(addGroup(root, path))
    path = [...path, nodeAt(root, path).c.length - 1]
  }
  assert.equal(canAddGroup(path), false)
  assert.equal(addGroup(root, path), false)
})

test('addGroup / setOperator / addCondition', () => {
  const t = emptyTree()
  addCondition(t, [], leaf('us:site', 'A'))
  addGroup(t, [])
  assert.equal(t.c[t.c.length - 1].op, 'OR')
  assert.equal(t.c[t.c.length - 1].c.length, 2)
  setOperator(t, [], 'OR')
  assert.equal(t.op, 'OR')
  setOperator(t, [], 'XOR')                              // retired: ignored
  assert.equal(t.op, 'OR')
})

test('countActive and isActive', () => {
  const t = group('AND', [leaf('us:site', 'A'), leaf('us:type', ''), condition('us:site', '_empty', ''), condition('', '_eq', 'x')])
  assert.equal(countActive(t), 2)
})

test('formula reads like the query, parentheses only where needed', () => {
  const t = group('AND', [
    leaf('us:site', 'Colle Oppio'),
    group('OR', [leaf('us:type', 'Fill'), leaf('us:type', 'Layer')]),
  ])
  assert.equal(formula(t, { ...fmtCtx, and: 'E', or: 'O' }), 'Site = Colle Oppio E (Type = Fill O Type = Layer)')
  assert.equal(formula(group('AND', [group('OR', [leaf('us:site', 'A'), leaf('us:site', 'B')])]), fmtCtx), 'Site = A OR Site = B')
  assert.equal(formula(group('AND', [condition('us:site', '_empty', '')]), fmtCtx), 'Site is empty')
  assert.equal(formula(emptyTree(), fmtCtx), '')
})

test('serialize / restore round trip, legacy rows, and untrusted input', () => {
  const t = group('OR', [leaf('us:site', 'A'), group('AND', [leaf('us:type', 'B'), leaf('us:period', 'C')])])
  t.c[0]._id = 7; t.c[0]._suggestions = ['x']
  const s = serializeTree(t)
  assert.deepEqual(Object.keys(s.c[0]).sort(), ['fld', 'operator', 't', 'value'])
  assert.deepEqual(restoreTree({ tree: s }), normalize(cloneTree(s)))
  // legacy URL payload
  const legacy = restoreTree({ rows: [{ connector: 'AND', fld: 'us:site', operator: '_eq', value: 'A' }] })
  assert.equal(legacy.op, 'AND')
  // garbage → a usable starting tree, never a throw
  for (const bad of [null, {}, { tree: 'x' }, { tree: { t: 'g', op: 'XOR', c: [] } }, { tree: { t: 'g', op: 'AND', c: [{ t: 'c', fld: 1 }] } }]) {
    assert.equal(restoreTree(bad).t, 'g')
    assert.ok(restoreTree(bad).c.length >= 1)
  }
})
