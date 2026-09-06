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

// ── Last-resort error safety net ─────────────────────────────────────────────
//
// Guarantees that *every* response leaving this endpoint is a JSON envelope,
// including failures that the try/catch below cannot reach:
//   - die()/exit() with a string message (not catchable)
//   - true fatal errors: out of memory, max_execution_time, E_ERROR,
//     parse errors in lazily loaded files, E_COMPILE_ERROR
//
// The catch block and the shutdown handler both funnel through
// _bdus_emit_error(); a one-shot guard makes sure nothing is emitted twice.

$GLOBALS['__bdus_error_emitted'] = false;

/**
 * Emit the canonical JSON error envelope and stop any further output.
 *
 * @param string      $code   stable machine code the frontend translates
 * @param string|null $debug  internal detail — logged always, returned only when DEBUG_ON
 */
function _bdus_emit_error(string $code, ?string $debug = null): void
{
    if ($GLOBALS['__bdus_error_emitted']) {
        return;
    }
    $GLOBALS['__bdus_error_emitted'] = true;

    // Always leave a server-side trace, even in production (Monolog may not be
    // wired up yet when the failure happens during bootstrap).
    error_log('BraDypUS API error [' . $code . ']: ' . ($debug ?? 'n/a'));

    // Discard any partial output a controller may have written before failing.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // The frontend treats error responses as HTTP 200 with a
        // { status:'error', code } body (see bdus-app/src/api/index.js).
        // Keep that contract and override any 500 PHP set on a fatal.
        http_response_code(200);
    }

    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => 'The server was unable to complete the request. Please retry; '
                   . 'if the problem persists contact the application administrator.',
        'debug'   => (defined('DEBUG_ON') && DEBUG_ON) ? $debug : null,
    ], JSON_UNESCAPED_UNICODE);
}

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
           | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if (($e['type'] & $fatal) === 0) {
        return;
    }
    _bdus_emit_error('server_error', "{$e['message']} in {$e['file']}:{$e['line']}");
});

// ── Bootstrap + dispatch ──────────────────────────────────────────────────────
ob_start();
try {
    require_once __DIR__ . '/lib/bootstrap.php';
    \Bdus\Router::dispatch();
    (new \Bdus\App())->start();
} catch (\Throwable $e) {
    _bdus_emit_error('server_error', $e->getMessage());
}

// Flush the normal-path buffer. On the error path _bdus_emit_error() has
// already discarded every buffer, so only flush when one is still open.
if (ob_get_level() > 0) {
    ob_end_flush();
}
