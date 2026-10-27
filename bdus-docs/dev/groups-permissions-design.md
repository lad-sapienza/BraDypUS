---
title: Groups & permissions — design note
---

# Groups & permissions — design note

> **Status: proposal (issue #78), awaiting review. Nothing described here is implemented.**
> This page is intentionally not linked from the sidebar.

## Goal

Add the simplest model that lets us:

1. give a user (e.g. an external reviewer) access to a **subset of the data** only, optionally with write access to that subset;
2. **freeze** a subset of records (e.g. `published = 1`) so that curators can no longer change them by accident;
3. restrict an **API key** to some tables and rows (e.g. a public read-only key on `published = 1`).

It is deliberately small: flat groups, no inheritance, no workflow engine, no field-level rules. The vocabulary follows Directus (groups, per-table CRUD rules, JSON filter), the semantics are simplified where we do not need more.

## Current state (verified in the code)

- Access is decided by one global **tier** per principal (`bdus_users.privilege`, `bdus_api_keys.privilege`): 1, 10, 20, 25, 30, 39, 40. `Auth\Authorization::can($privilege, $creator)` has no table argument.
- `bdus_user_table_privs` (per-table level + raw SQL `subset`) is **stored and editable but never enforced**: the `UAC\UAC` object built by the Dispatcher is injected into controllers and never consulted. The table, `UAC\Loader`, `UAC\UAC` and the three `User::*TablePrivilege` endpoints are dead weight. This design replaces them.
- API keys carry only a tier. A read key reads every configured table.
- The app status (`on` / `frozen` / `off`) is a global switch and stays as is: it locks all writes without touching groups or rules (useful e.g. while working on an offline parallel copy).

## Concepts

**Tier** — unchanged. It remains the baseline on *all* tables and the gate for non-data features (config, users, backup, upgrade). In the Users UI the tiers are shown as read-only *built-in groups* (same grid as custom groups, locked) so there is a single place to understand access; in the database they stay a column.

**Group** — flat, named. A user can belong to many groups; an API key to at most one.

**Rule** — grants one action on one table to a group, optionally limited to the rows matching a JSON filter:

| Action | Values |
|---|---|
| `create` | on / off (no filter) |
| `read` | off / all / filter |
| `update` | off / all / filter |
| `delete` | off / all / filter |

**Lock** — a table + JSON filter. Rows matching it cannot be updated or deleted by anyone except admins. Locks do not belong to groups.

**Filters** use the existing JSON filter syntax (`SQL\Filter\JsonFilter`), never raw SQL. One variable is added: `$CURRENT_USER`.

## Evaluation

For principal *P*, action *A*, table *T*, row *R*:

1. If *P* is admin or super_admin (tier ≤ 10): **allowed** (locks and rules do not apply — admins curate the data, including frozen records).
2. Otherwise *A* is allowed if **either**
   - the tier grants *A* on all tables (as today), **or**
   - at least one rule of *P*'s groups grants *A* on *T* and *R* matches that rule's filter (rules are OR-ed, default deny);
3. and, for `update`/`delete`, *R* matches **no** lock on *T*.

Application status `frozen`/`off` keeps its current meaning and is checked first.

Intended setups:

- **Reviewer of site 5** — tier 39 (`enter`, no data access today) + group "Reviewers site 5" with `read` on `us`, filter `{"sito": {"_eq": 5}}`; add `update` if they may correct.
- **Freeze published records** — lock on `us` with filter `{"published": {"_eq": 1}}`. Writers keep working on everything else.
- **Public API key** — key with tier 39 and `group_id` = "Public", rule `read` on `us`, filter `{"published": {"_eq": 1}}`.
- **No rules, no locks** — behaviour is identical to today. This is the regression baseline.

## Data model (migration M046, additive, SQLite/MySQL/PostgreSQL)

```
bdus_groups        (id, name UNIQUE, description)
bdus_user_groups   (user_id, group_id, UNIQUE(user_id, group_id))
bdus_group_rules   (id, group_id, table_name, action, filter TEXT NULL)   -- one row per (group, table, action)
bdus_table_locks   (id, table_name, filter TEXT NOT NULL, note)
bdus_api_keys      + group_id INTEGER NULL
```

The same migration drops `bdus_user_table_privs`. It was never enforced, so nothing is lost; if rows exist they are ignored.

## Where it is enforced

Three choke points plus the controllers' coarse gate.

1. **Row filter for lists** — `SQL\QueryFromRequest::setWhere` ANDs the principal's read filter into the WHERE. This single place already serves list, search, count, export, charts, GeoFace and assemblage analysis (precedent: the `sqlExpert` admin gate, #76).
2. **Single-record reads and satellites** — `Record\Read` and the hand-written queries: `getRecord`, links and backlinks (targets the principal cannot read are **omitted**), versions and diffs, deleted records, `checkUnique`, `searchLinkCandidates`, `Search::getUsedValues`, `Chrono`, `Widget`, `Home::listTables` (only tables with a read grant).
3. **Writes** — one gate in the record save path: `saveRecord`, `erase`, `duplicateRecord`, `restoreVersion`, file link/unlink/upload/delete, `Import`, `SearchReplace`, `multiple_edit`. The check runs against the row **as it is before the change** (a user may change a field and move a record out of their own scope; accepted). `create` checks only the table grant. Plugin rows, manual links, RS and geodata follow the record they belong to (they are persisted by the same save path, so a frozen record freezes them too). Bulk operations are checked per row.
4. **Coarse gate** — `Authorization::can('read'|'add_new'|'edit', $table = null)` gains an optional table argument. The tier fast path is unchanged; rules are consulted only when the tier alone does not grant. About 65 call sites in 11 controllers pass the table. API keys go through the same code path (`CurrentUser` carries the key's group).

Implementation: one new class (`Auth\Access` or similar) exposing `can(action, table)`, `readFilter(table)` and `rowAllowed(action, table, id)`, built once per request from the principal's groups. `JsonFilter` gains `$CURRENT_USER` substitution. Lock/deny conditions must be evaluated NULL-safe (`NOT (x = 1)` is NULL when `x` is NULL): use `id IN (SELECT id … WHERE <filter>)` or `COALESCE`, never a bare `NOT`.

## REST surface (admin only)

CRUD for groups, membership, rules and locks; a `group_id` on API key creation; the per-table abilities of the current principal added to `Home::listTables` / `Info::getAppInfo` so the frontend can hide actions (it already consumes `can_add` / `can_edit`). The `User::*TablePrivilege` endpoints are removed (`openapi.yaml` and the parity test updated, CHANGELOG entry).

## UI

In the Users view: a *Groups* tab. Pick a group → list of data tables with C / R / U / D controls; R/U/D are off / all / filter, the filter being the Filters panel of the query bar. Built-in tiers appear read-only. A separate *Locks* section lists table + filter. Membership is edited from the user form and from the group.

## Alternatives rejected

- **Make the old per-user table privileges effective** — raw SQL subsets, per user rather than per group, same weakness as #76.
- **Directus v11 policies layer** (roles → policies → permissions) — over-engineered for our use.
- **Tiers materialised as rows in the rules table** — tiers also gate non-data features and API-key routes; two sources of truth to keep aligned, on a part that works today.
- **`deny` rules inside groups** — a second semantic in every group; a lock table is simpler to read and covers the freeze use case.
- **Field-level rules** — filtering/sorting on hidden fields leaks values; postponed.
- **Group managers (delegated administration)** — postponed; a flag on `bdus_user_groups` can be added later.

## Open points

- **Files (`bdus_files`)** are not configured tables: for v1 they stay visible as today (the Files list is not filtered). Revisit after the implementation.
- Field allow-lists, group managers and `$CURRENT_GROUPS` / `$NOW` variables: out of v1.
- Unified tiers-as-groups storage: left open for later.

## Testing

PHPUnit: rule evaluation (union, default deny, lock precedence, admin bypass, NULL-safe locks), filter application, regression for users without groups. hurl: deny-by-default on **every** entry point listed above for a scoped user and a scoped key (list, record, search values, export, charts, links, files, versions, geodata, every write route), 403 on admin routes, a frozen record cannot be updated/deleted/restored by a writer but can by an admin. All three engines.
