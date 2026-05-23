<?php

/**
 * Main database connection class.
 * Catches and logs all PDO Exceptions and throws DBExceptions in case of errors

 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB;

use DB\DBInterface;
use DB\DBException;
use Monolog\Logger;

class DB implements DBInterface
{

  /**
   *
   * Database instance
   * @var object
   */
  private $pdo;

  /**
   * Database engine: sqlite, mysql or pgsql
   *
   * @var string
   */
  private $db_engine;

  /**
   * Application name/identifier
   *
   * @var string
   */
  private $app;

  /**
   * Log object
   *
   * @var Logger
   */
  private $log;

  /**
   * Full path to application root
   *
   * @var string
   */
  private $path_to_root;

  /**
   *
   * Loads connection info and starts PDO object
   * 
   * @param string $app	application to work with
   * @param array $custom_connection
   * 		db_engine
   * 		db_path, for sqlite
   * 		db_name, for mysql and pgsql
   * 		db_username, for mysql and pgsql
   * 		db_password, for mysql and pgsql
   * 		db_host, for mysql and pgsql
   * 		db_port, for mysql and pgsql, optional
   * @throws DBException
   */
  public function __construct(string $app = null, array $custom_connection = null)
  {
    $this->path_to_root = __DIR__ . '/../../';
    $this->app = $app;

    if (!$this->app) {
      throw new DBException("No valid app provided: cannot start database object");
    }
    if ($custom_connection) {
      $cfg = $custom_connection;
    } else if ($this->app) {
      $cfg = $this->getConnectionDataFromCfg($this->app);
    } else {
      throw new DBException("Cannot resolve DB connection information");
    }

    list($db_engine, $dsn, $username, $password) = $this->validateConnectionData($cfg);

    $this->initializePDO($db_engine, $dsn, $username, $password);
  }

  /**
   * Sets Logger
   *
   * @param Logger $log
   * @return void
   */
  public function setLog(Logger $log): void
  {
    $this->log = $log;
  }

  /**
   * Returns current app name
   *
   * @return string
   */
  public function getApp(): string
  {
    return $this->app;
  }

  /**
   * Returns current database engine
   * 
   * @return string
   */
  public function getEngine(): string
  {
    return $this->db_engine;
  }

  /**
   * Executes SQL inside a transaction
   *
   * @param string $sql
   * @return boolean
   */
  public function execInTransaction(string $sql): bool
  {
    $ret = false;
    try {
      $this->pdo->beginTransaction();
      $ret = $this->pdo->exec($sql);
      $this->pdo->commit();
    } catch (DBException $th) {
      $this->pdo->rollBack();
      $this->log->error($th);
      // Already logged
    } catch (\Throwable $th) {
      $this->pdo->rollBack();
      $this->log->error($th);
    }
    return ($ret !== false);
  }

  /**
   * Executes SQL
   *
   * @param string $sql
   * @return boolean
   */
  public function exec(string $sql): bool
  {
    try {
      return $this->pdo->exec($sql) !== false;
    } catch (\PDOException $e) {
      if ($this->log) {
        $this->log->error($e, [$sql]);
      }
      throw new DBException($e);
    }
  }

  /**
   * Saves a full record snapshot to bdus_versions before a write operation.
   *
   * The caller (Record\Persist) is responsible for building $content, because
   * plugin data requires Config knowledge unavailable at the DB layer:
   *
   *   $content = [
   *     'core'    => ['id' => 1, 'name' => 'Alpha', …],
   *     'plugins' => ['tags' => [['id' => 3, 'label' => 'foo', …], …]],
   *   ]
   *
   * @param string $tb        Table name (user-data table, not a system table).
   * @param int    $id        Primary key of the record being modified.
   * @param array  $content   Full snapshot: {core: {...}, plugins: {tb: [rows]}}.
   * @param string $operation One of 'update' | 'delete' | 'restore'.
   */
  public function saveSnapshot(
    string $tb,
    int $id,
    array $content,
    string $operation = 'update'
  ): void {
    try {
      $dt = new \DateTime();
      // editsql and editvalues are legacy columns that may carry NOT NULL on
      // older databases; pass empty strings to satisfy that constraint.
      // They are unused by the snapshot system (all data lives in `content`).
      $this->query(
        "INSERT INTO bdus_versions (userid, time, tb, rowid, content, editsql, editvalues, operation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
          \Auth\CurrentUser::id(),
          $dt->format('U'),
          $tb,
          $id,
          json_encode($content, JSON_UNESCAPED_UNICODE),
          '',   // editsql  — legacy column, kept for schema compat
          '',   // editvalues — legacy column, kept for schema compat
          $operation,
        ]
      );
    } catch (DBException $e) {
      // Already logged by DB::run().
    } catch (\Throwable $th) {
      if ($this->log) {
        $this->log->error($th);
      }
    }
  }

  /**
   * Prepares and runs a query statement and returns, dependin on $type:
   * 		array with output if read or false
   * 		last inserted id id id
   * 		boolean if boolean
   * Uses prepare and execute statement.
   * 
   * @param string $query			query string
   * @param array $values			values to use with query string
   * @param string $type			one of read (default value) | id | boolean | affected, integer, or false
   * @param boolean $fetch_style	if false an associative array will be returned else a numeric array
   */
  public function query(
    string $query,
    array $values = null,
    string $type = null,
    bool $fetch_style = false
  ) {
    try {

      $query = trim($query);

      $sql = $this->pdo->prepare($query);

      if (!$values) $values = [];

      $flag = $sql->execute($values);

      if (is_int($type)) {
        return $sql->fetchColumn($type);
      }

      switch ($type) {
        case 'boolean':
          return $flag;
          break;

        case 'read':
        case false:
        default:
          $fetch_style  = $fetch_style ? \PDO::FETCH_NUM : \PDO::FETCH_ASSOC;
          return $sql->fetchAll($fetch_style);
          break;

        case 'id':
          return $this->pdo->lastInsertId();
          break;

        case 'affected':
          return $sql->rowCount();
          break;
      }
    } catch (\PDOException $e) {
      if ($this->log) {
        $this->log->error($e, [$query, $values, $type, $fetch_style]);
      }
      // Pass PDOException as $previous so callers can inspect the original message
      throw new DBException('Database error', 0, $e);
    }
  }

  /**
   *
   * Starts a transaction
   * 
   * @return void
   */
  public function beginTransaction(): void
  {
    $this->pdo->beginTransaction();
  }

  /**
   * Commits a started transaction
   * 
   * @return void
   */
  public function commit(): void
  {
    $this->pdo->commit();
  }

  /**
   * Rolls back a started transaction
   * 
   * @return void
   */
  public function rollBack(): void
  {
    $this->pdo->rollBack();
  }

  /**
   * Parses and alidates configuration data from configuration array
   *
   * @param array $cfg
   * @return array
   */
  private function validateConnectionData(array $cfg): array
  {
    if (!$cfg['db_engine']) {
      throw new DBException('Missing database engine configuration');
    }

    if (!in_array($cfg['db_engine'], ['sqlite', 'mysql', 'pgsql'])) {
      throw new DBException("Database engine '{$cfg['db_engine']}' is not supported");
    }

    // Set DSN for sqlite
    if ($cfg['db_engine'] === 'sqlite') {
      if (!$cfg['db_path']) {
        throw new DBException('Missing SQLite file path');
      }
      $dsn = "sqlite:{$cfg['db_path']}";
    }

    // For engines other then sqlite, db_name, db_username, db_password are required
    if (!$dsn) {

      if (!$cfg['db_name']) {
        throw new DBException('Missing database name');
      }

      if (!$cfg['db_username']) {
        throw new DBException('Missing database username');
      }

      if (!$cfg['db_password']) {
        throw new DBException('Missing database password');
      }

      if (!$cfg['db_host']) {
        $cfg['db_host'] = '127.0.0.1';
      }

      $dsn = "{$cfg['db_engine']}:host={$cfg['db_host']};dbname={$cfg['db_name']};" .
        ($cfg['db_port'] ?  "port={$cfg['db_port']};" : '') .
        "options='--client_encoding=UTF8'";
    }

    if (!$cfg['db_engine'] || !$dsn) {
      throw new DBException('Not found any connection data');
    }

    return [
      $cfg['db_engine'],
      $dsn,
      $cfg['db_username'] ?? null,
      $cfg['db_password'] ?? null
    ];
  }

  /**
   * Parses connection data from configuration file
   * and returns array of connection data:
   *
   * @param string $app
   * @return array
   */
  private function getConnectionDataFromCfg(string $app): array
  {
    $cfg = [];
    // M016 renames app_data.json → config.json at migration time.
    // Fall back to the legacy name for apps that haven't migrated yet.
    $file = $this->path_to_root . "projects/{$app}/cfg/config.json";
    if (!file_exists($file)) {
      $file = $this->path_to_root . "projects/{$app}/cfg/app_data.json";
    }

    if (!file_exists($file)) {
      throw new \Exception("Missing configuration file for app '{$app}'");
    }

    $cfg = json_decode(file_get_contents($file), true);

    if (!is_array($cfg)) {
      throw new \Exception("Invalid configuration file: {$file}");
    }
    // One-time migration: legacy apps may not have db_engine set in config.
    // Only write the file when db_engine is actually missing (null), not on every request.
    if (null === $cfg['db_engine'] && file_exists($this->path_to_root . "projects/{$app}/db/bdus.sqlite")) {
      $cfg['db_engine'] = 'sqlite';
      file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT));
    }

    if (file_exists($this->path_to_root . "projects/{$app}/db/bdus.sqlite")) {
      $cfg['db_path'] = $this->path_to_root . "projects/{$app}/db/bdus.sqlite";
    }

    return $cfg;
  }

  /**
   * Parses connection data and initializes PDO
   * Throws DBException on error
   *
   * @param string $db_engine
   * @param string $dsn
   * @param string $user
   * @param string $password
   * @return void
   */
  private function initializePDO(
    string $db_engine,
    string $dsn,
    string $user = null,
    string $password = null
  ): void {
    try {
      $this->db_engine = $db_engine;

      /**
       *  Check if MYSQL_ATTR_INIT_COMMAND method exists (for systems without MySQL)
       *  http://stackoverflow.com/questions/2424343/undefined-class-constant-mysql-attr-init-command-with-pdo
       */

      $dbOptions = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false
      ];

      $this->pdo = new \PDO($dsn, $user, $password, $dbOptions);

      if ($this->db_engine == 'sqlite') {
        $this->pdo->query('PRAGMA encoding = "UTF-8"');
        $this->pdo->query('PRAGMA foreign_keys = ON');
      }
    } catch (\PDOException $e) {

      throw new DBException($e);
    }
  }

  /**
   * Checks is spatial extension is available
   *
   * @return boolean
   */
  public function hasSpatialExtension(): bool
  {
    try {
      $this->pdo->query("SELECT ST_GeomFromText('POINT(0 0)')");
      return true;
    } catch (\PDOException $e) {
      return false;
    }
  }
}
