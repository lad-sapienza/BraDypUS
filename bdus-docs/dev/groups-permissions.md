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
Variables (`Access::substitute`): `$CURRENT_USER` (the id of the principal; `0` for an API
key), `$CURRENT_GROUPS` (ids of the principal's groups) and `$GROUP.<key>` (the values of
the group attribute `<key>`, `bdus_groups.attrs` JSON, united over the principal's groups).
A list-valued variable turns `_eq`/`_neq` into `_in`/`_nin` and is spliced into `_in`/`_nin`
lists; under any other operator, an unknown variable (`$GROUP…`/`$CURRENT_…`) or an attribute
nobody sets, the filter throws and the rule fails closed (grants nothing).

### Rules for every table and the default groups

A rule with `table_name = '*'` (`Access::ALL_TABLES`) applies to every configured data table
(`isUsable()` still excludes `bdus_*` and unknown names); `Access::rulesFor()` unites it with
the rules of the table itself, and enforce candidates do the same (`*` rules cannot carry
`enforce`). Migration M050 (`M050_AddDefaultGroups`) creates Reader, Writer and Self writer
from such rules, skipping a name that already exists; `DefaultGroupsTest` asserts that, for
each, the table gates and the rows of every action equal those of the matching level
(30, 20, 25) for a user at level Enter. `LevelGroupParityTest` runs about forty operations through the real controllers as the
level and as the same user at Enter in the group, from the same database state, and requires
the same answer and the same rows; every scenario also states what the level is declared to
do. All three match in all of them. (The test originally found 12 operations where level 25
differed from its group and from the documented behaviour: a self writer could add plugin
rows and geometries to other people's records, and was refused the dependants of their own.
Fixed in `Controller::mayChange()`, which now looks up the owner of the record — of the
host, for a plugin row — when the caller does not pass it, in `mayAttach()` and
`mayAddPluginRow()`, which now require the right to change the record, and in the import
gates, which no longer demand the table-wide edit right.) Hurl phase 51 repeats the declared outcomes over HTTP for six users, on every
engine. The three are system groups (`bdus_groups.is_system = 1`): the Groups controller
refuses to delete, rename, change the attributes or the rules of such a group with
`system_group`, whoever asks (only `setMembers` is allowed; Run SQL, being raw SQL, is not
covered). Their `*` rules are the only source of what they grant: a future version changes
them with a migration. `bin/groups-equivalence.php --app <slug>` (class
`Auth\Verify\LevelGroupComparison`, read-only) compares each user at level 30/25/20 with the
same user at Enter in the equivalent group, table by table and action by action (gate and
rows), and lists differences, missing groups and API keys with a level and no group. Cells
that cannot be compared (a creator test on a table without a `creator` column, e.g. plugin
tables, whose owner the level takes from the host record) are reported as notes: check them
before moving self writers to the group. Admin and super admin are not expressible as
groups: `Router::ROUTE_PRIVILEGE`, configuration, users, backups and the lock bypass read the
level. The grid keeps the rules it has no row for (`*`) when it saves.

### Enforced values (#51)

`create` and `update` rules have an optional `enforce` column (JSON `{field: value|variable|list}`).
`Access::enforceValues($action, $table, $values, $recordId, $coerce)` is called by
`Record::saveRecord`, `Record::duplicateRecord` (`$coerce`: a copy's value outside the allowed
set is replaced when exactly one value is allowed) and `Import::importData`, after the
capability gate and before validation. Among the rules that grant the action on the table
(for update only those whose filter matches the record) one is enough: a rule without
`enforce` — or a self writer's own record — lifts the restriction. Not applied to admins, to
a tier that grants the action on every table, or to plugin tables. A field must be one of the
allowed values; left empty on create it is filled in when exactly one is possible and the
write is refused otherwise; on update an unsent field is left alone. Refusal:
`value_not_allowed`.

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
grid sends); filters and `enforce` maps are validated before anything is saved.
Record layouts: `PUT /api/group/{id}/layouts` (see below).
Group attributes: `attrs` on `POST /api/groups` and `PUT /api/group/{id}` (left out on
update = unchanged).
Locks: `GET/POST /api/locks`, `PUT/DELETE /api/lock/{id}`.
Keys: `POST /api/api-keys` takes `group_id` (required with privilege 39).
See `openapi.yaml`; the interface is `components/users/` in `bdus-app`.

What the interface needs to know comes with existing endpoints: `can` per table in
`GET /api/tables` (which lists only readable tables), the same flags in
`GET /api/info/app`, `can_edit` / `can_delete` / `locked` in the record view.

## Record layouts per group

A group can name, per table, the templates its members use (`bdus_groups.layouts`,
TEXT JSON `{table: [template, ...]}`, migration M051). **Interface convenience, never a
permission**: no reader of data consults it (verified by grep: only `Record::getTemplates`,
`Record::getRecord`, `Groups::setLayouts`/`getGroup`, `Templates::rename/deleteTemplate` use it).
User guide: [Record layouts per group](/guide/setup/record-layouts).

