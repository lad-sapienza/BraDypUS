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

/**
 * Resolves the controller and method from the current request, enforces the
 * auth gate, injects dependencies (DB, Config, Log), and calls the method.
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

    private function tooManyRequests(int $retryAfter): void
    {
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'code' => 'too_many_requests'], JSON_UNESCAPED_UNICODE);
    }

    public function dispatch(array $get, array $post, array $request): void
    {
        $obj    = $get['obj']    ?? 'home_ctrl';
        $method = $get['method'] ?? 'showAll';

        try {
            // ── Major upgrade gate ────────────────────────────────────────────
            // During a major upgrade, only 'none'-privilege routes (public,
            // app-independent) and the Upgrade controller itself are accessible.
            // All authenticated routes are blocked until the upgrade completes.
            // Using the privilege level (not a hardcoded list) means any future
            // public route is automatically allowed through.
            $required = Router::requiredPrivilege($obj, $method);

            if (defined('BDUS_MAJOR_UPGRADE') && BDUS_MAJOR_UPGRADE) {
                if ($required !== 'none' && $obj !== 'Bdus\\Controllers\\Upgrade') {
                    // 409 Conflict: the app needs a major upgrade before this
                    // operation can proceed. Not 503 (which signals "server down"
                    // to monitoring/load-balancers). The client reads the body,
                    // recognises the code, and redirects to the login/upgrade flow.
                    http_response_code(409);
                    header('Content-Type: application/json');
                    echo json_encode(
                        ['status' => 'error', 'code' => 'major_upgrade_required'],
                        JSON_UNESCAPED_UNICODE
                    );
                    return;
                }
            }

            // ── Auth gate ─────────────────────────────────────────────────────

            if ($required !== 'none') {
                if (!\Auth\CurrentUser::isAuthenticated() && $this->db) {
                    $ip = \Auth\ClientIp::get();
                    // An address that keeps presenting bad credentials is refused
                    // before any key lookup.
                    if ($wait = \Auth\RateLimiter::blocked('BAD_KEY_IP', $ip)) {
                        $this->tooManyRequests($wait);
                        return;
                    }
                    if (!\Auth\ApiKeyAuth::attempt($this->db) && \Auth\ApiKeyAuth::hasCredential()) {
                        \Auth\RateLimiter::hit('BAD_KEY_IP', $ip);
                    }
                }

                // API-key traffic only: people signed in with a JWT are never limited.
                if (\Auth\CurrentUser::isApiKey()) {
                    $isWrite = !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true);
                    $keyId   = (int) \Auth\CurrentUser::get('key_id');
                    $wait    = \Auth\RateLimiter::hit($isWrite ? 'KEY_WRITE' : 'KEY_READ', (string) $keyId)
                            ?: \Auth\RateLimiter::hit('KEY_IP', \Auth\ClientIp::get());
                    if ($wait) {
                        $this->tooManyRequests($wait);
                        return;
                    }
                }

                if (!\Auth\CurrentUser::isAuthenticated()) {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'code' => 'unauthenticated'], JSON_UNESCAPED_UNICODE);
                    return;
                }

                // For human users: verify token_version and refresh privilege from DB.
                // This ensures privilege changes and explicit revocations take effect
                // immediately, without waiting for the token to expire.
                if (!\Auth\CurrentUser::isApiKey() && $this->db) {
                    $row = $this->db->query(
                        'SELECT privilege, token_version FROM bdus_users WHERE id = ?',
                        [\Auth\CurrentUser::id()],
                        'read'
                    )[0] ?? null;

                    if (!$row || (int) $row['token_version'] !== \Auth\CurrentUser::get('tkv')) {
                        http_response_code(401);
                        header('Content-Type: application/json');
                        echo json_encode(['status' => 'error', 'code' => 'token_invalidated'], JSON_UNESCAPED_UNICODE);
                        return;
                    }

                    // Privilege is always read from the DB — the JWT value is ignored.
                    \Auth\CurrentUser::set(array_merge(
                        \Auth\CurrentUser::get(),
                        ['privilege' => (int) $row['privilege']]
                    ));
                }

                if (\Auth\CurrentUser::isApiKey() && !Router::apiKeyMayReach(
                    $obj, $method, $required,
                    \Auth\CurrentUser::privilege(),
                    \Auth\CurrentUser::get('group_id') ? (int) \Auth\CurrentUser::get('group_id') : null
                )) {
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

            // Skip Config when:
            //  a) a major upgrade is pending (system tables don't exist yet), or
            //  b) this is an Upgrade endpoint (never needs Config).
            $skipConfig = (defined('BDUS_MAJOR_UPGRADE') && BDUS_MAJOR_UPGRADE)
                       || $obj === 'Bdus\\Controllers\\Upgrade';

            if (defined('APP') && !$skipConfig) {
                $config = new Config(new Dot(), MAIN_DIR . 'projects/' . APP . '/', $this->db);
                $ctrl->setCfg($config);
                \Auth\Authorization::setAppStatus($config->get('main.status'));
                \Auth\Authorization::setReadOnlyTables(array_values(array_filter(
                    $config->get('tables.*.name') ?: [],
                    fn($t) => $config->get("tables.$t.is_view")
                        && ($config->get("tables.$t.view_writable") ?: 'none') === 'none'
                )));
                \Template\Loader::setDb($this->db);

                if ($this->db) {
                    $db     = $this->db;
                    $access = null;
                    \Auth\Authorization::setAccessProvider(
                        function () use ($db, $config, &$access): \Auth\Access {
                            return $access ??= \Auth\Access::forCurrentUser($db, $config);
                        }
                    );
                }
            }

            $ctrl->$method();

        } catch (\Throwable $e) {
            $this->log->error($e);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'status'  => 'error',
                'code'    => 'dispatch_error',
                'message' => 'The server was unable to complete the request.',
                'debug'   => (defined('DEBUG_ON') && DEBUG_ON) ? $e->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE);
        }
    }

}
