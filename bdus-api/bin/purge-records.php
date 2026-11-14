#!/usr/bin/env php
<?php

/**
 * bin/purge-records.php — retention: delete or anonymise old records and their history.
 *
 * Deleting a record leaves a full snapshot of it (every field, personal contacts
 * included) in the version history, and each later edit adds another. This command
 * removes the records, or blanks chosen fields, AND the snapshots that hold the old
 * values. "Older than" is measured on a date field of the table: records carry no
 * creation date of their own.
 *
 *   php bin/purge-records.php --app edr --table richieste --date-field data_rich --older-than 365
 *   php bin/purge-records.php --app edr --table richieste --date-field data_rich --older-than 365 \
 *       --anonymize-fields email_rich,messaggio --yes
 *
 * Without --yes it is a dry run: it counts and writes nothing. Run it before
 * --yes, and take a backup first (`bdus backup`): a purge cannot be undone.
 *
 *   --app <slug>             application under projects/
 *   --table <name>           a main table (not a plugin table, not a view)
 *   --date-field <field>     a `date` field of the table; rows with a date before today - N days match
 *   --older-than <days>      N, 1 or more
 *   --anonymize-fields a,b   blank these fields instead of deleting the records
 *   --yes                    do it (default: dry run)
 *   --json                   machine-readable result
 *
 * Deleting also removes the plugin rows, links, stratigraphic relations and geodata
 * of the record. Attached files are unlinked but stay on disk.
 *
 * Exit code: 0 ok, 1 failed, 2 bad invocation.
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "purge-records.php must be run from the command line\n");
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
  php bin/purge-records.php --app <slug> --table <name> --date-field <field> --older-than <days>
                            [--anonymize-fields a,b] [--yes] [--json]

  Dry run unless --yes. Without --anonymize-fields the matching records are deleted.
  The version history of the affected records is always purged with them.

Exit code: 0 ok, 1 failed, 2 bad invocation.

TXT);
    exit($code);
}

$o = getopt('h', ['help', 'app:', 'table:', 'date-field:', 'older-than:', 'anonymize-fields:', 'yes', 'json']);
if (isset($o['h']) || isset($o['help'])) {
    usage(0);
}
$app   = trim((string) ($o['app'] ?? ''));
$tb    = trim((string) ($o['table'] ?? ''));
$date  = trim((string) ($o['date-field'] ?? ''));
$days  = (string) ($o['older-than'] ?? '');
$anon  = array_values(array_filter(array_map('trim', explode(',', (string) ($o['anonymize-fields'] ?? '')))));
$apply = isset($o['yes']);
$json  = isset($o['json']);

if (!preg_match('/^[a-z][a-z0-9_]*$/', $app) || $tb === '' || $date === '' || !ctype_digit($days)) {
    fwrite(STDERR, "ERROR: --app, --table, --date-field and --older-than (a number of days) are required\n\n");
    usage(2);
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
    $log = new \Monolog\Logger('bdus');
    $log->pushHandler(new \DB\LogDBHandler($db, \Monolog\Logger::INFO));
    $db->setLog($log);
    $cfg = new \Config\Config(new \Adbar\Dot(), $projDir, $db);

    $r = \Record\Purge::run($db, $cfg, $tb, $date, (int) $days, $anon, $apply);

    if ($r['applied']) {
        // counts only: the log never carries the data that was removed
        $log->info("Retention: {$r['action']} on {$tb}, date field {$date} before {$r['cutoff']}: {$r['matched']} records, {$r['versions']} history entries");
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $verb = $r['action'] === 'delete' ? 'delete' : 'anonymise';
    echo ($r['applied'] ? '' : '[dry run] would ') . "{$verb} {$r['matched']} record(s) of {$tb} dated before {$r['cutoff']}"
       . "; {$r['versions']} history entr" . ($r['versions'] === 1 ? 'y' : 'ies') . ' purged'
       . ($r['applied'] ? '' : " — add --yes to do it") . "\n";
}
exit(0);
