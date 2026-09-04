#!/usr/bin/env php
<?php

/**
 * bin/verify-migration.php — read-only data checks for a v4 → v5 migrated app.
 *
 * Runs DB\Verify\MigrationVerifier against a project's live database and
 * prints a pass/warn/fail report. Exit code is non-zero when any check fails,
 * so it can gate a production cut-over script.
 *
 *   docker compose exec -u www-data api php bin/verify-migration.php --app paths
 *
 * Pass a copy of the pre-upgrade SQLite file to also get the row-count diff
 * (data-loss / plugin-split checks):
 *
 *   docker compose exec -u www-data api php bin/verify-migration.php \
 *     --app paths --baseline projects/paths/backups/pre-upgrade-20260904.sqlite
 *
 * Options:
 *   --app <slug>        (required) application under projects/
 *   --baseline <path>   pre-upgrade SQLite copy → enables the baseline diff
 *   --prefix <name>     extra legacy table prefix to hunt for (repeatable),
 *                       e.g. --prefix paths  (auto-detected from "<x>__" tables anyway)
 *   --json              print the full machine-readable report instead of the summary
 *   --quiet             print only the one-line summary
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "verify-migration.php must be run from the command line\n");
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
  php bin/verify-migration.php --app <slug> [--baseline <sqlite>] [--prefix <name>]
                               [--json] [--quiet]

  --app <slug>       application directory under projects/ (required)
  --baseline <path>  pre-upgrade SQLite copy → enables row-count / plugin-split diff
  --prefix <name>    extra legacy table prefix to look for (repeatable)
  --json             print the full JSON report
  --quiet            print only the summary line

Exit code: 0 = no failures, 1 = at least one FAIL, 2 = bad invocation.

TXT);
    exit($code);
}

$opts = getopt('h', ['help', 'app:', 'baseline:', 'prefix:', 'json', 'quiet']);
if (isset($opts['h']) || isset($opts['help'])) {
    usage(0);
}

$app = trim((string) ($opts['app'] ?? ''));
if ($app === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $app)) {
    fwrite(STDERR, "ERROR: --app <slug> is required and must match ^[a-z][a-z0-9_]*$\n\n");
    usage(2);
}

$projDir = $root . '/projects/' . $app . '/';
if (!is_dir($projDir)) {
    fwrite(STDERR, "ERROR: no application at {$projDir}\n");
    exit(2);
}

$asJson = isset($opts['json']);
$quiet  = isset($opts['quiet']);

$prefixes = [];
if (isset($opts['prefix'])) {
    $prefixes = is_array($opts['prefix']) ? $opts['prefix'] : [$opts['prefix']];
    $prefixes = array_map('strval', $prefixes);
}

// Constants a few lib classes still read from the global scope.
define('APP', $app);
define('PREFIX', '');
define('PROJ_DIR', $projDir);

try {
    $db = new \DB\DB($app);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: cannot open database for '{$app}': " . $e->getMessage() . "\n");
    exit(2);
}

$baseline = null;
if (isset($opts['baseline'])) {
    $path = (string) $opts['baseline'];
    $abs  = $path[0] === '/' ? $path : $root . '/' . ltrim($path, './');
    if (!is_file($abs)) {
        fwrite(STDERR, "ERROR: baseline file not found: {$abs}\n");
        exit(2);
    }
    try {
        $baseline = new \DB\DB('baseline_v4', ['db_engine' => 'sqlite', 'db_path' => $abs]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "ERROR: cannot open baseline SQLite: " . $e->getMessage() . "\n");
        exit(2);
    }
}

// Config is optional — it only powers the config↔DB alignment check.
$cfg = null;
try {
    $cfg = new \Config\Config(new \Adbar\Dot(), $projDir, $db);
} catch (\Throwable $e) {
    fwrite(STDERR, "WARNING: could not load Config (config↔DB check will be skipped): "
        . $e->getMessage() . "\n");
}

$report = (new \DB\Verify\MigrationVerifier($db, [
    'baseline'       => $baseline,
    'cfg'            => $cfg,
    'projDir'        => $projDir,
    'legacyPrefixes' => $prefixes,
]))->run();

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($report['summary']['ok'] ? 0 : 1);
}

// ── Human-readable report ──────────────────────────────────────────────────
$tty = stream_isatty(STDOUT);
$c = static fn(string $s, string $code): string => $tty ? "\033[{$code}m{$s}\033[0m" : $s;
$mark = [
    'pass' => $c('PASS', '0;32'),
    'warn' => $c('WARN', '1;33'),
    'fail' => $c('FAIL', '0;31'),
    'skip' => $c('skip', '0;90'),
];

if (!$quiet) {
    echo "\nMigration verification — app '{$app}'"
        . ($report['baseline_used'] ? " (with v4 baseline)" : " (no baseline)") . "\n";
    echo str_repeat('─', 64), "\n";
    foreach ($report['checks'] as $ch) {
        printf("  %s  %s\n", $mark[$ch['status']] ?? $ch['status'], $ch['title']);
        if ($ch['detail'] !== '') {
            echo '        ', $c($ch['detail'], '0;90'), "\n";
        }
        $shown = array_slice($ch['items'], 0, 20);
        foreach ($shown as $item) {
            echo '        • ', $item, "\n";
        }
        if (count($ch['items']) > count($shown)) {
            echo '        ', $c('… and ' . (count($ch['items']) - count($shown)) . ' more (use --json for the full list)', '0;90'), "\n";
        }
    }
    echo str_repeat('─', 64), "\n";
}

$s = $report['summary'];
$line = sprintf(
    "%d passed · %d warning(s) · %d failed · %d skipped",
    $s['passed'], $s['warnings'], $s['failed'], $s['skipped']
);
echo $s['ok'] ? $c("✔ {$line}", '0;32') : $c("✖ {$line}", '0;31');
echo "\n\n";

exit($s['ok'] ? 0 : 1);
