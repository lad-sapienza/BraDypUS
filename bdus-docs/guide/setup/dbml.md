---
title: DBML import / export
---

# DBML import / export <Badge type="tip" text="v5.1.0" />

The **DBML** panel in Config lets you export your entire application schema as a
single annotated `.dbml` file, and import new tables from a DBML file.

[DBML (Database Markup Language)](https://dbml.org) is a plain-text format
for describing relational schemas. BraDypUS extends it with `bdus:*` annotations
so the exported file is both human-readable and machine-importable.

::: info Privilege required
Only **super-admin** users can access the DBML panel.
:::

---

## Export

Click **Download .dbml** to generate and download the complete schema of the
current application. The file contains:

- One `Enum` block for every vocabulary list (values, `// bdus:vocabulary` marker).
- One `Table` block for every user-defined table with all its fields and a `Note`
  carrying the BraDypUS annotations (`bdus:label`, `bdus:preview`, `bdus:rs`, …).
- A `ref:` on every column that has a [relation](./relations) <Badge type="tip" text="v5.16.1" />,
  with `bdus:on_delete` / `bdus:on_update` when they are not the defaults.

The exported file is valid DBML: paste it into [dbdiagram.io](https://dbdiagram.io)
to visualise the schema as an entity-relationship diagram.

System tables (`bdus_*`) are never included.

---

## Import

Importing creates **new tables only**. Existing tables are never modified;
if a table in the DBML already exists it is skipped with a warning.

### Workflow

1. Paste the DBML text into the textarea, or click **Open file** to load a `.dbml` file.
2. Click **Analyse** — BraDypUS parses the DBML and shows a preview of what would be created.
3. Review the preview: fix any errors (red rows), note the warnings (yellow rows).
4. Click **Apply** — tables and vocabulary entries are created.

### Preview results

Each table in the preview shows its status:

| Status | Meaning |
|---|---|
| Green (no marker) | Ready to import |
| Yellow | Has warnings — import will proceed, with automatic additions |
| Red | Has errors — table will be skipped |

**Hard errors (block import):**

| Code | Cause |
|---|---|
| `table_already_exists` | A table with that name is already configured |
| `pk_must_be_id` | Primary key must be named `id` — this is a BraDypUS invariant |

**Warnings (import continues):**

| Code | Meaning |
|---|---|
| `auto_add_id` | No `id` field found; it will be added automatically |
| `auto_add_creator` | No `creator` field found; it will be added automatically (non-plugin tables only) |

Expand the **fields** summary on any table row to inspect the mapped field list
before committing.

---

## DBML annotations reference

BraDypUS uses `bdus:*` annotations in DBML `Note` fields to round-trip the full
configuration through a plain-text file.

### Table-level annotations

Place these inside the table's `Note: '...'` string:

| Annotation | Example | Meaning |
|---|---|---|
| `bdus:label` | `bdus:label=Sites` | Display name in the UI |
| `bdus:preview` | `bdus:preview=id,name` | Fields shown in record lists |
| `bdus:order` | `bdus:order=name` | Default sort column |
| `bdus:id_field` | `bdus:id_field=name` | Field used as the human identifier |
| `bdus:is_plugin` | `bdus:is_plugin=items` | Marks a plugin table and its parent |
| `bdus:rs` | `bdus:rs=1` | Enable Harris Matrix (stratigraphic relations) |
| `bdus:geodata` | `bdus:geodata=1` | Enable Geodata plugin |
| `bdus:fuzzy_date` | `bdus:fuzzy_date=1` | Enable Fuzzy date plugin |

On import these three flags switch the plugin on exactly as **Config → Tables** does (<Badge type="tip" text="v5.16.2" /> — before that version `bdus:geodata` and `bdus:fuzzy_date` were read but not applied). `bdus:fuzzy_date=1` adds the five `chrono_*` columns for you: do not list them in the DBML. An exported file does list them; importing it back is fine, they are not created twice.

### Column-level annotations

Place these inside the column's inline `note: '...'` constraint:

| Annotation | Example | Meaning |
|---|---|---|
| `bdus:type` | `bdus:type=long_text` | Override the default type mapping |
| `bdus:voc` | `bdus:voc=periodi` | Link column to a vocabulary list |
| `bdus:id_from_tb` | (with a DBML `ref:`) | Populate select from a related table |
| `bdus:on_delete` | `bdus:on_delete=SET NULL` | Policy of the relation created by a `ref:` <Badge type="tip" text="v5.16.1" /> — `CASCADE`, `RESTRICT` (default), `SET NULL`, `NO ACTION` |
| `bdus:on_update` | `bdus:on_update=RESTRICT` | Same, for updates (default `CASCADE`) <Badge type="tip" text="v5.16.1" /> |

### Relations <Badge type="tip" text="v5.16.1" />

A column with a `ref:` creates a [relation](./relations) — the *Linked records* panel on
both tables, backed by a real FOREIGN KEY and an index — exactly as if you had added it
in **Config → Relations**:

```dbml
Table finds {
  id      integer [pk, increment]
  site_id integer [ref: > sites.id, note: 'bdus:on_delete=CASCADE']
}
```

- `ref:` and `bdus:id_from_tb` are independent. With only `ref:` the column stays a
  plain field and you get the panel; add `bdus:id_from_tb` to make it a select that
  lists the records of the other table.
- The referred table may be defined **later in the same file**, or already exist. An
  existing table is never modified: the relation belongs to the new table.
- Only `>` (this column holds the id of the other table) is supported. A `<` ref would
  put the foreign key on the other table, and is reported as `ref_direction_unsupported`.
- Plugin tables cannot take part in a relation other than their attachment to the parent
  (`bdus:is_plugin`); a table that refers to a plugin table, or the other way round, gets
  `ref_plugin_not_allowed`.

A ref that cannot become a relation never blocks the import: the table is created, the
warning appears in the preview (and, if the database refuses the constraint, as
`relation_skipped:table.column:code` after **Apply**), and you can add the relation by hand.

| Warning | Meaning |
|---|---|
| `ref_target_missing` | The table or the column the ref points to does not exist |
| `ref_plugin_not_allowed` | The ref involves a plugin table |
| `ref_direction_unsupported` | `<` refs are not imported |
| `invalid_on_delete`, `invalid_on_update` | Unknown policy: the default is used |

Self-references are allowed; their policy is always `RESTRICT` / `CASCADE`.

### Vocabulary Enums

Mark an `Enum` block as a BraDypUS vocabulary with a comment line:

```dbml
Enum item_status {
  "active"
  "inactive"
  "pending"
  // bdus:vocabulary
}
```

On import, all values are inserted into `bdus_vocabularies`. Enums without
`// bdus:vocabulary` are ignored.

### Complete example

```dbml
Enum item_status {
  "active"
  "inactive"
  // bdus:vocabulary
}

Table items {
  id      integer  [pk, increment]
  creator integer
  name    varchar
  status  varchar  [note: 'bdus:voc=item_status']
  cat_ref integer  [ref: > categories.id, note: 'bdus:id_from_tb']  // select + relation
  Note: 'bdus:label=Items bdus:preview=id,name,status bdus:rs=1'
}

Table categories {
  id   integer [pk, increment]
  name varchar
  Note: 'bdus:label=Categories bdus:id_field=name'
}
```
