---
title: Data export
---

# Data export <Badge type="tip" text="v5.0.0" />

You can export records directly from the DataView toolbar. The export respects
the active search filter — only matching records are exported.

## How to export

1. Open the record list for the desired table.
2. Optionally apply a search filter to limit the export.
3. Click the **Export** button in the toolbar.
4. Choose the format: **CSV**, **XLSX** or **JSON**.
5. The file downloads immediately.

![DataView toolbar with the Export button highlighted and the format dropdown open](/images/v5/usage/export-toolbar.png)

### What "the active filter" covers

The export takes exactly what the list is showing: a text search, a Builder
search (nested groups included), an SQL search, or the filter a link from
another record opened. Sorting and which columns are shown do not matter — the
file always has every column of the table.

::: warning Errors are errors
If the search cannot be read — a hand-edited link with broken JSON, an unknown
`qt` value, a `qt` without its `q` — the export does not guess: it stops with
`invalid_parameters` and names the offending parameter, instead of quietly
downloading the whole table. The same applies to the API
(`GET /api/records/{tb}/export`).
:::

## Formats

| Format | Notes |
|---|---|
| **CSV** | Comma-separated values, UTF-8 encoded. Suitable for Excel, Google Sheets, R, Python. |
| **XLSX** | Native Excel file. Column headers in row 1. At most 1,048,575 records (the worksheet limit); beyond that the export stops with an error — use CSV. |
| **JSON** | Array of objects, one per record. Field names as keys. |

## What is exported

The export includes **all columns** of the main table (not plugin sub-tables).
The column order matches the [field order](/guide/setup/adding-columns) defined in Config.

Plugin table data (e.g. finds attached to a context) is not included in the main
export. Export the plugin table separately by selecting it as the active table.

## Large datasets <Badge type="tip" text="v5.16.5" />

Records are read from the database and written to the file **one at a time**, so
the memory used by the server does not grow with the size of the export. A table
of 120,000 records with long texts exports in CSV or JSON within the default PHP
`memory_limit` (256 MB in the Docker image).

- **CSV and JSON** are sent to the browser while they are being produced.
- **XLSX** is assembled in a temporary file on the server (removed as soon as the
  download is sent) and then sent, so an error such as the row limit is reported
  instead of delivering a broken file. It needs free space in the system
  temporary directory roughly equal to the compressed file.
- If the query fails or matches no record, nothing is downloaded and you get an
  error message instead of an empty or partial file.

On PostgreSQL the records are read through a server-side cursor; on MySQL/MariaDB
the query runs unbuffered, and SQLite reads row by row natively.
