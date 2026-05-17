# RS — Rapporti Stratigrafici (Stratigraphic Relations)

## Overview

Two-level UI for managing Harris Matrix stratigraphic relations:
1. **RsSection** — embedded panel inside `RecordView`, shows the 3×4 Harris grid for a single
   record and allows adding/removing relations.
2. **MatrixView** — full-page graph (`/matrix/:tb`) built with Cytoscape.js + cytoscape-dagre,
   showing the complete relation graph for a table or a filtered subset.

PHP endpoints: `record_ctrl::addRs`, `record_ctrl::deleteRs`, `record_ctrl::getRsMatrix`  
RS field exposed by: `record_ctrl::buildTableSchema`, `record_ctrl::getRecord`, `home_ctrl::listTables`

RS is only available for tables that have an `"rs": "FIELD_NAME"` key in their JSON config.
The value names the field whose content is used as the node identifier in the RS table
(typically `"id"` for the numeric primary key, but can be any field, e.g. a stratigraphic unit label).

---

## Relation types

Ten relation types in five inverse pairs. Relations 9 and 10 are self-inverse (symmetric).

| Code | Label (EN) | Inverse |
|------|------------|---------|
| 1    | is covered by | 5 (covers) |
| 2    | is cut by     | 6 (cuts)   |
| 3    | carries       | 7 (leans on) |
| 4    | is filled by  | 8 (fills)  |
| 5    | covers        | 1          |
| 6    | cuts          | 2          |
| 7    | leans on      | 3          |
| 8    | fills         | 4          |
| 9    | is the same as | 9 (symmetric) |
| 10   | is bound to    | 10 (symmetric) |

All relations are stored as `(tb, first, second, relation)` in the shared `{prefix}rs` system table.
Duplicate detection covers both the direct direction and the symmetric inverse before insertion.

---

## UI — RsSection (RecordView panel)

Visible only when `schema.rs_field` is present and the record is not new (`id !== 'new'`).

```
┌─ Stratigraphic relations ────────────────────────────────────────────────────┐
│                                                                               │
│  Row 1 (relations where SELF is below):                                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐                     │
│  │  is      │  │  is cut  │  │  carries │  │  is      │                     │
│  │ covered  │  │   by     │  │          │  │ filled   │                     │
│  │  by      │  │          │  │          │  │  by      │                     │
│  │ [node]   │  │ [node]   │  │ [node]   │  │ [node]   │                     │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘                     │
│                                                                               │
│  Row 2 (self + symmetric):                                                    │
│  ┌──────────┐  ┌──────────────────────┐  ┌──────────┐                       │
│  │  same as │  │       ★ SELF ★        │  │ bound to │                       │
│  │ [node]   │  │      (identifier)     │  │ [node]   │                       │
│  └──────────┘  └──────────────────────┘  └──────────┘                       │
│                                                                               │
│  Row 3 (relations where SELF is above):                                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐                     │
│  │  covers  │  │  cuts    │  │  leans   │  │  fills   │                     │
│  │          │  │          │  │   on     │  │          │                     │
│  │ [node]   │  │ [node]   │  │ [node]   │  │ [node]   │                     │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘                     │
│                                                                               │
│  [＋ Add relation]  relation: [dropdown▾]  identifier: [text input]  [Save]  │
└───────────────────────────────────────────────────────────────────────────────┘
```

**Grid layout:** 4 columns × 3 rows. Self node spans columns 2–3 of row 2.
Relation codes 1–4 appear in row 1 (self is the upper stratum — second in the DB row).
Relation codes 5–8 appear in row 3 (self is the lower stratum — first in the DB row).
Relations 9–10 appear in row 2, flanking the self node.

**Polarity resolution:** The DB always stores relations as `(first, second, relation)`.
When the current record's identifier matches `first`, the relation code is used as-is.
When it matches `second`, the display code is inverted: 1↔5, 2↔6, 3↔7, 4↔8 (9 and 10 stay).

