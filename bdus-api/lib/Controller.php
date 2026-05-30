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
    // Guarantee every response carries a `status` field.
    // Callers that already set it (e.g. error responses) are left unchanged;
    // callers that omit it get 'success' injected at the top of the object.
    if (!array_key_exists('status', $data)) {
      $data = ['status' => 'success'] + $data;
    }
    header("Content-type:application/json");
    echo json_encode($data);
  }

}
