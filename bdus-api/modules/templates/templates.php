<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class templates_ctrl extends Controller
{
  // ── v5 JSON template API ──────────────────────────────────────────────

  /**
   * Returns all non-plugin tables available for template editing.
   *
   * GET ?obj=templates_ctrl&method=getTableList
   *
   * Response: { status, tables: [{ tb, label }] }
   */
  public function getTableList(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $all     = $this->cfg->get('tables.*.label', 'is_plugin', null) ?: [];
    $appName = $this->cfg->get('main.name') ?? '';
    $prefix  = $appName !== '' ? $appName . '__' : '';
    $tables  = [];
    foreach ($all as $tb => $label) {
      // Skip built-in system tables (bdus_files, bdus_geodata, …).
      if (str_starts_with($tb, 'bdus_')) continue;
      $tables[] = [
        'tb'      => $tb,
        'label'   => $label ?: $tb,
        'stripped' => ($prefix !== '' && str_starts_with($tb, $prefix))
                        ? substr($tb, strlen($prefix))
                        : $tb,
      ];
    }

    $this->returnJson(['status' => 'success', 'tables' => $tables]);
  }

  /**
   * Returns templates available for a table, plus the field/plugin metadata
   * needed to drive the visual editor.
   *
   * GET ?obj=templates_ctrl&method=getTemplateList&tb=TABLE
   *
   * Response: { status, templates: string[], fields: [{name,label}], plugins: [{tb,label}] }
   */
  public function getTemplateList(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;
    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName   = $this->cfg->get('main.name') ?? '';
    $templates = \Template\Loader::listAvailable($appName, $tb);

    // Field list for the editor's field selector
    $fields = [];
    foreach ($this->cfg->get("tables.$tb.fields.*") ?: [] as $fld) {
      if (!empty($fld['name'])) {
        $fields[] = ['name' => $fld['name'], 'label' => $fld['label'] ?: $fld['name']];
      }
    }

    // Plugin list for the editor's plugin selector
    $plugins = [];
    foreach ($this->cfg->get("tables.$tb.plugin") ?: [] as $plgTb) {
      $plugins[] = [
        'tb'    => $plgTb,
        'label' => $this->cfg->get("tables.$plgTb.label") ?: $plgTb,
      ];
    }

    $this->returnJson([
      'status'    => 'success',
      'templates' => $templates,
      'fields'    => $fields,
      'plugins'   => $plugins,
    ]);
  }

  /**
   * Loads and returns the decoded JSON of one template.
   *
   * GET ?obj=templates_ctrl&method=getTemplate&tb=TABLE&name=NAME
   *
   * Response: { status, template: { sections: [...] } }
   */
  public function getTemplate(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb   = $this->get['tb']   ?? null;
    $name = $this->get['name'] ?? null;
    if (!$tb || !$name) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName  = $this->cfg->get('main.name') ?? '';
    $tpl = \Template\Loader::load($appName, $tb, $name);

    if ($tpl === null) {
      $this->returnJson(['status' => 'error', 'code' => 'template_not_found']);
      return;
    }

    $this->returnJson(['status' => 'success', 'template' => $tpl]);
  }

  /**
   * Validates and saves a template JSON file.
   * Creates the directory if it does not exist.
   *
   * POST ?obj=templates_ctrl&method=saveTemplate&tb=TABLE&name=NAME
   * Body: { sections: [...] }
   *
   * Response: { status, code, errors? }
   */
  public function saveTemplate(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb   = $this->get['tb']   ?? null;
    $name = $this->get['name'] ?? null;
    if (!$tb || !$name) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $payload = $this->post;
    if (!isset($payload['sections']) || !is_array($payload['sections'])) {
      $this->returnJson(['status' => 'error', 'code' => 'invalid_template_data']);
      return;
    }

    // Validate against known field/plugin names
    $fieldNames  = array_column($this->cfg->get("tables.$tb.fields.*") ?: [], 'name');
    $pluginNames = $this->cfg->get("tables.$tb.plugin") ?: [];
    $errors      = \Template\Loader::validate($payload, $fieldNames, $pluginNames);

    if (!empty($errors)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_validation_failed', 'errors' => $errors]);
      return;
    }

    $appName = $this->cfg->get('main.name') ?? '';

    if (!\Template\Loader::save($appName, $tb, $name, $payload)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_save_failed']);
      return;
    }

    $this->returnJson(['status' => 'success', 'code' => 'template_saved']);
  }

  /**
   * Deletes a template file.
   *
   * GET ?obj=templates_ctrl&method=deleteTemplate&tb=TABLE&name=NAME
   *
   * Response: { status, code }
   */
  public function deleteTemplate(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb   = $this->get['tb']   ?? null;
    $name = $this->get['name'] ?? null;
    if (!$tb || !$name) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName = $this->cfg->get('main.name') ?? '';

    if (!\Template\Loader::delete($appName, $tb, $name)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_not_found']);
      return;
    }

    $this->returnJson(['status' => 'success', 'code' => 'template_deleted']);
  }

  /**
   * Renames a template file.
   *
   * GET ?obj=templates_ctrl&method=renameTemplate&tb=TABLE&old=OLD&new=NEW
   *
   * Response: { status, code }
   */
  public function renameTemplate(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb      = $this->get['tb']  ?? null;
    $oldName = $this->get['old'] ?? null;
    $newName = $this->get['new'] ?? null;
    if (!$tb || !$oldName || !$newName) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Validate new name: alphanumeric + hyphens + underscores only
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $newName)) {
      $this->returnJson(['status' => 'error', 'code' => 'invalid_template_name']);
      return;
    }

    $appName = $this->cfg->get('main.name') ?? '';

    if (!\Template\Loader::exists($appName, $tb, $oldName)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_not_found']);
      return;
    }
    if (\Template\Loader::exists($appName, $tb, $newName)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_name_exists']);
      return;
    }
    if (!\Template\Loader::rename($appName, $tb, $oldName, $newName)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_rename_failed']);
      return;
    }

    $this->returnJson(['status' => 'success', 'code' => 'template_renamed']);
  }

}
