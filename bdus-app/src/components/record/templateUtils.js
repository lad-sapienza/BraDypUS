const WIDTH_MAP = {
  '1/1': '100%',
  '1/2': '50%',
  '1/3': '33.333%',
  '2/3': '66.667%',
  '1/4': '25%',
  '3/4': '75%',
}

/**
 * Convert a template width fraction string to a CSS percentage string.
 * Unknown widths fall back to '100%'.
 *
 * @param  {string} w  e.g. '1/2'
 * @returns {string}   e.g. '50%'
 */
export function widthToCss(w) {
  return WIDTH_MAP[w] ?? '100%'
}

/**
 * Convert a template width fraction string to a 12-column grid span number.
 * Used by TemplateSection to set `grid-column: span N` on a CSS Grid container.
 * Unknown widths fall back to 12 (full row).
 *
 * Grid mapping (out of 12 columns):
 *   1/1 → 12,  1/2 → 6,  1/3 → 4,  2/3 → 8,  1/4 → 3,  3/4 → 9
 *
 * @param  {string} w  e.g. '1/2'
 * @returns {number}   e.g. 6
 */
export function widthToSpan(w) {
  const SPAN_MAP = {
    '1/1': 12,
    '1/2':  6,
    '1/3':  4,
    '2/3':  8,
    '1/4':  3,
    '3/4':  9,
  }
  return SPAN_MAP[w] ?? 12
}

/**
 * The field schema a template item shows: the field's own schema with the item's
 * per-layout options applied. Interface only (the server's group rules still decide
 * what may be written):
 *  - `label`    replaces the field's label (an empty text keeps it);
 *  - `readonly` shows the value but offers no editor (a field already read-only in
 *    the table configuration stays read-only).
 *
 * @param {object} schema  the field's schema from record.schema.fields
 * @param {object} item    the template item { field, width, label?, readonly?, hidden? }
 */
export function itemSchema(schema, item) {
  const out = { ...schema }
  if (typeof item?.label === 'string' && item.label.trim() !== '') out.label = item.label
  if (item?.readonly === true) out.readonly = true
  return out
}

/** True when the template item asks to be left out of the form. */
export function isItemHidden(item) {
  return item?.hidden === true
}

// ── visible_if: conditional visibility ──────────────────────────────────────
//
// Grammar (no eval):
//   expr  := and ('or' and)*
//   and   := term ('and' term)*
//   term  := '(' expr ')' | field ('=' | '!=' | '>' | '>=' | '<' | '<=' | 'regex') value
//          | field 'in' '(' value (',' value)* ')' | field 'empty' | field 'not' 'empty'
//   value := 'quoted' | "quoted" | bare-word
// Keywords are case-insensitive. = != regex in compare as text; > >= < <= compare as numbers when
// both sides are numbers, otherwise as text (ISO dates sort correctly); an empty field never
// satisfies them.

