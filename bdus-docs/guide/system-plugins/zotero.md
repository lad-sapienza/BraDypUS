---
title: Zotero
---

# Zotero — bibliographic citations

The Zotero plugin links records in any table to items in online
[Zotero](https://www.zotero.org/) libraries (personal or group). Formatted
citations are cached locally and refreshed on demand; Zotero remains the
authoritative source.

## Prerequisites

1. At least one Zotero library must be registered in **Config → Zotero Libraries**.
2. The plugin must be **enabled per-table** (see below).

## Enabling the plugin for a table

Go to **Config → Tables**, select the table, scroll to the **System plugins**
section and toggle **Zotero** on. Save the table.

Once enabled, a citation panel appears in RecordView for every record of that
table — in edit mode even when no citations exist yet, so the user can start
adding them.

## Adding a citation

In RecordView (edit mode), open the citation panel, choose a library from the
dropdown, type a search query, and click the result to attach it. The citation
is stored in `bdus_zotero_links` and the formatted text is cached automatically.

## Citation display

Each citation shows:
- Formatted citation (HTML, from the library's configured CSL style)
- Author & year summary
- Pages and notes (editable inline)
- External link to Zotero.org (group libraries only)
- An orange badge if the Zotero item was removed from the library (**detached**)

## Sync

Citations are synced automatically in the background when a record is opened.
An admin can trigger a global sync for all libraries from **Config → Zotero Libraries**.

## Data model

| Table | Purpose |
|---|---|
| `bdus_zotero_libs` | Registered libraries (type, Zotero ID, API key, citation style) |
| `bdus_zotero_links` | Links between records and Zotero items; includes citation cache |

Both tables are created automatically by migration **M023**.
Deleting a library cascades to all its links.

See the [developer reference](../../dev/zotero) for the full API and PHP/Vue internals.
