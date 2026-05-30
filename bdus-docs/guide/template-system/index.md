---
title: Print templates
---

# Print templates

Print templates define custom display layouts for individual records. They are
useful for generating printable views or structured summaries of complex records.

Open **Templates** from the sidebar (super-admin required).

![TODO_SCREENSHOT: TemplatesView showing a table selector, a template list, and the JSON editor panel](/images/v5/usage/templates-view.png)

## Structure

A template is a JSON document that describes sections, each containing a list
of fields with their display width:

```json
[
  {
    "label": "Basic information",
    "plugin": null,
    "collapse": false,
    "rows": [
      { "fields": [
          { "field": "sigla",   "width": 3 },
          { "field": "periodo", "width": 9 }
        ]
      },
      { "fields": [
          { "field": "descrizione", "width": 12 }
        ]
      }
    ]
  }
]
```

### Section properties

| Property | Description |
|---|---|
| `label` | Section heading |
| `plugin` | Set to a plugin table name to show rows from that sub-table |
| `collapse` | `true` to render the section collapsed by default |
| `rows` | Array of row objects; each row is an array of `{ field, width }` objects |

### Field width

Width is a 1–12 integer following a 12-column grid. Fields in a row should sum
to 12 (or less).

## Creating a template

1. Select the target table from the **Table** dropdown.
2. Click **New template** and give it a name.
3. Edit the JSON in the editor panel.
4. Click **Save**.

## Applying a template to a record

In RecordView, the **Templates** dropdown in the toolbar lets the user switch
between available templates and the default view.

## Renaming and deleting templates

Use the **Rename** and **Delete** buttons in the template list.
