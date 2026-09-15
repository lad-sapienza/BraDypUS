---
title: Pleiades
---

# Pleiades — gazetteer place-name linking <Badge type="tip" text="v5.11.0" />

The Pleiades plugin links a record to a toponym in the
[Pleiades](https://pleiades.stoa.org) gazetteer of ancient places. Search-as-you-type
against the live Pleiades API, pick a result, and the record is filled with the
place's id and official name. If the place has coordinates, a point marker is added
to [GeoFace](/guide/system-plugins/geodata) automatically.

## Enabling the plugin for a table

Go to **Config → Tables**, select the table, scroll to the **System plugins** section
and toggle **Pleiades (place-name gazetteer)** on. The system adds four columns to
the table and confirms with a toast message. No other configuration is required.

To disable, toggle the switch off. The columns — and any data already entered — are
**preserved**; the panel simply disappears from RecordView. Toggle it back on at any
time to restore the panel without data loss.

## Linking a place

In RecordView (edit mode), open the **Pleiades** panel and start typing a place name.
After the third character, a debounced search runs against the Pleiades API and shows
up to as many suggestions as Pleiades itself returns for that query (no additional
limit is applied) — each with its title, Pleiades id, and a short snippet.

Click a suggestion to select it. This fetches the place's full record from Pleiades
and fills:

- **Pleiades id** and **official toponym** — read-only, taken from Pleiades
- **Alternative place name** — a free-text field you can fill independently, e.g. for
  a local or historical name that differs from the Pleiades title

Save the record. If the selected place has a representative point (`reprPoint`), a
marker for it is attached to GeoFace for this record.

## Removing a link

Click **Remove** in the Pleiades panel and save. This clears the id, the official
toponym and the alternative name, **and** removes the GeoFace marker that was added
for it — a manually-drawn geometry on the same record (if any) is never touched.
Deleting the whole record does the same cleanup automatically.

## Read mode

Displays the linked toponym as a link to its Pleiades page, its id, and the
alternative name if set:

> [Vigna Cartoni cistern](https://pleiades.stoa.org/places/492046782) `#492046782`
> Vigna Cartoni (local name)

## Data model

Four columns are added to the core table when the plugin is activated:

| Column | Type | Meaning |
|---|---|---|
| `pleiades_id` | INTEGER | Pleiades place numeric id |
| `pleiades_label` | VARCHAR(200) | Official Pleiades toponym, filled on selection |
| `pleiades_alt_label` | VARCHAR(200) | Free-text alternative name, independent of the above |
| `pleiades_geo_id` | INTEGER | Internal bookkeeping — the id of the GeoFace geometry this plugin created for the record, not shown in the UI |

`pleiades_geo_id` exists because a record can already carry a manually-drawn GeoFace
geometry: without tracking which geometry belongs to the Pleiades link specifically,
removing the link could not tell it apart from an unrelated one on the same record.

## Integration with other plugins

| Plugin | Integration |
|---|---|
| **GeoFace** | A linked place with coordinates shows up as a marker on the map like any other geometry, with no plugin-specific behaviour on the GeoFace side — it reuses the same geometry storage as manually-drawn features. |

Only one Pleiades toponym can be linked per record. If a record needs to reference
more than one ancient place, use a manual link or a custom lookup table instead.
