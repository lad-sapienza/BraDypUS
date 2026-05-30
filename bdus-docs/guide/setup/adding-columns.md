---
title: Fields
---

# Fields

The **Fields** panel lets you add, configure, rename and delete columns within a table.
Open it from **Config → Fields**, then select a table from the dropdown.

![TODO_SCREENSHOT: Fields panel with field list on the left and field form on the right](/images/v5/setup/fields-panel.png)

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

## Field types

| Type | Use for |
|---|---|
| `text` | Short single-line text |
| `textarea` | Long multi-line text |
| `date` | ISO date (`YYYY-MM-DD`) with date-picker |
| `boolean` | Yes/No toggle |
| `select` | Single value from a controlled vocabulary |
| `multi_select` | Multiple values from a controlled vocabulary |
| `combo_select` | Free text + vocabulary suggestions |
| `slider` | Integer within a min/max range |
| `link_to` | Foreign key — dropdown of records from another table |
| `link_out` | External URL |

## Vocabulary-backed fields

Fields of type `select`, `multi_select` and `combo_select` draw their options from a
[vocabulary](/guide/setup/vocabularies). Set the **Vocabulary** property to the name
of the vocabulary list to use.

## Foreign key fields (`link_to`)

Set **ID from table** to the table whose records should appear in the dropdown.
The dropdown shows the preview fields of that table and stores the `id`.

## Reordering fields

Drag rows in the field list to change the column order. This order is reflected in
RecordView, in all export headers and in the record list columns.

## Renaming a field

Use the **Rename** button. The DB column is renamed and all config references are updated.

## Deleting a field

Click **Delete**. The DB column and all its data are permanently removed.
