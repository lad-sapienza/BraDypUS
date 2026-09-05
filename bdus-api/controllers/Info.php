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
   * Response: { status, name: string, definition: string, color: string, lang: string }
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
      // App's configured default UI language — AppLayout.vue applies it only
      // for a browser that hasn't picked one yet (no bdus_locale in localStorage).
      'lang'       => $settings['lang'] ?? 'en',
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

    $settings = \Config\AppSettings::get($this->db);
    $this->returnJson([
      "status"          => "success",
      'version'         => json_decode(file_get_contents(MAIN_DIR . 'composer.json'), true)['version'] ?? 'unknown',
      'project_version' => $settings['bdus_version'] ?? null,
      'changelog_md'    => $this->readChangelog(),
    ]);
  }

  /**
   * Full changelog as raw Markdown.
   *
   * Since the monorepo consolidation the canonical file is CHANGELOG.md at the
   * repo root (one dir above MAIN_DIR). A copy is bundled inside the bdus-api
   * Docker image at MAIN_DIR, where the repo root is not present — kept in sync
   * by bump-version.sh.
   */
  private function readChangelog(): string
  {
    foreach ([MAIN_DIR . '../CHANGELOG.md', MAIN_DIR . 'CHANGELOG.md'] as $path) {
      if (is_file($path)) {
        return file_get_contents($path) ?: '';
      }
    }
    return '';
  }

}
