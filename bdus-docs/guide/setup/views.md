---
title: SQL views as tables
---

# SQL views as tables: hidden fields, rows per role, states

BraDypUS has no per-field permissions and no workflow engine
([Groups & locks](./groups) work on whole rows). You can still get both with the
database itself: **a SQL view can be registered as a table**, and BraDypUS treats
it like any other table. Each role gets a view that contains only the columns and
rows that role may see, and a trigger on the real table decides which state
changes are legal.

::: warning You write the SQL; BraDypUS registers it
BraDypUS does not create or edit views: you write the SQL. Since <Badge type="tip" text="v5.17.0" />
it can **register** an existing view from **Config → Database objects**, without touching
`bdus_cfg_*` by hand, and it knows whether the view can be written. It is proven on
**MariaDB 10.11** and **PostgreSQL 16**; read
[What is proven](#what-is-proven-and-what-is-not) and [Limits](#limits) before you rely on it.
:::

## When to use it

| You need | Use |
|---|---|
| Some roles must not even *see* a column (reviewer notes, personal data) | A view without that column |
| A role works only on records in some state (drafts, to be reviewed) | A view with `WHERE state IN (...)` |
| A role may only make certain state changes (draft → submitted, never → validated) | A trigger on the base table |
| An anonymous, read-only public face of a few columns of validated records | A view + a [group API key](../api/#api-keys-with-a-group) |
| Whole rows visible to some groups only, or records frozen once done | Plain [groups & locks](./groups): no views needed |

If a rule on whole rows is enough, do not use views.

## The idea in one example

A table `card` with roles **author**, **supervisor**, **validator** and an
anonymous **public** reader:

| Role | Reads | Writes | Sees rows in state |
|---|---|---|---|
| Author | `number`, `text`, `state` | `text` | `draft`, `rejected` |
| Supervisor | + `supervisor_notes` | `supervisor_notes`, `state` | `submitted` |
| Validator | everything (the base table) | `validator_notes`, `state` | all |
| Public key | `number`, `text` | nothing | `validated` |

### 1. The base table

Create `card` the usual way (**Config → Tables** and **Fields**, or
[DBML](./dbml)), with the columns `number`, `text`, `supervisor_notes`,
`validator_notes`, `state`. Every column that some view leaves out must accept
`NULL` or have a default, because inserts through that view cannot set it.

### 2. The views

```sql
CREATE VIEW v_author AS
  SELECT id, creator, number, text, state
  FROM card WHERE state IN ('draft', 'rejected');

CREATE VIEW v_supervisor AS
  SELECT id, creator, number, text, supervisor_notes, state
  FROM card WHERE state = 'submitted';

CREATE VIEW v_public AS
  SELECT id, number, text
  FROM card WHERE state = 'validated';
```

Keep `id` (and `creator`, when you want the record to remember who created it) in
every view that can write: BraDypUS addresses every record by `id`.

### 3. Register each view as a table <Badge type="tip" text="v5.17.0" />

Open **Config → Database objects**: the views are listed with a *view* badge and, next to
it, *read-only* or *writable*. Click **Preview and activate**, check the fields and the SQL
and **Activate**. Nothing is written to the view; only the configuration is created. The
full description is in [Database objects](./database-objects).

A view can be activated only if it has an **`id`** column, and, when it can be written, a
**`creator`** column too. Both must come from a base table: BraDypUS addresses every record
by `id`, and stores in `creator` who created it.

::: details Before 5.17.0 (kept for older installations)
Registration meant creating a table in **Config → Tables** with the fields of the view and then
`DROP TABLE` / `CREATE VIEW` under the same name, or copying rows of `bdus_cfg_tables` and
`bdus_cfg_fields` in SQL. Both still work, but the view is then not flagged as a view, so the
protections below do not apply to it. Remove it from the configuration and activate it again.
:::

::: tip What you cannot change on a registered view
From the Config panel you can set labels, field types and the preview, but adding, renaming or
deleting a *field*, renaming the view, adding indexes or relations, and switching on system
plugins are refused (`view_not_alterable`). Change the view in SQL (`CREATE OR REPLACE VIEW`), then
**Config → Validation** shows what no longer matches. See [Limits](#limits).
:::

### Writing through a view <Badge type="tip" text="v5.17.0" />

When a view is activated, BraDypUS records how it can be written (`view_writable`):

| | Meaning |
|---|---|
| `none` | **Read-only.** The interface hides *Edit*, *Delete* and *New*; the server answers `view_read_only` |
| `probe` | The database says the view is updatable (PostgreSQL and MariaDB: a simple view on one table) |
| `trigger` | SQLite, or any engine with `INSTEAD OF` triggers: writable through the triggers |

On **SQLite** a view is writable only if you defined `INSTEAD OF` triggers for it, and the
trigger that inserts should not invent the `id`: let the base table assign it, and BraDypUS reads
the new `id` from the base table inside the same transaction (SQLite's own "last inserted id"
is stale after an insert through a view). On **PostgreSQL**, an `INSTEAD OF` trigger that inserts
with explicit ids can make the new id come back wrong: let the sequence of the base table assign it.

If you change how a view can be written, **Config → Validation** warns that the saved value is
out of date; remove the view from the configuration and activate it again.

The history (`bdus_versions`), [locks](./groups#locks) and group rules of a view are kept **under
the view's name**, exactly as for a table. This is by design: a person working through a view
should not notice that it is not a table. The consequence is that a change made on the base table
does not appear in the history of the view, and a lock on the view does not block the base table
(and the other way round): put every rule and lock on every name that can reach the data.

### 4. One group per view

Create the groups and the rules on the **view names** (**Users → Groups**, or
`PUT /api/group/{id}/rules`; [Groups & locks](./groups)). Users are at level
*Enter* (39), so the group decides everything.

| Group | Table | Create | Read | Update | Delete |
|---|---|---|---|---|---|
| Authors | `v_author` | All | All | All | off |
| Supervisors | `v_supervisor` | off | All | All | off |
| Validators | `card` | All | All | All | All |
| Public | `v_public` | off | All | off | off |

A user whose group has no rule on a table gets `not_enough_privilege` for that
table. A group key for the public reader is created with
`POST /{app}/api/api-keys` at level 39 with the `group_id` of **Public**; the key
reads `v_public` and nothing else ([API keys with a group](../api/#api-keys-with-a-group)).

### 5. Triggers on the base table

The code below is MariaDB/MySQL. On PostgreSQL the same logic is a PL/pgSQL
function (`RAISE EXCEPTION` instead of `SIGNAL`, `nextval` or a counter row for the
number) attached with `CREATE TRIGGER … BEFORE INSERT/UPDATE … EXECUTE FUNCTION`.

The views decide what each role *sees*. The triggers decide what each role may
*write*, because a rule on a table or a row does not look at the values written.

```sql
DELIMITER //

-- Initial state and an automatic, sequential number.
CREATE TRIGGER card_bi BEFORE INSERT ON card FOR EACH ROW
BEGIN
  DECLARE k INT;
  IF NEW.state IS NULL OR NEW.state = '' THEN SET NEW.state = 'draft'; END IF;
  IF NEW.state <> 'draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a card is born as a draft';
  END IF;
  IF NEW.number IS NULL OR NEW.number = '' THEN
    UPDATE counter SET n = n + 1 WHERE id = 1;       -- locks the row: no duplicates
    SELECT n INTO k FROM counter WHERE id = 1;
    SET NEW.number = CONCAT('C', LPAD(k, 6, '0'));
  END IF;
END//

-- Immutable number; only the allowed state transitions.
CREATE TRIGGER card_bu BEFORE UPDATE ON card FOR EACH ROW
BEGIN
  IF NEW.number <> OLD.number THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the number cannot change';
  END IF;
  IF NEW.state <> OLD.state AND NOT (
       (OLD.state = 'draft'     AND NEW.state = 'submitted')
    OR (OLD.state = 'rejected'  AND NEW.state = 'submitted')
    OR (OLD.state = 'submitted' AND NEW.state IN ('reviewed', 'rejected'))
    OR (OLD.state = 'reviewed'  AND NEW.state IN ('validated', 'rejected'))
    OR (OLD.state = 'validated' AND NEW.state = 'reopened')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'state transition not allowed';
  END IF;
END//

DELIMITER ;
```

with a one-row table `counter (id INT PRIMARY KEY, n INT NOT NULL)` seeded with
`(1, 0)`. In the test, 20 concurrent inserts got distinct, consecutive numbers.

The text of `SIGNAL SQLSTATE '45000'` (MariaDB/MySQL) or `RAISE EXCEPTION`
(PostgreSQL) reaches the user: when a record save is refused by a trigger, the
message is shown as it is. If it is the key of a translation known to the app, it is
translated. Any other database error, such as a constraint violation, only
shows a generic message, without SQL text. Triggers on SQLite are not covered.

Because each role sees only rows in the states it acts on, it can only make the
transitions that start from there: the author never sees a `submitted` card, so
cannot move it; the supervisor sees only `submitted` cards, so cannot validate a
draft. The trigger adds the second lock: even a legitimate row cannot jump to a
state the table does not allow, for instance an author setting `validated` on his
own draft.

## `WITH CHECK OPTION` or a trigger?

Two ways to stop a save that would take the row out of its view.

| | `WITH CHECK OPTION` on the view | Trigger on the base table |
|---|---|---|
| What it stops | Any change that leaves the row outside the view's `WHERE` | Only the transitions you list |
| Closing a card (draft → submitted) | **Refused**: the row would leave `v_author` | **Allowed**: it is a listed transition |
| Use it for | Roles that must keep records where they are | A real workflow, where records move between roles |

For a workflow use the trigger and **no** `CHECK OPTION` on the views that must
let rows leave. `CHECK OPTION` suits a view where leaving is always an error.

## Behaviour you can rely on (proven)

- The view is in `/api/config/tables`, in lists, record view, search, filters and
  export.
- A column that is not in the view cannot be read, cannot be searched
  (`'field' is not a plugin, backlinked table, or lookup … field`) and is not in the
  export.
- Inserting and updating through a simple view (one table) works: the new `id` is
  returned, `creator` is set, accents and Greek are intact.
- Restoring a [version](../usage/versions) through a view works, including
  bringing back a deleted record (admin only, as for any table).
- Deleting through a view works if the group's rule allows it.
- A group without a rule on a view gets `not_enough_privilege`: HTTP `200` with a
  JWT, HTTP `403` with an API key.

## Limits

These are the things that surprise people. Read them before you plan.

1. **Engine.** The pattern was tried on MariaDB 10.11 and PostgreSQL 16 (triggers are
   written in each engine's own language: `SIGNAL` on MariaDB, PL/pgSQL on
   PostgreSQL). On SQLite, versions up to 5.16.2 dropped every SQL view at the next
   login; from 5.16.3 views are kept (and removed only by the v4 → v5 major upgrade,
   for v4 leftovers). That fix is covered by a test, but the whole pattern was not
   run on SQLite. PostGIS was not tried.
2. **Simple views (one table) write directly.** A view that joins several tables
   is not updatable by itself. On PostgreSQL, with `INSTEAD OF` triggers, it was
   written to successfully (insert returning the id, update across tables, cascading
   delete, search on a field of the second table). Not tried on MariaDB, which has no
   `INSTEAD OF` triggers.
3. **Columns left out must accept `NULL` or have a default**, or inserts through
   the view fail.
4. **A rejected save is a generic error.** A trigger's `MESSAGE_TEXT` does not
   reach the user: the app answers `error_saved` / "Database error". Name your
   states and transitions in the user documentation, not in the trigger message.
5. **The history is split by view.** `bdus_versions` stores the name of the
   *view* and only its *columns*: a record's history is fragmented across the views
   it was edited through, and a version made through `v_public` knows nothing about
   the hidden columns. This is intended (see *Writing through a view*). A save that the database then rejects still leaves a version
   row behind (the copy is taken before the update).
6. **Locks and rules are per table name.** A [lock](./groups#locks) on `v_author`
   does not freeze the same record when it is edited through `card`, and a lock on `card`
   does not stop edits made through a view (both seen: the first on MariaDB, the
   second reported on PostgreSQL). Put every rule and lock on every name that can reach the data.
7. **Rule and lock filters use the view's fields.** A filter on a column that the
   view does not contain is refused when you save it.
8. **Hidden columns are hidden from the app, not from the database.** Anyone with
   database access, and every backup, sees everything.
9. **A registered view cannot be altered from Config** (`view_not_alterable`, since
   5.17.0; before, it reported success and changed nothing). Adding a field to the base
   table does not add it to the views: change the view in SQL, then remove it from the
   configuration and activate it again to pick up the new column.
10. **DBML.** Since 5.17.0 a registered view is exported with `bdus:view=1`, and
    importing it *activates* the view of the same name that must already exist in
    the target database; it never creates a view (see [DBML](./dbml#views)). Before,
    a view was exported as an ordinary table and the import created a real table.

## What is proven and what is not

*Proven here* = run again while writing this page (MariaDB 10.11, v5.16.2).
*Reported* = seen by the people who first tried the pattern, not repeated here.

| Statement | MariaDB 10.11 | PostgreSQL 16 |
|---|---|---|
| View in table list, records, search, filters, record view | Proven here | Reported |
| Insert/update through a simple view, `creator`, UTF-8 | Proven here | Reported |
| Hidden column unreadable, unsearchable | Proven here | Reported |
| Rules per view; 200 with JWT, 403 with API key | Proven here | Reported |
| Trigger rejects a transition; generic error | Proven here | Reported |
| A save rejected by a trigger still writes a `bdus_versions` row | Proven here | Not checked |
| Versions record the view name and the view's columns | Proven here | Reported |
| Restore through a view (update and deleted record) | Proven here | Reported (a trigger can refuse a restored state) |
| Lock on a view does not lock the base table, and the other way round | Proven here (view → base) | Reported (both ways) |
| Registering by creating the table, then swapping it for a view (before 5.17.0) | Proven here | Not checked |
| Registered view appears as a table in DBML export (before 5.17.0) | Proven here | Not checked |
| Config panel field changes on a view do not change the view (before 5.17.0) | Proven here (view unchanged; configuration not inspected) | Not checked |
| `WITH CHECK OPTION` refuses a row leaving the view | Reported | Not tried |
| 20 concurrent inserts give consecutive numbers (counter table) | Reported | Not checked |
| CSV export of a view omits hidden columns | Reported | Reported |
| Multi-table view written through `INSTEAD OF` triggers | Not available | Reported |
| A large real dataset and the graphical interface (list, record, edit, save as a group user) | Not checked | Reported |
| SQLite up to 5.16.2 drops views at the next login | Fixed in 5.16.3; fix covered by a test, pattern not run on SQLite | |
| Alter of a view's fields from the interface | Refused with `view_not_alterable` since 5.17.0 | Same code, run on SQLite |
| PostGIS, performance under concurrent load on real data | | Not tried |

### Since 5.17.0 (automated tests, not a hand run)

| Statement | SQLite | MariaDB 11 | PostgreSQL 16 |
|---|---|---|---|
| Views, columns, SQL and writability are read from the database | Test | Test | Test |
| Foreign keys are read from the database | Test | Test | Test |
| New `id` after an insert through a view is right | Handled in the code (`MAX(id)` in the transaction), tested | Test | Test |
| Activation, `is_view` flag, `view_read_only`, `view_not_alterable`, DBML `bdus:view` | Test | Routes only (HTTP) | Routes only (HTTP) |
| Activation of a view run end to end (SQL → configuration → record) | Test and by hand in the interface | Not run | Not run |
| Writable view updated and deleted through triggers | Test (`INSTEAD OF`) | Not run | Not run |
