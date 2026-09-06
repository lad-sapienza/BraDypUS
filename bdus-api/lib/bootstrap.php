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
// v5.9.0: the application is the first URL path segment — /{app}/api/…
// (Router::resolveRequest()). The app-independent surface (/api/auth/*,
// /api/new-app, /api/info) has no segment: those endpoints either need no app
// or carry it in the request body (POST /api/auth/login) or query string
// (GET /api/auth/oauth/{provider}/callback?app=…). Authenticated requests
// without a segment (/api/auth/refresh, /api/auth/logout) fall back to the
// 'app' claim inside the Bearer token, which only tells us which per-app
// secret to load for verification.

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

[$_bdus_url_app] = \Bdus\Router::resolveRequest();

$_bdus_token   = _bdus_bearer_token();
$_bdus_tok_app = $_bdus_token ? \JWT\JwtManager::peekApp($_bdus_token) : null;

// A token minted for another application will never verify against this app's
// per-app secret. When the URL scopes the request to one app and the token
// belongs to another, fail loudly (403) instead of a bare 401.
if ($_bdus_url_app !== null && $_bdus_tok_app !== null && $_bdus_url_app !== $_bdus_tok_app) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'code' => 'app_mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Resolve the application: URL segment first, then the token hint (segment-less
// authenticated endpoints), then an explicit hint in the query string / body.
$_bdus_app = $_bdus_url_app ?? $_bdus_tok_app;

if (!$_bdus_app) {
    $_bdus_app = $_REQUEST['app'] ?? null;
    if (!$_bdus_app) {
        $raw       = file_get_contents('php://input');
        $decoded   = $raw ? json_decode($raw, true) : null;
        $_bdus_app = is_array($decoded) ? ($decoded['app'] ?? null) : null;
    }
}

if ($_bdus_app && is_dir(MAIN_DIR . 'projects/' . $_bdus_app)) {
    define('APP',      $_bdus_app);
    define('PREFIX',   '');
    define('PROJ_DIR', MAIN_DIR . 'projects/' . APP . '/');

    if ($_bdus_token) {
        $claims = \JWT\JwtManager::decode($_bdus_token, APP);
        if ($claims) {
            \Auth\CurrentUser::set([
                'id'        => (int) $claims['sub'],
                'privilege' => (int) $claims['prv'],
                'tkv'       => isset($claims['tkv']) ? (int) $claims['tkv'] : 0,
                'name'      => $claims['name'] ?? '',
                'email'     => $claims['eml']  ?? '',
                'app'       => APP,
            ]);
        }
    }
}

unset($_bdus_token, $_bdus_tok_app, $_bdus_url_app, $_bdus_app, $raw, $decoded, $claims);

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
            // Throw rather than die(): index.php catches \Throwable and turns it
            // into a JSON error envelope. die() would leak a raw text response.
            throw new \RuntimeException("Runtime directory is not writable: {$dir}");
        }
    }

    unset($must_exist_dirs, $dir);
}
