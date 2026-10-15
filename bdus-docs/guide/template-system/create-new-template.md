---
title: Creating a template
---

# Creating a template <Badge type="tip" text="v5.0.0" />

Templates are managed in the **Templates** view, accessible from the sidebar
(super-admin required).

## 1. Select a table

Use the **Table** dropdown at the top to select which table the template
applies to.

## 2. Create a new template

Click **New template** and enter a name (e.g. `full-detail` or `compact`).
Names are lowercase, no spaces.

## 3. Edit the JSON

The template is a JSON object with a `sections` array. Each section has a label,
optional plugin binding, and a `content` list of fields with widths.

```json
{
  "sections": [
    {
      "label": "Identification",
      "collapsible": false,
      "content": [
        { "field": "sigla",   "width": "1/3" },
        { "field": "periodo", "width": "2/3" }
      ]
    },
    {
      "label": "Description",
      "collapsible": true,
      "collapsed": true,
      "content": [
        { "field": "descrizione",     "width": "1/1" },
        { "field": "interpretazione", "width": "1/1" }
      ]
    }
  ]
}
```

### Field widths

Valid width values: `"1/1"`, `"1/2"`, `"1/3"`, `"2/3"`, `"1/4"`, `"3/4"`.
Fields in a section should ideally fill one row. A single full-width field uses
`"1/1"`; two equal columns use `"1/2"`; three equal columns use `"1/3"`.

### Plugin sections

Set `"plugin": "table_name"` to render rows from a plugin sub-table instead
of main-table fields.

### Plugin fields in core sections <Badge type="tip" text="v5.15.1" />

Plugin tables can be placed among core fields with
`{ "plugin": "table_name", "field": "*", "width": "1/1" }` (`"*"` = all
plugin fields, or a single plugin field name). They are rendered as a
multi-row table. See [Print templates](./index.md#plugin-fields-in-core-sections).

### Accordion sections <Badge type="tip" text="v5.1.0" />

Set `"type": "accordion"` to group fields into collapsible sub-panels within a
section. Useful for tables with many fields:

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

Each panel has a `label`, an `open` flag (default `true`), and its own `fields`
list with the same `{ field, width }` format as a regular section. In the
template editor, choose **Accordion** from the _Section type_ dropdown and use
**Add panel** to create sub-panels.

### Editing the JSON directly

Use the **Visual / JSON code** switch in the editor header to paste or edit the
whole template as JSON. Switching back to Visual requires valid JSON.

## 4. Save

Click **Save**. The server validates field names, plugin names and widths; any
errors are listed above the editor. The template becomes immediately available in RecordView's
**Templates** dropdown for that table.

## 5. Applying the template

In RecordView, click the **Templates** button in the header to switch
the display layout to this template.
