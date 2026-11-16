/**
 * Keyboard shortcut rules for the record page, kept apart from the component so
 * they can be unit-tested.
 */

/**
 * `E` (no modifiers) opens a record that is being read in edit mode.
 *
 * @param {{key:string, metaKey?:boolean, ctrlKey?:boolean, altKey?:boolean,
 *          shiftKey?:boolean, repeat?:boolean, isComposing?:boolean}} e  keydown event
 * @param {{mode:string, canEdit:boolean, hasRecord:boolean, focusFree:boolean}} ctx
 *        focusFree: the focus is on the page body or on the record navigation, never
 *        on a field, a modal or a drawer (so typing an "e" is never intercepted).
 */
export function isEditShortcut(e, ctx) {
  if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey || e.repeat || e.isComposing) return false
  if (typeof e.key !== 'string' || e.key.toLowerCase() !== 'e') return false
  return ctx.mode === 'read' && ctx.hasRecord && ctx.canEdit && ctx.focusFree
}
