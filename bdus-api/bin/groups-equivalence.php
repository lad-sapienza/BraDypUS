#!/usr/bin/env php
<?php

/**
 * bin/groups-equivalence.php — read-only comparison of privilege levels and the
 * system groups that stand for their data side (Reader, Self writer, Writer).
 *
 * For every user at level 30, 25 or 20 it checks whether the same user at level
 * Enter in the equivalent group would see and change exactly the same rows on
 * every table. Nothing is written. Exit code: 0 = every comparable user is
 * equivalent, 1 = at least one difference (or a missing group), 2 = bad usage.
 *
 *   docker compose exec -u www-data api php bin/groups-equivalence.php --app paths
 *
 * Options:
 *   --app <slug>   (required) application under projects/
 *   --user <id>    only this user (id)
 *   --json         print the full machine-readable report
 *   --quiet        print only the summary line
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "groups-equivalence.php must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__);
chdir($root);
define('MAIN_DIR', $root . '/');
require $root . '/vendor/autoload.php';

date_default_timezone_set('Europe/Rome');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

$opts = getopt('h', ['help', 'app:', 'user:', 'json', 'quiet']);
if (isset($opts['h']) || isset($opts['help'])) {
    echo "Usage: php bin/groups-equivalence.php --app <slug> [--user <id>] [--json] [--quiet]\n";
    exit(0);
}

$app = trim((string) ($opts['app'] ?? ''));
if ($app === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $app)) {
    fwrite(STDERR, "ERROR: --app <slug> is required and must match ^[a-z][a-z0-9_]*$\n");
    exit(2);
}
$projDir = $root . '/projects/' . $app . '/';
if (!is_dir($projDir)) {
    fwrite(STDERR, "ERROR: no application at {$projDir}\n");
    exit(2);
}

define('APP', $app);
define('PREFIX', '');
define('PROJ_DIR', $projDir);

try {
    $db  = new \DB\DB($app);
    $cfg = new \Config\Config(new \Adbar\Dot(), $projDir, $db);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: cannot open '{$app}': " . $e->getMessage() . "\n");
    exit(2);
}

$report = (new \Auth\Verify\LevelGroupComparison($db, $cfg))->run();

if (isset($opts['user'])) {
    $only = (int) $opts['user'];
    $report['users'] = array_values(array_filter($report['users'], fn($u) => $u['id'] === $only));
}

if (isset($opts['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($report['summary']['ok'] ? 0 : 1);
}

$s     = $report['summary'];
$quiet = isset($opts['quiet']);
$names = [1 => 'super admin', 10 => 'admin', 20 => 'writer', 25 => 'self writer', 30 => 'reader', 39 => 'enter', 40 => 'waiting'];

if (!$quiet) {
    echo "\nLevels vs system groups — app '{$app}'\n", str_repeat('─', 64), "\n";
    foreach ($report['groups'] as $name => $id) {
        echo sprintf("  group %-12s %s\n", $name, $id === null ? 'MISSING (run the minor upgrade: M049/M050)' : "id {$id}");
    }
    echo "\n";
    foreach ($report['levels'] as $level => $l) {
        $eq = $l['group'] === null ? 'stays a level' : ($l['equivalent'] ? "= group {$l['group']}" : "≠ group {$l['group']}");
        echo sprintf("  level %-3d %-12s %3d user(s)   %s\n", $level, $names[$level] ?? '?', $l['users'], $eq);
    }
    $shown = 0;
    foreach ($report['users'] as $u) {
        if ($u['equivalent'] !== false) {
            continue;
        }
        echo "\n  user #{$u['id']} {$u['name']} <{$u['email']}> (level {$u['level']}) differs from group {$u['group']}:\n";
        foreach (array_slice($u['differences'], 0, 15) as $d) {
            echo $d['kind'] === 'gate'
                ? "    • {$d['table']} {$d['action']}: gate by level " . ($d['level_only'] ? 'yes' : 'no') . ', by group ' . ($d['group_only'] ? 'yes' : 'no') . "\n"
                : "    • {$d['table']} {$d['action']}: {$d['level_only']} row(s) only by level, {$d['group_only']} only by group\n";
        }
        if (count($u['differences']) > 15) {
            echo '    … and ' . (count($u['differences']) - 15) . " more (use --json)\n";
        }
        if (++$shown >= 20) {
            echo "\n  … more users differ (use --json)\n";
            break;
        }
    }
    foreach ($report['keys'] as $k) {
        echo "\n  API key #{$k['id']} \"{$k['label']}\" at level {$k['level']} has no group (convert it by hand)";
    }
    foreach ($report['notes'] as $n) {
        echo "\n  note: {$n}";
    }
    echo "\n", str_repeat('─', 64), "\n";
}

echo sprintf(
    "%s %d user(s): %d comparable, %d equivalent, %d different%s\n\n",
    $s['ok'] ? '✔' : '✖', $s['users'], $s['comparable'], $s['equivalent'], $s['different'],
    $s['missing_groups'] ? '; missing groups: ' . implode(', ', $s['missing_groups']) : ''
);
exit($s['ok'] ? 0 : 1);
