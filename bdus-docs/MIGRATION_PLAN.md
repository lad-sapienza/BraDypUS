# Documentation Migration Plan — Jekyll → VitePress

> Working document. Status: **draft**. Last updated: 2026-05-28.

---

## Goal

Replace the current hand-maintained Jekyll site at `docs.bdus.cloud` with a
VitePress site that is:

- **Homogeneous** — one toolchain, one deployment pipeline.
- **Easy to maintain** — Markdown files stay in this repo; API-specific docs
  live in `bdus-api` and are pulled at build time.
- **Two-audience** — a `/guide/` section for users & sys-admins, a `/dev/`
  section for developers & AI agents.
- **API reference** — `openapi.yaml` rendered via Scalar (no manual upkeep).

`openapi.yaml` remains in `bdus-api` (it belongs to the API contract).

---

## Repository layout (target)

```
docs/                         ← this repo (lad-sapienza/docs)
├── .github/
│   └── workflows/
│       ├── build.yml         ← fetch remote assets + VitePress build + deploy
│       └── fetch-api-docs.yml (optional scheduled fetch)
├── .vitepress/
│   ├── config.ts             ← site config, sidebars, nav
│   └── theme/                ← custom theme (logo, colours)
├── public/
│   ├── images/               ← moved from images/
│   ├── CNAME                 ← moved from root
│   └── favicon.ico
├── guide/                    ← user / sysadmin docs
│   ├── index.md              ← landing / introduction
│   ├── conventions.md
│   ├── tree-structure.md
│   ├── environment/
│   ├── install/
│   ├── create-app/           ← renamed from create_app/
│   ├── setup/
│   ├── usage/
│   ├── template-system/
│   ├── system-plugins/
│   ├── deploy/
│   ├── api/                  ← ShortSQL docs
│   ├── vocabulary.md         ← renamed from voc.md
│   └── changelog.md          ← fetched from bdus-api/CHANGELOG.md at build
├── dev/                      ← developer / contributor docs (NEW)
│   ├── index.md              ← overview + "start here" for devs
│   ├── architecture.md       ← bootstrapping, routing, controller pattern
│   ├── lib-map.md            ← lib/ namespace directory with roles
│   ├── modules.md            ← 37 modules grouped by function
│   ├── database.md           ← DB layer: multi-engine, migrations, Manage
│   ├── sql-layer.md          ← ShortSQL DSL, QueryObject, SafeQuery
│   ├── record-lifecycle.md   ← Record read / edit / persist pipeline
│   ├── config.md             ← YAML config schema, UAC privilege levels
│   ├── frontend.md           ← Vue 3, Pinia stores, build pipeline
│   ├── testing.md            ← PHPUnit + Hurl test suite
│   ├── migrations.md         ← How to write a DB migration (Mxxx class)
│   ├── oauth.md              ← fetched from bdus-api/docs/oauth.md at build
│   └── widget-api.md         ← fetched from bdus-api/docs/widget-api.md
└── api-reference/
    └── index.md              ← Scalar embed of openapi.yaml
```

---

## Content mapping — existing → new

### Keep and move (path rename only)

| Current path | New path | Notes |
|---|---|---|
| `index.md` | `guide/index.md` | trim Jekyll front-matter |
| `conventions.md` | `guide/conventions.md` | |
| `tree-structure.md` | `guide/tree-structure.md` | |
| `environment/*.md` | `guide/environment/*.md` | |
| `install/*.md` | `guide/install/*.md` | update Docker section |
| `create_app/*.md` | `guide/create-app/*.md` | rename dir (underscore → hyphen) |
| `setup/*.md` | `guide/setup/*.md` | screenshots need refresh |
| `usage/*.md` | `guide/usage/*.md` | |
| `template-system/*.md` | `guide/template-system/*.md` | |
| `system-plugins/*.md` | `guide/system-plugins/*.md` | |
| `deploy/*.md` | `guide/deploy/*.md` | |
| `api/*.md` | `guide/api/*.md` | ShortSQL docs |
| `voc.md` | `guide/vocabulary.md` | |
| `images/` | `public/images/` | |

### Fetch from `bdus-api` at build time

