#!/usr/bin/env php
<?php

/**
 * bin/files-backfill-uuid.php — repair the file addresses of one application.
 *
 * Files are addressed by a random uuid and stored as projects/{app}/files/{uuid}.{ext} (migration M060).
 * A row that reaches `bdus_files` without a uuid (a tool that inserts into the table directly, a restore
 * of an old dump) cannot be served: this gives it one and renames its binary. It is the work of M060,
 * repeatable and safe: only what is missing is done, nothing is overwritten.
 *
 *   docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths --dry-run
 *   docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths
 *   docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths --scan-urls
 *
 * It does NOT take a backup (take one first: it renames files).
 *
 * Options:
 *   --app <slug>   the application under projects/ (required)
 *   --dry-run      say what would be done, change nothing
 *   --scan-urls    only look for old-style file addresses (projects/{app}/files/{id}.ext,
 *                  /api/file/{preset}/{id}.webp) in the text fields, templates and welcome text; read-only
 *
 * Exit code: 0 ok, 1 something could not be done (a rename failed), 2 bad invocation.
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "files-backfill-uuid.php must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__);
chdir($root);
define('MAIN_DIR', $root . '/');

require $root . '/vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

$opts = getopt('h', ['help', 'app:', 'dry-run', 'scan-urls']);
if (isset($opts['h']) || isset($opts['help'])) {
    echo "Usage: php bin/files-backfill-uuid.php --app <slug> [--dry-run | --scan-urls]\n";
    exit(0);
}

$app = isset($opts['app']) ? trim((string) $opts['app']) : '';
if ($app === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $app) || !is_dir($root . '/projects/' . $app)) {
    fwrite(STDERR, "ERROR: --app <slug> is required and must be an application under projects/\n");
    exit(2);
}

$projDir = $root . '/projects/' . $app . '/';
define('APP', $app);
define('PREFIX', '');
define('PROJ_DIR', $projDir);

try {
    $db = new \DB\DB($app);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: cannot open the database of '{$app}': " . $e->getMessage() . "\n");
    exit(1);
}

if (!(new \DB\System\Manage($db))->tableExists('bdus_files')) {
    fwrite(STDERR, "ERROR: '{$app}' has no bdus_files table\n");
    exit(1);
}
if (!(new \DB\System\Manage($db))->columnExists('bdus_files', 'uuid')) {
    fwrite(STDERR, "ERROR: '{$app}' has no bdus_files.uuid yet: upgrade the application first (bin/upgrade-app.php --app {$app})\n");
    exit(1);
}

if (isset($opts['scan-urls'])) {
    $cfg   = new \Config\Config(new \Adbar\Dot(), $projDir, $db);
    $found = \File\LegacyUrls::scan($db, $cfg);
    if (!$found) {
        echo "{$app}: no old-style file addresses found in the data\n";
        exit(0);
    }
    echo "{$app}: " . count($found) . " place(s) with old-style file addresses (they no longer work: use the file's uuid)\n";
    foreach ($found as $f) {
        echo '  ' . $f['where'] . ($f['id'] !== null ? '#' . $f['id'] : '') . '.' . $f['field'] . ': ' . implode(', ', $f['found']) . "\n";
    }
    exit(0);
}

if (isset($opts['dry-run'])) {
    $plan = \File\UuidBackfill::plan($db, $projDir);
    echo "{$app}: {$plan['without_uuid']} file(s) without a uuid, {$plan['to_rename']} binary(ies) to rename, "
       . "{$plan['missing']} binary(ies) missing on disk (dry run, nothing changed)\n";
    exit(0);
}

try {
    $done = \File\UuidBackfill::run($db, $projDir);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

echo "{$app}: {$done['assigned']} uuid(s) assigned, {$done['renamed']} binary(ies) renamed, "
   . "{$done['missing']} missing on disk, {$done['failed']} failed\n";
exit($done['failed'] > 0 ? 1 : 0);
