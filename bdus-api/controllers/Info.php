<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class Info extends \Bdus\Controller
{
  /**
   * Returns basic app metadata (name + description) for the dashboard.
   * Requires only read privilege.
   *
   * GET ?obj=info_ctrl&method=getAppInfo
   *
   * Response: { status, name: string, definition: string }
   */
  public function getAppInfo(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $settings = \Config\AppSettings::get($this->db);
    $this->returnJson([
      'status'     => 'success',
      'name'       => $this->cfg->get('main.name')       ?? '',
      'definition' => $this->cfg->get('main.definition') ?? '',
      'color'      => $settings['color'] ?? 'indigo',
    ]);
  }

  /**
   * Returns app version and full changelog as raw Markdown.
   * Rendering is done client-side by the Vue frontend (marked.js).
   *
   * GET /api/info
   *
   * Response: { version: string, changelog_md: string }
   */
  public function getInfo(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $this->returnJson([
      "status"       => "success",
      'version'      => json_decode(file_get_contents(MAIN_DIR . 'composer.json'), true)['version'] ?? 'unknown',
      'changelog_md' => file_get_contents(MAIN_DIR . 'CHANGELOG.md') ?: '',
    ]);
  }

}
