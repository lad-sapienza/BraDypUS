# Info module — v5 documentation

## UI spec

Accessible via the **Info** nav item (any authenticated user with `read` privilege).

`InfoView.vue` displays:

- **Version badge** — current BraDypUS version string.
- **Changelog** — full `CHANGELOG.md` rendered as HTML (Markdown → HTML conversion done server-side via `Michelf\Markdown`).

No user interaction beyond scrolling.

---

## API endpoints (`info_ctrl`)

| Method | HTTP | Privilege | Params | Response |
|---|---|---|---|---|
| `getInfo` | GET | `read` | — | `{ version: string, changelog_html: string }` |

`getIP()` is an internal server utility (returns the host machine's IP address). It is not exposed in the v5 UI but remains available for debugging.

---

## Legacy → v5 mapping

| v4 method | Status |
|---|---|
| `copyright()` | `@deprecated v4` — Twig renderer, replaced by `InfoView.vue` + `getInfo()` |
| `getIP()` | Kept as-is — internal utility, not surfaced in UI |

---

## Future improvements

- **System diagnostics panel**: PHP version, loaded extensions, DB version, disk usage.
- **Update check**: compare current version against latest GitHub release tag.
