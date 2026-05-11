# Data — Record Browser

## Overview

Two-panel layout for browsing, searching and (future) editing records in any application table.  
Route: `/data` · Vue view: `DataView.vue`  
PHP endpoints: `home_ctrl::listTables`, `record_ctrl::getRecords`, `search_ctrl::getAdvancedConfig`, `search_ctrl::getUsedValues`

---

## UI

```
┌─ Table sidebar (200px) ──┬─ Records panel ────────────────────────────────┐
│ DATA MANAGEMENT          │                                                │
│ ─────────────────        │  ┌─ Search bar ──────────────────────────────┐ │
│ 📋 Manuscripts           │  │ [🔍 fast search…] [Send]  | [⚙] [</>]    │ │
│ 📋 Collections           │  └───────────────────────────────────────────┘ │
│ 📋 Works                 │  ┌─ Advanced panel (collapsible) ─────────────┐ │
│ …                        │  │ [AND▾] [(] [Field▾] [Operator▾] [value] [)] [−] │
│                          │  │ [AND▾] [(] [Field▾] [Operator▾] [value] [)] [−] │
│                          │  │ [+ Add row]  [Advanced search]  [Reset]    │ │
│                          │  └───────────────────────────────────────────┘ │
│                          │  ┌─ SQL Expert panel (collapsible) ───────────┐ │
│                          │  │ WHERE …                                    │ │
│                          │  │ [monospace textarea]                        │ │
│                          │  │ [Search]  [Reset]                          │ │
│                          │  └───────────────────────────────────────────┘ │
│                          │                                                │
│                          │  Manuscripts   1,234 records found             │
│                          │  ┌─ DataTable (lazy, server-side) ──────────┐ │
│                          │  │ ID │ CMCL ID │ TM │ … paginator          │ │
│                          │  └───────────────────────────────────────────┘ │
└──────────────────────────┴────────────────────────────────────────────────┘
```

---

## Search modes

### Fast search
- Single text field, searches all fields in the table via `LIKE '%…%'`
- Triggered on Enter or "Send" button
- GET request; no panel needed

### Advanced search (row builder)
- Fields loaded lazily from `search_ctrl::getAdvancedConfig` when panel first opens
- Each row: **connector** (AND/OR/XOR, hidden on first row) · **(** · **field** (dropdown, including plugin fields) · **operator** · **value** (AutoComplete with `getUsedValues`) · **)** · **remove**
- Rows sent as `adv[]` array to `record_ctrl::getRecords` (POST, JSON body)
- Empty rows (no field or no value for non-is_empty operators) are silently skipped
- Resetting returns to "all records" mode

### SQL Expert
- Raw WHERE clause written by the user (textarea, monospace)
- Passed as `querytext` to `record_ctrl::getRecords` (POST)
- Empty input = show all records (falls back to `1=1`)
- Errors (e.g. wrong column name) are shown in a toast with the actual DB engine message

---

## API Endpoints

### `GET listTables` — table list
```
GET /index.php?obj=home_ctrl&method=listTables
Response: { tables: [{ name: string, label: string }, ...] }
```

### `GET getRecords` — all / fast search
```
GET /index.php?obj=record_ctrl&method=getRecords
    &tb=TABLE&page=1&per_page=30
    &sort_field=FIELD&sort_dir=asc|desc
    &search_type=all|fast&search=STRING
Response: { total: int, fields: [{name,label},...], data: [{...},...] }
```

### `POST getRecords` — advanced / expert search
```
POST /index.php?obj=record_ctrl&method=getRecords&tb=TABLE
Content-Type: application/json
{
  page, per_page, sort_field, sort_dir,
  search_type: "advanced",
  adv: [
    { connector:"", "(":false, fld:"tb:field", operator:"LIKE", value:"x", ")":false },
    { connector:"AND", ...}
  ]
}
```
```json
{
  search_type: "sqlExpert",
  querytext: "cmclid LIKE 'P.Bodm%'",
  join: ""
}
```
Response: same as GET.

### `GET getAdvancedConfig` — field + operator lists for builder
```
GET /index.php?obj=search_ctrl&method=getAdvancedConfig&tb=TABLE
Response: {
  fields:     [{ value:"tb:field", label:"Field label" }, ...],
  operators:  [{ value:"LIKE", label:"contains" }, ...],
  connectors: [{ value:"AND", label:"AND" }, ...]
}
```
Includes plugin fields (grouped as "Plugin label › Field label").

### `GET getUsedValues` — autocomplete values for a field
```
GET /index.php?obj=search_ctrl&method=getUsedValues&tb=TABLE&fld=FIELD
Response: ["value1", "value2", ...]
```
Handles `id_from_tb` fields (vocabulary lookups) transparently.

---

## Advanced search — operator reference

| Value         | Meaning              | SQL equivalent                        |
|---------------|----------------------|---------------------------------------|
| `LIKE`        | contains             | `field LIKE '%val%'`                  |
| `=`           | is exactly           | `field = 'val'`                       |
| `NOT LIKE`    | does not contain     | `field NOT LIKE '%val%'`              |
| `starts_with` | starts with          | `field LIKE 'val%'`                   |
| `ends_with`   | ends with            | `field LIKE '%val'`                   |
| `is_empty`    | is empty / NULL      | `field = '' OR field IS NULL`         |
| `is_not_empty`| is not empty / NULL  | `field != '' AND field IS NOT NULL`   |
| `>`           | greater than         | `field > 'val'`                       |
| `<`           | less than            | `field < 'val'`                       |

---

## Legacy (v4) — to remove after migration

| Method / file           | Status           | Replacement                          |
|-------------------------|------------------|--------------------------------------|
| `search_ctrl::advancedGUI()` | `@deprecated v5` | `getAdvancedConfig()` + Vue builder  |
| `search_ctrl::expertGUI()`   | `@deprecated v5` | Vue SQL Expert panel                 |
| `tmpl/advanced.twig`         | obsolete         | Vue component                        |
| `tmpl/expertGUI.twig`        | obsolete         | Vue component                        |
| `search.js`                  | obsolete         | Vue component                        |
| `record_ctrl::showResults()` | `@deprecated v5` | `getRecords()` JSON                  |
| `record_ctrl::sql2json()`    | `@deprecated v5` | `getRecords()` JSON                  |

---

## Future improvements

### 🔮 Directus-style visual filter builder
The current row-based builder mirrors the v4 UI (connector + field + operator + value + parentheses).  
A future version should adopt a modern nested-group builder similar to Directus or Metabase:
- Groups with AND/OR connector at the group level (not per-row)
- Nested groups (group within group)
- Drag-and-drop reordering
- Visual grouping with colour-coded indentation  
This would replace the row model entirely and require a new backend representation (JSON tree → SQL via a dedicated builder class).

### 🔮 Record editing
Clicking a row should open the record for editing (currently view-only list).  
This requires migrating `record_ctrl::show()` (Twig) → a Vue `RecordView.vue` component,  
which is a significant effort (custom Twig templates per table, plugin fields, file uploads, geodata).

### 🔮 Column selection / visibility
Users should be able to show/hide columns in the DataTable (currently fixed to preview fields from config).

### 🔮 Bookmarks / saved searches
The v4 UI encodes the search as `obj_encoded` (SafeQuery) for bookmarking.  
Vue should support saving/restoring searches via the URL hash or a saved-queries module.

### 🔮 Export from search results
After a search, users should be able to export the result set to CSV/JSON/Excel  
via `myExport_ctrl` (already exists in v4).
