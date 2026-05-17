<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 8, 2012
 *
 * List of system query parameters:
 * 	GET: mini:		if 1 minifies all js scripts
 * 	GET: logout:	if 1/true/set forces user logout
 * 	env: BRADYPUS_DEBUG=1  enables debug mode (verbose errors, Twig debug, no cache)
 *
 * Controller related
 * REQUEST: obj		Object name to run
 * REQUEST: method	Method name to run
 * REQUEST: param	Params to pass to object::method
 *
 */

// ── CORS ─────────────────────────────────────────────────────────────────────
// When the Vue frontend is served from a different origin (e.g. GitHub Pages,
// a separate Vite dev server, or a CDN), the browser sends cross-origin requests.
// Set BRADYPUS_CORS_ORIGIN to a space-separated list of allowed origins, e.g.:
//   BRADYPUS_CORS_ORIGIN=https://myapp.github.io https://localhost:5173
// Leave unset (or empty) when frontend and backend share the same origin.
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
    // Preflight — answer immediately, no further processing needed.
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

ob_start();

try {
	$basePath = './';

	require_once './lib/constants.php';

	// ── REST API v1 ──────────────────────────────────────────────────────────
	// Handled before general routing.  constants.php (autoloader) is already
	// loaded at this point so all namespaced classes are available.
	if (isset($_SERVER['REQUEST_URI']) && preg_match('#/api/v1(/|$)#', $_SERVER['REQUEST_URI'])) {
		\API\V1\Router::handle();
		ob_end_flush();
		exit;
	}

	// ── FastRoute dispatch ────────────────────────────────────────────────────
	// Matches /api/... paths to controller::method and merges URL vars + JSON
	// body into $_GET / $_POST / $_REQUEST.  Legacy ?obj=&method= requests are
	// returned as [null, null, []] and handled by Bdus\App normally.
	\Bdus\Router::dispatch();

	// ── Legacy public REST API ────────────────────────────────────────────────
	// Requests that reach here without a FastRoute match AND that look like
	// /api/{app}/... are the old public REST API (served by api_ctrl::run).
	// Previously handled by .htaccess; now handled in PHP so that the web-server
	// config stays a simple catch-all.
	if (!isset($_GET['obj'])) {
		$_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
		if (preg_match('#^(?:.*/)?api/([a-z0-9_]+)(/.*)?$#', $_uri, $_m)) {
			$_GET     = array_merge($_GET,     ['obj' => 'api', 'method' => 'run', 'app' => $_m[1]]);
			$_REQUEST = array_merge($_REQUEST, $_GET);
		}
		unset($_uri, $_m);
	}

	$application = new \Bdus\App($_GET, $_POST, $_REQUEST);

	$application->setDebug(DEBUG_ON);

	if (defined('PREFIX')){
		$application->setPrefix(PREFIX);
	}

	if (defined('APP')) {
		$application->setApp(APP);
	}

	$application->start();

} catch (\Throwable $e) {

	// Always respond with valid JSON — debug details go to logs/error.log, not the response body.
	echo json_encode([
		"text" => 'generic_error',
		"status" => 'error',
		"debug" => DEBUG_ON ? $e->getMessage() : null,
	], JSON_UNESCAPED_UNICODE);
}
ob_end_flush();
