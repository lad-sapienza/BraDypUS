---
title: Groups & permissions
---

# Groups & permissions

How access to **tables and rows** works on top of the global privilege levels
(issue #78). The user-facing description is in
[Groups, permissions & locks](/guide/setup/groups); this page is for whoever works
on the code.

The model is deliberately small: flat groups, rules that only add, one separate
mechanism (locks) that only takes away, no inheritance, no field-level rules, no
workflow engine. The vocabulary follows Directus (groups, per-table CRUD rules,
JSON filters).

## Concepts

| Piece | Where | Meaning |
|---|---|---|
| **Tier** | `bdus_users.privilege`, `bdus_api_keys.privilege` | Global level (1, 10, 20, 25, 30, 39, 40). Baseline on **every** table and the gate for non-data features (config, users, backups…). Stays a column; the UI shows the tiers as read-only built-in groups. |
| **Group** | `bdus_groups` | Flat, named. A user belongs to several (`bdus_user_groups`), an API key to at most one (`bdus_api_keys.group_id`). |
| **Rule** | `bdus_group_rules` | One row per (group, table, action): `create`, `read`, `update` or `delete`, with an optional JSON filter limiting the rows. Rules only grant. |
| **Lock** | `bdus_table_locks` | A table and a filter: the rows it selects cannot be updated or deleted by anyone but admins. |
| **App status** | `bdus_cfg_app.status` | `on` / `frozen` / `off`, unchanged: `frozen` blocks all writes whatever the groups say. |

Filters use the record filter syntax (`SQL\Filter\JsonFilter`), never raw SQL.
The only variable is `$CURRENT_USER` (the id of the principal; `0` for an API key).

## Evaluation

For a principal *P*, an action *A*, a table *T* and a row *R*:

1. **Admin and super admin** (tier ≤ 10): allowed. Locks and rules do not apply.
2. Tiers above `ENTER` (40 and unauthenticated): nothing, groups or not.
3. Otherwise *A* needs a **grant**: the tier grants it on every row (read ≤ 30,
   create ≤ 25, update/delete ≤ 20); or, for a self writer (tier 25), update and
   delete on the rows they created (`creator = user id`); or a group rule of *P*
   grants *A* on *T* and *R* matches its filter. Rules of several groups are OR-ed;
   no grant means refused.
4. For `update` and `delete`, *R* must not match any **lock** on *T*.

Failure modes are closed: a rule whose filter cannot be compiled (unknown field,
bad JSON) grants nothing; a lock that cannot be compiled locks the whole table.

Locks are evaluated NULL-safely — `id NOT IN (SELECT id … WHERE <lock>)`, never a
bare `NOT (col = x)`, which is NULL (and hides the row) when `col` is NULL.

The check is on the row **as it is before the change**: a user allowed to update
rows with `site = 5` could move one to another site. That is accepted; versions
record it.

## Code

### `Auth\Access` — the engine

Built once per principal (`Access::forCurrentUser($db, $cfg)`; group ids load
lazily, so admins cost no query):

| Method | Use |
|---|---|
| `can($action, $table)` | May the principal do this on **some** rows of the table? (table-level gate) |
| `canAnyTable($action)` | …on some table |
| `rowCondition($action, $table)` | SQL + bound values selecting the rows on which the action is allowed, to AND into a `WHERE` over the unaliased table. `1=1` = all, `1=0` = none |
| `rowAllowed($action, $table, $id)` | Row-level check |
| `unrestricted($action, $table)` | Nothing is withheld (no query needed) |
| `readableIds($table, $ids)` | The subset of ids the principal may read |
| `isLocked($table, $id, $includeAdmins)` | Is the record frozen? |
| `checkFilter(...)` | Validates a filter when it is saved |

### `Auth\Authorization`

`can($privilege, $creator, $table)` takes the table as an optional third argument
(`can('read', table: $tb)`): the tier decides first, then the group rules, always
respecting the application status. `canOnSomeTable()` and `tableAbilities($table)`
(create/read/update/delete, as the interface needs them) are the other entry
points. `'delete'` is a privilege string of its own, with the thresholds of `'edit'`.
The Dispatcher registers the provider of the current principal's `Access`
(`setAccessProvider`).

### Where the rules are applied

There are three choke points; a new endpoint that touches record data must go
through one of them.

1. **Lists** — `SQL\QueryFromRequest::setWhere()` ANDs the read condition into the
   `WHERE`. Lists, search, counts, export, charts, GeoFace and assemblage analysis
   all build their predicate there.
2. **Single records and satellites** — controllers use
   `Controller::andReadable($tb)` (a ready-to-append `AND (…)` for hand-written
   queries), `mayReadRecord()`, and `Record::hideUnreadableRelations()`, which
   leaves out of a record view the links, backlinks, manual links, RS relations and
   lookup labels that point at data the principal cannot read. This filtering is on
   the *response* only — never on the model used for saving, or a save would drop
   the hidden links.
3. **Writes** — `Controller::mayChange($action, $tb, $id, $creator)`: the tier or a
   rule must grant it (table level, honouring ownership for a self writer), the row
   must be in scope, and no lock may cover it. A plugin row is judged by its host
   record, always as an update (`writeTarget()`); adding something to a record
   without changing a row (a geometry, a plugin row) uses `mayAttach()`; relations
   and links check **both** ends. Imports check every target before writing and are
   cancelled as a whole on one refusal.

`restoreVersion` and search & replace are admin-only, hence unaffected.

### API keys

`ApiKeyAuth` puts `group_id` in `CurrentUser`. The route gate
(`Router::apiKeyMayReach()`) passes a key whose tier is too low only if it has a
group **and** the route is listed in `Router::GROUP_KEY_ROUTES` — the data routes
whose controllers check table and row. A refusal to a key is HTTP 403
(`Controller::returnJson`); people keep the usual 200 with an error code.

## REST (admin only)

Groups: `GET/POST /api/groups`, `GET/PUT/DELETE /api/group/{id}`,
`PUT /api/group/{id}/members`, `GET/PUT /api/user/{id}/groups`.
Rules: `PUT /api/group/{id}/rules` replaces the whole set (what the permissions
grid sends); filters are validated before anything is saved.
Locks: `GET/POST /api/locks`, `PUT/DELETE /api/lock/{id}`.
Keys: `POST /api/api-keys` takes `group_id` (required with privilege 39).
See `openapi.yaml`; the interface is `components/users/` in `bdus-app`.

What the interface needs to know comes with existing endpoints: `can` per table in
`GET /api/tables` (which lists only readable tables), the same flags in
`GET /api/info/app`, `can_edit` / `can_delete` / `locked` in the record view.

## Tests

- `AccessTest`, `AccessReadEnforcementTest`, `AccessSatelliteReadTest`,
  `AccessWriteEnforcementTest`, `AccessWriteSatellitesTest`, `AccessAbilitiesTest`,
  `AccessMetadataTest`, `ApiKeyGroupTest`, `GroupsCtrlTest` (PHPUnit): the engine,
  and each entry point against a scoped principal, with the filter or the gate
  removed to check the test notices.
- Hurl phase 45 — rules, locks and keys end to end, on every engine.
- Hurl phase 46 — **deny by default**: a level-Enter person and a level-Enter key,
  in a group that reads a slice of one table, against every data and every
  administrative entry point. When you add an endpoint, add it there.
- Hurl phase 47 — the demo profiles on the **demo data** (runs with `--seed`):
  Editor Demo, Revisore Demo, a key in its group, plus a self writer (25) and a
  pending user (40). If you change what the seed builds, keep it passing.
- `RouterGroupKeyTest` checks that every `GROUP_KEY_ROUTES` entry is a real
  read/edit route.

## Decisions and limits

- **Tiers stay a column**, not rows of rules: they also gate non-data features and
  the API-key route table; two sources of truth would drift.
- **Locks are not rules**: "deny" inside groups would be a second semantic in every
  group, and a lock is easier to read and covers the freeze use case.
- **No field-level rules**: filtering or sorting on a hidden field leaks its values.
  Hidden columns, and rules on the *values* written, are obtained outside this
  mechanism with [SQL views and triggers](/guide/setup/views); rules and locks are
  keyed by table name, so each view needs its own.
- **Files are not filtered** (`bdus_files` is not a configured table); the *link*
  between a file and a record follows the record.
- **Plugin tables** are read through their record; read on their own (a chart, an
  analysis) they need a rule of their own.
- **`checkUnique`** answers a bare boolean over every row: it must reflect the real
  constraint.
- Group managers (delegated administration) and the variables `$CURRENT_GROUPS` /
  `$NOW` are not implemented.

## Alternatives rejected

- Making the old per-user table privileges effective — raw SQL subsets, per user
  rather than per group, never applied; removed.
- A Directus-v11-style policy layer — too much for this use.
- A single `locked_when` option in the table config — enough for the freeze, but a
  second mechanism that does not solve the reviewer case.
