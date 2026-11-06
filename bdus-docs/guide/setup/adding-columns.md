---
title: Fields
---

# Fields

The **Fields** panel lets you add, configure, rename and delete columns within a table.
Open it from **Config → Fields**, then select a table from the dropdown.

![Fields panel with field list on the left and field form on the right](/images/v5/setup/fields-panel.png)

## Adding a field

Click **Add field** and fill in the field form. The property names below are the labels
shown in the form:

| Property | Description |
|---|---|
| **Field name** | Database name of the column: 2 to 15 lowercase letters and underscores (`^[a-z_]{2,15}$`, e.g. `site_name`). Required; cannot be changed afterwards except with **Rename** |
| **Label** | Display label shown to users (spaces, uppercase and special characters allowed) |
| **Type** | See field types below. Required |
| **Database type** | Column type: `TEXT` (strings, long texts), `INTEGER` (numbers, booleans) or `TIMESTAMP` (dates and times). Required |
| **Vocabulary**, **Values from table**, **FK table** | Source of the values of `select`, `multi_select` and `combo_select` fields, see below |
| **Read-only** | The field is visible and its value is sent to the server, but users cannot edit it |
| **Hidden** | The field is not shown to users; its value is still sent to the server and cannot be edited |
| **Default value** | Pre-filled value for new records. Variables: `%today%` (current date), `%current_user%` (id of the current user) |

The **Validation** group of the form collects everything that limits the accepted values.
Each rule is checked as soon as it is filled in:

| Property | Description |
|---|---|
| **Checks** | One or more checks, see below. The mandatory and no-duplicates rules are set here |
| **Max length** | Maximum number of characters, spaces included |
| **Min value** / **Max value** | Lower and upper bound: numbers, or ISO dates on `date` fields. Also the range of a `slider` field |
| **Regex pattern** | Pattern the value must match, e.g. `^[A-Z]{2}\d+$` for a site code |

Below the group, two more properties:

| Property | Description |
|---|---|
| **Help text** | Text shown to users explaining how to fill in the field |
| **Widget** | Optional display widget for read mode: put a `.js` ES module in `projects/{app}/widgets/` and it appears in the list |

### Validation checks

The **Checks** property selects one or more checks. They run while the user types and
again on the server when the record is saved.

| Check | Accepts |
|---|---|
| `not_empty` | Mandatory field: it cannot be saved empty |
| `no_dupl` | A value not already used in another record |
| `int` | Whole numbers only: `10`, `-3` (not `10.5`) |
| `real` | Whole or decimal numbers, with a **dot** as the decimal separator: `10`, `10.5`, `-0.25` (not `10,5`, not `1e3`) |
| `email` | A valid email address |
| `valid_wkt` | A geometry in Well Known Text format |

Use `int` for counts and identifiers, `real` for measurements (weights, lengths). The two
are alternatives: do not combine them on the same field.

Min / Max value and Regex pattern need no check: they apply as soon as they are filled in.

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
| **FK table** | A table whose records appear in the dropdown; the record `id` is stored, but the preview fields are shown to users |

## Foreign key fields

A foreign key field stores the `id` of a record from another table. Use type `select`
and set **FK table** to the target table.

The dropdown shows the target table's preview fields; the stored value is the integer `id`.
This is the recommended way to avoid data duplication across tables.

## Reordering fields

Drag rows in the field list to change the column order. This order is reflected in
RecordView, in all export headers and in the record list columns.

## Renaming a field

Use the **Rename** button. The DB column is renamed and all config references are updated.

## Deleting a field

Click **Delete**. The DB column and all its data are permanently removed.
