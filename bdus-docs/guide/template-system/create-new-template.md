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

## 4. Save

Click **Save**. The template becomes immediately available in RecordView's
**Templates** dropdown for that table.

## 5. Applying the template

In RecordView, click the **Templates** button in the header to switch
the display layout to this template.
