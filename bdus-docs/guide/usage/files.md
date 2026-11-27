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

## Who can see and change a file <Badge type="tip" text="v5.30.0" />

A file is reached in two ways, and **either is enough to read it**:

- through the **records it is attached to**: if you can read one of them, you can read the file;
- directly, through a [group rule](../setup/groups) on the table **Files** (with the usual row filter,
  for instance "only files whose keywords contain *public*").

To **change or delete** a file you need a rule on **Files**, or **every** record it is attached to
must be one you may change: changing the file changes what each of those records shows. A **lock** on
any of those records freezes the file. A file attached to nothing (an *orphan*) can only be reached
through a rule on **Files**; its creator has no special right. Administrators and the levels Writer and
Reader work as usual, and rules on *all tables* include Files.

You also need to be able to **read a file to attach it** to a record.

An address that you may not read answers exactly like one that does not exist, so nobody can learn
that a file is there.

### Where a file is read from

Files are addressed by a random **uuid**: `GET /{app}/api/file/{uuid}` (derived versions:
`/{app}/api/file/{preset}/{uuid}.{ext}`). The request must be authenticated, with the usual token or API
key, or, in a browser, with the [session cookie](./authentication#session-cookie-files-in-the-browser) set at login,
which is accepted for this and nothing else. Images, PDF, plain text, audio and video are shown by the
browser; any other type (HTML, scripts, archives, office documents…) is sent as a download, never
interpreted as a page of the application.

## Custom fields <Badge type="tip" text="v5.30.0" />

The table **Files** is configured like any other: in *System configuration → Tables* it appears as a
**system** table. Its structure is fixed (the table itself, and its system fields `filename`, `description`,
`keywords`, `attribution`, `printable`, `public` and the technical ones cannot be removed, renamed or
retyped; their labels and checks can change), but you can **add your own fields** from its *Fields* list: a
licence, an author, a campaign, a vocabulary term, a link to another table, with the same field types,
checks and vocabularies as anywhere else.

Custom fields appear in the file list and the file details, are saved with the other metadata (validated,
and kept in the file's history like any record change), travel in the API (`GET /api/files`,
`PATCH /api/file/{id}`) and are included in the search box (text fields). The columns that belong to the
binary (`uuid`, `ext`, `creator`, `id`) are never written from a request. A file cannot be created or
deleted as a record of the table: it is created by uploading it and deleted with the file actions.

## Table and cards views <Badge type="tip" text="v5.30.0" />

The files page has two views, switched with the buttons at the top left and remembered for you (the first time you see **cards**):

- **Table**: one row per file with a **preview** column (the `thumb` version, an icon for other types), the
  fields as columns (**Columns** chooses which; by default the descriptive fields and your custom ones), sortable
  by clicking a header, with the cells editable in place when you may change the file.
- **Cards**: a grid with the preview large, the name, a few lines of description, and badges (*orphan*,
  *public*, how many records use it): the best way to browse photographs.

In both, tick files to **select** them: a bar offers **Make public** / **Make not public** and **Delete** for the
selected files you may change or delete (the others are left alone). The **Details** button opens every field,
custom ones included, in a side panel. The filters are the search box, **Orphans only** and **Public** /
**Not public**; Previous/Next in the preview follows the same filter and order.

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

## Deleting a record: its files <Badge type="tip" text="v5.30.0" />

When you delete a record, the files **only it uses** are not deleted by surprise: the delete dialog asks, for
each one, to *keep* it (an orphan) or *delete* it with the record. Over the API, `DELETE /api/record/{tb}/{id}`
takes `files` (`"keep"` — the default —, `"delete"`, or `{ "<fileId>": "delete" | "keep" }`) and answers with
`orphaned_files` and `deleted_files`; `GET /api/record/{tb}/files-before-delete?id=N` lists the files the
question is about. The MCP tool `records_delete` takes the same choice (`files`, default `keep`).

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
GET /{app}/api/file/{preset}/{file uuid}.{webp|jpg}
e.g. /myapp/api/file/thumb/3f2b8c1e-5d4a-4b7e-9a10-6c2d8e4f1a77.webp
```

- **Who is sent it** is decided by the preset and the file, never by the address alone:
  - a **public** preset sends the version to **anyone, with no login**, but only for files marked
    **public** (the *Public* field of the file; new files start *not public*);
  - in every other case it goes only to someone who may read the original (a signed-in person, an API
    key, or the browser session);
  - a preset that is **switched off** is served to nobody.

  Everything else is a plain **404**, so an address you may not read looks like one that does not exist.
  The original file is never sent anonymously, public or not.
- The extension must match the preset's format. Only names defined as presets are ever
  generated; an unknown preset, a missing file, a wrong extension or a file that is not a
  JPG, PNG, GIF or WebP image gives a plain **404** — there is no fallback to the original.
- Depending on the preset's *fit*, the image is cropped to exactly width × height (*Cover*,
  enlarging small images) or kept whole within the sides and never enlarged (*Inside*). An
  animated GIF yields its first frame.
- A preset with a **watermark** (see [Watermark](../setup/main-app-config.md#watermark)) serves
  images carrying the file's attribution text, for example `© Jane Doe, CC BY 4.0`; the original
  stays unchanged. Editing the attribution regenerates the version.
- Cached versions live in `projects/{app}/cache/{preset}/` and are **not** included in
  backups. They are deleted automatically when the file is replaced or deleted, when its
  metadata (e.g. the attribution) is edited, or when the preset is changed or removed, and
  rebuilt on demand. You can also delete the `cache/` folder at any time.
  A file that cannot be processed leaves a small `.fail` marker in the cache and is not
  retried (nor logged again) until the file changes.

The gallery and this page show the `thumb` version; clicking an image opens the original.

::: tip Publishing images to a website
Mark the files you want to publish **public** (one by one, or select several on the files page and use
*Make public*), and give the preset your website uses *Who may be sent it: anyone, for files marked public*.
Nothing else is reachable without a login.
:::

::: warning The direct address `projects/{app}/files/…` is closed
The web server no longer serves the `files/` folder: `projects/{app}/files/{uuid}.{ext}` answers **404**
(Apache `.htaccess` and the bundled Nginx template; only `geodata/` is still passed through). Files are
read only through `GET /api/file/{uuid}` and the derived-image address, which check who is asking. If you
proxy `/projects/` yourself (outer Nginx/Caddy), apply the same rule there, otherwise your proxy keeps
serving the files without a login.
:::

::: tip Upgrading: file addresses changed
The upgrade (migration M060) gives every file a uuid and **renames the files on disk** from
`{id}.{ext}` to `{uuid}.{ext}`; the derived-image cache is emptied and rebuilt on demand.
A link to `projects/{app}/files/42.jpg` or to `/api/file/thumb/42.webp` placed outside
BraDypUS (another website, a document) stops working: take the new address from the API
(`uuid` in `GET /api/files` or in the record's `files`). Take a backup first.
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
