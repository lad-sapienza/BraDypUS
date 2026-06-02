---
title: Records (CRUD)
---

# Records

## Browsing records

Select a table from the sidebar, then click **Data management** to open the
record list (**DataView**). The list shows the configured [preview fields](/guide/setup/preview-config)
in a paginated table.

![TODO_SCREENSHOT: DataView showing a paginated record table with column headers and row data](/images/v5/usage/dataview-list.png)

Controls in the DataView toolbar:

| Control | Action |
|---|---|
| **New** | Create a new record |
| **Export** | Download visible records as CSV, XLSX or JSON |
| **Columns** | Toggle which columns are visible |
| **Harris Matrix** | Open the stratigraphic matrix (only for tables with RS configured) |
| **Search bar** | Fast text search across preview fields |
| **Advanced** | Open the advanced search panel |

Click any row to open that record in **RecordView**.

## Reading a record

RecordView displays all field values for a single record, organised into sections:

- **Core fields** — the table's own columns
- **File gallery** — attached images and documents
- **Plugin tables** — inline editable sub-tables
- **Related records** — cross-table links defined in Config → Relations
- **Stratigraphic relations** — RS panel (if configured)
- **Bibliography** — Zotero citations (if configured)

![TODO_SCREENSHOT: RecordView showing a record with field values, a file gallery, and an RS panel](/images/v5/usage/record-view.png)

## Creating a record

Click **New** in the DataView toolbar (or the floating **+** button).
An empty edit form opens with all fields blank (or pre-filled with defaults).

Fill in the fields and click **Save**. Required fields are marked and the form
will not save until all required values are provided.

![TODO_SCREENSHOT: RecordEdit form with several fields filled in and the Save button visible](/images/v5/usage/record-edit.png)

## Editing a record

Open a record in RecordView and click **Edit** in the header. The same form used
for creation opens with current values pre-filled.

- Changes are not saved until you click **Save**.
- Navigating away with unsaved changes triggers a confirmation dialog.
- Validation errors are shown inline next to each offending field.

## Deleting a record

In RecordView, click the **Delete** button in the record header. A confirmation
dialog prevents accidental deletion.

Deleted records are soft-deleted and can be recovered from
[Deleted records](/guide/usage/deleted-records) for a configurable retention period.

## File attachments

In RecordView (edit mode), the file gallery panel allows:
- **Upload** one or more files (images, PDFs, any format)
- **Reorder** files by dragging
- **Delete** individual files

Images are displayed as thumbnails. Non-image files show a generic icon.
Large images are automatically downscaled on upload if **Max image size** is set
in [App settings](/guide/setup/main-app-config).

## Duplicating a record

In RecordView (read mode), click **Duplicate** in the record header. A new record
is immediately created with all the same field values. You are then taken directly
to the new record so you can review and adjust it before saving.

The `creator` field of the duplicate is automatically set to the logged-in user.
Privilege required: **add new**.

## Manual links

Manual links connect any two records across any two tables without requiring a
formal FK relation to be defined in Config. They appear in their own section in
RecordView, grouped by target table.

### Adding a manual link

In RecordView (edit mode), open the **Linked records** section:

1. Click **Add link**.
2. Select the target table.
3. Type to search for the target record.
4. Optionally type a **relation label** — a short free-text description of what
   the link means (e.g. `cites`, `is part of`, `parallels`). The label is shown
   as a small chip next to the link in both records.
5. Select the record from the autocomplete list; the link is saved immediately.

::: tip Typed links
The relation label turns a generic link into a **typed** link. You can use any
label you like — there is no controlled vocabulary enforced by the system.
Typed links are the foundation for the planned **graph visualisation** feature
(C2), which will render the network of typed links as an interactive diagram.
:::

### Deleting a manual link

In edit mode, click the **×** button next to the link. Links are bidirectional —
deleting it removes it from both records.

::: info
Manual links are stored in the `bdus_userlinks` system table. They are
**not** affected by deleting the linked record — orphaned link entries are
cleaned up when the surviving record is next opened.
:::
