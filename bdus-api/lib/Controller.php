<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 12, 2013
 * 
 * @uses Exception
 * @uses cfg
 * @uses tr
 * @uses \Twig\Environment
 * @uses \Twig\Loader\FilesystemLoader
 * @uses \Twig\Extension\DebugExtension
 */

use DB\DBInterface;
use UAC\UAC;
use Config\Config;
use Monolog\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;

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
    $this->post = $post;
    $this->request = $request;
  }

  /**
   * Returns true if this is a live application
   * and false if it is running on localhost / 127.0* or 192.168*
   *
   * @return boolean
   */
  public function is_online(): bool
  {
    $host = $_SERVER['HTTP_HOST'];
    return !(strpos($host, 'localhost') !== false ||
      substr($host, 0, 5) === "127.0" ||
      substr($host, 0, 7) === "192.168");
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
   * Echoes json-encoded data from text, with proper header
   *
   * @param string $text
   * @param string $status
   * @param array $text_bindings
   * @param array $other_args
   * @return void
   */
  public function response(
    string $text,
    string $status = 'success',
    array $text_bindings = null,
    array $other_args = []
  ): void {
    $response['status'] = $status;
    $response['text'] = \tr::get($text, $text_bindings);
    $other_args ? $response = array_merge($response, $other_args) : '';
    echo json_encode($response);
  }

  /**
   * Compiles template from path and filename or module and template name
   * and returns html
   *
   * @param string $module module name or path to template directory
   * @param string $template template name or template file name with extension
   * @param array $data optional array of template data
   * @return string
   */
  public function compileTmpl(
    string $module,
    string $template,
    array $data = []
  ): string {
    // Template can be a full path to a template file or a template name in the module folder
    if (file_exists($module . '/' . $template)) {
      $tmpl_file = $template;
      $tmpl_dir = $module;
    } else {
      $tmpl_file = $template . '.twig';
      $tmpl_dir = MAIN_DIR . 'modules/' . $module . '/tmpl';
    }
    if (!file_exists("{$tmpl_dir}/{$tmpl_file}")) {
      throw new \Exception("Template {$tmpl_dir}/{$tmpl_file} not found");
    }

    $settings =  $this->debug ? ["autoescape" => false, "debug" => true] : ["autoescape" => false, "cache" => "cache"];

    $twig = new Environment(new FilesystemLoader($tmpl_dir), $settings);
    if ($settings['debug']) {
      $twig->addExtension(new DebugExtension());
    }

    $data['uid'] = uniqid('uid');
    $data['tr'] = new tr();

    return $twig->render($tmpl_file, $data);
  }

  /**
   * Alias for Controller::compileTmpl, but echoes result
   *
   * @param string $module module name or path to template directory
   * @param string $template template name or template file name with extension
   * @param array $data optional array of template data
   * @return void
   */
  public function render(
    string $module,
    string $template,
    array $data = []
  ): void {
    echo $this->compileTmpl($module, $template, $data);
  }
}
