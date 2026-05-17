<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Bootstrap: constants, autoloaders, JWT authentication.
 *
 * No PHP sessions are used in v5. Authentication is carried by a
 * per-request JWT in the Authorization: Bearer header.
 * Application context (APP / PREFIX / PROJ_DIR) is derived from the
 * token's 'app' claim, or from $_REQUEST['app'] for unauthenticated
 * endpoints (login, app list, password-reset).
 */

date_default_timezone_set('Europe/Rome');

define('MAIN_DIR', $basePath);

error_reporting(0);
ini_set('display_errors', 'off');

/**
 * Debug mode: set the BRADYPUS_DEBUG=1 environment variable to enable.
 * When on: Twig debug mode, verbose error reporting, no template cache.
 */
define('DEBUG_ON', getenv('BRADYPUS_DEBUG') === '1');

if (DEBUG_ON) {
    define('CACHE', serialize(["autoescape" => false, "debug" => true]));
    error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
    ini_set('error_log', 'logs/error.log');
} else {
    define('CACHE', serialize(["autoescape" => false, "cache" => "cache"]));
}

// ── Autoloaders (must come before any class usage) ────────────────────
require_once $basePath . 'lib/autoLoader.php';
require_once $basePath . 'vendor/autoload.php';
new autoLoader($basePath . 'lib/', $basePath . 'modules/');

// ── JWT / App resolution ──────────────────────────────────────────────

/**
 * Extract the Bearer token from the Authorization header.
 * Nginx and some PHP-FPM setups expose it under REDIRECT_HTTP_AUTHORIZATION.
 */
function _bdus_bearer_token(): ?string
{
    // 1. Standard $_SERVER key (mod_php, most FPM setups)
    // 2. Some Apache+rewrite configs expose it under REDIRECT_
    // 3. getallheaders() works on any PHP 7.3+ SAPI (Apache, nginx-FPM, Caddy, CLI)
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        $all    = getallheaders();                            // case-insensitive lookup
        $header = $all['Authorization'] ?? $all['authorization'] ?? '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

$_bdus_token = _bdus_bearer_token();

if ($_bdus_token) {
    // Peek at the unverified 'app' claim to load the correct per-app secret.
    $app_hint = \JWT\JwtManager::peekApp($_bdus_token);

    if ($app_hint && is_dir(MAIN_DIR . 'projects/' . $app_hint)) {
        define('APP',      $app_hint);
        define('PREFIX',   APP . '__');
        define('PROJ_DIR', MAIN_DIR . 'projects/' . APP . '/');

        // Full signature verification
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

} elseif (isset($_REQUEST['app']) && is_dir(MAIN_DIR . 'projects/' . $_REQUEST['app'])) {
    // Unauthenticated request with explicit app param
    // (login, app list, password-reset flows)
    define('APP',      $_REQUEST['app']);
    define('PREFIX',   APP . '__');
    define('PROJ_DIR', MAIN_DIR . 'projects/' . APP . '/');
}

// ── Runtime directories ───────────────────────────────────────────────

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
}
