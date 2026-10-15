---
title: Template example
---

# Template example <Badge type="tip" text="v5.0.0" />

A template for a stratigraphic unit (`us`) table with core fields, a plugin
table in the middle of the page, and an accordion.

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
      "collapsible": true,
      "content": [
        { "field": "descrizione", "width": "1/1" },
        { "plugin": "samples", "field": "*", "width": "1/1" }
      ]
    },
    {
      "label": "Samples (dedicated section)",
      "plugin": "samples",
      "collapsible": true,
      "collapsed": true,
      "content": [
        { "field": "sample_code", "width": "1/3" },
        { "field": "type",        "width": "1/3" },
        { "field": "notes",       "width": "1/3" }
      ]
    },
    {
      "label": "Technical sheet",
      "type": "accordion",
      "content": [
        {
          "label": "Location",
          "open": true,
          "fields": [
            { "field": "sito",     "width": "1/2" },
            { "field": "ambiente", "width": "1/2" }
          ]
        }
      ]
    }
  ]
}
```

## What this template does

- **Section 1**: sigla, period and interpretation in a three-column row.
- **Section 2**: description full width, followed by all `samples` columns as a
  multi-row table placed right after it.
- **Section 3**: the `samples` plugin as its own section, collapsed by default,
  showing only the three listed columns.
- **Section 4**: an accordion; the panel *Location* is open by default.
