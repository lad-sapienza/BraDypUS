# Widget API

Widgets are per-application display extensions that render a field value in read
mode using custom logic (diagrams, maps, formatted text, …).  
They live entirely outside the bdus-app bundle: the frontend loads them at
runtime from the PHP API, so **adding or changing a widget never requires
rebuilding the application**.

---

## How it works

```
projects/{app}/widgets/{name}.js   ← you write this
         │
         └─ served by PHP at  GET /api/widget/{name}
                  │
                  └─ dynamically imported by DynamicWidget.vue
                           │
                           └─ mount(container, value) called on render
```

The field schema carries a `widget` property (set in the table configuration
UI).  When `FieldDisplay.vue` encounters it, `DynamicWidget.vue` fetches the
widget module for that field and delegates rendering to it.

---

## File location and naming

| What | Rule |
|------|------|
| Directory | `projects/{app}/widgets/` |
| File name | `{name}.js` — **only** lowercase letters, digits and dashes (`[a-z0-9-]+`) |
| Module format | ES module (`export default { … }`) |

Examples of valid names: `quirematrix`, `my-map`, `timeline2`.  
Underscores, uppercase letters, spaces and any other character are rejected by
the backend and the widget will not be discovered.

> The naming restriction also prevents path-traversal attacks: the backend
> validates the name with the same regex before reading any file.

---

## The module contract

A widget file must export a **plain object** (no framework dependency) with the
following shape:

```js
export default {
  /**
   * Called when the field is rendered (and again whenever the value changes).
   *
   * @param {HTMLElement} container  - The host <div> managed by DynamicWidget.
   *                                   Always empty when mount() is called.
   * @param {string}      value      - The raw field value as stored in the DB.
   */
  mount(container, value) {
    // Render into container.
    // container.innerHTML is already cleared before this call.
  },

  /**
   * Called when the component is unmounted (navigation away, record closed…).
   * Optional — implement only if you need to release resources (timers,
   * event listeners, external library instances, …).
   *
   * @param {HTMLElement} container
   */
  unmount(container) {
    container.innerHTML = ''
  },
}
```

### Rules

- **`mount` is required.** A widget without it is silently ignored and the raw
  value is displayed as plain text instead.
- **`unmount` is optional.** If present it is called before the component is
  destroyed.
- `mount` may be `async` — `DynamicWidget.vue` awaits it.
- The `container` element is a `<div class="dynamic-widget" style="width:100%">`.
  You own its entire subtree; do not manipulate anything outside it.
- `value` is always a `string`. If the field stores a number, date, or JSON
  blob you must parse it inside the widget.
- `mount` is called again (with the same container, already cleared) whenever
  `value` changes while the component is mounted. Stateful widgets must handle
  re-entry cleanly.

---

## Loading external libraries

If the widget depends on a third-party library not bundled in bdus-app, load it
lazily from a CDN using a dynamic `<script>` tag.  
Use the **singleton / promise-cache** pattern so the library is fetched only
once, regardless of how many fields on the page use the widget:

```js
const CDN = 'https://cdn.jsdelivr.net/...'

let _lib      = null   // resolved library reference
let _loading  = null   // in-flight Promise (prevents duplicate requests)

function loadLib() {
  if (_lib)     return Promise.resolve(_lib)
  if (_loading) return _loading

  _loading = new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src     = CDN
    s.onload  = () => { _lib = window.MyLib; resolve(_lib) }
    s.onerror = () => reject(new Error('Failed to load MyLib'))
    document.head.appendChild(s)
  })
  return _loading
}

export default {
  async mount(container, value) {
    const MyLib = await loadLib()
    // … render with MyLib …
  },
}
```

---

## Error handling

`DynamicWidget.vue` wraps the `import()` call in a try/catch: if the module
fails to load (network error, syntax error, …) the raw value is displayed as
plain text — no crash.

Inside `mount`, you are responsible for your own errors.  
A recommended pattern:

```js
mount(container, value) {
  try {
    // … render …
  } catch (e) {
    container.textContent = value   // degrade gracefully
  }
},
```

---

## Associating a widget with a field

In the table configuration UI, every field has an optional **Widget** dropdown
that lists the `.js` files discovered in `projects/{app}/widgets/`.  
Select the widget name and save: from that point on, every record that displays
that field will use the widget.

The raw value is always stored as plain text in the database — the widget only
affects display.

---

## Backend API endpoints

These are used internally by `DynamicWidget.vue` and are available to any
`read`-privileged session.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/widgets` | Returns `{ widgets: string[] }` — sorted list of available widget names for the current app |
| `GET` | `/api/widget/{name}` | Serves the widget JS file as `application/javascript` |

---

## Minimal example

A widget that renders the value in bold inside a coloured box:

```js
// projects/{app}/widgets/highlight.js

export default {
  mount(container, value) {
    container.innerHTML = `
      <div style="
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 4px;
        padding: 0.4rem 0.75rem;
        font-weight: 600;
      ">${String(value).replace(/</g, '&lt;')}</div>
    `
  },

  unmount(container) {
    container.innerHTML = ''
  },
}
```

---

## Reference implementation

`projects/paths/widgets/quirematrix.js` — renders the physical structure of a
manuscript quire as a canvas diagram using the
[quireMatrix](https://github.com/paths-erc/quireMatrix) library loaded from the
jsDelivr CDN.  It demonstrates:

- CDN loading with the singleton/promise-cache pattern
- Unique element ID generation for multiple instances on the same page
- Graceful fallback when the library fails to load
- An `unmount` implementation that clears the container