const TOKEN_RE = /\s*(?:('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")|(!=|>=|<=|=|>|<|\(|\))|(,)|([^\s=!<>(),'"]+))/y

function tokenize(src) {
  const tokens = []
  TOKEN_RE.lastIndex = 0
  let pos = 0
  while (pos < src.length) {
    if (/^\s*$/.test(src.slice(pos))) break
    TOKEN_RE.lastIndex = pos
    const m = TOKEN_RE.exec(src)
    if (!m) throw new Error(`unexpected character at ${pos}`)
    pos = TOKEN_RE.lastIndex
    if (m[1] !== undefined) tokens.push({ t: 'str', v: m[1].slice(1, -1).replace(/\\(.)/g, '$1') })
    else if (m[2] !== undefined) tokens.push({ t: 'op', v: m[2] })
    else if (m[3] !== undefined) tokens.push({ t: 'op', v: ',' })
    else tokens.push({ t: 'word', v: m[4] })
  }
  return tokens
}

/**
 * Parse a visible_if expression into an AST. Throws Error on invalid syntax.
 * @param {string} src
 */
export function parseCondition(src) {
  if (typeof src !== 'string' || src.trim() === '') throw new Error('empty expression')
  const tokens = tokenize(src)
  let i = 0
  const peek = () => tokens[i]
  const kw = (k) => peek()?.t === 'word' && peek().v.toLowerCase() === k
  const next = () => tokens[i++]

  function value() {
    const tk = next()
    if (!tk || (tk.t !== 'str' && tk.t !== 'word')) throw new Error('value expected')
    return tk.v
  }
  function expr() {
    const parts = [and()]
    while (kw('or')) { next(); parts.push(and()) }
    return parts.length === 1 ? parts[0] : { op: 'or', parts }
  }
  function and() {
    const parts = [term()]
    while (kw('and')) { next(); parts.push(term()) }
    return parts.length === 1 ? parts[0] : { op: 'and', parts }
  }
  function term() {
    const tk = peek()
    if (!tk) throw new Error('condition expected')
    if (tk.t === 'op' && tk.v === '(') {
      next()
      const e = expr()
      const close = next()
      if (!close || close.v !== ')') throw new Error("')' expected")
      return e
    }
    if (tk.t !== 'word') throw new Error('field name expected')
    next()
    const field = tk.v
    const o = next()
    if (!o) throw new Error('operator expected')
    if (o.t === 'op' && ['=', '!=', '>', '>=', '<', '<='].includes(o.v)) return { op: o.v, field, value: value() }
    if (o.t === 'word') {
      const w = o.v.toLowerCase()
      if (w === 'regex') {
        const pattern = value()
        try { new RegExp(pattern) } catch { throw new Error('invalid regex') }
        return { op: 'regex', field, value: pattern }
      }
      if (w === 'empty') return { op: 'empty', field }
      if (w === 'not') {
        const e = next()
        if (!e || e.t !== 'word' || e.v.toLowerCase() !== 'empty') throw new Error("'empty' expected after 'not'")
        return { op: 'not empty', field }
      }
      if (w === 'in') {
        const open = next()
        if (!open || open.v !== '(') throw new Error("'(' expected after 'in'")
        const values = [value()]
        while (peek()?.t === 'op' && peek().v === ',') { next(); values.push(value()) }
        const close = next()
        if (!close || close.v !== ')') throw new Error("')' expected")
        return { op: 'in', field, values }
      }
    }
    throw new Error(`unknown operator '${o.v}'`)
  }

  const ast = expr()
  if (i < tokens.length) throw new Error(`unexpected '${tokens[i].v}'`)
  return ast
}

/** Plain text of a form value (select/lookup values may be { val, label }). */
function textOf(v) {
  if (v && typeof v === 'object' && !Array.isArray(v) && 'val' in v) v = v.val
  return v === null || v === undefined ? '' : String(v)
}

/** Ordering comparison: numeric when both sides are numbers, text otherwise; empty is never true. */
function compare(op, a, b) {
  if (a.trim() === '') return false
  const na = Number(a)
  const nb = Number(b)
  const numeric = b.trim() !== '' && Number.isFinite(na) && Number.isFinite(nb)
  const [x, y] = numeric ? [na, nb] : [a, b]
  switch (op) {
    case '>':  return x > y
    case '>=': return x >= y
    case '<':  return x < y
    case '<=': return x <= y
  }
  return false
}

/** Collect the field names an AST refers to. */
export function conditionFields(ast, out = new Set()) {
  if (ast.parts) ast.parts.forEach(p => conditionFields(p, out))
  else out.add(ast.field)
  return out
}

/** Evaluate an AST against a { field: value } object. */
export function evalCondition(ast, values) {
  switch (ast.op) {
    case 'and': return ast.parts.every(p => evalCondition(p, values))
    case 'or':  return ast.parts.some(p => evalCondition(p, values))
    case '=':   return textOf(values[ast.field]) === ast.value
    case '!=':  return textOf(values[ast.field]) !== ast.value
    case '>': case '>=': case '<': case '<=': return compare(ast.op, textOf(values[ast.field]), ast.value)
    case 'regex': return new RegExp(ast.value).test(textOf(values[ast.field]))
    case 'in':  return ast.values.includes(textOf(values[ast.field]))
    case 'empty':     return textOf(values[ast.field]).trim() === ''
    case 'not empty': return textOf(values[ast.field]).trim() !== ''
  }
  return true
}

const warned = new Set()
const cache = new Map()
function warnOnce(key, msg) {
  if (warned.has(key)) return
  warned.add(key)
  console.warn(msg)
}

/**
 * Evaluate a visible_if expression. Fails safe: an invalid expression, or a field
 * that does not exist (when `knownFields` is given), keeps the element visible
 * and logs one warning.
 *
 * @param {string} expr
 * @param {object} values        { field: value } (editData.core or record.core)
 * @param {string[]} [knownFields] database names of the table's fields
 */
export function evalVisibleIf(expr, values, knownFields = null) {
  let ast = cache.get(expr)
  if (ast === undefined) {
    try { ast = parseCondition(expr) } catch (e) {
      ast = null
      warnOnce(expr, `visible_if "${expr}" ignored: ${e.message}`)
    }
    cache.set(expr, ast)
  }
  if (!ast) return true
  if (knownFields) {
    for (const f of conditionFields(ast)) {
      if (!knownFields.includes(f)) {
        warnOnce(expr + '|' + f, `visible_if "${expr}" ignored: unknown field '${f}'`)
        return true
      }
    }
  }
  try { return evalCondition(ast, values ?? {}) } catch { return true }
}

/**
 * True when a template item, section or accordion panel should be shown:
 * not `hidden` and its `visible_if` (if any) holds.
 */
export function isItemVisible(item, values, knownFields = null) {
  if (isItemHidden(item)) return false
  if (typeof item?.visible_if !== 'string' || item.visible_if.trim() === '') return true
  return evalVisibleIf(item.visible_if, values, knownFields)
}
