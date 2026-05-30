<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;

use DB\LogDBHandler;
use DB\DB;

use Config\Config;
use Adbar\Dot;
use UAC\UAC;
use UAC\Loader as UACLoader;

class App
{
    protected array $get;
    protected array $post;
    protected array $request;
    protected ?DB $db = null;
    protected Logger $log;

    /**
     * Reads the current request directly from superglobals.
     * bootstrap.php must have run before this is called so that
     * APP / PROJ_DIR / DEBUG_ON constants and Auth\CurrentUser are set.
     */
    public function __construct()
    {
        $this->get     = $_GET;
        $this->post    = $_POST;
        $this->request = $_REQUEST;
    }

    public function start(): void
    {
        if (defined('APP')) {
            $this->db = new DB(APP);
        }

        $this->setupLogger();

        if ($this->db) {
            \DB\System\Migrate::maybeRemovePrefix($this->db, $this->log);
            \DB\System\Migrate::maybeAddBdusPrefix($this->db, $this->log);
            \DB\System\Migrate::run($this->db, $this->log);
        }

        $this->route();
    }

    private function setupLogger(): void
    {
        $this->log  = new Logger('bdus');
        $log_file   = MAIN_DIR . 'logs/error.log';

        try {
            if ($this->db && !DEBUG_ON) {
                $this->log->pushHandler(new LogDBHandler($this->db));
                $this->db->setLog($this->log);
            } else {
                $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
                if ($this->db) {
                    $this->db->setLog($this->log);
                }
            }
        } catch (\Throwable $th) {
            $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
            $this->log->error($th);
        }

        $handler = new ErrorHandler($this->log);
        $handler->registerErrorHandler([], false);
        $handler->registerExceptionHandler();
        $handler->registerFatalHandler();
    }

    /**
     * Maps an API-key privilege label to the numeric UAC threshold.
     */
    private function apiKeyHasPrivilege(string $required): bool
    {
        $privilege = \Auth\CurrentUser::privilege();
        if ($privilege === null) {
            return false;
        }
        return match ($required) {
            'read'        => $privilege <= \UAC\UAC::READ,
            'edit'        => $privilege <= \UAC\UAC::CREATE,
            'admin'       => $privilege <= \UAC\UAC::ADM,
            'super_admin' => $privilege <= \UAC\UAC::SUPERADM,
            default       => false,
        };
    }

    private function route(): void
    {
        $obj    = $this->get['obj']    ?? 'home_ctrl';
        $method = $this->get['method'] ?? 'showAll';

        try {
            // ── Auth gate ────────────────────────────────────────────────────
            $requiredPrivilege = Router::requiredPrivilege($obj, $method);

            if ($requiredPrivilege !== 'none') {
                if (!\Auth\CurrentUser::isAuthenticated() && $this->db) {
                    \Auth\ApiKeyAuth::attempt($this->db);
                }

                if (!\Auth\CurrentUser::isAuthenticated()) {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'code' => 'unauthenticated'], JSON_UNESCAPED_UNICODE);
                    return;
                }

                if (\Auth\CurrentUser::isApiKey() && !$this->apiKeyHasPrivilege($requiredPrivilege)) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'code' => 'not_enough_privilege'], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            if (!method_exists($obj, $method)) {
                throw new \Exception("Object {$obj} does not have method {$method}");
            }

            if (get_parent_class($obj) !== 'Controller') {
                throw new \Exception("Called object {$obj} must extend Controller");
            }

            $ctrl = new $obj($this->get, $this->post, $this->request);

            if ($this->db) {
                $ctrl->setDB($this->db);
            }

            $ctrl->setLog($this->log);

            if (defined('APP')) {
                $config = new Config(new Dot(), MAIN_DIR . 'projects/' . APP . '/', $this->db);
                $ctrl->setCfg($config);
                \Template\Loader::setDb($this->db);

                if ($this->db) {
                    $uac = new UAC($config->get('main.status'), $this->db);

                    if (\Auth\CurrentUser::isAuthenticated()) {
                        $uac->setUAL(UACLoader::buildUAL(
                            \Auth\CurrentUser::id(),
                            \Auth\CurrentUser::privilege(),
                            $this->db
                        ));
                    }

                    $ctrl->setUAC($uac);
                }
            }

            $ctrl->$method();

        } catch (\Throwable $e) {
            $this->log->error($e);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'code' => 'dispatch_error'], JSON_UNESCAPED_UNICODE);
        }
    }
}
