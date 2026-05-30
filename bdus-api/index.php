<?php

// ── CORS ──────────────────────────────────────────────────────────────────────
// Must run before ob_start() so that header() and exit work unconditionally.
$_cors_raw = getenv('BRADYPUS_CORS_ORIGIN') ?: '';
if ($_cors_raw !== '') {
    $_cors_allowed = array_filter(array_map('trim', explode(' ', $_cors_raw)));
    $_cors_origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($_cors_origin, $_cors_allowed, true)) {
        header('Access-Control-Allow-Origin: '    . $_cors_origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Vary: Origin');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
unset($_cors_raw, $_cors_allowed, $_cors_origin);

// ── Bootstrap + dispatch ──────────────────────────────────────────────────────
ob_start();
try {
    require_once __DIR__ . '/lib/bootstrap.php';
    \Bdus\Router::dispatch();
    (new \Bdus\App())->start();
} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'code'   => 'generic_error',
        'debug'  => (defined('DEBUG_ON') && DEBUG_ON) ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
ob_end_flush();
