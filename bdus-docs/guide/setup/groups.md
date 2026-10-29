---
title: Groups, permissions & locks
---

# Groups, permissions & locks <Badge type="tip" text="v5.16.0" />

Privilege levels say what a user can do on **every** table. Groups say what
someone can do on **some** tables, or on **some rows** of a table — and locks
freeze records so that nobody but an admin can change them any more.

Typical uses:

- an external **reviewer** who may read, and perhaps correct, only the records of
  one site or one excavation season;
- a **public** API key that reads only the records marked as published;
- freezing the records that have been **checked and published**, so that a stray
  edit cannot alter them.

All of it is managed in **Users**, in the **Groups** and **Locks** tabs
(admins only).

## How the pieces fit

| Piece | What it does |
|---|---|
| **Level** (see [Users & privileges](./users)) | The baseline on every table |
| **Group** | A named set of rules; a user can be in several, an API key in one |
| **Rule** | In a group: this action (create, read, update, delete) on this table, optionally only on the rows that match a filter |
| **Lock** | Rows of a table (selected by a filter) that nobody but admins can update or delete |

The rules are simple:

1. A user can do what their level allows **or** what any of their groups allows.
   Groups only add; there is no "deny" inside a group.
2. A **lock** then takes update and delete away from everyone below admin, on the
   rows it freezes — even if the level or a group would allow it.
3. **Admins and super admins** are not affected by locks: they curate the data,
   frozen records included.
4. With no groups and no locks, nothing changes: the level decides, as before.

## Level Enter and groups

A user at level **Enter** (39) has no data access of their own. Put them in a
group and they get exactly what the group's rules say — nothing else. They see
only the tables they may read in the table list.

## Creating a group

1. **Users → Groups → New group.** Give it a name and, if you like, a description.
2. Add **members**: the users who belong to it. (You can also set a user's groups
   in the user form.)
3. Fill in the **permissions** grid and click **Save permissions**.

The left list also shows the six **built-in levels**, read only, with what each
one allows — the table you saw above — so there is a single place to look.

![The built-in levels, read only, at the top of the Groups tab](/images/v5/setup/groups-builtin.png)

## The permissions grid

![A group with its members and the permissions grid](/images/v5/setup/groups-permissions.png)

One row per data table, four columns:

| Column | Choices |
|---|---|
| **Create** | on / off |
| **Read** | Off · All · Filter |
| **Update** | Off · All · Filter |
| **Delete** | Off · All · Filter |

**All** means every row of the table. **Filter** means only the rows that match a
filter: click the funnel to edit it.

![The filter of a rule, edited with the query builder](/images/v5/setup/group-filter.png)

The filter editor is the same **query builder** used to search the data
(conditions, *all* / *at least one*, sub-groups). The value `$CURRENT_USER` stands
for the id of the user who is asking, so a rule can say "the records I created":

```
creator  is exactly  $CURRENT_USER
```

Filters can also be edited as **JSON** — the escape hatch for anything the builder
cannot show. A filter is checked when you save: an unknown field or operator is
refused, and nothing is saved until the grid is valid.

::: tip Create has no filter
A new record does not exist yet, so there is no row to test. **Create** is a
plain switch.
:::

### Example: a reviewer for one site

Group **Reviewers site 5**, with the user at level *Enter*:

| Table | Create | Read | Update | Delete |
|---|---|---|---|---|
| Stratigraphic units | off | Filter: `site` is `5` | Filter: `site` is `5` | off |
| Sites, Finds | off | All | off | off |

The reviewer sees the units of site 5, can correct them, and can read (but not
change) the other tables. Nothing else is visible.

### Example: a public, read-only key

Group **Public**, with an [API key](../api/#api-keys-with-a-group) at level
*Enter*:

| Table | Read |
|---|---|
| Stratigraphic units | Filter: `status` is `published` |

## Locks

**Users → Locks → New lock.** Choose a table and a filter; the rows it matches are
frozen:

![The Locks tab: a lock on the verified stratigraphic units, with the number of records it freezes](/images/v5/setup/locks.png)

- they cannot be **changed or deleted** by anyone except admins;
- the freeze extends to what belongs to the record — its plugin rows, attached
  files, stratigraphic relations, manual links, geometries and Zotero links;
- it also holds for imports: an import that touches a frozen record is refused as
  a whole, and writes nothing.

The list shows how many records each lock freezes right now. A frozen record shows
a **Frozen** badge in its page, and its Edit and Delete buttons are hidden for
everyone but admins. Remove the lock to thaw the records.

![A frozen record: the Frozen badge next to its title](/images/v5/setup/record-frozen.png)

A lock needs a filter: an empty one would silently freeze the whole table.

::: tip Publishing
A convenient pattern is a field such as `status` or `published`, and a lock on
`status is published`: setting the field marks the record as done, the lock keeps
it that way. An admin can still correct a published record, or remove the lock.
:::

## API keys with a group

An [API key](../api/#api-keys-with-a-group) can belong to a group, so that it
reads only what the group allows. Create it at level **Enter** and pick the group
(Config → API Keys). A request the rules refuse gets HTTP `403`.

## Good to know

- **Plugin tables follow their record.** Rules are set on the main tables; a
  plugin row is read, edited and frozen together with the record it belongs to.
- **Links to records you cannot read are left out.** A user sees neither the
  records they cannot read nor the links, backlinks and relations that point at
  them.
- **Files are not filtered.** The file manager lists all files; rules and locks
  apply to the *link* between a file and a record, not to the file list itself.
- **Frozen application.** When the application status is *Frozen* nobody writes,
  groups or not.
- **No field-level rules.** A rule covers whole rows; hiding single columns is not
  available.
- **Admins act with care.** An admin is never stopped by a rule or a lock; to
  freeze data against admins too, use the application status.