| Source (bdus-api repo) | Destination in this repo | Trigger |
|---|---|---|
| `CHANGELOG.md` | `guide/changelog.md` | every build |
| `docs/oauth.md` | `dev/oauth.md` | every build |
| `docs/widget-api.md` | `dev/widget-api.md` | every build |
| `openapi.yaml` | `public/openapi.yaml` | every build |

The Scalar component in `api-reference/index.md` points to `/openapi.yaml`
(the public copy fetched at build time — no cross-origin request).

### Drop (don't migrate)

| File | Reason |
|---|---|
| `migration-from-v3.md` | v3 is long EOL; archive as a GitHub Gist if needed |
| `design/test-schema.dot` | Source asset, not end-user doc |
| `design/index.md` | Content too thin; fold into a setup guide intro |
| `404.md` | VitePress has built-in 404 handling |
| Jekyll infrastructure | `Gemfile`, `Gemfile.lock`, `_config.yml`, `_data/`, `_layouts/`, `css/`, `js/` |

### Write from scratch (dev/ section)

In priority order — each is a standalone Markdown file:

1. **`dev/architecture.md`** — how `index.php` bootstraps, how `Bdus\App` and
   `Router` work, the controller contract (`Controller` abstract class),
   privilege injection, Twig rendering.
2. **`dev/lib-map.md`** — table of every lib/ namespace with one-line role,
   links to detail pages.
3. **`dev/modules.md`** — 37 modules grouped by function; for each: what it
   does, which endpoints it exposes, whether it has a JS companion.
4. **`dev/database.md`** — `DB\DB` PDO wrapper, `DB\Inspect`, `DB\Alter`,
   `DB\System\Manage`, migration system (`Migrate.php` + `Mxxx` classes).
5. **`dev/sql-layer.md`** — ShortSQL DSL syntax, `SQL\QueryObject`,
   `SQL\SafeQuery`; how `QueryFromRequest` parses HTTP params.
6. **`dev/record-lifecycle.md`** — `Record\Read`, `Record\Edit`,
   `Record\Persist`; plugin hooks; file upload flow.
7. **`dev/config.md`** — YAML config schema (main + per-table), UAC privilege
   levels (0–40), `pref` session preferences.
8. **`dev/frontend.md`** — Vue 3 + Pinia architecture, major views/stores,
   `gulp` build pipeline, CSS/Less structure.
9. **`dev/testing.md`** — PHPUnit bootstrap, `BdusTestCase`, Hurl phase
   conventions, how to run the full suite.
10. **`dev/migrations.md`** — how to write a new `Mxxx` migration class,
    register it in `Migrate.php`, test idempotency.

`dev/oauth.md` and `dev/widget-api.md` are fetched — no manual work needed.

---

## Phase plan

### Phase 0 — Prep (1 session)
- [ ] Add `node_modules/`, `.vitepress/cache/`, `dist/` to `.gitignore`
- [ ] `npm init -y && npm install -D vitepress`
- [ ] Scaffold `.vitepress/config.ts` with nav + dual sidebar (guide / dev)
- [ ] Create `public/CNAME` (content: `docs.bdus.cloud`)
- [ ] Add GitHub Actions workflow `build.yml` (fetch + build + deploy to Pages)
- [ ] Smoke-test: `npx vitepress dev` locally, see empty site

### Phase 1 — Content migration (1–2 sessions)
- [ ] Move all existing Jekyll Markdown into `guide/` (path renames above)
- [ ] Strip Jekyll front-matter (`title:` key → VitePress `# heading` or `title` in frontmatter)
- [ ] Update all internal links (`/conventions` → `/guide/conventions`)
- [ ] Move `images/` → `public/images/`, update `![](../images/…)` references
- [ ] Delete all Jekyll infrastructure files

### Phase 2 — CI pipeline (1 session)
- [ ] GitHub Actions step: fetch `bdus-api` raw files from GitHub API
  ```yaml
  - name: Fetch API docs from bdus-api
    run: |
      BASE=https://raw.githubusercontent.com/lad-sapienza/bdus-api/main
      curl -fsSL $BASE/CHANGELOG.md        -o guide/changelog.md
      curl -fsSL $BASE/docs/oauth.md       -o dev/oauth.md
      curl -fsSL $BASE/docs/widget-api.md  -o dev/widget-api.md
      curl -fsSL $BASE/openapi.yaml        -o public/openapi.yaml
  ```
