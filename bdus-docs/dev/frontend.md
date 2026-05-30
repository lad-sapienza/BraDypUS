---
title: Frontend
---

# Frontend

The BraDypUS frontend (`bdus-app`) is a Vue 3 single-page application built
with Vite. It communicates exclusively with the PHP backend via REST JSON calls
and never renders server-side HTML.

---

## Tech stack

| Library / tool | Version | Role |
|---|---|---|
| Vue 3 | ^3.5 | Reactivity, components, Composition API |
| Vue Router 4 | ^4.0 | Client-side routing (hash history) |
| Pinia | ^2.0 | Global state management |
| PrimeVue 4 | ^4.0 | UI component library |
| Vite 6 | ^6.0 | Dev server + production bundler |
| MapLibre GL | ^5.24 | Interactive maps (GeoFace view) |
| MapLibre GL Draw | ^1.6 | Geometry drawing on maps |
| Cytoscape + dagre | ^3.33 | Harris matrix graph layout |
| Chart.js + vue-chartjs | ^4.5 | Charts |
| marked | ^18.0 | Markdown rendering |

---

## Directory layout

```
bdus-app/
├── index.html            ← Vite entry point (mounts #app)
├── vite.config.js        ← Build config; VITE_API_BASE env var support
├── src/
│   ├── main.js           ← App bootstrap: Vue + PrimeVue + Pinia + Router
│   ├── App.vue           ← Root component (router-view + global layout)
│   ├── token.js          ← JWT read/write/decode helpers (localStorage)
│   ├── api/
│   │   └── index.js      ← API client (fetch wrapper, auth header, refresh)
│   ├── router/
│   │   └── index.js      ← Route definitions + auth guard
│   ├── stores/
│   │   ├── auth.js       ← Pinia auth store (user, login, logout, refresh)
│   │   └── config.js     ← Pinia config store (app config, table list)
│   ├── composables/
│   │   ├── useDarkMode.js    ← Dark/light mode toggle
│   │   ├── useAppColor.js    ← Primary colour palette + runtime theme switch
│   │   ├── useTables.js      ← Table list loader
│   │   └── useRsRelations.js ← RS relation type loader
│   ├── views/            ← One component per route
│   ├── components/
│   │   ├── record/       ← Record detail sub-components
│   │   ├── config/       ← Config panel sub-components
│   │   └── users/        ← User management sub-components
│   ├── i18n/             ← i18n setup (vue-i18n or custom)
│   └── locale/           ← Translation JSON files
```

---

## Running locally

```bash
cd bdus-app
npm install
npm run dev        # dev server at http://localhost:5173
npm run build      # production build → dist/
npm run preview    # preview the production build
```

**Backend URL**: by default all API calls are relative (same origin). To point
at a separate backend, set the env var before running:

```bash
VITE_API_BASE=https://api.myapp.org npm run dev
```

---

## Routing

The router uses **hash history** (`createWebHashHistory`) so that all URLs
begin with `#/`. This avoids conflicts with PHP routing on the same origin —
the PHP server sees only `/index.html`, and the hash fragment is handled
entirely by the browser.

### Route table

All authenticated routes are namespaced under `/:app` so that URLs are
self-contained deep links — `#/myapp/record/sites/42` works as a bookmark
or shared link without any extra context.

**Public routes** (no auth, no app prefix):

| Path | View |
|---|---|
| `/login` | `LoginView` |
| `/oauth-callback` | `OAuthCallbackView` |
| `/new-app` | `NewAppView` |

**App routes** (require auth, prefixed with `/:app`):

| Path | View |
|---|---|
| `/:app` | `HomeView` |
| `/:app/data` | `DataView` |
| `/:app/record/:tb/:id` | `RecordView` |
| `/:app/record/:tb` | redirect → `/:app/record/:tb/new` |
| `/:app/matrix/:tb` | `MatrixView` |
| `/:app/geoface/:tb` | `GeofaceView` |
| `/:app/config` | `ConfigView` |
| `/:app/users` | `UsersView` |
| `/:app/vocabularies` | `VocabulariesView` |
| `/:app/templates` | `TemplatesView` |
| `/:app/import` | `ImportView` |
| `/:app/backups` | `BackupView` |
| `/:app/history` | `HistoryView` |
| `/:app/deleted-records` | `DeletedRecordsView` |
| `/:app/find-replace` | `SearchReplaceView` |
| `/:app/free-sql` | `FreeSqlView` |
| `/:app/migrations` | `MigrationsView` |
| `/:app/log` | `LogView` |
| `/:app/info` | `InfoView` |

The global navigation guard in `router/index.js` redirects unauthenticated
requests to `/login`. If the `:app` segment in the URL doesn't match the
logged-in app (JWT claim), the guard silently corrects it. The check is
client-side (JWT expiry); the backend validates the signature on every API
call and returns `401` if the token is invalid or expired.

