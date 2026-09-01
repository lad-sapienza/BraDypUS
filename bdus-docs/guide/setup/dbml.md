---
title: DBML import / export
---

# DBML import / export

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

### Column-level annotations

Place these inside the column's inline `note: '...'` constraint:

| Annotation | Example | Meaning |
|---|---|---|
| `bdus:type` | `bdus:type=long_text` | Override the default type mapping |
| `bdus:voc` | `bdus:voc=periodi` | Link column to a vocabulary list |
| `bdus:id_from_tb` | (with a DBML `ref:`) | Populate select from a related table |

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
  cat_ref integer  [ref: > categories.id, note: 'bdus:id_from_tb']
  Note: 'bdus:label=Items bdus:preview=id,name,status bdus:rs=1'
}

Table categories {
  id   integer [pk, increment]
  name varchar
  Note: 'bdus:label=Categories bdus:id_field=name'
}
```
