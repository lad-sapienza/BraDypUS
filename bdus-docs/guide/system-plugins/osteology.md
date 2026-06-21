---
title: Osteology (bone inventory)
---

# Osteology — skeletal bone inventory

The osteology plugin adds a structured bone-inventory section to any table. It is
designed for burial records in archaeological contexts: it stores the preservation
state of up to 51 anatomical elements per individual in a single JSON column and
renders them as an interactive SVG skeleton diagram directly in RecordView.

## When to use it

Use this plugin when your records describe skeletal remains and you need to document:

- **Which bones are present** — and which are absent or not documented
- **Conservation grade** — complete, >50%, <50%, fragmentary, traces only
- **Anatomical certainty** — certain, probable, uncertain identification
- **Laterality certainty** — for paired elements (left/right), whether the side is certain or uncertain
- **Multiple individuals** — more than one skeleton recovered from the same burial unit

## Enabling the plugin for a table

Go to **Config → Tables**, select the table, scroll to the **System plugins** section and
toggle **Inventario osteologico** on. The system immediately adds one `osteo_data TEXT`
column to the table. No other configuration is required.

To disable, toggle the switch off. The column — and any data already entered — is
**preserved**; the panel simply disappears from RecordView. Toggle it back on at any
time to restore the panel without data loss.

## The osteology panel

Once enabled, an **Inventario osteologico** section appears in RecordView below the
regular fields.

### Read mode

Displays each individual's name (or a default label) with an SVG skeleton diagram.
Bones are colour-coded by conservation grade:

| Colour | Grade |
|---|---|
| Dark fill | Complete (`complete`) |
| Medium fill | > 50% (`gt50`) |
| Light fill | < 50% (`lt50`) |
| Hatched | Fragmentary (`fragmentary`) |
| Outline only | Traces (`traces`) |
| No fill | Absent or not documented |

Hovering over a bone shows a tooltip with the anatomical name and all recorded attributes.

### Edit mode

Clicking on any bone in the SVG opens a **BonePanel** sidebar with four fields:

| Field | Options |
|---|---|
| **Presente** | Yes / No / Not documented |
| **Conservazione** | Complete / >50% / <50% / Fragmentary / Traces |
| **Certezza anatomica** | Certain / Probable / Uncertain |
| **Certezza lateralità** | Certain / Uncertain *(only for paired elements)* |

Changes are reflected immediately on the SVG. Saving the record persists all bone data.

The SVG is **zoomable** (mouse wheel or +/− buttons) and **pannable** (click and drag
on the background). A reset button (↺) restores the original view.

### Multiple individuals

Use the tab bar above the SVG to switch between individuals. The **Add individual**
button adds a new blank skeleton. Each individual has its own label and optional notes
field.

## Anatomical elements

51 bones are organised into 8 body regions:

| Region | Count | Elements |
|---|---|---|
| Head (`head`) | 3 | Cranio, Faccia, Mandibola |
| Spine (`spine`) | 5 | Vertebre cervicali, toraciche, lombari; Sacro, Coccige |
| Thorax (`thorax`) | 3 | Sterno; Coste dx/sx |
| Shoulder (`shoulder`) | 4 | Clavicola dx/sx; Scapola dx/sx |
| Upper limb (`upper_limb`) | 12 | Omero, Radio, Ulna, Carpali, Metacarpali, Falangi mano (dx/sx each) |
| Pelvis (`pelvis`) | 6 | Ileo, Ischio, Pube (dx/sx each) |
| Lower limb (`lower_limb`) | 8 | Femore, Patella, Tibia, Fibula (dx/sx each) |
| Foot (`foot`) | 10 | Astragalo, Calcagno, Tarsali, Metatarsali, Falangi piede (dx/sx each) |

## Data model

A single column is added to the table when the plugin is activated:

| Column | Type | Content |
|---|---|---|
| `osteo_data` | TEXT | JSON array of individuals (see structure below) |

JSON structure:

```json
{
  "individuals": [
    {
      "id": 1,
      "label": "Individuo 1",
      "notes": "Scheletro in connessione anatomica parziale.",
      "bones": {
        "cranium": {
          "present": true,
          "conservation": "complete",
          "certainty": "certain"
        },
        "humerus_right": {
          "present": true,
          "conservation": "fragmentary",
          "certainty": "probable",
          "laterality_certainty": "uncertain"
        },
        "fibula_left": {
          "present": false
        }
      }
    }
  ]
}
```

The `bones` object uses the standard anatomical English ID as the key (e.g. `cranium`,
`humerus_right`, `femur_left`). Missing keys mean the element was not documented — distinct
from `present: false` (explicitly absent) and `present: true` (present).

::: tip
Because all data lives in a single JSON column, cross-record queries on individual
bones (e.g. "all burials where the femur is present") are not supported via the
standard search interface. For statistical analysis across the assemblage, export the
data and process it externally.
:::