**Delete:** Each node chip has a delete icon. Deletion is immediate (no undo).
After any add/delete, `rs-updated` is emitted → `RecordView` re-fetches the record.

**Visibility in view mode:** RsSection is shown in both view and edit mode (RS edits are
always "live" — they do not depend on the record edit lifecycle).

---

## UI — MatrixView (full-page graph)

Route: `/matrix/:tb`  Optional query params: same as DataView search (forwarded to `getRsMatrix`).

```
┌─ Toolbar ──────────────────────────────────────────────────────────────────┐
│ Harris Matrix  — TABLE LABEL   [N nodes] [M relations]  [Reload] [Export PNG] │
└────────────────────────────────────────────────────────────────────────────┘
┌─ Cytoscape canvas (flex: 1) ───────────────────────────────────────────────┐
│                                                                              │
│     ┌──────┐      ┌──────┐                                                  │
│     │  US1 │─────▶│  US2 │  (directed edge: "cov.by")                       │
│     └──────┘      └──────┘                                                  │
│                       │                                                      │
│                       ▼                                                      │
│                   ┌──────┐  (isolated node, dashed border = out of filter)  │
│                   │  US3 ╌╌╌                                                │
│                   └──────┘                                                  │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Node styles:**
- Default: white fill, blue border — record is in the filtered result set.
- Highlighted (`?highlight=ID`): orange fill — the record the user navigated from.
- Out-of-filter (`in_filter: false`): dashed grey border, grey text — appears in a
  relation with a filtered node but was not itself in the query result.

**Edge styles:**
- Directed (relations 1–8): solid line with arrowhead.
- Undirected (relations 9–10): dashed line, no arrowhead.
- Edge label: abbreviated relation name (e.g. "cov.by", "cuts", "=", "~").

**Subset mode:** when launched from DataView via the "Harris Matrix" button, all current
URL query params (search_type, search, adv, where, querytext…) are forwarded to `getRsMatrix`.
The PHP backend re-executes the same query internally, so only the filtered records appear as
solid-border nodes; their relations pull in dangling out-of-filter nodes automatically.

**Node click:** navigates to `/record/:tb/:db_id`.

**Export PNG:** calls `cy.png({ output: 'blob', bg: 'white', full: true, scale: 2 })` and
triggers a browser download of `harris-matrix.png` at 2× resolution.

---

## DataView integration

A "Harris Matrix" button appears in the DataView toolbar only when the selected table has
`rs_field` set (i.e. the table's config includes an `"rs"` key). Clicking it opens MatrixView,
passing all current search query params so the graph reflects the active filter.

---

## API Endpoints

### `GET buildTableSchema` — schema includes rs_field
```
GET /index.php?obj=record_ctrl&method=getRecord&tb=TABLE&id=ID
Response (partial):
{
  "schema": {
    "fields": [...],
    "plugins": [...],
    "rs_field": "id"          ← null if table has no RS config
  },
  "rs": [
    { "id": 1, "tb": "...", "first": "12", "second": "15", "relation": 5 },
    ...
  ],
  ...
}
```

### `GET listTables` — includes rs_field per table
```
GET /index.php?obj=home_ctrl&method=listTables
Response:
{
  "tables": [
    { "name": "us", "label": "Stratigraphic Units", "rs_field": "us_num" },
    { "name": "finds", "label": "Finds", "rs_field": null },
    ...
  ]
}
```

### `POST addRs` — insert a relation
```
POST /index.php?obj=record_ctrl&method=addRs
Content-Type: application/x-www-form-urlencoded
Body: tb=TABLE&first=IDENT_A&relation=CODE&second=IDENT_B

Success:
  { "status": "success", "code": "ok_relation_add", "id": 42 }

Errors:
  { "status": "error", "code": "parameter_missing" }
  { "status": "error", "code": "relation_already_exist" }   ← direct or symmetric duplicate
  { "status": "error", "code": "db_error", "detail": "..." }
