---
title: System plugins
---

# System plugins

System plugins are optional modules that add domain-specific functionality on
top of the core record management features. They are enabled **per-table** in
**Config → Tables** and appear as extra panels in RecordView only for the
tables where they are active.

## Available plugins

### [Geodata & GeoFace](/guide/system-plugins/geodata)

Store geographic coordinates (points, polygons, lines) for records and visualise
them on an interactive MapLibre GL JS map. Supports WMS, WFS and local GeoJSON/KML layers.

### [Harris Matrix (stratigraphic relations)](/guide/system-plugins/rs)

Manage stratigraphic unit relationships (covers, cuts, abuts, fills, equivalence)
and visualise them as a directed acyclic Harris Matrix graph (Cytoscape.js).

### [Zotero (bibliographic citations)](/guide/system-plugins/zotero)

Link records to items in online Zotero libraries and display formatted citations
inline. Citations are cached locally; Zotero remains the authoritative source.

### [Fuzzy date (Chronology)](/guide/system-plugins/fuzzy-date)

Store uncertain, approximate or one-sided dates (ante quem / post quem) using a
compact string grammar (`c4l BCE`, `?/c3 CE`, …). Integrates with the GeoFace
temporal filter and the Harris Matrix absolute timeline.

### [Osteology (bone inventory)](/guide/system-plugins/osteology)

Document the bone preservation state of skeletal individuals recovered from burials.
Supports multiple individuals per record, 51 anatomical elements in 8 body regions,
conservation grade, anatomical certainty and laterality certainty. Data is stored as
a JSON column; an interactive SVG skeleton viewer is provided in RecordView.

## Enabling a plugin

Each plugin is configured at the table level in **Config → Tables** under the
**System plugins** section:

| Plugin | Setting |
|---|---|
| Geodata | Toggle **Geodata** on |
| RS / Harris Matrix | Set the **RS field** to the column that holds the unit identifier |
| Zotero | Toggle **Zotero** on — requires at least one Zotero library configured in Config |
| Fuzzy date | Toggle **Chronology (fuzzy date)** on — adds five `chrono_*` columns to the table |
| Osteology | Toggle **Inventario osteologico** on — adds one `osteo_data` JSON column to the table |

Once enabled, the corresponding panel appears in RecordView for all records of
that table.
