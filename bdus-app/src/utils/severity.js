// PrimeVue severity names ('warn', 'danger', 'secondary'...) → AntD Tag preset colors.
const TAG_COLOR = {
  success:   'success',
  info:      'processing',
  warn:      'warning',
  warning:   'warning',
  danger:    'error',
  error:     'error',
  secondary: 'default',
  contrast:  'default',
}

export function severityToTagColor(severity) {
  return TAG_COLOR[severity] || 'default'
}
