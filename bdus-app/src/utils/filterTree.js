/**
 * Filter tree — the data model behind the query builder.
 *
 * The builder edits a tree of groups and conditions, the shape the backend's
 * JsonFilter already evaluates (`_and` / `_or`, nested to any depth):
 *
 *   group      { t: 'g', op: 'AND' | 'OR', c: [ group | condition, … ] }
 *   condition  { t: 'c', fld, operator, value }
 *
 * `fld` is the builder's field key (`field` of the main table, or
 * `table:field`), `operator` a JsonFilter operator (`_eq`, `_icontains`, …).
 *
 * Plain functions, no Vue and no DOM: the component keeps the tree in a
 * reactive ref and calls these to change it in place. Paths are arrays of child
 * indexes from the root group ([] is the root, [2, 0] its third child's first).
 */

/** Deepest allowed group: the root is depth 0, its sub-groups depth 1, … */
export const MAX_DEPTH = 3

/** Operators that need no value (the condition is complete without one). */
export const NO_VALUE_OPS = ['_empty', '_nempty', '_null', '_nnull']

const OPPOSITE = { AND: 'OR', OR: 'AND' }

// ── Builders ──────────────────────────────────────────────────────────────

export function group(op = 'AND', children = []) {
  return { t: 'g', op, c: children }
}

export function condition(fld = '', operator = '_icontains', value = '') {
  return { t: 'c', fld, operator, value }
}

/** A tree with a single empty condition: the builder's starting state. */
export function emptyTree() {
  return group('AND', [condition()])
}

export function cloneTree(node) {
  return JSON.parse(JSON.stringify(node))
}

// ── Inspection ────────────────────────────────────────────────────────────

/** A condition counts only when it names a field and has a value (or needs none). */
export function isActive(leaf) {
  return !!leaf.fld && (leaf.value !== '' || NO_VALUE_OPS.includes(leaf.operator))
}

/** Number of complete conditions in the tree. */
export function countActive(node) {
  if (node.t === 'c') return isActive(node) ? 1 : 0
  return node.c.reduce((n, child) => n + countActive(child), 0)
}

export function nodeAt(root, path) {
  return path.reduce((node, i) => node.c[i], root)
}

// ── Editing (in place) ────────────────────────────────────────────────────

/** Drops empty groups and dissolves groups left with a single child. */
function prune(g) {
  for (let i = g.c.length - 1; i >= 0; i--) {
    const child = g.c[i]
    if (child.t !== 'g') continue
    prune(child)
    if (child.c.length === 0) g.c.splice(i, 1)
    else if (child.c.length === 1) g.c[i] = child.c[0]
  }
}

/** Prunes, and makes sure the root never ends up empty. */
export function normalize(root) {
  prune(root)
  if (root.c.length === 0) root.c.push(condition())
  return root
}

export function addCondition(root, groupPath, leaf = condition()) {
  nodeAt(root, groupPath).c.push(leaf)
}

export function canAddGroup(groupPath) {
  return groupPath.length < MAX_DEPTH
}

/** Adds a sub-group (opposite operator, two empty rows so it survives pruning). */
export function addGroup(root, groupPath) {
  if (!canAddGroup(groupPath)) return false
  const parent = nodeAt(root, groupPath)
  parent.c.push(group(OPPOSITE[parent.op], [condition(), condition()]))
  return true
}

export function setOperator(root, groupPath, op) {
  if (op === 'AND' || op === 'OR') nodeAt(root, groupPath).op = op
}

export function remove(root, path) {
  const i = path[path.length - 1]
  nodeAt(root, path.slice(0, -1)).c.splice(i, 1)
  normalize(root)
}

/** Dissolves a group: its rows move up into the parent, in place. */
export function ungroup(root, path) {
  const i = path[path.length - 1]
  const parent = nodeAt(root, path.slice(0, -1))
  const target = parent.c[i]
  if (target.t !== 'g') return
  parent.c.splice(i, 1, ...target.c)
  normalize(root)
}

/**
 * "Group with the row above": a condition joins the group right above it, or —
 * when the row above is another condition — forms a new group with it
 * (operator opposite to the parent's, so the grouping actually changes the
 * meaning). Only conditions can be indented.
 */
export function canIndent(root, path) {
  const i = path[path.length - 1]
  if (i === undefined || i === 0) return false
  const parent = nodeAt(root, path.slice(0, -1))
  if (parent.c[i].t !== 'c') return false
  const prev = parent.c[i - 1]
  return prev.t === 'g' || path.length <= MAX_DEPTH
}

export function indent(root, path) {
  if (!canIndent(root, path)) return false
  const i = path[path.length - 1]
  const parent = nodeAt(root, path.slice(0, -1))
  const [me] = parent.c.splice(i, 1)
  const prev = parent.c[i - 1]
  if (prev.t === 'g') prev.c.push(me)
  else parent.c.splice(i - 1, 1, group(OPPOSITE[parent.op], [prev, me]))
  normalize(root)
  return true
}

/** "Take out of the group": a condition moves to the level above, after its group. */
export function canOutdent(path) {
  return path.length > 1
}

export function outdent(root, path) {
  if (!canOutdent(path)) return false
  const i = path[path.length - 1]
  const groupPath = path.slice(0, -1)
  const parent = nodeAt(root, groupPath)
  const [me] = parent.c.splice(i, 1)
  const grandparent = nodeAt(root, groupPath.slice(0, -1))
  grandparent.c.splice(groupPath[groupPath.length - 1] + 1, 0, me)
  normalize(root)
  return true
}

