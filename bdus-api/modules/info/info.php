<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use Michelf\Markdown;

class info_ctrl extends Controller
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
    if (!\utils::canUser('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $this->returnJson([
      'status'     => 'success',
      'name'       => $this->cfg->get('main.name')       ?? '',
      'definition' => $this->cfg->get('main.definition') ?? '',
    ]);
  }

  /**
   * Returns app version and full changelog rendered as HTML.
   *
   * GET ?obj=info_ctrl&method=getInfo
   *
   * Response: { version: string, changelog_html: string }
   */
  public function getInfo(): void
  {
    if (!\utils::canUser('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $this->returnJson([
      'version'        => \version::current(),
      'changelog_html' => Markdown::defaultTransform(
        file_get_contents(MAIN_DIR . 'CHANGELOG.md')
      ),
    ]);
  }

}
