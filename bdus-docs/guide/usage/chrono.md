---
title: Chronology
---

# Chronology <Badge type="tip" text="v5.1.0" />

BraDypUS provides two chronological visualisation tools for tables that use the
**`fuzzy_date`** plugin (interval dates with `from` / `to`, a certainty level and
a period name). For how to enable the plugin and the input grammar, see
[Fuzzy date (Chronology)](/guide/system-plugins/fuzzy-date).

## `fuzzy_date` plugin

Enabling the plugin on a table adds five columns to that table:

| Column | Description |
|---|---|
| `chrono_from` | Start year (integer, negative = BCE). `NULL` for *ante quem* or undated. |
| `chrono_to` | End year (integer). `NULL` for *post quem* or undated. |
| `chrono_label` | The chronology **string you typed** (e.g. `c4l BCE / c3m CE` or `-50/200`). The formatted label shown in the UI ("Late 4th cent. BCE") is derived from this string — or from `chrono_from`/`chrono_to` — on the fly. There is no separate free-text label field. |
| `chrono_certainty` | Certainty level, stored as an integer: `1` = certain, `2` = probable, `3` = uncertain. (Databases migrated from earlier builds may still hold the legacy strings `certain` / `probable` / `possible`; these are read transparently.) |
| `chrono_period` | Qualitative period name (e.g. "Hellenistic"). Label only — no numeric mapping. |

---

## Chronological Timeline

The **Chronological Timeline** is a full-page view that overlays every record with
`fuzzy_date` data, from the selected tables, on a single time axis.

### Opening it

From the **DataView** (record list of any table) click the **Calendar** button in
the toolbar. The view opens full-page.

### Reading the view

- The rows are grouped by table: a **table header row**, then **one row per
  record** (`US012`, `US024`, …) below it.
- Each **coloured bar** is a record and spans from `chrono_from` to `chrono_to`.
- **Colour encodes the table**, not the certainty — each table gets its own hue
  (e.g. blue for *Stratigraphic units*, orange for *Finds*).
- **Certainty is encoded by the bar's opacity**: solid = certain, semi-transparent
  = probable, faint = uncertain.
- *Ante quem* and *post quem* records are drawn with a **dashed extension** running
  to the edge of the axis on the open side.
- Hovering a bar shows a **tooltip** with: the record title, the table name, the
  chronology string (`chrono_label`), the readable interval (e.g.
  `50 BCE → 200 CE`), the period (if set) and the certainty level.
- Clicking a row opens that record.

### Available filters

The bar at the top of the view lets you:

- **Select the tables** to include (multiple selection).
- **Set a year range** (`from` / `to`) to restrict the records shown; a record is
  kept when its window intersects the range (open-ended *ante quem* / *post quem*
  records are included on the matching side).

---

## Derived chronological distribution

The **Derived chronological distribution** panel appears in the RecordView body when
the current record's table has related tables (FK children, or a configured path —
see below) that have the `fuzzy_date` plugin enabled.

### Reading the panel

For each related table the panel draws **one horizontal density band** along a
shared time axis:

- The band is divided internally into **60 equal segments (bins)**.
- A bin's **opacity** (not its height) reflects how many related records have a
  chronological window (`chrono_from`–`chrono_to`) overlapping that bin. The band
  has a fixed height throughout — it is a density strip, not a bar histogram.
- The **peak** — the run of bins with the highest count — is marked below the band
  with its `from` and `to` years (e.g. `700 BCE – 489 BCE`).
- A **count badge** shows the total number of related records that carry
  chronological data.
- The whole band is a **link** that opens the related records in DataView,
  filtered by the relationship itself (all records reachable from the current one),
  not by any single time bin.

### Automatic behaviour (default)

With no extra configuration the panel shows a single automatic hop: the **direct
child tables** (FK relation in `bdus_cfg_relations`) that have `fuzzy_date` enabled.
If no direct child has `fuzzy_date`, the panel stays hidden — even if a more distant
descendant (e.g. a grandchild) does.

### Configurable path (to reach a more distant descendant) <Badge type="tip" text="v5.4.0" />

For cases such as "from a Site, show the Finds linked through the Stratigraphic
units" (a grandchild, not a direct child), each table can have a **chronological
distribution path** configured in Config → Tables, section
"Chronological distribution path":

1. Each step of the cascade lists the **direct child tables** of the previous one
   (via `bdus_cfg_relations`), without filtering by `fuzzy_date` — intermediate
   tables can act as a simple bridge (Stratigraphic units need not have their own
   chronology).
2. Only the **last** table in the path must have `fuzzy_date` active: a badge in the
   selector flags it at a glance, and saving is blocked (both client- and
   server-side) if the condition is not met.
3. If at some point in the cascade no selectable table appears, an FK relation is
   missing — go to Config → Relations to add it.
4. An empty path is equivalent to the automatic behaviour above (no migration or
   side effect on existing apps).

When a path is configured the panel shows **only** the last table in the chain (no
longer the direct children), and the band's link filters on the whole set of
records reachable along the path — not only those that carry chronological data, as
in the automatic single-hop behaviour.
