<?php
/**
 * PHPUnit bootstrap for BraDypUS.
 *
 * Initialises constants, autoloaders and global helpers without starting
 * an HTTP session (no session_start, no headers, no output buffering).
 */

// ── Root path ─────────────────────────────────────────────────────────────
$basePath = __DIR__ . '/../';

// ── Constants normally set by constants.php ───────────────────────────────
date_default_timezone_set('Europe/Rome');
define('MAIN_DIR', $basePath);
define('DEBUG_ON', false);
define('PREFIX',   'test__');   // needed by Record\Read::getFull() and buildTableSchema()

// PROJ_DIR is normally set per-request by constants.php (depends on the logged-in app).
// In tests we point it to a dedicated temp tree so filesystem-touching tests
// (backup_ctrl, etc.) have a safe scratch space that is isolated from any real project.
$testProjDir = sys_get_temp_dir() . '/bradypus_test_proj/';
define('PROJ_DIR', $testProjDir);

// Pre-create the sub-directories that filesystem-touching controllers expect.
foreach (['backups', 'db', 'geodata', 'templates', 'export', 'files'] as $sub) {
    $dir = $testProjDir . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ── Suppress errors the way production does, but keep PHPUnit able to catch ─
// (do NOT call error_reporting(0) — that would hide PHPUnit's own errors)

// ── CACHE: used by Template\Template when rendering Twig templates ────────
// Disable file caching in tests to avoid stale artefacts.
define('CACHE', serialize(["autoescape" => false, "cache" => false]));

// ── Stub server vars needed by Controller base class ─────────────────────
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
$_SERVER['HTTP_HOST']            = $_SERVER['HTTP_HOST']            ?? 'localhost';
$_SERVER['CONTENT_TYPE']         = $_SERVER['CONTENT_TYPE']         ?? '';

// ── Composer autoloader (Monolog, Twig, Adbar\Dot, PHPUnit itself, …) ─────
require_once $basePath . 'vendor/autoload.php';

// ── BraDypUS custom autoloader ────────────────────────────────────────────
require_once $basePath . 'lib/autoLoader.php';
new autoloader($basePath . 'lib/', $basePath . 'modules/');

// ── Locale helper (tr::get) ───────────────────────────────────────────────
// tr is loaded via the autoloader from lib/tr.inc; pre-load English strings
\tr::load_file('en');
