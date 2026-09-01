---
title: Print templates
---

# Print templates

Print templates define custom display layouts for individual records. They are
useful for generating printable views or structured summaries of complex records.

Open **Templates** from the sidebar (super-admin required).

![TemplatesView showing a table selector, a template list, and the JSON editor panel](/images/v5/usage/templates-view.png)

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
| `type` | string | `"accordion"` to render sub-panels instead of fields (see below) |
| `plugin` | string | Set to a plugin table name to show rows from that sub-table |
| `collapsible` | boolean | `true` to allow the section to be collapsed (not used with accordion) |
| `collapsed` | boolean | `true` to render the section collapsed by default |
| `content` | array | For core/plugin sections: array of `{ field, width }` objects. For accordion: array of panel objects |

### Field width

Width is a fraction string. Valid values: `"1/1"`, `"1/2"`, `"1/3"`, `"2/3"`,
`"1/4"`, `"3/4"`. Fields in a section should ideally sum to one full row.

### Accordion sections

A section with `"type": "accordion"` renders its content as a series of
collapsible sub-panels. Each panel has its own label and field list:

```json
{
  "label": "Scheda tecnica",
  "type": "accordion",
  "content": [
    {
      "label": "Localizzazione",
      "open": true,
      "fields": [
        { "field": "sito",     "width": "1/2" },
        { "field": "ambiente", "width": "1/2" }
      ]
    },
    {
      "label": "Definizione",
      "open": false,
      "fields": [
        { "field": "categoria", "width": "1/3" },
        { "field": "tipo",      "width": "2/3" }
      ]
    }
  ]
}
```

| Panel property | Type | Description |
|---|---|---|
| `label` | string | Panel heading |
| `open` | boolean | `true` (default) to render the panel open; `false` to render it collapsed |
| `fields` | array | Array of `{ field, width }` objects, same format as section `content` |

Accordion sections are ideal for tables with many fields: group logically related
fields into panels so the form stays manageable without hiding data.

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