```

Duplicate check covers:
1. Exact match — same `(tb, first, second, relation)` already exists.
2. Symmetric inverse — `(tb, second, first, inverse_relation)` already exists, where the
   inverse is obtained by swapping pairs 1↔5, 2↔6, 3↔7, 4↔8 (9 and 10 are their own inverse).

### `GET deleteRs` — remove a relation by RS row id
```
GET /index.php?obj=record_ctrl&method=deleteRs&id=ROW_ID

Success:
  { "status": "success", "code": "ok_relation_erased" }

Errors:
  { "status": "error", "code": "parameter_missing" }
  { "status": "error", "code": "not_found" }
```

### `GET getRsMatrix` — full graph data for a table (optionally filtered)
```
GET /index.php?obj=record_ctrl&method=getRsMatrix
    &tb=TABLE
    [&search_type=shortSql&where=FIELD|OP|VALUE]
    [&search_type=fast&search=STRING]
    [&search_type=advanced&adv=JSON]
    [&search_type=sqlExpert&querytext=SQL&join=SQL]

Success:
{
  "rs_field": "us_num",
  "nodes": [
    { "db_id": 7, "identifier": "12", "in_filter": true },
    { "db_id": 9, "identifier": "15", "in_filter": false },
    ...
  ],
  "relations": [
    { "id": 1, "first": "12", "second": "15", "relation": 5 },
    ...
  ]
}

Errors:
  { "status": "error", "code": "parameter_missing" }
  { "status": "error", "code": "rs_not_configured" }
```

**`in_filter` semantics:**
- `true` — node belongs to the query result set (or no filter was applied).
- `false` — node appears in a relation with a filtered node but is not itself in the result set
  ("dangling" node). Shown with attenuated style in the graph.

**No filter:** all records in the table appear as nodes with `in_filter: true`.
Isolated records (no relations) are included as nodes with no edges.

---

## Legacy (v4) — deprecated

| Method / file | Status | Replacement |
|---|---|---|
| `rs_ctrl::addRs()` | `@deprecated v5` | `record_ctrl::addRs()` |
| `rs_ctrl::deleteRs()` | `@deprecated v5` | `record_ctrl::deleteRs()` |
| `rs_ctrl::getRs()` | `@deprecated v5` | included in `record_ctrl::getRecord()` |
| `rs_ctrl::showMatrix()` | `@deprecated v5` | `record_ctrl::getRsMatrix()` + MatrixView |
| `modules/rs/rs.js` | obsolete | Vue RsSection + RsGraph |
| `modules/matrix/matrix.php` | obsolete | MatrixView + RsGraph (Cytoscape) |
| `modules/matrix/matrix.js` | obsolete | RsGraph.vue (Cytoscape, lazy-loaded) |

---

## Future improvements

### 🔮 RecordView link from matrix node
When navigating from a RecordView to MatrixView, the originating record's identifier is
passed as `?highlight=IDENT`. The reverse — clicking a node in the matrix to open the record —
is already implemented. A "back to record" button in MatrixView toolbar would close the loop.

### 🔮 Filtered matrix from RecordView
RsSection already shows the local grid for one record. A "show in matrix" button on the
RsSection panel would open MatrixView with `?where=rs_field|=|SELF_IDENT` pre-filled,
highlighting the current record.

### 🔮 Interactive add from matrix
Currently relations can only be added from RecordView → RsSection. A future version could
allow clicking two nodes in MatrixView and choosing a relation to insert directly on the graph.

### 🔮 Layout options
The dagre top-to-bottom layout is the standard Harris Matrix convention. Alternative layouts
(left-to-right, radial) could be offered as a toolbar toggle for exploratory use.

### 🔮 Multi-table relations
The current system stores `tb` in each RS row, so cross-table relations are technically
possible in the schema but not exposed in the UI. A future extension could allow relating
records from different tables (e.g. US ↔ Finds) if the archaeological workflow requires it.
