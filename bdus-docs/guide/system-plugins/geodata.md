---
title: Geodata & GeoFace
---

# Geodata & GeoFace

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

![TODO_SCREENSHOT: GeoFace map view showing a basemap with point markers for records](/images/v5/usage/geoface-map.png)

- **Points, lines and polygons** are rendered from the stored WKT geometries.
- Click a feature to open its record.
- The active DataView filter is inherited — only filtered records appear on the map.

## Configuring map layers

Open **Config → Geoface** to add or edit map layers.

![TODO_SCREENSHOT: Geoface config panel showing a list of configured layers with type (WMS/WFS/local) and URL](/images/v5/usage/geoface-config.png)

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
