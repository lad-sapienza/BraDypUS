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
| Filename | The filename (without extension) — editable inline |
| Description | Free-text description — editable inline |
| Keywords | Comma-separated keywords — editable inline |
| Attribution | Credit or licence line, e.g. `© Jane Doe, CC BY 4.0` — editable inline; shown under the image in the record's file gallery |
| Linked records | Badges linking to each record the file is attached to |
| Actions | Replace binary, delete permanently |

Click a thumbnail to open an **in-app preview**:
- **Images** → a near-full-screen preview titled with the file's name and
  extension. The image is scaled down to fit the available height and width
  (keeping its proportions); a small image is never enlarged and stays
  centered at its natural size
- **Documents / PDFs** → inline viewer filling the same preview area

### Previous / Next <Badge type="tip" text="v5.13.0" />

The preview window has **‹ 3 / 7 ›** buttons at the bottom, and the **←** and
**→** keys do the same: they step through **every file the list matches** —
the current search and **Orphans only** filter included — not just the page
on screen. The files follow the order of the table (newest first), and **Esc**
closes the preview at any time.

With more than **5000** matching files, the navigation stops at the 5000th
and the counter shows it (**‹ 12 / 5000+ ›**).

## Filtering and searching

Toggle **Orphans only** in the toolbar to show only files that are not linked
to any record — useful for cleaning up the library after deleting records.

The search box filters by filename, description, keywords, attribution, or an exact file
id, and can be combined with **Orphans only**.

## Editing metadata

Click any cell in the **Filename**, **Description**, **Keywords** or **Attribution** column to
edit its value. Changes are saved automatically when you press Enter or click
outside the cell.

Renaming a file only changes this label — the file's actual location on the
server is always based on its internal id, never on the filename, so renaming
never breaks a link, an embedded image, or a download URL. An empty filename
is rejected.

## Replacing a file

Click the **sync** icon (↺) in the Actions column to upload a new binary for an
existing file. The original description, keywords, attribution, and all record links are
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

## Derived images <Badge type="tip" text="v5.18.0" />

For every image, BraDypUS can serve smaller versions defined as **presets** in
*System configuration → App settings → Images* (see
[Derived images](../setup/main-app-config.md#derived-images-presets)). A version is
created the first time it is requested and then kept in a cache, so the application stays
light until a version is actually needed:

```
GET /{app}/api/file/{preset}/{file id}.{webp|jpg}
e.g. /myapp/api/file/thumb/42.webp
```

- The URL works like the one of the original file: **no login is needed**.
- The extension must match the preset's format. Only names defined as presets are ever
  generated; an unknown preset, a missing file, a wrong extension or a file that is not a
  JPG, PNG, GIF or WebP image gives a plain **404** — there is no fallback to the original.
- Depending on the preset's *fit*, the image is cropped to exactly width × height (*Cover*,
  enlarging small images) or kept whole within the sides and never enlarged (*Inside*). An
  animated GIF yields its first frame.
- Cached versions live in `projects/{app}/cache/{preset}/` and are **not** included in
  backups. They are deleted automatically when the file is replaced or deleted, when its
  metadata (e.g. the attribution) is edited, or when the preset is changed or removed, and
  rebuilt on demand. You can also delete the `cache/` folder at any time.

The gallery and this page show the `thumb` version; clicking an image opens the original.

::: warning Files are publicly reachable by id
Originals (`projects/{app}/files/{id}.{ext}`) and derived versions are served without
authentication, and ids are sequential. Do not upload material that must stay private.
Access control for files is a separate, future feature.
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
