---
title: Import data
---

# Import data

BraDypUS can import records from external files. Open **Import data** from the
sidebar (editor privilege or higher required).

![TODO_SCREENSHOT: ImportView showing the file upload area and table selector](/images/v5/usage/import.png)

## Supported formats

| Format | Use for |
|---|---|
| **CSV** | Tabular data — one row per record, first row as column headers |
| **JSON** | Array of objects, field names as keys |
| **GeoJSON** | Geographic features — geometries are imported as geodata |
| **Photos + index** | Bulk photo import with a CSV index file |

## Importing CSV or JSON

1. Select the target **Table**.
2. Upload the file.
3. **Preview** — BraDypUS reads the first rows and shows a field-mapping form.
4. Map each source column to a target field (or mark it as "ignore").
5. Click **Import**. A progress indicator shows the number of records inserted.

![TODO_SCREENSHOT: Field mapping form showing source columns and their mapped target fields](/images/v5/usage/import-mapping.png)

## Importing GeoJSON

1. Select the target table (must have geodata enabled).
2. Upload a `.geojson` or `.json` file with a `FeatureCollection`.
3. Each feature's `properties` are mapped to table fields; its `geometry` is
   stored as the record's geodata.

## Photo import

1. Prepare a CSV index file with a column for the filename and columns for any
   metadata fields.
2. Upload the photos as a ZIP archive along with the index CSV.
3. BraDypUS creates one record per photo, attaches the file, and fills in
   the metadata fields from the index.

## Error handling

Rows that fail validation (e.g. a required field is empty) are skipped and
listed in an error report at the end of the import. Successfully imported rows
are not rolled back if later rows fail.
