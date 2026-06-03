---
title: Harris Matrix (stratigraphic relations)
---

# Harris Matrix (stratigraphic relations)

BraDypUS supports stratigraphic unit (US) relationship management with a built-in
**Harris Matrix** visualisation. The feature is available for any table that has
the **RS plugin** enabled.

## Enabling the RS system

In **Config → Tables → System plugins**, toggle the **Stratigraphic relations**
switch on. This enables the RS panel in RecordView and the Harris Matrix button in
DataView.

The table's **ID field** (configured under Layout) is used as the human-readable
label shown in the RS panel and the matrix. The relations are stored in the database
using the integer record `id` as a foreign key, ensuring referential integrity.

## Stratigraphic relation types

| Code | Relation | Meaning |
|---|---|---|
| 1 | **Covers / is covered by** | Superposition |
| 2 | **Cuts / is cut by** | Truncation |
| 3 | **Abuts / is abutted by** | Abutment |
| 4 | **Fills / is filled by** | Fill relationship |
| 9 | **Is equivalent to** | Correlation (undirected) |
| 10 | **Is contemporary with** | Contemporaneity (undirected) |

Directed relations (1–4) are stored as a single row with direction. The inverse
is computed automatically — you do not need to enter both directions.

## Managing relations in RecordView

The **RS panel** in RecordView shows all relations for the current record.

![TODO_SCREENSHOT: RS panel inside RecordView showing a table of relations with type and linked unit identifier](/images/v5/usage/rs-panel.png)

To add a relation:
1. Select the **relation type** from the dropdown.
2. Start typing in the search box to find the other unit — an autocomplete list
   shows matching records from the same table. Select the desired unit.
3. Click **Add**.

To remove a relation: click the delete icon next to it.

## Harris Matrix view

Click **Harris Matrix** in the DataView toolbar to open a full-page interactive
matrix for all records in the current table (respecting the active search filter).

![TODO_SCREENSHOT: Harris Matrix full-page view showing a directed acyclic graph of stratigraphic units](/images/v5/usage/harris-matrix.png)

- Nodes represent individual stratigraphic units.
- Directed arrows represent the **covers** relationship (newer → older).
- Undirected dashed lines represent equivalences and contemporaneity.
- Units outside the active filter are shown with a dashed border.
- Click a node to navigate to that record.

### Subset matrix

Apply a search filter in DataView before opening the Harris Matrix to see only
the selected units. Units that are *related* to the filtered set but not themselves
in the filter are shown in a lighter style (in-context but not in-filter).

### Export

Click **Export PNG** in the Matrix toolbar to save the current view as a
high-resolution PNG image.

## Absolute chronological timeline

When the [fuzzy-date plugin](/guide/system-plugins/fuzzy-date) is active on
the same table, a **Chronological** toggle appears in the Matrix toolbar.

| Layout | Description |
|---|---|
| **Stratigraphic** | Standard Harris Matrix — nodes positioned by topological depth (default). |
| **Chronological** | Nodes positioned on a vertical year axis according to their `chrono_from` / `chrono_to` values. The horizontal columns from the stratigraphic layout are preserved, so stratigraphic position and calendar time are both visible simultaneously. |

In chronological mode:
- **Older** units appear **lower** on the axis; **newer** units appear **higher**.
- The **vertical distance** between nodes reflects the time gap between events.
- Nodes whose date type is ante quem or post quem are positioned at the known bound.
- **Undated nodes** are placed below the dated zone, marked with an italic label.
- The SVG axis on the left shows year labels calibrated to the data range.
- Edges still connect units by their stratigraphic relationship.
- Edit mode is not available in chronological layout (read-only view).

::: tip Why is this useful?
Traditional Harris Matrix tools show *which* unit comes before another, but
not *by how much*. The chronological layout reveals the real time gaps — a
stratigraphically short sequence may span centuries, while a long sequence
may compress into decades.
:::
