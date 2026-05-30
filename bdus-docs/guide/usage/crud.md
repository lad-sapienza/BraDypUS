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