---

## API client (`src/api/index.js`)

All HTTP calls go through the centralised `api` object, never via raw `fetch`
calls in components. It provides:

```js
api.get(path, params?)          // GET with query string
api.post(path, body?, params?)  // POST with JSON body
api.put(path, body?)            // PUT with JSON body
api.patch(path, body?)          // PATCH with JSON body
api.delete(path, params?)       // DELETE
```

Key behaviours:

- **Auth header** — every request adds `Authorization: Bearer <token>`.
- **Proactive refresh** — if the token has less than 30 minutes of validity
  remaining, the client silently calls `/api/auth/refresh` first and stores
  the new token before making the actual request.
- **401 handling** — on `401` the token is cleared and the browser is
  redirected to `#/login`.
- **Asset URLs** — `assetUrl(path)` prepends `VITE_API_BASE` for uploaded
  files and images (e.g. `assetUrl('projects/myapp/files/42.jpg')`).

---

## Auth store (`src/stores/auth.js`)

Pinia store that manages the authenticated user across the session.

```js
const auth = useAuthStore()

auth.user            // { id, name, email, app, privilege_value, can_write }
auth.login(email, password, appName)   // POST /api/auth/login → stores JWT
auth.loginWithToken(token)             // direct token apply (OAuth2 callback)
auth.refresh()                         // silent token refresh
auth.logout()                          // clear token, null user
auth.isAuthenticated()                 // bool: user set + token valid + not expired
auth.updateProfile(fields)             // patch local user fields
```

The JWT payload is decoded client-side (`token.js`) for display purposes only.
Signature verification happens server-side on every API call.

The store restores the user on init from `localStorage` so page reloads within
the same tab do not require re-login.

---

## Config store (`src/stores/config.js`)

Loads and caches the application configuration (table list, app metadata)
from `GET /api/tables`. Provides reactive access to the table list for all
views.

---

## Key views

### `DataView` — table browser

The most-used view. Displays a paginated, filterable list of records for a
given table. Accepts search parameters via route query:

```
/data?tb=sites
/data?tb=sites&search_type=shortSql&where=sites.typology|=|villa
/data?tb=sites&search_type=advanced&adv[0][fld]=name&adv[0][op]=like&adv[0][val]=%villa%
```

When a user clicks a "linked records" link in `RecordView`, they are
navigated to `DataView` with a `shortSql` where expression pre-populated.

### `RecordView` — record detail

Loads a single record via `GET /api/record/:tb/:id` and renders:

- Core fields via `FieldDisplay` / `FieldEditor`
- Plugin rows via `PluginSection`
- Files via `FileGallery`
- Manual links via `ManualLinksSection`
- Stratigraphic relations via `RsSection`
- Print templates via `TemplateSection`
- Version history via `RecordVersionsDrawer`

New records use `:id = 'new'` and POST to `POST /api/record/:tb`.

### `MatrixView` — Harris matrix

Renders a directed graph of stratigraphic relations using Cytoscape + dagre.
Accepts a `where` query parameter (ShortSQL) to restrict the node set.

### `GeofaceView` — map

MapLibre GL map that fetches GeoJSON from `GET /api/geoface?tb=...` and
allows drawing / editing geometries. Supports the same search filter params
as `DataView`.

---

## Record detail components

Located in `src/components/record/`:

| Component | Role |
|---|---|
| `FieldDisplay.vue` | Read-only field rendering (handles all field types) |
| `FieldEditor.vue` | Editable field rendering (select, text, date, boolean…) |
| `PluginSection.vue` | Plugin table rows (CRUD) |
| `FileGallery.vue` | File list with upload, sort, delete |
| `ManualLinksSection.vue` | Free-form cross-record links |
| `RsSection.vue` | Stratigraphic relation editor + matrix link |
| `TemplateSection.vue` | Print template selector + preview |
| `RecordVersionsDrawer.vue` | Version history + restore |
| `RsGraph.vue` | Inline mini Cytoscape graph for RS |
| `DynamicWidget.vue` | Renders a public widget |

---

## Environment variables

| Variable | Default | Effect |
|---|---|---|
| `VITE_API_BASE` | `''` (same origin) | Base URL for all API calls and asset URLs |

Set in `.env`, `.env.local`, or shell before running Vite:

```bash
# .env.production
VITE_API_BASE=https://api.myapp.org
```

---

## Build output

`npm run build` produces `dist/`. The output is a static site — deploy it to
any web server or CDN. All routes resolve to `index.html` (hash routing means
no server-side rewrite rules are needed).

When the frontend and backend are served from the same origin, `VITE_API_BASE`
can be left empty and `dist/` can be placed inside the PHP project root.
