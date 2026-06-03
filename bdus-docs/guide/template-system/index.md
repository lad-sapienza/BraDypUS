---
title: Print templates
---

# Print templates

Print templates define custom display layouts for individual records. They are
useful for generating printable views or structured summaries of complex records.

Open **Templates** from the sidebar (super-admin required).

![TODO_SCREENSHOT: TemplatesView showing a table selector, a template list, and the JSON editor panel](/images/v5/usage/templates-view.png)

## Structure

A template is a JSON object with a `sections` array. Each section contains a
`content` list of fields with their display width:

```json
{
  "sections": [
    {
      "label": "Basic information",
      "collapsible": false,
      "content": [
        { "field": "sigla",       "width": "1/4" },
        { "field": "periodo",     "width": "3/4" },
        { "field": "descrizione", "width": "1/1" }
      ]
    }
  ]
}
```

### Section properties

| Property | Type | Description |
|---|---|---|
| `label` | string | Section heading |
| `plugin` | string | Set to a plugin table name to show rows from that sub-table |
| `collapsible` | boolean | `true` to allow the section to be collapsed |
| `collapsed` | boolean | `true` to render the section collapsed by default |
| `content` | array | Array of `{ field, width }` objects |

### Field width

Width is a fraction string. Valid values: `"1/1"`, `"1/2"`, `"1/3"`, `"2/3"`,
`"1/4"`, `"3/4"`. Fields in a section should ideally sum to one full row.

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
