---
title: Creating a template
---

# Creating a template

Templates are managed in the **Templates** view, accessible from the sidebar
(super-admin required).

## 1. Select a table

Use the **Table** dropdown at the top to select which table the template
applies to.

## 2. Create a new template

Click **New template** and enter a name (e.g. `full-detail` or `compact`).
Names are lowercase, no spaces.

## 3. Edit the JSON

The template is a JSON array of **section** objects. Each section has a label,
optional plugin binding, and an array of rows. Each row contains fields with widths.

```json
[
  {
    "label": "Identification",
    "plugin": null,
    "collapse": false,
    "rows": [
      {
        "fields": [
          { "field": "sigla",   "width": 4 },
          { "field": "periodo", "width": 8 }
        ]
      }
    ]
  },
  {
    "label": "Description",
    "plugin": null,
    "collapse": true,
    "rows": [
      {
        "fields": [
          { "field": "descrizione",     "width": 12 }
        ]
      },
      {
        "fields": [
          { "field": "interpretazione", "width": 12 }
        ]
      }
    ]
  }
]
```

### Field widths

Widths follow a 12-column grid. Fields in one row should sum to 12 (or less).
A single full-width field uses `"width": 12`; three equal columns use `"width": 4`.

### Plugin sections

Set `"plugin": "table_name"` to render rows from a plugin sub-table instead
of main-table fields.

## 4. Save

Click **Save**. The template becomes immediately available in RecordView's
**Templates** dropdown for that table.

## 5. Applying the template

In RecordView, click the **Templates** button in the header to switch
the display layout to this template.
