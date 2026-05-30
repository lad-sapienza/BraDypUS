---
title: Template example
---

# Template example

A complete template for a stratigraphic unit (`us`) table with two sections
and a plugin sub-table.

```json
[
  {
    "label": "Identification & period",
    "plugin": null,
    "collapse": false,
    "rows": [
      {
        "fields": [
          { "field": "sigla",           "width": 3 },
          { "field": "periodo",         "width": 5 },
          { "field": "interpretazione", "width": 4 }
        ]
      }
    ]
  },
  {
    "label": "Description",
    "plugin": null,
    "collapse": false,
    "rows": [
      {
        "fields": [
          { "field": "descrizione", "width": 12 }
        ]
      }
    ]
  },
  {
    "label": "Samples",
    "plugin": "samples",
    "collapse": true,
    "rows": [
      {
        "fields": [
          { "field": "sample_code", "width": 4 },
          { "field": "type",        "width": 4 },
          { "field": "notes",       "width": 4 }
        ]
      }
    ]
  }
]
```

## What this template does

- **Section 1** — shows sigla, period and interpretation in a three-column row.
- **Section 2** — shows description full-width.
- **Section 3** — shows the `samples` plugin table, collapsed by default,
  with three columns per row.
