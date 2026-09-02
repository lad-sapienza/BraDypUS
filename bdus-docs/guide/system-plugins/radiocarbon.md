---
title: Radiocarbon dating
---

# Radiocarbon dating (C14) <Badge type="tip" text="v5.3.0" />

The radiocarbon plugin records C14 determinations (BP + error) and automatically
calibrates them into calendar-year ranges using the IntCal20 curve. Unlike the other
system plugins, it does not add columns to your table — it creates a dedicated child
table so a single record can carry more than one dating.

## When to use it

Use this plugin when your records need scientific radiocarbon dates, for example
stratigraphic units, burials, or organic finds:

- **One or more determinations per record** — a context can have several C14 samples
- **Automatic calibration** — enter the lab's BP value and error, the calendar range
  is computed for you, server-side, every time the record is saved
- **Searchable calibrated ranges** — filter or sort records by calibrated date range
  in the advanced search, exactly like any other field

## Enabling the plugin for a table

Go to **Config → Tables**, select the table, scroll to the **System plugins** section
and click **Activate** next to **Radiocarbon dating**. This creates a new plugin table
named `{table}_radiocarbon` (e.g. `us_radiocarbon`) with the fields listed below. No
further configuration is required — the section appears immediately in RecordView.

There is currently no "deactivate but keep data" toggle for this plugin (unlike
fuzzy-date or osteology, which only add columns). To remove it, delete the
`{table}_radiocarbon` table from **Config → Tables** like any other plugin table —
this permanently deletes all datings.

## The radiocarbon panel

Once enabled, a **Radiocarbon dating** section appears in RecordView with the usual
add/remove-row plugin table editor.

| Field | Type | Notes |
|---|---|---|
| Lab code | text | e.g. `Beta-123456` |
| BP | integer | uncalibrated radiocarbon age |
| Error (± years) | integer | 1σ measurement uncertainty |
| Material dated | text | e.g. charcoal, bone collagen |
| δ13C (‰) | decimal | optional, isotopic fractionation correction |
| Calibration curve | text | fixed to `intcal20` for now |
| Cal BP (68.2%) from / to | integer | **read-only**, computed on save |
| Cal BP (95.4%) from / to | integer | **read-only**, computed on save |
| Notes | long text | free text |

The four calibrated fields cannot be edited directly — whatever the record's `BP`
and `Error` values are, the calendar range is (re)computed automatically the next
time the record is saved, and any value a client might send for those fields is
discarded server-side.

## Calibration method and its limits

Calibration convolves the sample's Gaussian likelihood (BP, error) against the
[IntCal20](https://intcal.org) Northern Hemisphere curve (Reimer et al. 2020), then
finds the narrowest range covering 68.2% and 95.4% of the resulting probability mass.

::: warning Not a substitute for OxCal
This is the same core method every calibration tool uses, but with a deliberate
simplification: the four calibrated fields store a single **bounding range** (min/max
calBP), not the true — and sometimes disjoint — Highest Posterior Density (HPD)
regions that a tool like [OxCal](https://c14.arch.ox.ac.uk/oxcal.html) reports. On
wiggly stretches of the curve (e.g. the Hallstatt plateau) the stored range can include
a low-probability "gap" as if it were part of the result. This is intentional: it keeps
the calibrated range as two plain, indexable, searchable integer columns instead of a
JSON blob of disjoint sub-ranges. For publication-grade precision, cross-check with
OxCal or a dedicated calibration tool.
:::

## Data model

Activating the plugin creates a genuine plugin table (like a user-defined one, e.g.
`is_plugin`/`plugin_of`), not a JSON column:

| Column | Type | Content |
|---|---|---|
| `id` | INTEGER | primary key |
| `id_link` | INTEGER | standard plugin-table linkage to the parent record |
| `lab_code` | VARCHAR | |
| `bp` | INTEGER | |
| `bp_error` | INTEGER | |
| `material` | VARCHAR | |
| `d13c` | FLOAT | nullable |
| `curve` | VARCHAR | `intcal20` |
| `cal_1s_from` / `cal_1s_to` | INTEGER | 1σ bounding range, computed |
| `cal_2s_from` / `cal_2s_to` | INTEGER | 2σ bounding range, computed |
| `notes` | TEXT | |

Because this is a real child table (not JSON), the calibrated fields are automatically
exposed as filterable fields in the advanced search UI, and joined via the standard
plugin-table subquery mechanism — no extra configuration needed.
