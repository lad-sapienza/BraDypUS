---
title: File management
---

# File management <Badge type="tip" text="v5.0.0" />

The **File management** page (sidebar → *File management*) gives a centralised
view of every file uploaded to the current application, independent of which
record it may be attached to.

## Browsing files

Files are listed in a paginated table. Each row shows:

| Column | Description |
|---|---|
| Preview | Thumbnail for images, file-type icon for documents |
| Filename | The original filename and extension |
| Description | Free-text description — editable inline |
| Keywords | Comma-separated keywords — editable inline |
| Linked records | Badges linking to each record the file is attached to |
| Actions | Replace binary, delete permanently |

Click a thumbnail or filename to open an **in-app preview**:
- **Images** → fullscreen lightbox
- **Documents / PDFs** → inline iframe viewer

## Filtering orphan files

Toggle **Orphans only** in the toolbar to show only files that are not linked
to any record. This is useful for cleaning up the library after deleting records.

## Editing metadata

Click any cell in the **Description** or **Keywords** column to edit its value.
Changes are saved automatically when you press Enter or click outside the cell.

## Replacing a file

Click the **sync** icon (↺) in the Actions column to upload a new binary for an
existing file. The original description, keywords, and all record links are
preserved. If the new file has a different extension, the old physical file is
deleted.

## Deleting a file

Click the **trash** icon in the Actions column to permanently delete a file.
This removes:
- The physical file from the server
- The `bdus_files` record
- All `bdus_file_links` rows pointing to it

::: warning
Deletion is permanent and cannot be undone. If you only want to detach a file
from a specific record, use the **Unlink** action in the record's file gallery
instead.
:::

## File actions in RecordView

When editing a record, the file gallery (right sidebar) provides additional
per-record file operations:

| Action | Description |
|---|---|
| **Upload** | Upload a new file and attach it to this record |
| **Link existing** | Pick a file already in the library and attach it without re-uploading |
| **Unlink** (chain icon) | Remove the file from this record only — the file remains in the library |
| **Delete** (trash icon) | Permanently delete the file from the whole application |
| **Reorder** | Drag the grip handle to change the display order within this record |
