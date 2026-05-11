# Log — Application Log Viewer

## Overview

Displays the application's internal error/event log stored in the `{prefix}log` table (managed by Monolog via `LogDBHandler`).  
Route: `/log` · Controller: `debug_ctrl` · Vue view: `LogView.vue`

---

## UI

```
┌─ Toolbar ────────────────────────────────────────────────────────────────┐
│ "Application Log"  [Level ▾]  [Search…]        [↻ Refresh]  [🗑 Purge] │
└──────────────────────────────────────────────────────────────────────────┘
┌─ DataTable (server-side, lazy) ──────────────────────────────────────────┐
│ ▸ │ Time              │ Level   │ Channel │ Message (truncated, 1 line)  │
│ ▸ │ 2026-05-10 08:00  │ ERROR   │ bdus    │ DB\DBException: Generic…     │
│ ▾ │ 2026-05-10 07:55  │ INFO    │ bdus    │ User login successful        │
│   └─ expanded: full message with stack trace (monospace, scrollable) ───┘
├─ Paginator: 25 / 50 / 100 rows per page ─────────────────────────────────┤
```

### Level filter
Dropdown filters entries by Monolog numeric level:

| Value | Name      | Badge colour |
|-------|-----------|-------------|
| 0     | All       | —           |
| 100   | DEBUG     | secondary   |
| 200   | INFO      | info        |
| 250   | NOTICE    | info        |
| 300   | WARNING   | warn        |
| 400   | ERROR     | danger      |
| 500   | CRITICAL  | danger      |
| 550   | ALERT     | danger      |
| 600   | EMERGENCY | danger      |

### Search
Free-text filter applied to the `message` column (`LIKE %…%`).  
Triggered on Enter or when the Level filter changes.

### Refresh
Resets to page 1 and re-fetches.

### Purge
Opens a modal; user picks a time window (1 / 7 / 14 / 30 / 90 / 365 days).  
Confirmation deletes entries older than the selected threshold.  
A toast reports how many rows were deleted.

### Row expansion
Clicking the row toggle (▸/▾) expands the row to show the full raw log message in a monospace `<pre>` block (stack traces, JSON context, etc.).

---

## API Endpoints

### `GET getLogs` — list log entries

```
GET /index.php?obj=debug_ctrl&method=getLogs
    &page=1&per_page=50[&level=400][&search=string]
Accept: application/json
```

**Query params**

| Param    | Type | Default | Description                     |
|----------|------|---------|---------------------------------|
| page     | int  | 1       | 1-based page number             |
| per_page | int  | 50      | Rows per page (max 200)         |
| level    | int  | 0       | Monolog level code; 0 = all     |
| search   | str  | —       | Substring to match in `message` |

**Response**

```json
{
  "total": 1514,
  "data": [
    {
      "id": 1514,
      "channel": "bdus",
      "level": 400,
      "level_name": "ERROR",
      "message": "[2026-05-10T…] bdus.ERROR: …",
      "time": "2026-05-10 08:00:00"
    }
  ]
}
```

Error response: `{ "status": "error", "text": "…" }`

---

### `POST purgeLogs` — delete old log entries

```
POST /index.php?obj=debug_ctrl&method=purgeLogs
Content-Type: application/json
Accept: application/json

{ "days": 30 }
```

**Body**

| Param | Type | Default | Description                     |
|-------|------|---------|---------------------------------|
| days  | int  | 30      | Delete entries older than N days |

**Response**

```json
{ "status": "success", "deleted": 42, "text": "42 entries deleted (older than 30 days)." }
```

---

## DB schema

```sql
CREATE TABLE {prefix}log (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  channel TEXT    NOT NULL,           -- Monolog channel name (e.g. 'bdus')
  level   INTEGER NOT NULL,           -- Monolog level code (100–600)
  message TEXT    NOT NULL,           -- Full formatted log string
  time    INTEGER NOT NULL            -- Unix timestamp
);
```

---

## Future improvements

### 🔮 Reuse `sql2json` / `jsonForTabletop` instead of custom endpoint
`getLogs()` replicates what `sql2json()` + `utils::jsonForTabletop()` already do  
(pagination, search, sort, total). A thin param-translation adapter in Vue  
(PrimeVue pagination → DataTables 1.x protocol) would have avoided the new PHP method.  
Consider refactoring `getLogs()` to delegate to `jsonForTabletop()`, or generalise  
`jsonForTabletop()` to accept a level filter.

### 🔮 Real-time log streaming
Long-term: stream new log entries via SSE (Server-Sent Events) so the table  
auto-updates without polling / manual refresh.

### 🔮 Log level editing / suppression rules
Allow configuring which levels are actually written (e.g. suppress DEBUG in production).

### 🔮 Export log to file
Download the current filtered view as CSV or plain text.

---

## Legacy (v4) — to remove after migration

| Method      | Status          | Replacement            |
|-------------|-----------------|------------------------|
| `sql2json`  | `@deprecated v5`| `getLogs()`            |
| `read()`    | `@deprecated v5`| Vue `LogView.vue`      |
| `tmpl/read.twig` | obsolete   | Vue component          |
