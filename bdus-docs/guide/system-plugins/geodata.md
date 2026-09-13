---
title: Geodata & GeoFace
---

# Geodata & GeoFace <Badge type="tip" text="v5.0.0" />

BraDypUS stores geographic coordinates (points, polygons, lines) for records
that have **geodata** configured, and displays them on an interactive map via
the **GeoFace** module.

## Enabling geodata for a table

In **Config → Fields**, add a field of type… geodata fields are not regular
fields but are enabled per-table. When records have geodata, the total count
appears in RecordView.

Geodata can be imported from GeoJSON (see [Import data](/guide/usage/import)).

## The GeoFace map

Open **GeoFace** from the sidebar to see all geolocated records on an
interactive map (MapLibre GL JS).

![GeoFace map view showing a basemap with point markers for records](/images/v5/usage/geoface-map.png)

- **Points, lines and polygons** are rendered from the stored WKT geometries.
- Click a feature to open its record.
- The active DataView filter is inherited — only filtered records appear on the map.

## Temporal filter

When the table also has the [fuzzy-date plugin](/guide/system-plugins/fuzzy-date)
active, GeoFace shows a **Temporal filter** bar above the map with a dual-handle
year slider (range −3000 to 2000, step 25 years). Dragging the handles filters the
markers in real time, keeping every record whose chronological window intersects
the selected years — including open-ended *ante quem* and *post quem* records (it
uses the `_chrono_overlap` operator internally).

## Coloring geometries by field value

The **Color by** dropdown above the map lets you theme every geometry by the
value of one field of the table, instead of the default flat color:

- **Categorical fields** (text, vocabulary, select…) get a distinct color per
  unique value, with a legend listing each value and its swatch. Datasets
  with many unique values only show the 12 most frequent ones individually —
  everything else (including records with no value at all) is grouped into a
  single **Other** entry.
- **Numeric fields** get a continuous color gradient from the lowest to the
  highest value in the current view, with a gradient bar legend showing the
  range.
- Whether a field is treated as categorical or numeric is detected
  automatically from the values actually returned — there is no need to mark
  a field as "numeric" in its configuration.
- Fields that reference another table (a foreign key) are not offered in the
  dropdown — coloring by a raw internal id wouldn't be meaningful.

If you have edit rights on the table, your choice is remembered as the
table's default and applied automatically the next time anyone opens this
map; pick **None** to go back to the default flat color.

## Configuring map layers

Open **Config → Geoface** to add or edit map layers.

![Geoface config panel showing a list of configured layers with type (WMS/WFS/local) and URL](/images/v5/usage/geoface-config.png)

Supported layer types:

| Type | Description |
|---|---|
| **WMS** | OGC Web Map Service — raster tiles |
| **WFS** | OGC Web Feature Service — vector features |
| **Local file** | GeoJSON or KML file uploaded to the server (`projects/{app}/geodata/`) |

For each layer configure:

| Property | Description |
|---|---|
| **Label** | Display name shown in the layer switcher |
| **URL** | Service endpoint or local file path |
| **Layer** | Layer name (WMS/WFS) |
| **Attribution** | Copyright/attribution text shown on the map |
| **Visible by default** | Whether the layer is on when the map opens |

## Adding geodata to a record

In RecordEdit, the geodata panel shows a mini-map. Click to place a point,
or draw a polygon/line with the drawing tools. The geometry is stored as WKT.

To remove geodata from a record, click **Clear geometry** in the geodata panel.
