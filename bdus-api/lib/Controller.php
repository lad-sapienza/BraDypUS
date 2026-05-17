<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 12, 2013
 */

use DB\DBInterface;
use UAC\UAC;
use Config\Config;
use Monolog\Logger;

abstract class Controller
{
  protected $get;
  protected $post;
  protected $request;
  protected $db;
  protected $log;
  protected $prefix;
  protected $cfg;
  protected $uac;
  protected $debug;

  public function __construct($get, $post, $request)
  {
    $this->get = $get;

    // If the request body is JSON (sent by Vue api.post with complex data),
    // merge decoded JSON into $post so $this->post works transparently.
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
      $json = json_decode(file_get_contents('php://input'), true);
      $post = is_array($json) ? array_merge($post, $json) : $post;
    }

    $this->post    = $post;
    $this->request = array_merge($get, $post);
  }

  public function setUAC(UAC $uac): void
  {
    $this->uac = $uac;
  }

  /**
   * Injects Database object dependency
   *
   * @param DBInterface $db
   * @return void
   */
  public function setDB(DBInterface $db): void
  {
    $this->db = $db;
  }

  /**
   * Injects Config Object dependency
   *
   * @param Config $cfg
   * @return void
   */
  public function setCfg(Config $cfg): void
  {
    $this->cfg = $cfg;
  }

  /**
   * Sets application prefix
   *
   * @param string $prefix
   * @return void
   */
  public function setPrefix(string $prefix = null): void
  {
    $this->prefix = $prefix;
  }

  /**
   * Injects Logger Object dependency
   *
   * @param Logger $log
   * @return void
   */
  public function setLog(Logger $log): void
  {
    $this->log = $log;
  }

  /**
   * Turn of/off debugging
   *
   * @param boolean $debug
   * @return void
   */
  public function setDebug(bool $debug = false): void
  {
    $this->debug = $debug;
  }

  /**
   * Echoes json-encoded data from array, with proper header
   *
   * @param array $data
   * @return void
   */
  public function returnJson(array $data): void
  {
    header("Content-type:application/json");
    echo json_encode($data);
  }

  /**
   * Echoes a simple status+text JSON response.
   * Legacy helper used by many modules; `text` carries an i18n code string.
   *
   * @param string $text    i18n code (e.g. 'ok_def_update')
   * @param string $status  'success' | 'error'
   * @param array|null $text_bindings  ignored (kept for BC)
   * @param array $other_args  extra keys merged into the response
   */
  public function response(
    string $text,
    string $status = 'success',
    ?array $text_bindings = null,
    array $other_args = []
  ): void {
    $res = ['status' => $status, 'text' => $text];
    if (!empty($other_args)) {
      $res = array_merge($res, $other_args);
    }
    echo json_encode($res);
  }

}
