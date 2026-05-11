# Config module — v5 documentation

## UI spec

Accessible via the **Config** nav item (super-admin only, password-gated).

The layout is a two-column shell:

- **Left sidebar** (`ConfigSidebar.vue`) — lists all tables with a drag-and-drop sort handle; buttons for App settings, Validation, Geoface settings.
- **Right panel** — lazy-loaded component matching the current selection.

### Panels

| Panel | Component | What the user can do |
|---|---|---|
| App settings | `ConfigAppForm.vue` | Edit app name, label, lang, status, db engine, description |
| Validation | `ConfigValidation.vue` | Run schema validation; toggle "errors only"; apply one-click fixes |
| Geoface | `ConfigGeoface.vue` | Add/edit/delete WMS/WFS/local layers; upload local GeoJSON/KML files |
| Table settings | `ConfigTableForm.vue` | Edit table name/label, layout fields, preview fields, plugins, backlinks, cross-table links; rename or delete table; add new table |
| Field list | `ConfigFieldList.vue` | Split panel: field list on left, `ConfigFieldForm` on right; add/rename/delete fields |
| Field form | `ConfigFieldForm.vue` | Auto-generated from `fld_structure.json`; supports `input`, `select`, `multi_select` meta-types |

---

## API endpoints (`config_ctrl`)

All endpoints require `super_admin` privilege unless noted.

### Read endpoints

| Method | Params | Response |
|---|---|---|
| `getTableList` | — | `{ status, tables: [{ name, label, is_plugin, order }] }` |
| `getAppProperties` | — | `{ status, main: {...}, langs: [...], status_options: [...], db_engines: [...] }` |
| `getTableConfig` | `tb?` | `{ status, table, field_labels, available_plugins, available_tables }` |
| `getFldStructure` | — | `{ status, structure: { [key]: { type, required, readonly, values?, pattern?, help? } } }` |
| `getFldConfig` | `tb?, fld?` | `{ status, field: {...}, structure: {...} }` |
| `getGeoFaceConfig` | — | `{ status, layers: [...], local_files: [...] }` |
| `getValidationReport` | — | `{ status, report: [{ status, text, fix?, suggest? }] }` |
| `getFldList` | `tb` | `{ status, fields: { name: label } }` |

### Write endpoints

| Method | Params | Response |
|---|---|---|
| `save_app_properties` | POST body: app config object | `{ status, code }` |
| `getTableConfig` (POST via `save_tb_data`) | `tb?` (GET) + POST body | `{ status, code }` |
| `add_new_tb` | POST body: `{ name, label, ... }` | `{ status, code }` |
| `delete_tb` | `tb` | `{ status, code }` |
| `rename_tb` | `old_name, new_name` | `{ status, code }` |
| `save_fld_properties` | POST body: field data + `tb_name, fld_orig_name` | `{ status, code }` |
| `add_new_fld` | POST body: field data + `tb_name` | `{ status, code }` |
| `delete_column` | `tb, fld` | `{ status, code }` |
| `rename_column` | `tb, fld, new_name` | `{ status, code }` |
| `save_geoface_properties` | POST body: layers array | `{ status, code }` |
| `delete_local_geofile` | `file` | `{ status, code }` |
| `uploadGeoFile` | multipart `file` | `{ status, code, filename? }` |
| `sortTables` | `tables` (comma-separated) | `{ status, code }` |
| `fix` | `action, tb, col?` | `{ status, code }` |

### `fix` actions

| `action` | `col` present | Effect |
|---|---|---|
| `create` | no | Creates missing DB table |
| `create` | yes | Adds missing DB column |
| `delete` | no | Drops DB table |
| `delete` | yes | Drops DB column |

---

## Legacy → v5 mapping

| v4 method | v5 replacement |
|---|---|
| `home()` | `ConfigSidebar.vue` + `getTableList()` |
| `app_properties()` | `ConfigAppForm.vue` + `getAppProperties()` / `save_app_properties()` |
| `fld_list()` | `ConfigFieldList.vue` + `getTableConfig()` |
| `field_properties()` | `ConfigFieldForm.vue` + `getFldConfig()` / `getFldStructure()` |
| `table_properties()` | `ConfigTableForm.vue` + `getTableConfig()` / `save_tb_data()` |
| `validate_app()` | `ConfigValidation.vue` + `getValidationReport()` |

---

## Future improvements

- **Field reordering**: drag-and-drop field order within a table (currently alphabetical in sidebar, positional in JSON config).
- **Bulk field operations**: copy fields from one table to another.
- **Config export/import**: download/upload the full YAML config as a ZIP.
- **Audit trail**: show who changed what and when (requires a config change log).
- **Template Twig settings** (`tmpl_edit`, `tmpl_read`): removed from v5 UI — v5 uses a fixed automatic layout. The YAML keys are still preserved on disk but ignored during save.
