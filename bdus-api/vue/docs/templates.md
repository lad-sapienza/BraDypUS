# Design Templates module — v5 documentation

## Overview

The Design Templates module lets super-admins create and edit **JSON layout templates** for record view/edit pages. A template controls which fields appear, in what order, at what width, and how they are grouped into collapsible sections.

Templates are stored as JSON files at:
```
projects/{app}/template/{tbStripped}.{templateName}.json
```

`tbStripped` is the table name with the app prefix removed (e.g. `test__items` → `items`).

### Template JSON format

```json
{
  "sections": [
    {
      "label": "Main info",
      "collapsible": false,
      "content": [
        { "field": "name",        "width": "1/2" },
        { "field": "status",      "width": "1/4" },
        { "field": "description", "width": "1/1" }
      ]
    },
    {
      "label": "Tags",
      "plugin": "myapp__tags",
      "collapsible": true,
      "collapsed": false,
      "content": [
        { "field": "label", "width": "3/4" }
      ]
    }
  ]
}
```

**Valid widths:** `1/1`, `1/2`, `1/3`, `2/3`, `1/4`, `3/4`

A section with a `plugin` key is a plugin section (shows the plugin rows/table). A section without `plugin` is a core section (shows main-table fields).

---

## UI spec (`TemplatesView.vue`)

Split layout: left sidebar + right editor panel.

### Left sidebar
- Lists all non-plugin tables.
- Clicking a table loads its template list below.
- Template list shows existing template names; clicking one opens the editor.
- **New template** `+` button opens a dialog to name and create an empty template.

### Editor panel (right)
- **Header**: table label → template name; Rename / Save / Delete buttons.
- **Sections list**: one card per section, in order.
  - Section header: label input, plugin selector (empty = core section), collapsible checkbox, "collapsed by default" checkbox.
  - Up/down arrows and trash button to reorder/remove sections.
  - Content rows: field selector + width selector + up/down/remove per row.
  - "Add field" button appends a new content row.
- **Add section** button appends a new empty section at the bottom.

Validation errors from the backend are logged to the console (the toast shows the human-readable code).

---

## API endpoints (`templates_ctrl`)

All endpoints require `super_admin` privilege.

| Method | HTTP | Params | Response |
|---|---|---|---|
| `getTableList` | GET | — | `{ status, tables: [{tb, label, stripped}] }` |
| `getTemplateList` | GET | `tb` | `{ status, templates: string[], fields: [{name,label}], plugins: [{tb,label}] }` |
| `getTemplate` | GET | `tb, name` | `{ status, template: {sections:[...]} }` |
| `saveTemplate` | POST | `tb, name` (GET) + `{sections:[...]}` (body) | `{ status, code, errors? }` |
| `deleteTemplate` | GET | `tb, name` | `{ status, code }` |
| `renameTemplate` | GET | `tb, old, new` | `{ status, code }` |

`saveTemplate` runs `Template\Loader::validate()` before writing. If validation fails it returns `status: error` with an `errors` array — nothing is written.

Template names are restricted to `[a-zA-Z0-9_-]+`.

---

## Legacy → v5 mapping

| v4 method (`templates_ctrl`) | v5 replacement |
|---|---|
| `ui()` | `TemplatesView.vue` + `getTemplateList()` |
| `openEditForm()` | `TemplatesView.vue` + `getTemplate()` |
| `saveContent()` | `saveTemplate()` |
| `deleteTmpl()` | `deleteTemplate()` |
| `renameTmpl()` | `renameTemplate()` |

The v4 module edited raw Twig (`.twig`) files in `projects/{app}/templates/` (plural). The v5 module edits structured JSON in `projects/{app}/template/` (singular). The two directories and formats are completely separate.

---

## Future improvements

- **Visual drag-and-drop** reordering of sections and fields (PrimeVue `OrderList` or `DataTable` row reorder).
- **Template preview**: render a sample record using the template being edited, side-by-side in the editor.
- **Duplicate template**: clone an existing template under a new name.
- **Import/export**: download a template JSON, upload to another app.
- **Per-user template preference** (`myTmpl_ctrl` equivalent): let non-admin users choose their preferred template per table, stored in session preferences.
