---
title: Table relations
---

# Table relations

The **Relations** panel defines cross-table links that are displayed as a
read-only reference panel inside RecordView. This is distinct from plugin tables
(which are editable sub-tables) and from `link_to` fields (which store a FK value).

Open it from **Config → Relations**.

![TODO_SCREENSHOT: Relations panel showing a list of defined relations with table and field columns](/images/v5/setup/relations-panel.png)

## What a relation does

When a relation is defined, RecordView shows an extra collapsible section in
**both** tables that lists the linked records from the other table. One definition
is enough: the system automatically exposes the relation in both directions.

For example, a relation between `contexts` and `finds` will add a *Finds* panel
to the context RecordView **and** a *Contexts* panel to the finds RecordView.

## Adding a relation

Click **Add relation** and fill in:

| Field | Description |
|---|---|
| **Table A** | One of the two tables to link |
| **Table B** | The other table |
| **Field in A** | The field in Table A that holds the reference value |
| **Field in B** | The field in Table B that it is matched against |

The order of A and B does not matter: the system stores the relation in a
canonical form (alphabetically-first table) and automatically inverts the
field mapping when building the panel for the other side.

## Example

Table `contexts` has an `id` field. Table `finds` has a `context_id` field that
stores the id of the parent context. Define one relation:

- Table A: `contexts` — field: `id`
- Table B: `finds` — field: `context_id`

Result:
- The **context** RecordView shows a *Finds* panel listing all finds whose
  `context_id` equals the current context's `id`.
- The **find** RecordView shows a *Contexts* panel with the parent context
  whose `id` matches the find's `context_id`.

## Bidirectional relations

Relations are **bidirectional by design**. Each relation is stored once; the
system synthesises both directions automatically when loading the config. There
is no need to create a second "reverse" relation — doing so would be rejected
as a duplicate.
