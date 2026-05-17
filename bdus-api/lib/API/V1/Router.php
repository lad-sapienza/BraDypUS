<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Entry point for the v1 REST API.
 *
 * Called from index.php when the URI matches /api/v1/...
 * constants.php (including the autoloader) has already been loaded, so all
 * namespaced classes are available.  The MAIN_DIR constant is defined there.
 *
 * Bootstrap strategy:
 *   1. Parse the URI to extract {app}, {resource?}, {id?}.
 *   2. Validate the app name (directory must exist under projects/).
 *   3. Define APP / PREFIX / PROJ_DIR constants (mirroring what constants.php
 *      does for authenticated requests) — only if not already defined.
 *   4. Instantiate DB\DB and Config\Config for the app.
 *   5. Run Auth::verify() — every endpoint requires a valid API key.
 *   6. Dispatch to Handler.
 */

namespace API\V1;

use DB\DB;
use Config\Config;
use Adbar\Dot;

class Router
{
    public static function handle(): void
    {
        // ── HTTP headers ──────────────────────────────────────────────────────
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
            return;
        }

        try {
            // ── URI parsing ───────────────────────────────────────────────────
            // Strip everything up to and including /api/v1
            $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $uri  = preg_replace('#^.*/api/v1#', '', $uri);
            $parts = array_values(array_filter(explode('/', trim($uri, '/'))));
            // $parts[0] = app
            // $parts[1] = resource | 'schema' | 'vocabularies'
            // $parts[2] = id (or table name when resource === 'schema')

            if (empty($parts[0])) {
                self::error('App name required', 'MISSING_APP', 400);
                return;
            }

            $app      = $parts[0];
            $resource = $parts[1] ?? null;
            $id       = $parts[2] ?? null;

            // ── Validate app ──────────────────────────────────────────────────
            $valid_apps = \utils::dirContent(MAIN_DIR . 'projects');
            if (!in_array($app, $valid_apps, true)) {
                self::error("Unknown app: $app", 'UNKNOWN_APP', 404);
                return;
            }

            // ── Bootstrap constants (mimic constants.php logic) ───────────────
            // These may already be defined when a JWT was present in the request.
            if (!defined('APP'))      { define('APP',      $app); }
            if (!defined('PREFIX'))   { define('PREFIX',   $app . '__'); }
            if (!defined('PROJ_DIR')) { define('PROJ_DIR', MAIN_DIR . 'projects/' . $app . '/'); }

            $prefix = $app . '__';

            // Ensure runtime directories exist (same as constants.php)
            $dirs = [
                MAIN_DIR . 'cache',
                MAIN_DIR . 'cache/img',
                PROJ_DIR . 'files',
                PROJ_DIR . 'backups',
                PROJ_DIR . 'export',
                PROJ_DIR . 'db',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            }

            // ── Instantiate DB and Config ─────────────────────────────────────
            // DB::__construct(string $app) reads projects/{app}/cfg/app_data.json
            $db  = new DB($app);
            $dot = new Dot();
            $cfg = new Config($dot, MAIN_DIR . 'projects/' . $app . '/cfg/', $prefix);

            // ── Authentication ────────────────────────────────────────────────
            Auth::verify($db, $prefix);

            // ── Dispatch ──────────────────────────────────────────────────────
            $handler = new Handler($db, $cfg, $prefix, $app);

            if ($resource === null) {
                $handler->listTables();
            } elseif ($resource === 'schema') {
                // $id here is an optional table name (without prefix)
                $handler->schema($id);
            } elseif ($resource === 'vocabularies') {
                if (!$id) {
                    self::error('Vocabulary name required', 'MISSING_PARAM', 400);
                } else {
                    $handler->vocabulary($id);
                }
            } else {
                // $resource is a table name (without prefix)
                $table = $prefix . $resource;
                if ($id !== null) {
                    $handler->singleRecord($table, $id);
                } else {
                    $handler->listRecords($table);
                }
            }

        } catch (\Throwable $e) {
            self::error($e->getMessage(), 'INTERNAL_ERROR', 500);
        }
    }

    /**
     * Emit a JSON error response and terminate.
     */
    public static function error(string $message, string $code, int $status = 400): void
    {
        http_response_code($status);
        echo json_encode(
            ['errors' => [['message' => $message, 'code' => $code]]],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Emit a successful JSON response.
     *
     * @param array      $data  Primary payload.
     * @param array|null $meta  Optional metadata envelope (pagination etc.).
     */
    public static function respond(array $data, array $meta = null): void
    {
        $response = ['data' => $data];
        if ($meta !== null) {
            $response['meta'] = $meta;
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (isset($_GET['pretty'])) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($response, $flags);
    }
}
