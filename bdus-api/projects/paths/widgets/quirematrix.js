/**
 * quirematrix widget for BraDypUS
 *
 * Renders the physical structure of a manuscript quire as a canvas diagram.
 * The field value must be a quirematrix notation string.
 *
 * Depends on: quireMatrix.min.js (loaded once from the CDN below).
 * See: https://github.com/paths-erc/quireMatrix
 */

const CDN = 'https://cdn.jsdelivr.net/gh/paths-erc/quireMatrix/quireMatrix.min.js'

let _matrixClass  = null
let _loadPromise  = null

function loadLibrary() {
  if (_matrixClass)  return Promise.resolve(_matrixClass)
  if (_loadPromise)  return _loadPromise

  _loadPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src     = CDN
    s.onload  = () => { _matrixClass = window.Matrix; resolve(_matrixClass) }
    s.onerror = () => reject(new Error('Failed to load quireMatrix from CDN'))
    document.head.appendChild(s)
  })
  return _loadPromise
}

export default {
  async mount(container, value) {
    if (!value) return

    container.innerHTML = `
      <div style="font-family:monospace;font-size:0.85rem;color:#888;word-break:break-all;margin-bottom:4px">${value}</div>
      <span></span>
      <canvas width="400" height="200" style="max-width:100%"></canvas>
    `

    // Capture element references NOW, before the async gap.
    // getElementById after an await is unreliable when multiple instances
    // mount concurrently; a direct reference captured here always works.
    const canvasEl = container.querySelector('canvas')
    const halfEl   = container.querySelector('span')

    try {
      const Matrix = await loadLibrary()
      // Matrix accepts a DOM element wrapped in an array-like (jQuery-style).
      const m = new Matrix([canvasEl])
      m.parseString(value, [halfEl])
    } catch (e) {
      canvasEl?.remove()
    }
  },

  unmount(container) {
    container.innerHTML = ''
  }
}
