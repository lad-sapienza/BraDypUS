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

::: warning This is a database-level pattern, not a feature
Nothing in BraDypUS creates or manages views. You write the SQL, and the
application only sees the result. It is proven on **MariaDB 10.11** and **PostgreSQL 16**; read
[What is proven](#what-is-proven-and-what-is-not) and [Limits](#limits) before you
rely on it.
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

### 3. Register each view as a table

BraDypUS only knows what is in its configuration (`bdus_cfg_tables`,
`bdus_cfg_fields`). There are two ways to put a view there.

**A. Let BraDypUS write the configuration, then swap the table for the view**
(proven). In **Config → Tables** create a table named `v_public` with the label
you want, add one field for each column of the view with the right type, then in
the database:

```sql
DROP TABLE v_public;
CREATE VIEW v_public AS SELECT id, number, text FROM card WHERE state = 'validated';
```

The configuration stays; the name now points to a view. Do it before anyone enters
data in the placeholder table (it is dropped).

**B. Copy the configuration in SQL** (proven; this is what the original test did).
Copy the base table's row in `bdus_cfg_tables` under the new name and label, and
the rows of `bdus_cfg_fields` for the columns the view contains:

```sql
INSERT INTO bdus_cfg_tables
  (name, label, order_field, id_field, preview, is_plugin, sort, links, backlinks, extra)
SELECT 'v_public', 'Card (public)', order_field, id_field, preview,
       is_plugin, sort, links, backlinks, extra
FROM bdus_cfg_tables WHERE name = 'card';

INSERT INTO bdus_cfg_fields (table_name, name, label, type, db_type, sort, extra)
SELECT 'v_public', name, label, type, db_type, sort, extra
FROM bdus_cfg_fields
WHERE table_name = 'card' AND name IN ('id', 'number', 'text');
```

After either, the view appears in **Config → Tables** and in the table lists, and
behaves like a table for search, filters, list, record view and export.

::: tip Do not change the view from the Config panel
Adding, renaming or deleting a *field* of a registered view from the Config panel
reports success but does **not** change the view (only the configuration moves, and
they drift apart). Change the view in SQL (`CREATE OR REPLACE VIEW`) and edit the
configuration to match. See [Limits](#limits).
:::

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
   the hidden columns. A save that the database then rejects still leaves a version
   row behind (the copy is taken before the update).
6. **Locks and rules are per table name.** A [lock](./groups#locks) on `v_author`
   does not freeze the same record when it is edited through `card`, and a lock on `card`
   does not stop edits made through a view (both seen: the first on MariaDB, the
   second reported on PostgreSQL). Put every rule and lock on every name that can reach the data.
7. **Rule and lock filters use the view's fields.** A filter on a column that the
   view does not contain is refused when you save it.
8. **Hidden columns are hidden from the app, not from the database.** Anyone with
   database access, and every backup, sees everything.
9. **Changing a registered view from Config is not supported** (see the tip above).
   Adding a field to the base table does not add it to the views.
10. **Registered views are exported to DBML as ordinary tables.** Importing that
    DBML in another app creates real tables, not views (see [DBML](./dbml#views)).

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
| Registering by creating the table, then swapping it for a view | Proven here | Not checked |
| Registered view appears as a table in DBML export | Proven here | Not checked |
| Config panel field changes on a view do not change the view | Proven here (view unchanged; configuration not inspected) | Not checked |
| `WITH CHECK OPTION` refuses a row leaving the view | Reported | Not tried |
| 20 concurrent inserts give consecutive numbers (counter table) | Reported | Not checked |
| CSV export of a view omits hidden columns | Reported | Reported |
| Multi-table view written through `INSTEAD OF` triggers | Not available | Reported |
| A large real dataset and the graphical interface (list, record, edit, save as a group user) | Not checked | Reported |
| SQLite up to 5.16.2 drops views at the next login | Fixed in 5.16.3; fix covered by a test, pattern not run on SQLite | |
| Alter of a view's fields from the interface | Does not alter the view (see tip) | Not checked |
| PostGIS, performance under concurrent load on real data | | Not tried |