- **`Template\Assign`** (`lib/Template/Assign.php`): `allowed($db, $appName, $tb, $groupIds)` unites
  the layouts of the groups (groups by id, templates in the order listed, no duplicates),
  drops names whose template no longer exists (`Loader::listAvailable`) and returns `null`
  ("no restriction") when nothing valid is left or the column does not exist yet (M051
  pending). `normalise()` validates the request value; `renamed()` / `removed()` rewrite the
  groups when a template is renamed or deleted.
- **`Auth\Access::layouts($table, $appName)`** wraps it with the principal's groups (the key's
  `group_id` for an API key) and returns `null` for admins and super admins.
- **Enforcement is on the server**: `Record::getTemplates` returns only the allowed list with
  `assigned` and `locked` (`locked` = a single template); `Record::getRecord` replaces a
  `?template=` that is not allowed with the first allowed one, and uses it when none is asked.
  The SPA (`resolveTemplateChoice` in `src/utils/groupLayouts.js`) only hides or restricts the
  menu accordingly.
- **REST (admin)**: `PUT /api/group/{id}/layouts {layouts}` (replace all; `{}` clears; refuses
  system groups; errors `group_not_found`, `unknown_table`, `template_not_found`,
  `invalid_layouts`, `system_group`, `migration_pending`); `GET /api/group/{id}` returns
  `layouts`. Templates themselves stay super-admin (`Templates::*`).
- **Why a JSON column and not a table**: one value per group, read together with the groups
  already loaded, no join and no foreign key to keep; the only reference that can dangle is the
  template name, which a table would not protect either (handled by `renamed`/`removed` and by
  ignoring unknown names).
- **Why no priority, mode or precedence between groups**: the union of the groups' templates is
  predictable and the user chooses among them; a hierarchy would be a second semantic to explain.
- **Per-field options in a template item** (`label`, `readonly`, `hidden`; `Template\Loader::validate`
  → `validateItemOptions`, applied by `TemplateSection.vue` through `itemSchema()` / `isItemHidden()` in
  `templateUtils.js`): core field items and accordion panel fields only; a plugin item or a plugin
  section's content carrying one is refused (`option_not_supported_on_plugin_item`). `readonly` renders
  the value with `FieldDisplay` instead of `FieldEditor`, also in edit mode, and can only add to the
  field's own `readonly`. **Nothing on the server reads them**: they are in the stored JSON and are
  ignored by `Persist`, so a layout-readonly field stays writable through the API. Editor
  (`TemplatesView.vue`) strips `false` / blank values before saving.
- **Limits (deliberate)**: a layout is the choice of a template, plus the field options above.
  Inheritance (`extends`), a different layout for create vs edit, by record state, and list
  columns/preview per layout are **not implemented** (idea kept as a GitHub issue). Whatever is
  added, the same rule holds: the server's group rules remain the only authority.
- **Not verified**: how the SPA behaves with a template that exists but lists no field of the
  current table; the interaction with other readers of `bdus_cfg_templates` (print/export) was
  not exercised.

## Tests

- `AccessTest`, `AccessReadEnforcementTest`, `AccessSatelliteReadTest`,
  `AccessWriteEnforcementTest`, `AccessWriteSatellitesTest`, `AccessAbilitiesTest`,
  `AccessMetadataTest`, `ApiKeyGroupTest`, `GroupsCtrlTest` (PHPUnit): the engine,
  and each entry point against a scoped principal, with the filter or the gate
  removed to check the test notices.
- Hurl phase 45 — rules, locks and keys end to end, on every engine.
- Hurl phase 50 and `GroupVariablesTest` — group variables and enforced values (#51).
- Hurl phase 52 and `TemplateAssignTest` — record layouts per group: no assignment, one/several
  layouts, union over groups, stale names, admin bypass, `?template=` outside the assignment,
  validation, rename/delete following. Frontend: `bdus-app/tests/groupLayouts.test.mjs`.
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
  Hidden columns are obtained outside this mechanism with
  [SQL views and triggers](/guide/setup/views); rules and locks are keyed by table
  name, so each view needs its own. The *values* written can be pinned per field
  with `enforce` (see above), but only on the table's own columns: not on plugin
  tables, and not as a transition rule (draft → review → validated).
- **Field-level rules were considered and declined (issue #56, closed 2026-11-07).** A
  `fields: {read, write}` entry in a rule would have to be enforced at every reader of a
  field: record read (core, plugins, backlinks), lists, text search, `JsonFilter` and
  ShortSQL (conditions and ordering on a hidden field leak its values), export, `bdus_versions`
  snapshots, charts, GeoFace, assemblage, templates, the API and, for writes, record save,
  duplicate and import. There is no single choke point, so a missed one is a silent leak, and
  the code to add was disproportionate to the few cases it would serve. SQL views already give
  the read side with the database as the barrier. The known rough edges of views (rules, locks
  and history are keyed by the view's name; writable views depend on the engine; links, files
  and plugins hang off the table name) are tracked in the views plan (#53). **Reopen only from
  a concrete case that views cannot cover** (typically: different groups editing different
  fields of the *same* table with a shared history), and start by listing the readers above.
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
