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
   * Response: { status, tables: [{ tb, label, stripped }] }
   */
  public function getTableList(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $all = $this->cfg->get('tables.*.label', 'is_plugin', null) ?: [];
    $tables = [];
    foreach ($all as $tb => $label) {
      $tables[] = [
        'tb'       => $tb,
        'label'    => $label ?: $tb,
        'stripped' => str_replace($this->prefix, '', $tb),
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
    $stripped  = str_replace($this->prefix, '', $tb);
    $templates = \Template\Loader::listAvailable($appName, $stripped);

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
    $stripped = str_replace($this->prefix, '', $tb);
    $tpl      = \Template\Loader::load($appName, $stripped, $name);

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

    $appName  = $this->cfg->get('main.name') ?? '';
    $stripped = str_replace($this->prefix, '', $tb);
    $dir      = \Template\Loader::getDir($appName);
    $path     = \Template\Loader::getPath($appName, $stripped, $name);

    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($path, $json) === false) {
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

    $appName  = $this->cfg->get('main.name') ?? '';
    $stripped = str_replace($this->prefix, '', $tb);
    $path     = \Template\Loader::getPath($appName, $stripped, $name);

    if (!file_exists($path)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_not_found']);
      return;
    }

    if (!unlink($path)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_delete_failed']);
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

    $appName  = $this->cfg->get('main.name') ?? '';
    $stripped = str_replace($this->prefix, '', $tb);
    $oldPath  = \Template\Loader::getPath($appName, $stripped, $oldName);
    $newPath  = \Template\Loader::getPath($appName, $stripped, $newName);

    if (!file_exists($oldPath)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_not_found']);
      return;
    }
    if (file_exists($newPath)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_name_exists']);
      return;
    }
    if (!rename($oldPath, $newPath)) {
      $this->returnJson(['status' => 'error', 'code' => 'template_rename_failed']);
      return;
    }

    $this->returnJson(['status' => 'success', 'code' => 'template_renamed']);
  }

  // ── v4 legacy (Twig template file editor) ────────────────────────────

  /** @deprecated v5 — replaced by TemplatesView.vue + JSON template API above */
  public function ui(): void
  {
    $available_tmpls = \utils::dirContent(PROJ_DIR . 'templates');
    $this->render('templates', 'ui', [
      'available_tmpls' => $available_tmpls
    ]);
  }

  /** @deprecated v5 — replaced by TemplatesView.vue + getTemplate() */
  public function openEditForm()
  {
    $tmpl = $this->get['tmpl'];

    try {
      $file = PROJ_DIR . 'templates/' . $tmpl;

      if (!file_exists($file)) {
        throw new \Exception(\tr::get("file_not_found", [$file]));
      }

      $content = file_get_contents($file);

      if (!$content) {
        throw new \Exception(\tr::get('error_getting_content_of_file', [$file]));
      }

      $content = htmlentities($content);

    } catch (\Throwable $th) {
      $error = $th->getMessage();
    }

    echo $this->render('templates', 'editForm', [
      "tmpl"    => $tmpl,
      "content" => $content,
      "error"   => $error
    ]);
  }

  /** @deprecated v5 — replaced by TemplatesView.vue + saveTemplate() */
  public function saveContent()
  {
    $tmpl    = $this->get['tmpl'];
    $is_new  = $this->get['is_new'];
    $content = $this->post['content'];
    $file    = PROJ_DIR . 'templates/' . $tmpl;

    if ($is_new && file_exists($file)) {
      $this->returnJson(["status" => "error", "text" => \tr::get('tmpl_file_exists', [$file])]);
      return;
    }

    $res = file_put_contents($file, $content);
    $this->returnJson($res
      ? ["status" => "success", "text" => \tr::get("tmpl_file_updated", [$file])]
      : ["status" => "error",   "text" => \tr::get('tmpl_file_not_updated', [$file])]
    );
  }

  /** @deprecated v5 — replaced by TemplatesView.vue + deleteTemplate() */
  public function deleteTmpl()
  {
    $tmpl = $this->get['tmpl'];
    try {
      $file = PROJ_DIR . 'templates/' . $tmpl;
      if (!file_exists($file)) throw new \Exception(\tr::get('file_not_found', [$file]));
      unlink($file);
      if (file_exists($file)) throw new \Exception(\tr::get('tmpl_file_not_deleted', [$file]));
      $this->returnJson(["status" => "success", "text" => \tr::get('tmpl_file_deleted', [$file])]);
    } catch (\Throwable $th) {
      $this->returnJson(["status" => "error", "text" => $th->getMessage()]);
    }
  }

  /** @deprecated v5 — replaced by TemplatesView.vue + renameTemplate() */
  public function renameTmpl()
  {
    $old = PROJ_DIR . 'templates/' . $this->get['old'];
    $new = PROJ_DIR . 'templates/' . $this->get['new'];
    try {
      if (!file_exists($old)) throw new \Exception(\tr::get('file_not_found', [$old]));
      if (file_exists($new))  throw new \Exception(\tr::get('tmpl_file_exists', [$new]));
      if (!rename($old, $new)) throw new \Exception(\tr::get('tmpl_file_not_renamed', [$old, $new]));
      $this->returnJson(["status" => "success", "text" => \tr::get('tmpl_file_renamed', [$old, $new])]);
    } catch (\Throwable $th) {
      $this->returnJson(["status" => "error", "text" => $th->getMessage()]);
    }
  }
}
