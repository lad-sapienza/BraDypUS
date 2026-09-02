---
title: Import data
---

# Import data <Badge type="tip" text="v5.0.0" />

BraDypUS can import records from external files. Open **Import data** from the
sidebar (editor privilege or higher required).

![ImportView showing the file upload area and table selector](/images/v5/usage/import.png)

## Supported formats

| Format | Use for |
|---|---|
| **CSV** | Tabular data — one row per record, first row as column headers |
| **JSON** | Array of objects, field names as keys |
| **GeoJSON** | Geographic features — geometries are imported as geodata |
| **Photos + index** | Bulk photo import with a CSV index file |

## Importing CSV or JSON

The import wizard has three steps:

**Step 1 — Setup:** Select the import type, target table, and upload the file.
Click **Preview** to continue.

**Step 2 — Configure:** BraDypUS reads the first rows and shows a mapping form.

![Field mapping form showing source columns and their mapped target fields](/images/v5/usage/import-mapping.png)

- Map each source column to a target field (or mark it as "ignore").
- Choose a **key field**: the table field used to match incoming rows against
  existing records. If a record with that value already exists it is updated
  (upsert); otherwise a new record is inserted.

**Step 3 — Result:** A summary shows how many records were inserted, updated,
and how many rows could not be matched.

::: tip Transactional import
The entire import runs in a single database transaction. If any row causes an
error, **all changes are rolled back** and no data is written. Fix the file and
try again.
:::

## Importing GeoJSON

1. Select the target table (must have geodata enabled).
2. Upload a `.geojson` or `.json` file with a `FeatureCollection`.
3. Select which GeoJSON property to use as the match key, and which table field
   to match it against.
4. Each matched record's geodata field is **replaced** with the feature geometry.
   Non-matching features are skipped.

CSV delimiter (comma, semicolon, tab) is detected automatically from the file.

## Photo import

1. Prepare a ZIP archive containing the image files.
2. Prepare a CSV index file with at least two columns: `filename` (the name
   inside the ZIP) and `record_id` (the id of the record to attach to).
3. Upload both files. BraDypUS validates the index, warns about any missing
   filenames, and links each photo to its record.

## Error handling

If the import transaction fails, no data is written. The error report shows
which row caused the failure so you can correct the source file and retry.