// ── Translation to the backend filter ─────────────────────────────────────

/**
 * Builds the Directus-style filter object JsonFilter evaluates.
 *
 * Incomplete conditions are skipped; a group with a single usable child
 * collapses into it; nothing usable → null.
 *
 * Lookup fields (id_from_tb) store the referenced record's id while the user
 * types the referenced table's display value, so the condition is wrapped in a
 * traversal on `ref_field` — `{ cat_ref: { name: { _eq: 'Ceramics' } } }`.
 *
 * @param {object} root
 * @param {{ mainTb: string, fieldMeta?: (fld: string) => ({ref_tb?: string, ref_field?: string}|undefined) }} ctx
 */
export function treeToFilter(root, ctx) {
  const { mainTb, fieldMeta = () => undefined } = ctx

  const leafToFilter = leaf => {
    const [tb, field] = leaf.fld.split(':')
    const val = NO_VALUE_OPS.includes(leaf.operator) ? true : leaf.value
    const meta = fieldMeta(leaf.fld)
    let cond = { [leaf.operator]: val }
    if (meta?.ref_tb && meta?.ref_field) cond = { [meta.ref_field]: cond }
    return tb === mainTb ? { [field]: cond } : { [tb]: { [field]: cond } }
  }

  const walk = node => {
    if (node.t === 'c') return isActive(node) ? leafToFilter(node) : null
    const parts = node.c.map(walk).filter(Boolean)
    if (parts.length === 0) return null
    if (parts.length === 1) return parts[0]
    return { [node.op === 'AND' ? '_and' : '_or']: parts }
  }

  return walk(root)
}

// ── Readable form ─────────────────────────────────────────────────────────

/**
 * The query as a sentence with parentheses, e.g.
 *   "Site = Colle Oppio AND (Type = Fill OR Type = Layer)"
 *
 * @param {object} root
 * @param {{ fieldLabel: (fld: string) => string,
 *           operatorLabel: (operator: string) => string,
 *           and?: string, or?: string }} ctx
 */
export function formula(root, ctx) {
  const { fieldLabel, operatorLabel, and = 'AND', or = 'OR' } = ctx

  const walk = (node, isRoot) => {
    if (node.t === 'c') {
      if (!isActive(node)) return ''
      const op = operatorLabel(node.operator)
      return NO_VALUE_OPS.includes(node.operator)
        ? `${fieldLabel(node.fld)} ${op}`
        : `${fieldLabel(node.fld)} ${op} ${node.value}`
    }
    const usable = node.c.filter(child => countActive(child) > 0)
    if (usable.length === 0) return ''
    // A lone usable child needs no parentheses of its own.
    if (usable.length === 1) return walk(usable[0], isRoot)
    const text = usable.map(child => walk(child, false)).join(node.op === 'AND' ? ` ${and} ` : ` ${or} `)
    return isRoot ? text : `(${text})`
  }

  return walk(root, true)
}

// ── Persistence (URL / storage) ───────────────────────────────────────────

/** Keeps only the model's own keys, dropping UI-only ones (_id, _suggestions…). */
export function serializeTree(node) {
  if (node.t === 'c') {
    return { t: 'c', fld: node.fld, operator: node.operator, value: node.value }
  }
  return { t: 'g', op: node.op, c: node.c.map(serializeTree) }
}

/**
 * Converts the previous flat builder rows (one AND/OR connector per row) to a
 * tree, with the same reading as before: AND binds tighter than OR, so each OR
 * starts a new AND-run. Incomplete rows are dropped, as they always were.
 *
 * @param {Array<{connector?: string, fld: string, operator: string, value: string}>} rows
 */
export function rowsToTree(rows) {
  const active = (rows ?? []).filter(r => isActive(r))
  if (active.length === 0) return emptyTree()

  const runs = [[condition(active[0].fld, active[0].operator, active[0].value)]]
  for (let i = 1; i < active.length; i++) {
    const r = active[i]
    const leaf = condition(r.fld, r.operator, r.value)
    // Only OR splits; anything else (AND, the retired XOR…) joins the run.
    if (r.connector === 'OR') runs.push([leaf])
    else runs[runs.length - 1].push(leaf)
  }

  if (runs.length === 1) return group('AND', runs[0])
  return group('OR', runs.map(run => (run.length === 1 ? run[0] : group('AND', run))))
}

/** Checks the shape of an untrusted serialized node (from the URL). */
function isValidNode(node, depth = 0) {
  if (!node || typeof node !== 'object') return false
  if (node.t === 'c') {
    return typeof node.fld === 'string' && typeof node.operator === 'string' && typeof node.value === 'string'
  }
  if (node.t !== 'g' || (node.op !== 'AND' && node.op !== 'OR') || !Array.isArray(node.c)) return false
  if (depth > MAX_DEPTH) return false
  return node.c.every(child => isValidNode(child, depth + 1))
}

/**
 * Reads what a `qt=advanced` URL (or a stored search) carries: the new
 * `{ tree }`, or the previous `{ rows }`. Returns a usable tree in every case
 * (an empty one when nothing valid is there).
 */
export function restoreTree(parsed) {
  if (parsed?.tree && isValidNode(parsed.tree)) return normalize(cloneTree(parsed.tree))
  if (Array.isArray(parsed?.rows)) return rowsToTree(parsed.rows)
  return emptyTree()
}
