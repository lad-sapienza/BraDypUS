<?php

/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * v5 migration:
 *   - listBackups()     replaces list_all_backups() (JSON instead of Twig HTML)
 *   - downloadBackup()  new: streams file directly instead of window.open(filesystem_path)
 *   - doBackup()        kept; removed hardcoded PostgreSQL binary path (uses system PATH)
 *   - deleteBackup()    kept; response format updated to v5 convention
 *   - restoreBackup()   kept; response format updated to v5 convention
 */

use \Spatie\DbDumper\Databases\MySql;
use \Spatie\DbDumper\Databases\PostgreSql;
use \Spatie\DbDumper\Compressors\GzipCompressor;

class backup_ctrl extends Controller
{
  /**
   * Returns a JSON list of available backup files with metadata and privilege flags.
   *
   * GET ?obj=backup_ctrl&method=listBackups
   *
   * Response:
   * {
   *   engine:       string,          // current DB engine
   *   can_delete:   bool,            // utils::canUser('admin')
   *   can_restore:  bool,            // utils::canUser('super_admin') && engine !== 'pgsql'
   *   backups: [
   *     { file, app, engine, timestamp, formatted_time, size_mb, gz }
   *   ]
   * }
   */
  public function listBackups(): void
  {
    if (!\utils::canUser('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $engine = $this->db->getEngine();
    $files  = \utils::dirContent(PROJ_DIR . 'backups') ?: [];

    $backups = [];
    foreach ($files as $file) {
      $info         = $this->parseFileName($file);
      $info['file'] = $file;
      $info['size_mb'] = round(
        filesize(PROJ_DIR . 'backups/' . $file) / 1024 / 1024,
        3
      );
      $backups[] = $info;
    }

    // Sort newest first
    usort($backups, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

    $this->returnJson([
      'engine'      => $engine,
      'can_delete'  => \utils::canUser('admin'),
      'can_restore' => \utils::canUser('super_admin') && $engine !== 'pgsql',
      'backups'     => $backups,
    ]);
  }

  /**
   * Creates a compressed SQL backup and saves it to the project backups/ folder.
   *
   * GET ?obj=backup_ctrl&method=doBackup
   *
   * Response: { status, code }
   */
  public function doBackup(): void
  {
    if (!\utils::canUser('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $file = $this->buildFileName();

      switch ($this->db->getEngine()) {
        case 'mysql':
          $bup = MySql::create()
            ->setDbName($this->cfg->get('main.db_name'))
            ->setUserName($this->cfg->get('main.db_username'))
            ->setPassword($this->cfg->get('main.db_password'));

          $host = $this->cfg->get('main.db_host');
          if ($host && $host !== '') {
            $bup->setHost($host);
          }
          break;

        case 'pgsql':
          // Binary path NOT set: Spatie uses pg_dump from the system PATH.
          // This works in Docker, Linux, and on any system where pg_dump is
          // in PATH — unlike the previous hardcoded /Applications/Postgres.app path.
          $bup = PostgreSql::create()
            ->setDbName($this->cfg->get('main.db_name'))
            ->setUserName($this->cfg->get('main.db_username'))
            ->setPassword($this->cfg->get('main.db_password'));

          $host = $this->cfg->get('main.db_host');
          if ($host && $host !== '') {
            $bup->setHost($host);
          }
          break;

        case 'sqlite':
          // Spatie's SQLite dumper requires the external `sqlite3` CLI binary,
          // which is not available in Docker. Use a native PHP/PDO dumper instead.
          $this->dumpSqliteNative($file);
          $this->returnJson(['status' => 'success', 'code' => 'ok_backup']);
          return;

        default:
          throw new \Exception('Unknown or unsupported database engine: ' . $this->db->getEngine());
      }

      $bup->useCompressor(new GzipCompressor())
          ->dumpToFile($file);

      $this->returnJson(['status' => 'success', 'code' => 'ok_backup']);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_backup', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Streams a backup file as a download attachment.
   *
   * GET ?obj=backup_ctrl&method=downloadBackup&file=FILENAME
   *
   * Only filenames (no paths) are accepted; the file is served from
   * the project backups/ folder. Terminates execution after streaming.
   */
  public function downloadBackup(): void
  {
    if (!\utils::canUser('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    // Accept only a bare filename — no directory traversal
    $file = basename($this->get['file'] ?? '');
    if (!$file) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $path = PROJ_DIR . 'backups/' . $file;

    if (!file_exists($path)) {
      $this->returnJson(['status' => 'error', 'code' => 'file_not_found']);
      return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($path);
  }

  /**
   * Deletes a backup file.
   *
   * GET ?obj=backup_ctrl&method=deleteBackup&file=FILENAME
   *
   * Response: { status, code }
   */
  public function deleteBackup(): void
  {
    if (!\utils::canUser('admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $file = basename($this->get['file'] ?? '');
    if (!$file) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $path = PROJ_DIR . 'backups/' . $file;

    try {
      if (!file_exists($path)) {
        throw new \Exception('File not found');
      }
      if (!@unlink($path)) {
        throw new \Exception("Error erasing file: {$file}");
      }
      $this->returnJson(['status' => 'success', 'code' => 'success_erasing_file']);
    } catch (\Exception $e) {
      $this->returnJson(['status' => 'error', 'code' => 'error_erasing_file', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Restores a backup file into the current database.
   * Restricted to super_admin + non-PostgreSQL engines.
   *
   * GET ?obj=backup_ctrl&method=restoreBackup&file=FILENAME
   *
   * Response: { status, code }
   */
  public function restoreBackup(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if ($this->db->getEngine() === 'pgsql') {
      $this->returnJson(['status' => 'error', 'code' => 'restore_not_supported_pgsql']);
      return;
    }

    $file = basename($this->get['file'] ?? '');
    if (!$file) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $info = $this->parseFileName($file);

      if (isset($info['engine']) && $info['engine'] !== $this->db->getEngine()) {
        $this->returnJson([
          'status' => 'error',
          'code'   => 'wrong_restore_engine',
          'detail' => $info['engine'] . ' → ' . $this->db->getEngine(),
        ]);
        return;
      }

      $restore = new bigRestore($this->db, $this->db->getEngine() === 'sqlite');
      $restore->runImport(PROJ_DIR . 'backups/' . $file);

      $this->returnJson(['status' => 'success', 'code' => 'ok_backup_restored']);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_backup_not_restored', 'detail' => $e->getMessage()]);
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  /**
   * Builds the absolute destination path for a new backup file.
   * Pattern: {PROJ_DIR}backups/{app}-{engine}-{timestamp}.sql.gz
   */
  private function buildFileName(): string
  {
    return PROJ_DIR . 'backups/' . implode('-', [
      $this->cfg->get('main.name'),
      $this->db->getEngine(),
      (new DateTime())->getTimestamp(),
    ]) . '.sql.gz';
  }

  /**
   * Pure-PHP SQLite dumper — no external binaries required.
   *
   * Opens the project SQLite file via PDO and writes a gzip-compressed SQL dump
   * with DROP/CREATE for every table, INSERT for every row, plus indexes/views/triggers.
   *
   * @throws \Exception on PDO errors or filesystem failures
   */
  private function dumpSqliteNative(string $outputFile): void
  {
    $dbPath = PROJ_DIR . 'db/bdus.sqlite';

    if (!file_exists($dbPath)) {
      throw new \Exception("SQLite database file not found: {$dbPath}");
    }

    $pdo = new \PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    // Level 6 = default gzip compression: good ratio, much faster than 9.
    $gz = gzopen($outputFile, 'wb6');
    if (!$gz) {
      throw new \Exception("Cannot create backup file: {$outputFile}");
    }

    // Helper: flush a string buffer to the gzip stream and reset it.
    $flush = function (string &$buf) use ($gz): void {
      if ($buf !== '') {
        gzwrite($gz, $buf);
        $buf = '';
      }
    };

    $buf  = '';
    $buf .= "-- BraDypUS SQLite backup\n";
    $buf .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $buf .= "-- Source: {$dbPath}\n\n";
    $buf .= "BEGIN TRANSACTION;\n\n";

    // Read all schema objects that have SQL (skip internal sqlite_ entries)
    $objects = $pdo->query(
      "SELECT type, name, sql FROM sqlite_master " .
      "WHERE sql IS NOT NULL ORDER BY type DESC, name ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Pass 1: tables — structure + cursor-based row iteration (no fetchAll)
    foreach ($objects as $obj) {
      if ($obj['type'] !== 'table') {
        continue;
      }
      if (str_starts_with($obj['name'], 'sqlite_')) {
        continue;
      }

      $qName = $this->quoteSqliteId($obj['name']);

      $buf .= "-- Table: {$obj['name']}\n";
      $buf .= "DROP TABLE IF EXISTS {$qName};\n";
      $buf .= $obj['sql'] . ";\n\n";
      $flush($buf);

      // Cursor-based: never loads the full table into memory.
      $stmt    = $pdo->query("SELECT * FROM {$qName}");
      $hasRows = false;
      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        if (!$hasRows) {
          $cols    = implode(', ', array_map([$this, 'quoteSqliteId'], array_keys($row)));
          $hasRows = true;
        }
        $vals  = implode(', ', array_map([$this, 'quoteSqliteVal'], array_values($row)));
        $buf  .= "INSERT INTO {$qName} ({$cols}) VALUES ({$vals});\n";
        // Flush every ~256 KB to keep memory flat.
        if (strlen($buf) >= 262144) {
          $flush($buf);
        }
      }
      if ($hasRows) {
        $buf .= "\n";
        $flush($buf);
      }
    }

    // Pass 2: indexes, views, triggers
    foreach ($objects as $obj) {
      if ($obj['type'] === 'table') {
        continue;
      }
      if (str_starts_with($obj['name'], 'sqlite_')) {
        continue;
      }
      $buf .= "-- {$obj['type']}: {$obj['name']}\n";
      $buf .= $obj['sql'] . ";\n\n";
    }

    $buf .= "COMMIT;\n";
    $flush($buf);
    gzclose($gz);
  }

  /**
   * Wraps an SQLite identifier in double-quotes, escaping any embedded double-quotes.
   */
  private function quoteSqliteId(string $name): string
  {
    return '"' . str_replace('"', '""', $name) . '"';
  }

  /**
   * Serialises a PHP value to a SQL literal suitable for SQLite INSERT statements.
   * NULL → NULL, integers/floats → unquoted, everything else → single-quoted with
   * internal quotes doubled.
   */
  private function quoteSqliteVal(mixed $val): string
  {
    if ($val === null) {
      return 'NULL';
    }
    if (is_int($val) || is_float($val)) {
      return (string) $val;
    }
    return "'" . str_replace("'", "''", (string) $val) . "'";
  }

  /**
   * Parses backup metadata from the filename convention {app}-{engine}-{ts}.sql[.gz].
   * Returns a partial array for unrecognised filenames (legacy or manual files).
   */
  private function parseFileName(string $filename): array
  {
    $filename = trim($filename);
    $gz       = false;

    if (str_ends_with($filename, '.sql.gz')) {
      $gz   = true;
      $base = substr($filename, 0, -7);       // strip .sql.gz
    } elseif (str_ends_with($filename, '.sql')) {
      $base = substr($filename, 0, -4);       // strip .sql
    } else {
      return ['app' => $filename];            // unrecognised format
    }

    $parts = explode('-', $base, 3);          // app, engine, timestamp
    if (count($parts) !== 3) {
      return ['app' => $base];
    }

    [$app, $engine, $ts] = $parts;

    return [
      'app'            => $app,
      'engine'         => $engine,
      'timestamp'      => (int)$ts,
      'formatted_time' => (new DateTime("@{$ts}"))->format('Y-m-d H:i:s'),
      'gz'             => $gz,
    ];
  }
}
