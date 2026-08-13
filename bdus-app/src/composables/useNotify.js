/**
 * useNotify — drop-in replacements for `primevue/usetoast` and
 * `primevue/useconfirm`, backed by Ant Design Vue's message/Modal.
 *
 * Call signatures mirror the PrimeVue originals (`toast.add(...)`,
 * `confirm.require(...)`) so call sites don't need to change — only the
 * import path does. Uses AntD's <a-app> context (App.useApp(), wired in
 * App.vue) rather than the static message/Modal.confirm API so dark mode
 * and the app's brand color propagate correctly.
 */
import { h } from 'vue'
import { App } from 'ant-design-vue'
import { useI18n } from '@/i18n'
import { resolveIcon } from '@/utils/icons'

const MESSAGE_TYPE = {
  error:    'error',
  danger:   'error',
  success:  'success',
  warn:     'warning',
  warning:  'warning',
  info:     'info',
  secondary: 'info',
}

export function useToast() {
  const { message } = App.useApp()
  return {
    add({ severity = 'info', summary, detail, life = 3000 } = {}) {
      const type    = MESSAGE_TYPE[severity] || 'info'
      const content = summary && detail ? `${summary}: ${detail}` : (summary || detail || '')
      message[type](content, life === 0 ? 0 : life / 1000)
    },
  }
}

function isDanger(opts) {
  return opts.severity === 'danger' || opts.severity === 'warn'
    || opts.acceptProps?.severity === 'danger'
    || (typeof opts.acceptClass === 'string' && /danger|warning/.test(opts.acceptClass))
}

export function useConfirm() {
  const { modal } = App.useApp()
  const { t } = useI18n()
  return {
    require(opts = {}) {
      const { message: content, header, icon, acceptProps, rejectProps, acceptLabel, rejectLabel, accept, reject } = opts
      modal.confirm({
        title:       header,
        content,
        icon:        resolveIcon(icon) ? () => h(resolveIcon(icon)) : undefined,
        okText:      acceptProps?.label || acceptLabel || t('yes'),
        cancelText:  rejectProps?.label || rejectLabel || t('no'),
        okType:      isDanger(opts) ? 'danger' : 'primary',
        onOk:        accept,
        onCancel:    reject,
      })
    },
  }
}
