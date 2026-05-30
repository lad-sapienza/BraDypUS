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

When a relation is defined, RecordView shows an extra collapsible section that
lists all records from the *target* table whose specified field matches the current
record's identifier. This is useful for showing reverse references: e.g. all finds
associated with a given context.

## Adding a relation

Click **Add relation** and fill in:

| Field | Description |
|---|---|
| **Label** | Section heading shown in RecordView (e.g. `Associated finds`) |
| **Source table** | The table that *contains* the relation (the table whose RecordView will show the panel) |
| **Target table** | The table whose records will be listed in the panel |
| **Target field** | The field in the target table that holds the reference value |
| **Reference field** | The field in the source record whose value is used to filter target records (usually `id`) |

## Example

Table `contexts` has id-based records. Table `finds` has a field `context_id` that
stores the id of the parent context. To show finds inside a context record:

- Source table: `contexts`
- Target table: `finds`
- Target field: `context_id`
- Reference field: `id`

This will list all `finds` records where `context_id` equals the current context's `id`.

## Bidirectional relations

Relations are **unidirectional**: the panel appears only in the source table's RecordView.
Create a second relation with swapped source/target if you want it visible from both sides.
