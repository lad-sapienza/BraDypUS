<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * v5 migration:
 *   - getInfo()   new: JSON endpoint returning version + changelog HTML
 *   - copyright() kept for backward compatibility with the v4 Twig UI
 *   - getIP()     kept as-is (internal utility)
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

  /** @deprecated v4 Twig renderer — kept for backward compatibility */
  public function copyright(): void
  {
    $this->render('info', 'main', [
      'date'      => date('Y'),
      'changelog' => Markdown::defaultTransform(file_get_contents('CHANGELOG.md')),
      'version'   => \version::current(),
    ]);
  }

  /** Internal utility — returns server IP address */
  public function getIP(): void
  {
    $ipRegEx = '[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}';

    if (preg_match('/^win/i', PHP_OS)) {
      $cmd = 'ipconfig/all';
      exec($cmd, $msg);
      $msg = implode("\n", $msg);
      preg_match("/(.+)ipv4 address[\. ]+ : ({$ipRegEx})\(Preferred\)/i", $msg, $ip);
      if (empty($ip)) {
        preg_match("/(.+)indirizzo ip[\. ]+ : ({$ipRegEx})/i", $msg, $ip);
      }
      $my_ip = $ip[2] ?? null;
    } elseif (preg_match('/linux/i', PHP_OS)) {
      $cmd = '/sbin/ifconfig';
      exec($cmd, $msg);
      $msg = implode("\n", $msg);
      preg_match("/inet addr:({$ipRegEx})/i", $msg, $ip);
      $my_ip = $ip[1] ?? null;
    } elseif (strtolower(PHP_OS) === 'darwin') {
      $cmd = 'ifconfig | grep "inet "';
      exec($cmd, $msg);
      $tmp_msg = array_filter($msg, fn($l) => !preg_match('/127\.0\.0\.1/', $l));
      $msg = implode("\n", $msg);
      preg_match("/inet ({$ipRegEx})/i", implode($tmp_msg), $ip);
      $my_ip = $ip[1] ?? null;
    } else {
      $this->response('cannot_get_ip_for_system', 'error', [PHP_OS]);
      return;
    }

    if ($my_ip) {
      $this->response($my_ip, 'success', null, ['more' => $msg, 'cmd' => $cmd]);
    }
  }
}
