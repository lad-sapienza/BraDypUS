// Synchronous, per-field validation rules shared by the record form.
//
// Single source of truth for FieldEditor (inline message) and RecordView
// (pre-save gate): both used to carry their own copy of these rules.
// The server (Record::validateFieldValue) stays authoritative and mirrors them.
// `no_dupl` is asynchronous and lives in FieldEditor.

const RE_INT   = /^-?\d+$/
const RE_REAL  = /^-?\d+(\.\d+)?$/          // decimal point only, no comma, no exponent
const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * @param {object} schema  field schema (check[], required, min, max, max_length, pattern, type)
 * @param {*}      value
 * @returns {null | [string, ...any[]]}  null when valid, else [i18nKey, ...i18nArgs]
 */
export function validateField(schema, value) {
  const check   = schema.check ?? []
  const isEmpty = value === null || value === undefined || value === ''

  if (schema.required && isEmpty) return ['field_required']
  if (isEmpty) return null

  const s = String(value)

  if (check.includes('int')   && !RE_INT.test(s))   return ['value_must_be_integer']
  if (check.includes('real')  && !RE_REAL.test(s))  return ['value_must_be_real']
  if (check.includes('email') && !RE_EMAIL.test(s)) return ['invalid_email']

  if (schema.type === 'date') {
    if (schema.min && s < String(schema.min)) return ['value_too_small', schema.min]
    if (schema.max && s > String(schema.max)) return ['value_too_large', schema.max]
  } else {
    if (schema.min != null && Number(value) < Number(schema.min)) return ['value_too_small', schema.min]
    if (schema.max != null && Number(value) > Number(schema.max)) return ['value_too_large', schema.max]
  }

  if (schema.max_length && s.length > Number(schema.max_length)) return ['value_too_long', schema.max_length]

  if (schema.pattern) {
    try {
      if (!new RegExp(schema.pattern).test(s)) return ['invalid_format']
    } catch { /* invalid regex in config — ignore */ }
  }

  return null
}
