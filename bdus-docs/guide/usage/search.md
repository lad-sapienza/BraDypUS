---
title: Search & filter
---

# Search & filter

BraDypUS offers three search modes, all accessible from the DataView toolbar.

## Fast search

Type in the search bar at the top of the record list. The query is matched
against all [preview fields](/guide/setup/preview-config) using a
case-insensitive `LIKE` search.

![DataView with text typed in the fast-search bar and filtered results in the list](/images/v5/usage/search-fast.png)

Fast search is designed for quick lookups. For more precise filtering use
Advanced search.

## Advanced search

Click **Advanced** in the toolbar to open the filter builder. Each row defines
one condition:

| Column | Description |
|---|---|
| **Field** | Any field from the table or its plugin tables |
| **Operator** | How to compare (see table below) |
| **Value** | The value to compare against |
| **Connector** | `AND` / `OR` — how this row combines with the next |

![Advanced search panel with two filter rows visible, each with a field dropdown and operator](/images/v5/usage/search-advanced.png)

### Operators

| Operator | Meaning |
|---|---|
| `contains` | Field value contains the text (case-insensitive) |
| `is exactly` | Exact match |
| `does not contain` | Field value does not contain the text |
| `starts with` | Field value starts with the text |
| `ends with` | Field value ends with the text |
| `is empty` | Field is NULL or empty string |
| `is not empty` | Field has a non-empty value |
| `>` | Greater than (numeric or date) |
| `<` | Less than (numeric or date) |

Add rows with **Add condition** and remove them with the trash icon.
Click **Search** to apply. Click **Reset** to clear all filters.

## SQL expert search

Click **SQL** in the toolbar to enter a raw SQL `WHERE` clause.
This mode is available to admin users only.

```sql
sigla LIKE 'US%' AND periodo = 'Basso Medioevo'
```

![SQL expert search panel with a raw SQL clause typed in the input box](/images/v5/usage/search-sql.png)

::: warning
SQL expert search is powerful but bypasses the field-level validation. Only
use it if you know the exact column names and value formats.
:::

## Persistent filters

The active filter is stored in the URL as a query parameter. Copying the URL
and sharing it (or using Back/Forward in the browser) restores the exact search
state.

The Harris Matrix and Geoface views inherit the active filter from DataView — the
matrix or map will show only the records that match the current filter.

## Cross-table (plugin) search

Advanced search lets you filter by fields in **plugin tables** as well as the main
table. Plugin fields are listed under a labelled group in the field dropdown.

## Searching reference fields

Fields that point to another table (configured with `id_from_tb`) store the
**id** of the referenced record, but you search them by the referenced table's
display value: the autocomplete suggests those values, and the search resolves
them through the referenced table automatically. This works for reference
fields of the main table and of its plugin tables alike.
