#!/usr/bin/env php
<?php

/**
 * bin/create-app.php — create a BraDypUS application from the command line.
 *
 * Wraps DB\System\CreateApp directly: no HTTP, no BRADYPUS_ALLOW_NEW_APP gate,
 * no container restart, no exposure window. Run inside the api container:
 *
 *   docker compose exec api php bin/create-app.php \
 *     --name siti_scavo --engine sqlite --email admin@example.org --password-stdin
 *
 * For pgsql / mysql the target database must already exist — this script does
 * not create it (that is a deployment concern; see add-app.sh at the repo root).
 *
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "create-app.php must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__);
chdir($root);                          // CreateApp resolves projects/ relative to CWD
define('MAIN_DIR', $root . '/');       // used by Migrate::readCurrentVersion()

require $root . '/vendor/autoload.php';

date_default_timezone_set('Europe/Rome');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');

function usage(int $code = 0)
{
    fwrite($code === 0 ? STDOUT : STDERR, <<<TXT
Usage:
  php bin/create-app.php --name <slug> --engine <sqlite|pgsql|mysql> \\
      --email <admin-email> (--password-stdin | --password <pw>) [--definition "<text>"]

For pgsql / mysql also pass (the database must already exist):
  --db-host <h> --db-port <p> --db-name <n> --db-user <u> [--db-pass <pw> | env BDUS_DB_PASS]

  --password-stdin   read the admin password from the first line of stdin (preferred)
  --password <pw>    inline — visible in `ps` / shell history, avoid

TXT);
    exit($code);
}

$opts = getopt('h', [
    'help', 'name:', 'engine:', 'email:', 'definition:',
    'password:', 'password-stdin',
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:',
]);

if (isset($opts['h']) || isset($opts['help'])) {
    usage(0);
}

$name   = trim((string) ($opts['name']   ?? ''));
$engine = trim((string) ($opts['engine'] ?? ''));
$email  = trim((string) ($opts['email']  ?? ''));
$defn   = trim((string) ($opts['definition'] ?? '')) ?: $name;

// ── admin password ─────────────────────────────────────────────────────────
$password = '';
if (array_key_exists('password-stdin', $opts)) {
    $password = rtrim((string) fgets(STDIN), "\r\n");
} elseif (isset($opts['password'])) {
    $password = (string) $opts['password'];
} elseif (stream_isatty(STDIN)) {
    fwrite(STDERR, 'Admin password: ');
    shell_exec('stty -echo 2>/dev/null');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
}

// ── validation ─────────────────────────────────────────────────────────────
$errors = [];
if ($name === '') {
    $errors[] = '--name is required';
} elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
    $errors[] = '--name must match ^[a-z][a-z0-9_]*$';
}
if ($engine === '') {
    $errors[] = '--engine is required';
} elseif (!in_array($engine, \DB\Engines\AvailableEngines::getList(), true)) {
    $errors[] = "--engine '{$engine}' is not available (have: "
        . implode(', ', \DB\Engines\AvailableEngines::getList()) . ')';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = '--email must be a valid address';
}
if ($password === '') {
    $errors[] = 'admin password is required (--password-stdin or --password)';
}

$dbHost = $dbPort = $dbName = $dbUser = $dbPass = null;
if ($engine !== '' && $engine !== 'sqlite') {
    $dbHost = trim((string) ($opts['db-host'] ?? ''));
    $dbPort = trim((string) ($opts['db-port'] ?? ($engine === 'pgsql' ? '5432' : '3306')));
    $dbName = trim((string) ($opts['db-name'] ?? ''));
    $dbUser = trim((string) ($opts['db-user'] ?? ''));
    $dbPass = (string) ($opts['db-pass'] ?? getenv('BDUS_DB_PASS') ?: '');
    foreach (['db-host' => $dbHost, 'db-name' => $dbName, 'db-user' => $dbUser, 'db-pass' => $dbPass] as $k => $v) {
        if ($v === '') {
            $errors[] = "--{$k} is required for engine {$engine}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, "ERROR:\n  - " . implode("\n  - ", $errors) . "\n\n");
    usage(2);
}

// ── create ────────────────────────────────────────────────────────────────
try {
    $app = new \DB\System\CreateApp(
        $name,
        $defn,
        $email,
        $password,
        $engine,
        $dbHost,
        $dbPort,
        $dbName,
        $dbUser,
        $dbPass
    );
    $app->createAll();

    foreach ($app->getLog() as $line) {
        fwrite(STDOUT, "  {$line}\n");
    }
    fwrite(STDOUT, "OK: application '{$name}' created ({$engine})\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
