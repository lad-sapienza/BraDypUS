#!/usr/bin/env php
<?php

/**
 * bin/upgrade-app.php — apply the pending MINOR migrations of one or all apps.
 *
 * After a release that adds migrations, an app stays "upgrade pending" until an
 * administrator logs in and confirms; until then ordinary users cannot enter.
 * This command does the same thing from the command line, with no login: it
 * calls DB\System\Upgrader, the same code behind POST /api/upgrade/minor.
 *
 *   docker compose exec -u www-data api php bin/upgrade-app.php --app paths
 *   docker compose exec -u www-data api php bin/upgrade-app.php --all
 *   docker compose exec -u www-data api php bin/upgrade-app.php --all --dry-run
 *
 * It never runs a MAJOR upgrade (v4 → v5): such an app is reported and skipped,
 * it needs a super admin, a snapshot and the verification (login → upgrade).
 *
 * It does NOT take a backup: that is the caller's job (`bdus update` does it).
 *
 * Options:
 *   --app <slug>   one application under projects/
 *   --all          every application under projects/ (one child process each,
 *                  so a failure — even a fatal error — never stops the others)
 *   --dry-run      list what would be applied, change nothing
 *   --json         machine-readable result on stdout
 *
 * Exit code: 0 = every app up to date (or upgraded), 1 = at least one app failed,
 *            2 = bad invocation, 3 = nothing failed but at least one app needs
 *            the MAJOR upgrade (skipped).
 *
 * Each result line is `<app>: <state>` where state is one of
 *   up-to-date | upgraded (n) | pending (n) [dry run] | needs-major | failed: <reason>
 * A failure is also written to the app's own log (bdus_log).
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "upgrade-app.php must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__);
chdir($root);
define('MAIN_DIR', $root . '/');

require $root . '/vendor/autoload.php';

date_default_timezone_set('Europe/Rome');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

function usage(int $code = 0): void
{
    fwrite($code === 0 ? STDOUT : STDERR, <<<TXT
Usage:
  php bin/upgrade-app.php (--app <slug> | --all) [--dry-run] [--json]

  --app <slug>   one application under projects/
  --all          every application under projects/
  --dry-run      only list the pending migrations
  --json         machine-readable result

Exit code: 0 ok, 1 at least one app failed, 2 bad invocation, 3 an app needs the major upgrade.

TXT);
    exit($code);
}

$opts = getopt('h', ['help', 'app:', 'all', 'dry-run', 'json']);
if (isset($opts['h']) || isset($opts['help'])) {
    usage(0);
}

$one    = isset($opts['app']) ? trim((string) $opts['app']) : '';
$all    = isset($opts['all']);
$dry    = isset($opts['dry-run']);
$asJson = isset($opts['json']);

if (($one === '') === !$all) {
    fwrite(STDERR, "ERROR: pass exactly one of --app <slug> or --all\n\n");
    usage(2);
}
if ($one !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $one)) {
    fwrite(STDERR, "ERROR: --app must match ^[a-z][a-z0-9_]*\$\n\n");
    usage(2);
}

/** Exit code for a list of per-app results. */
function exitCodeFor(array $results): int
{
    $states = array_column($results, 'state');
    if (in_array('failed', $states, true)) {
        return 1;
    }
    return in_array('needs-major', $states, true) ? 3 : 0;
}

function renderLine(array $r): string
{
    return match ($r['state']) {
        'up-to-date'  => "{$r['app']}: up-to-date",
        'upgraded'    => "{$r['app']}: upgraded (" . count($r['migrations']) . ')',
        'pending'     => "{$r['app']}: pending (" . count($r['migrations']) . ')',
        'needs-major' => "{$r['app']}: needs-major",
        default       => "{$r['app']}: failed: " . ($r['error'] ?? 'unknown'),
    };
}

// ── --all: one child process per app ──────────────────────────────────────
if ($all) {
    $apps = [];
    foreach (glob($root . '/projects/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            continue;
        }
        // An app has a config.json at one of the three historical locations.
        foreach (['config.json', 'cfg/config.json', 'cfg/app_data.json'] as $f) {
            if (is_file("{$dir}/{$f}")) {
                $apps[] = $slug;
                break;
            }
        }
    }
    sort($apps);

    $results = [];
    foreach ($apps as $slug) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
             . ' --json --app ' . escapeshellarg($slug) . ($dry ? ' --dry-run' : '');
        $out = [];
        exec($cmd . ' 2>/dev/null', $out, $rc);
        $decoded = json_decode(implode("\n", $out), true);
        $r = $decoded['results'][0] ?? null;
        if (!is_array($r) || !isset($r['state'])) {
            // The child died before printing its result (fatal error, OOM, …).
            $r = ['app' => $slug, 'state' => 'failed', 'migrations' => [], 'error' => "child exited with code {$rc}"];
        }
        $results[] = $r;
        if (!$asJson) {
            echo renderLine($r), "\n";
        }
    }

    if ($asJson) {
        echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    } elseif (!$apps) {
        echo "no applications found under projects/\n";
    }
    exit(exitCodeFor($results));
}

// ── --app: do the work in this process ────────────────────────────────────
$projDir = $root . '/projects/' . $one . '/';
if (!is_dir($projDir)) {
    fwrite(STDERR, "ERROR: no application at {$projDir}\n");
    exit(2);
}

// Constants a few lib classes still read from the global scope.
define('APP', $one);
define('PREFIX', '');
define('PROJ_DIR', $projDir);

$result = ['app' => $one, 'state' => 'failed', 'migrations' => [], 'error' => null];

try {
    $db = new \DB\DB($one);

    // The app's own log (bdus_log), like a web request: what happened here is
    // visible to the app's administrator, not only to whoever ran the command.
    $log = new \Monolog\Logger('bdus');
    $log->pushHandler(new \DB\LogDBHandler($db, \Monolog\Logger::INFO));
    $db->setLog($log);

    $pending = \DB\System\Upgrader::pending($db);

    if ($pending === null) {
        $result['state'] = 'needs-major';
    } elseif ($pending === []) {
        $result['state'] = 'up-to-date';
    } elseif ($dry) {
        $result['state']      = 'pending';
        $result['migrations'] = $pending;
    } else {
        $log->info('Minor upgrade started from the command line');
        $result['migrations'] = \DB\System\Upgrader::apply($db, $log);
        $result['state']      = 'upgraded';
        $log->info('Minor upgrade applied from the command line: ' . implode(', ', $result['migrations']));
    }
} catch (\Throwable $e) {
    $result['state'] = 'failed';
    $result['error'] = $e->getMessage();
    if (isset($log)) {
        try {
            $log->error('Minor upgrade failed from the command line: ' . $e->getMessage());
        } catch (\Throwable $ignored) {
        }
    }
}

// `docker compose exec` runs as root by default: files created by a migration
// would then be unusable for Apache (www-data). A no-op when not root.
if ($result['state'] === 'upgraded'
    && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    exec('chown -R www-data:www-data ' . escapeshellarg(rtrim($projDir, '/')) . ' 2>/dev/null');
}

if ($asJson) {
    echo json_encode(['results' => [$result]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo renderLine($result), "\n";
    if ($result['state'] === 'pending' || $result['state'] === 'upgraded') {
        foreach ($result['migrations'] as $m) {
            echo "  - {$m}\n";
        }
    }
}
exit(exitCodeFor([$result]));
