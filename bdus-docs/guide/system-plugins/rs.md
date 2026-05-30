---
title: Harris Matrix (stratigraphic relations)
---

# Harris Matrix (stratigraphic relations)

BraDypUS supports stratigraphic unit (US) relationship management with a built-in
**Harris Matrix** visualisation. The feature is available for any table that has
an **RS field** configured.

## Enabling the RS system

In **Config → Tables**, select the target table and set the **RS field** to the
column that stores the unit identifier (e.g. `sigla`). This enables the RS panel
in RecordView and the Harris Matrix button in DataView.

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
2. Enter the **identifier** of the other unit (e.g. `US003`).
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
