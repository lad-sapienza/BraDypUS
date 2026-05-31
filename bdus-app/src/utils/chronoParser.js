/**
 * BraDypUS fuzzy-date parser — JavaScript port of lib/Chrono/Parser.php.
 *
 * Grammar:
 *   input   = '?'              → undated
 *           | '?' '/' token    → ante quem
 *           | token '/' '?'    → post quem
 *           | token '/' token  → explicit range
 *           | token            → point or century range
 *
 *   century = 'c' N qualifier? era    (N = 1–99, era = BCE|CE)
 *   year    = '-' N | N 'BCE'? | N 'CE'
 *
 *   qualifier = e | m | l | h1 | h2 | q1 | q2 | q3 | q4
 */

const QUALIFIERS = {
  e:  [0,  24],
  m:  [25, 74],
  l:  [75, 99],
  h1: [0,  49],
  h2: [50, 99],
  q1: [0,  24],
  q2: [25, 49],
  q3: [50, 74],
  q4: [75, 99],
  '': [0,  99],
}

const QUALIFIER_LABELS = {
  e:  'Early',
  m:  'Mid',
  l:  'Late',
  h1: 'First half of',
  h2: 'Second half of',
  q1: 'First quarter of',
  q2: 'Second quarter of',
  q3: 'Third quarter of',
  q4: 'Fourth quarter of',
  '': '',
}

const CENTURY_RE = /^c(\d{1,2})(e|m|l|h1|h2|q[1-4])?\s+(BCE|CE)$/i
const YEAR_NEG   = /^-(\d{1,5})$/
const YEAR_POS   = /^(\d{1,5})\s*(BCE|CE)?$/i

/**
 * Parse a full input string.
 *
 * @param {string} input
 * @returns {{ from: number|null, to: number|null, label: string, valid: boolean, error: string|null }}
 */
export function parse(input) {
  const s = (input ?? '').trim()

  if (s === '' || s === '?') {
    return { from: null, to: null, label: 'Undated', valid: true, error: null }
  }

  try {
    const parts = s.split('/')
    if (parts.length === 1) {
      const t = parseToken(parts[0].trim())
      return { from: t.from, to: t.to, label: t.label, valid: true, error: null }
    }

    const [left, right] = [parts[0].trim(), parts[1].trim()]

    if (left === '?' && right === '?') {
      return { from: null, to: null, label: 'Undated', valid: true, error: null }
    }

    if (left === '?') {
      const t = parseToken(right)
      return { from: null, to: t.to, label: 'Ante quem: ' + t.label, valid: true, error: null }
    }

    if (right === '?') {
      const t = parseToken(left)
      return { from: t.from, to: null, label: 'Post quem: ' + t.label, valid: true, error: null }
    }

    const tl = parseToken(left)
    const tr = parseToken(right)
    if (tl.from > tr.to) throw new Error('Range start is after range end')

    return { from: tl.from, to: tr.to, label: tl.label + ' – ' + tr.label, valid: true, error: null }

  } catch (e) {
    return { from: null, to: null, label: '', valid: false, error: e.message }
  }
}

/**
 * Format stored from/to integers into a human-readable label.
 *
 * @param {number|null} from
 * @param {number|null} to
 * @returns {string}
 */
export function format(from, to) {
  if (from == null && to == null) return 'Undated'
  if (from == null) return 'Ante quem: ' + yearLabel(to)
  if (to == null)   return 'Post quem: ' + yearLabel(from)
  if (from === to)  return yearLabel(from)
  return yearLabel(from) + ' – ' + yearLabel(to)
}

// ── Private ──────────────────────────────────────────────────────────────────

function parseToken(token) {
  const t = token.trim()

  let m

  m = t.match(CENTURY_RE)
  if (m) {
    return centuryToRange(parseInt(m[1], 10), (m[2] ?? '').toLowerCase(), m[3].toUpperCase())
  }

  m = t.match(YEAR_NEG)
  if (m) {
    const y = -parseInt(m[1], 10)
    return { from: y, to: y, label: yearLabel(y) }
  }

  m = t.match(YEAR_POS)
  if (m) {
    const n   = parseInt(m[1], 10)
    const era = (m[2] ?? 'CE').toUpperCase()
    const y   = era === 'BCE' ? -n : n
    return { from: y, to: y, label: yearLabel(y) }
  }

  throw new Error(`Cannot parse token: '${t}'`)
}

function centuryToRange(century, qualifier, era) {
  if (century < 1 || century > 99) throw new Error(`Century must be 1–99, got ${century}`)
  if (!(qualifier in QUALIFIERS))  throw new Error(`Unknown qualifier: '${qualifier}'`)

  const [pFrom, pTo] = QUALIFIERS[qualifier]
  let from, to

  if (era === 'BCE') {
    const base = -(century * 100)
    from = base + pFrom
    to   = base + pTo
  } else {
    const base = (century - 1) * 100 + 1
    from = base + pFrom
    to   = base + pTo
  }

  return { from, to, label: centuryLabel(century, qualifier, era) }
}

function centuryLabel(century, qualifier, era) {
  const ordStr   = ordinal(century)
  const qualStr  = QUALIFIER_LABELS[qualifier] ?? ''
  const centPart = `${ordStr} cent. ${era}`
  return qualStr ? `${qualStr} ${centPart}` : centPart
}

function yearLabel(year) {
  return year < 0 ? `${Math.abs(year)} BCE` : `${year} CE`
}

function ordinal(n) {
  const abs    = Math.abs(n)
  const mod100 = abs % 100
  const mod10  = abs % 10
  if (mod100 >= 11 && mod100 <= 13) return `${n}th`
  if (mod10 === 1) return `${n}st`
  if (mod10 === 2) return `${n}nd`
  if (mod10 === 3) return `${n}rd`
  return `${n}th`
}
