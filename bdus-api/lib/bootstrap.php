<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Application bootstrap: constants, autoloaders, JWT authentication.
 *
 * Required by index.php after CORS handling and ob_start().
 * Sets up everything that must exist before any controller runs:
 *   - MAIN_DIR constant and debug flags
 *   - Composer + custom autoloaders
 *   - APP / PROJ_DIR constants (resolved from Bearer token or request body)
 *   - Auth\CurrentUser populated when a valid JWT is present
 *   - Runtime directory scaffolding
 *
 * No output is produced here. No PHP sessions are used.
 */

date_default_timezone_set('Europe/Rome');

// ── Constants ─────────────────────────────────────────────────────────────────

define('MAIN_DIR', __DIR__ . '/../');

error_reporting(0);
ini_set('display_errors', 'off');

define('DEBUG_ON', getenv('BRADYPUS_DEBUG') === '1');

if (DEBUG_ON) {
    error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
    ini_set('error_log', MAIN_DIR . 'logs/error.log');
}

// ── Autoloader ────────────────────────────────────────────────────────────────

require_once MAIN_DIR . 'vendor/autoload.php';

// ── JWT / App resolution ──────────────────────────────────────────────────────
//
// Authenticated requests carry a signed Bearer token whose 'app' claim
// identifies the application.  Unauthenticated requests (login, app list)
// pass 'app' in the JSON body or query string.

/**
 * Returns the raw Bearer token from the current request, or null if absent.
 * Checks $_SERVER, REDIRECT_* (Apache rewrite), and getallheaders() (FPM/Caddy).
 */
function _bdus_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        $all    = getallheaders();
        $header = $all['Authorization'] ?? $all['authorization'] ?? '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

$_bdus_token = _bdus_bearer_token();

if ($_bdus_token) {
    $app_hint = \JWT\JwtManager::peekApp($_bdus_token);

    if ($app_hint && is_dir(MAIN_DIR . 'projects/' . $app_hint)) {
        define('APP',      $app_hint);
        define('PREFIX',   '');
        define('PROJ_DIR', MAIN_DIR . 'projects/' . APP . '/');

        $claims = \JWT\JwtManager::decode($_bdus_token, APP);
        if ($claims) {
            \Auth\CurrentUser::set([
                'id'        => (int) $claims['sub'],
                'privilege' => (int) $claims['prv'],
                'name'      => $claims['name'] ?? '',
                'email'     => $claims['eml']  ?? '',
                'app'       => APP,
            ]);
        }
    }
} else {
    // Unauthenticated: resolve app from query string or JSON body.
    $_bdus_app = $_REQUEST['app'] ?? null;

    if (!$_bdus_app) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded   = json_decode($raw, true);
            $_bdus_app = $decoded['app'] ?? null;
        }
    }

    if ($_bdus_app && is_dir(MAIN_DIR . 'projects/' . $_bdus_app)) {
        define('APP',      $_bdus_app);
        define('PREFIX',   '');
        define('PROJ_DIR', MAIN_DIR . 'projects/' . APP . '/');
    }
    unset($_bdus_app, $raw, $decoded);
}

unset($_bdus_token, $app_hint, $claims);

// ── Runtime directory scaffolding ─────────────────────────────────────────────

if (defined('APP')) {
    $must_exist_dirs = [
        MAIN_DIR . 'cache',
        MAIN_DIR . 'cache/img',
        PROJ_DIR . 'files',
        PROJ_DIR . 'backups',
        PROJ_DIR . 'export',
        PROJ_DIR . 'db',
    ];

    foreach ($must_exist_dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    foreach ($must_exist_dirs as $dir) {
        if (!is_writable($dir)) {
            die("Directory {$dir} is not writable. Application cannot start!");
        }
    }

    unset($must_exist_dirs, $dir);
}
