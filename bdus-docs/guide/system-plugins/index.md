---
title: System plugins
---

# System plugins

System plugins are optional modules that add domain-specific functionality on
top of the core record management features. They are enabled per-table in Config.

## Available plugins

### [Geodata & GeoFace](/guide/system-plugins/geodata)

Store geographic coordinates (points, polygons, lines) for records and visualise
them on an interactive MapLibre GL JS map. Supports WMS, WFS and local GeoJSON/KML layers.

### [Harris Matrix (stratigraphic relations)](/guide/system-plugins/rs)

Manage stratigraphic unit relationships (covers, cuts, abuts, fills, equivalence)
and visualise them as a directed acyclic Harris Matrix graph (Cytoscape.js).

## Enabling a plugin

Each plugin is configured at the table level in **Config → Tables**:

- **Geodata**: set the `geodata` property to `true` for the table.
- **RS/Harris Matrix**: set the **RS field** to the column that holds the unit identifier.

Once enabled, the corresponding panels appear in RecordView for all records of that table.
