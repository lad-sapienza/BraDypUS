---
title: Fields
---

# Fields

The **Fields** panel lets you add, configure, rename and delete columns within a table.
Open it from **Config → Fields**, then select a table from the dropdown.

![Fields panel with field list on the left and field form on the right](/images/v5/setup/fields-panel.png)

## Adding a field

Click **Add field** and fill in the field form:

| Property | Description |
|---|---|
| **Name** | Internal column name (lowercase, underscores; e.g. `site_name`) |
| **Label** | Display label shown to users |
| **Type** | See field types below |
| **Required** | Prevent saving if this field is empty |
| **No duplicates** | Reject values already present in another record |
| **Default** | Pre-filled value for new records |
| **Max length** | Character limit for text fields |
| **Min / Max** | Numeric or date bounds |
| **Regex pattern** | Validation pattern (e.g. `^[A-Z]{2}\d+$` for a site code) |

### Validation checks

The **Check** property of a field selects one or more validation rules. They run while the
user types and again on the server when the record is saved.

| Check | Accepts |
|---|---|
| `int` | Whole numbers only: `10`, `-3` (not `10.5`) |
| `real` | Whole or decimal numbers, with a **dot** as the decimal separator: `10`, `10.5`, `-0.25` (not `10,5`, not `1e3`) |
| `email` | A valid email address |
| `not_empty` | Any non-empty value (same as **Required**) |
| `no_dupl` | A value not already used in another record (same as **No duplicates**) |
| `valid_wkt` | A geometry in Well Known Text format |

Use `int` for counts and identifiers, `real` for measurements (weights, lengths, coordinates
stored as numbers). The two are alternatives: do not combine them on the same field.

## Field types

| Type | Use for |
|---|---|
| `text` | Short single-line text |
| `long_text` | Long multi-line text (plain, preserves line breaks) |
| `md` | Markdown text — rendered as formatted HTML in read mode; edit mode has a live preview toggle |
| `date` | ISO date (`YYYY-MM-DD`) with date-picker |
| `boolean` | Yes/No toggle |
| `select` | Single value from a controlled vocabulary or another table |
| `multi_select` | Multiple values from a controlled vocabulary |
| `combo_select` | Free text + vocabulary suggestions |
| `slider` | Integer within a min/max range |

## Value sources for select fields

Fields of type `select`, `multi_select` and `combo_select` need a source for their
available values. Set exactly one of the following:

| Property | Description |
|---|---|
| **Vocabulary** | Name of a [vocabulary list](/guide/setup/vocabularies) — values are managed in the Vocabularies panel |
| **Values from table** | A table and field whose unique values are used as autocomplete suggestions |
| **ID from table** | A table whose records appear in the dropdown; the record `id` is stored, but the preview fields are shown to users |

## Foreign key fields

A foreign key field stores the `id` of a record from another table. Use type `select`
and set **ID from table** to the target table.

The dropdown shows the target table's preview fields; the stored value is the integer `id`.
This is the recommended way to avoid data duplication across tables.

## Reordering fields

Drag rows in the field list to change the column order. This order is reflected in
RecordView, in all export headers and in the record list columns.

## Renaming a field

Use the **Rename** button. The DB column is renamed and all config references are updated.

## Deleting a field

Click **Delete**. The DB column and all its data are permanently removed.
