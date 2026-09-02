---
title: Template example
---

# Template example <Badge type="tip" text="v5.0.0" />

A complete template for a stratigraphic unit (`us`) table with two sections
and a plugin sub-table.

```json
{
  "sections": [
    {
      "label": "Identification & period",
      "collapsible": false,
      "content": [
        { "field": "sigla",           "width": "1/4" },
        { "field": "periodo",         "width": "1/2" },
        { "field": "interpretazione", "width": "1/4" }
      ]
    },
    {
      "label": "Description",
      "collapsible": false,
      "content": [
        { "field": "descrizione", "width": "1/1" }
      ]
    },
    {
      "label": "Samples",
      "plugin": "samples",
      "collapsible": true,
      "collapsed": true,
      "content": [
        { "field": "sample_code", "width": "1/3" },
        { "field": "type",        "width": "1/3" },
        { "field": "notes",       "width": "1/3" }
      ]
    }
  ]
}
```

## What this template does

- **Section 1** — shows sigla, period and interpretation in a three-column row.
- **Section 2** — shows description full-width.
- **Section 3** — shows the `samples` plugin table, collapsed by default,
  with three columns per row.
