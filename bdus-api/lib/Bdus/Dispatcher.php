<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use Monolog\Logger;
use DB\DB;
use Config\Config;
use Adbar\Dot;
use UAC\UAC;
use UAC\Loader as UACLoader;

/**
 * Resolves the controller and method from the current request, enforces the
 * auth gate, injects dependencies (DB, Config, UAC, Log), and calls the method.
 *
 * Receives DB and Logger from App after they have been initialised and
 * migrations have run. The request arrays come in via dispatch() so the
 * class is independent of superglobals and easy to unit-test.
 */
class Dispatcher
{
    public function __construct(
        private readonly ?DB     $db,
        private readonly Logger  $log,
    ) {}

    public function dispatch(array $get, array $post, array $request): void
    {
        $obj    = $get['obj']    ?? 'home_ctrl';
        $method = $get['method'] ?? 'showAll';

        try {
            // ── Auth gate ─────────────────────────────────────────────────────
            $required = Router::requiredPrivilege($obj, $method);

            if ($required !== 'none') {
                if (!\Auth\CurrentUser::isAuthenticated() && $this->db) {
                    \Auth\ApiKeyAuth::attempt($this->db);
                }

                if (!\Auth\CurrentUser::isAuthenticated()) {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'code' => 'unauthenticated'], JSON_UNESCAPED_UNICODE);
                    return;
                }

                if (\Auth\CurrentUser::isApiKey() && !$this->apiKeyHasPrivilege($required)) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'code' => 'not_enough_privilege'], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            // ── Controller validation ─────────────────────────────────────────
            if (!method_exists($obj, $method)) {
                throw new \Exception("Object {$obj} does not have method {$method}");
            }

            if (!is_a($obj, \Bdus\Controller::class, true)) {
                throw new \Exception("{$obj} must extend \\Bdus\\Controller");
            }

            // ── DI: build and inject dependencies ─────────────────────────────
            $ctrl = new $obj($get, $post, $request);

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

    /**
     * Returns true if the current API-key user satisfies the required privilege level.
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
}
