<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use DB\DBInterface;
use UAC\UAC;
use Config\Config;
use Monolog\Logger;

abstract class Controller
{
    protected array  $get;
    protected array  $post;
    protected array  $request;
    protected ?DBInterface $db  = null;
    protected ?Logger      $log = null;
    protected ?Config      $cfg = null;
    protected ?UAC         $uac = null;

    public function __construct(array $get, array $post, array $request)
    {
        $this->get = $get;

        // Merge JSON body into $post so $this->post works transparently
        // for requests sent with Content-Type: application/json.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode(file_get_contents('php://input'), true);
            $post = is_array($json) ? array_merge($post, $json) : $post;
        }

        $this->post    = $post;
        $this->request = array_merge($get, $post);
    }

    public function setDB(DBInterface $db): void   { $this->db  = $db;  }
    public function setCfg(Config $cfg): void       { $this->cfg = $cfg; }
    public function setLog(Logger $log): void       { $this->log = $log; }
    public function setUAC(UAC $uac): void          { $this->uac = $uac; }

    /**
     * Emits a JSON response. Injects `"status": "success"` if not already set.
     */
    public function returnJson(array $data): void
    {
        if (!array_key_exists('status', $data)) {
            $data = ['status' => 'success'] + $data;
        }
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