- [ ] Add `api-reference/index.md` with Scalar web component
  ```html
  <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
  <ApiReference spec-url="/openapi.yaml" />
  ```
- [ ] VitePress config: `vite.assetsInclude: ['**/*.yaml']` if needed

### Phase 3 — Dev docs (multi-session, ongoing)
Write each file in `dev/` in the priority order listed above.
Each file = 1 focused session reading source code and writing accurate docs.

### Phase 4 — Polish & launch
- [ ] Review all screenshots for v5 accuracy; retake or remove stale ones
- [ ] Add OpenGraph/social meta to VitePress theme
- [ ] Verify GitHub Pages deployment (custom domain + HTTPS)
- [ ] Remove Jekyll remnants from repo (confirm `git rm`)
- [ ] Update `bdus-api/README.md` to link to docs.bdus.cloud

---

## GitHub Actions workflow sketch

```yaml
# .github/workflows/build.yml
name: Build and deploy docs

on:
  push:
    branches: [master]
  workflow_dispatch:
  schedule:
    - cron: '0 4 * * *'   # nightly fetch of bdus-api assets

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Fetch assets from bdus-api
        run: |
          BASE=https://raw.githubusercontent.com/lad-sapienza/bdus-api/main
          curl -fsSL "$BASE/CHANGELOG.md"       -o guide/changelog.md
          curl -fsSL "$BASE/docs/oauth.md"      -o dev/oauth.md
          curl -fsSL "$BASE/docs/widget-api.md" -o dev/widget-api.md
          curl -fsSL "$BASE/openapi.yaml"       -o public/openapi.yaml

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - run: npm ci
      - run: npx vitepress build

      - name: Deploy to GitHub Pages
        uses: peaceiris/actions-gh-pages@v4
        with:
          github_token: ${{ secrets.GITHUB_TOKEN }}
          publish_dir: .vitepress/dist
          cname: docs.bdus.cloud
```

---

## VitePress config sketch

```ts
// .vitepress/config.ts
import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'BraDypUS',
  description: 'Web database system for humanities research',
  head: [['link', { rel: 'icon', href: '/favicon.ico' }]],

  themeConfig: {
    logo: '/images/bdus.svg',
    nav: [
      { text: 'Guide',         link: '/guide/' },
      { text: 'Developer',     link: '/dev/' },
      { text: 'API Reference', link: '/api-reference/' },
    ],
    sidebar: {
      '/guide/': guideSidebar(),
      '/dev/':   devSidebar(),
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/lad-sapienza/bdus-api' },
    ],
    editLink: {
      pattern: 'https://github.com/lad-sapienza/docs/edit/master/:path',
    },
  },
})

function guideSidebar() { /* mirror current menu.yaml structure */ }
function devSidebar()   { /* architecture → lib-map → modules → … */ }
```

---

## Content quality notes (from audit)

- **ShortSQL docs** (`api/`) are good and fairly complete — keep as-is.
- **Setup docs** (`setup/`) are detailed but screenshots reference the old UI; 
  flag for screenshot refresh in Phase 4.
- **Template system** (`template-system/`) is thin (18 lines for index.md) — 
  needs expansion.
- **System-plugins** (`system-plugins/`) covers RS and geodata but both are 
  sparse; geodata page has no content yet.
- **Deploy** (`deploy/`) only covers Infinityfree; add Docker section.
- **Usage** (`usage/`) is reasonably complete.
- **Dev docs**: entirely absent — all 10 pages are net-new.

---

## Decisions made

| Decision | Rationale |
|---|---|
| VitePress over Docusaurus/MkDocs | Vue ecosystem (matches bdus-app frontend), excellent multi-sidebar, fast |
| Scalar over Redoc/Swagger UI | Lighter, modern UI, zero-config Markdown embed |
| `openapi.yaml` stays in `bdus-api` | It is the API contract, not a doc artifact |
| Fetch at build time (not git submodule) | Simpler, no credential sharing, auto-syncs nightly |
| Two-sidebar layout (guide / dev) | Different audiences; guide = stable, dev = living doc |
| Drop migration-from-v3 | v3 is EOL; not worth the maintenance cost |
