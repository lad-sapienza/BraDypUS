---
title: Fuzzy date (Chronology)
---

# Fuzzy date — uncertain chronological dating <Badge type="tip" text="v5.0.0" />

The fuzzy-date plugin adds a structured chronology section to any table. It stores
uncertain, approximate, or range-based dates in five dedicated columns directly in
the core table — making them searchable, filterable, and usable by other plugins
such as the [GeoFace temporal filter](/guide/system-plugins/geodata) and the
[Harris Matrix absolute timeline](/guide/system-plugins/rs).

## When to use it

Use this plugin when your records have a dating that is:

- **Approximate** — "probably 4th century BC"
- **A range** — "between 350 and 300 BCE"
- **One-sided** — "not later than 300 BCE" (ante quem) or "not earlier than the late 4th century BCE" (post quem)
- **Uncertain** — "possibly Hellenistic"

## Enabling the plugin for a table

Go to **Config → Tables**, select the table, scroll to the **System plugins** section and
toggle **Chronology (fuzzy date)** on. The system immediately adds five columns to the
table and confirms with a toast message. No other configuration is required.

To disable, toggle the switch off. The columns — and any data already entered — are
**preserved**; the panel simply disappears from RecordView. Toggle it back on at any
time to restore the panel without data loss.

## The chronology panel

Once enabled, a **Chronology** section appears in RecordView below the regular fields.

### Read mode

Displays the stored chronology as a formatted label, a certainty badge, and an optional
period name. Examples:

> **Late 4th cent. BCE** <span style="background:#f59e0b;color:#fff;padding:2px 6px;border-radius:4px;font-size:.8em">Probable</span> · Hellenistic

> **Ante quem: 4th cent. BCE**

> **350 BCE – 300 BCE** <span style="background:#22c55e;color:#fff;padding:2px 6px;border-radius:4px;font-size:.8em">Certain</span>

### Edit mode

The panel shows three inputs:

| Input | Purpose |
|---|---|
| **Chronology string** | Primary input — see grammar below |
| **Certainty** | Certain / Probable / Possible |
| **Period** | Free text or vocabulary term (qualitative label only) |

Typing in the chronology string field triggers live parsing. A preview shows the
generated label and the numeric range (`from – to`) so you can verify the result
before saving.

## Chronology string grammar

The chronology string is the primary input method. It supports two token types:
**century** and **year**. The general structure is:

```
input = token             → single date or century range
      | token / token     → explicit range
      | ? / token         → ante quem
      | token / ?         → post quem
      | ?                 → undated
```

### Century tokens

A century token starts with **`c`** (mandatory), followed by the century number,
an optional qualifier, and the era:

```
c{N}{qualifier} {BCE|CE}
```

| Qualifier | Meaning | Example | Range |
|---|---|---|---|
| *(none)* | Full century | `c4 BCE` | 400–301 BCE |
| `e` | Early (~first 25%) | `c4e BCE` | 400–376 BCE |
| `m` | Mid (~middle 50%) | `c4m BCE` | 375–326 BCE |
| `l` | Late (~last 25%) | `c4l BCE` | 325–301 BCE |
| `h1` | First half | `c4h1 BCE` | 400–351 BCE |
| `h2` | Second half | `c4h2 BCE` | 350–301 BCE |
| `q1` – `q4` | Quarter 1–4 | `c4q3 BCE` | 350–326 BCE |

### Year tokens

| Format | Example | Meaning |
|---|---|---|
| `-N` | `-350` | Year 350 BCE |
| `N BCE` | `350 BCE` | Year 350 BCE |
| `N` or `N CE` | `300` or `300 CE` | Year 300 CE |

::: warning Disambiguation
`4 BCE` means **year 4 BCE** (not the 4th century). To express the 4th century BCE,
write `c4 BCE`. The `c` prefix is what identifies a century token.
:::

### Range and one-sided dates

| Input | Meaning | Stored as |
|---|---|---|
| `c4l BCE / c3m CE` | Late 4th BCE to mid 3rd CE | from=–325, to=275 |
| `-350 / -300` | Years 350–300 BCE | from=–350, to=–300 |
| `? / c4q3 BCE` | Ante quem: before 3rd quarter 4th BCE | from=null, to=–326 |
| `c4l BCE / ?` | Post quem: after late 4th BCE | from=–325, to=null |
| `?` | Undated | from=null, to=null |

## Data model

Five columns are added to the core table when the plugin is activated:

| Column | Type | Meaning |
|---|---|---|
| `chrono_from` | INTEGER | Start year (negative = BCE). `NULL` for ante quem or undated. |
| `chrono_to` | INTEGER | End year. `NULL` for post quem or undated. |
| `chrono_label` | VARCHAR(200) | Human-readable label (auto-generated or free text). |
| `chrono_certainty` | VARCHAR(10) | `certain` / `probable` / `possible` |
| `chrono_period` | VARCHAR(200) | Qualitative period name (e.g. "Hellenistic"). No numeric mapping. |

The **dating type is implicit** — no separate type column is needed:

| `chrono_from` | `chrono_to` | Type |
|---|---|---|
| null | null | Undated |
| X | X | Point date (exact year) |
| X | Y *(X < Y)* | Range / circa |
| null | Y | Ante quem |
| X | null | Post quem |

## Searching by chronology

### `_chrono_overlap` — the primary operator

Use `_chrono_overlap` on `chrono_from` to find all records whose dating
**intersects** a given range. It correctly handles the NULL semantics of
ante quem and post quem records:

```
filter[chrono_from][_chrono_overlap][]=-400&filter[chrono_from][_chrono_overlap][]=-300
```

Or as a JSON body:

```json
{ "chrono_from": { "_chrono_overlap": [-400, -300] } }
```

The operator generates a three-branch condition:

| Record type | Included if |
|---|---|
| Normal range (`from` and `to` set) | range overlaps `[low, high]` |
| Ante quem (`from` is null) | `to >= low` |
| Post quem (`to` is null) | `from <= high` |
| Undated (both null) | never |

::: warning
Use `_chrono_overlap` on `chrono_from` only. Applying it to any other field
returns an error.
:::

### Filtering by other chrono fields

The remaining chrono columns support all standard operators:

```
# Records with probable certainty
filter[chrono_certainty][_eq]=probable

# Records in the Hellenistic period
filter[chrono_period][_icontains]=hellenistic

# Records with a label containing "BCE"
filter[chrono_label][_icontains]=BCE

# Records that have any chrono data (not undated)
filter[chrono_to][_nnull]=true
```

## Integration with other plugins

| Plugin | Integration |
|---|---|
| **GeoFace** | When the fuzzy-date plugin is active on a table, GeofaceView shows a **Temporal filter** bar with a dual-handle year slider (range −3000 to 2000, step 25 years). Dragging the handles filters map markers in real time using `_chrono_overlap`, correctly including ante quem and post quem records that intersect the selected window. |
| **Harris Matrix** | An absolute chronological layout positions stratigraphic units on a vertical timeline using their fuzzy date (coming soon). |
