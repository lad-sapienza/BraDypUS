# Search module — v5 documentation

## UI spec

Search is embedded directly in **`DataView.vue`** — there is no separate Search view. The search bar is always visible at the top of the records panel once a table is selected.

### Search modes

| Mode | Trigger | Description |
|---|---|---|
| **Fast search** | Text input + Enter / Send button | Full-text LIKE across all preview fields |
| **Advanced search** | Sliders icon | Row-based query builder: field + operator + value, rows joined by AND/OR/XOR connector. Rows are added/removed dynamically. Autocomplete for used values. |
| **Expert (ShortSQL)** | `</>` icon | Free-text ShortSQL input for power users |

### Advanced search UX details

- Field dropdown populated by `getAdvancedConfig()` — includes main table fields and all plugin fields (prefixed with `Plugin › `).
- Operator dropdown populated by `getAdvancedConfig()` — operators are translated client-side via `t(op.key)`.
- Value field: plain text input; clicking shows a popover with values already in use (`getUsedValues()`).
- Connector (AND/OR/XOR) shown between rows.
- "Test" button calls `search_ctrl::test()` to validate the query before submitting; shows record count.

### Search execution

Search does **not** call `search_ctrl` — it calls `record_ctrl::getRecords()` directly, passing the assembled ShortSQL `where` parameter. `search_ctrl` only supplies configuration and validation helpers.

---

## API endpoints (`search_ctrl`)

| Method | HTTP | Privilege | Params | Response |
|---|---|---|---|---|
| `getAdvancedConfig` | GET | `read` | `tb` | `{ fields: [{value, label}], operators: [{value, key}], connectors: ['AND','OR','XOR'] }` |
| `getUsedValues` | GET | `read` | `tb, fld` | `[value, ...]` (JSON array) |
| `test` | GET | `read` | ShortSQL params (same as `getRecords`) | `{ status, code, found: N }` |

### Operator values

| `value` | `key` (i18n) |
|---|---|
| `LIKE` | `contains` |
| `=` | `is_exactly` |
| `NOT LIKE` | `doesnt_contain` |
| `starts_with` | `starts_with` |
| `ends_with` | `ends_with` |
| `is_empty` | `is_empty` |
| `is_not_empty` | `is_not_empty` |
| `>` | `bigger` |
| `<` | `smaller` |

---

## Legacy → v5 mapping

| v4 method | v5 replacement |
|---|---|
| `expertGUI()` | Expert ShortSQL input in `DataView.vue` |
| `advancedGUI()` | Advanced search builder in `DataView.vue` + `getAdvancedConfig()` |

Both legacy methods already carry `@deprecated v5` tags.

---

## Future improvements

- **Saved searches**: persist named search presets per table per user.
- **Search history**: last N searches stored in session / localStorage.
- **Date range picker**: dedicated UI for date fields instead of raw text.
- **`getUsedValues` privilege check**: currently the method has no `canUser()` guard — add `read` check for consistency.
- **`test()` response format**: currently uses raw `echo json_encode()` instead of `$this->returnJson()` — normalise to match the v5 response convention.
