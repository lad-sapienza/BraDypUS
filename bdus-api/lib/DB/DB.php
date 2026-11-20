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
      if ($this->pdo->inTransaction()) {
        $this->pdo->commit();
      }
    } catch (DBException $th) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      if ($this->log) { $this->log->error($th); }
    } catch (\Throwable $th) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      if ($this->log) { $this->log->error($th); }
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
   * @param string $operation One of 'update' | 'delete' | 'restore' | 'create'.
   */
  public function saveSnapshot(
    string $tb,
    int $id,
    array $content,
    string $operation = 'update'
  ): void {
    try {
      // editsql and editvalues are legacy columns that may carry NOT NULL on
      // older databases; pass empty strings to satisfy that constraint.
      // They are unused by the snapshot system (all data lives in `content`).
      $row = [
        'userid'     => \Auth\CurrentUser::id(),
        'time'       => time(),
        'tb'         => $tb,
        'rowid'      => $id,
        'content'    => json_encode($content, JSON_UNESCAPED_UNICODE),
        'editsql'    => '',
        'editvalues' => '',
        'operation'  => $operation,
      ];
      // Where the change came from (M053): the channel, and the API key or
      // the OAuth client that made it.
      if ($this->versionsHaveProvenance()) {
        $row['channel']   = \Auth\Channel::get();
        $row['key_id']    = \Auth\Channel::keyId();
        $row['client_id'] = \Auth\Channel::client();
      }
      $this->query(
        'INSERT INTO bdus_versions (' . implode(', ', array_keys($row)) . ') VALUES ('
          . implode(', ', array_fill(0, count($row), '?')) . ')',
        array_values($row)
      );
    } catch (DBException $e) {
      // Already logged by DB::run().
    } catch (\Throwable $th) {
      if ($this->log) {
        $this->log->error($th);
      }
    }
  }

  /** Does bdus_versions have the provenance columns of M053? Looked up once. */
  private ?bool $versionsProvenance = null;

  private function versionsHaveProvenance(): bool
  {
    if ($this->versionsProvenance === null) {
      // Asked of the catalogue directly, with a statement that cannot fail: this
      // runs inside the transaction of a write, and on PostgreSQL a failed
      // statement aborts the whole transaction. (DB\System\Manage is not used
      // here for that reason: building it probes for a spatial extension.)
      try {
        if ($this->getEngine() === 'sqlite') {
          $cols = array_column($this->query('PRAGMA table_info(bdus_versions)', [], 'read') ?: [], 'name');
          $this->versionsProvenance = in_array('channel', $cols, true);
        } else {
          $this->versionsProvenance = !empty($this->query(
            "SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
            ['bdus_versions', 'channel'],
            'read'
          ));
        }
      } catch (\Throwable) {
        $this->versionsProvenance = false;
      }
    }
    return $this->versionsProvenance;
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

      if ($this->db_engine === 'pgsql' && in_array(true, array_map('is_bool', $values), true)) {
        // execute($values) sends PHP false as '' (and true as '1'), which a
        // PostgreSQL boolean column refuses: bind booleans as booleans.
        $i = 0;
        foreach ($values as $k => $v) {
          $sql->bindValue(is_int($k) ? $i + 1 : $k, $v, is_bool($v) ? \PDO::PARAM_BOOL : \PDO::PARAM_STR);
          $i++;
        }
        $flag = $sql->execute();
      } else {
        $flag = $sql->execute($values);
      }

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
      throw DBException::fromPdo($e);
    }
  }

  /**
   * Like query() with type 'read', but yields the rows one at a time instead
   * of building the whole result in memory (large exports).
   *
   * Per engine: SQLite fetches row by row natively; MySQL/MariaDB run the
   * statement unbuffered; PostgreSQL (whose PDO driver always buffers the whole
   * result client-side) reads through a server-side cursor in chunks.
   *
   * The statement is executed when the generator is first advanced, so call
   * current() before sending output to surface SQL errors early. Do not run
   * other queries on this connection while iterating (MySQL unbuffered mode
   * forbids it).
   *
   * @return \Generator<int, array>  associative rows
   * @throws DBException
   */
  public function queryIterate(string $query, array $values = null): \Generator
  {
    $query  = trim($query);
    $values = $values ?: [];

    try {
      if ($this->db_engine === 'pgsql') {
        yield from $this->iteratePgsql($query, $values);
        return;
      }

      $buffered = null;
      if ($this->db_engine === 'mysql') {
        $buffered = $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
      }

      $stmt = null;
      try {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($values);
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
          yield $row;
        }
      } finally {
        if ($stmt) {
          $stmt->closeCursor();
        }
        if ($buffered !== null) {
          $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
      }
    } catch (\PDOException $e) {
      if ($this->log) {
        $this->log->error($e, [$query, $values, 'iterate']);
      }
      throw DBException::fromPdo($e);
    }
  }

  /** Rows fetched per round trip from the PostgreSQL cursor. */
  private const CURSOR_CHUNK = 1000;

  private function iteratePgsql(string $query, array $values): \Generator
  {
    $ownTransaction = !$this->pdo->inTransaction();
    if ($ownTransaction) {
      $this->pdo->beginTransaction();
    }
    $cursor = 'bdus_iter_' . bin2hex(random_bytes(4));
    $opened = false;

    try {
      $this->pdo->prepare("DECLARE {$cursor} NO SCROLL CURSOR FOR {$query}")->execute($values);
      $opened = true;

      do {
        $stmt = $this->pdo->query('FETCH FORWARD ' . self::CURSOR_CHUNK . " FROM {$cursor}");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
          yield $row;
        }
      } while (count($rows) === self::CURSOR_CHUNK);
    } finally {
      if ($ownTransaction && $this->pdo->inTransaction()) {
        // Read-only: rolling back also closes the cursor, and is the only
        // option once the transaction is aborted by an error.
        $this->pdo->rollBack();
      } elseif ($opened && $this->pdo->inTransaction()) {
        $this->pdo->exec("CLOSE {$cursor}");
      }
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
    // A transaction begun inside another joins it: the outer one decides. This
    // lets a caller wrap several writes that each open their own transaction
    // (Record\Persist) into one all-or-nothing unit.
    if ($this->txDepth > 0 && $this->pdo->inTransaction()) {
      $this->txDepth++;
      return;
    }
    $this->pdo->beginTransaction();
    $this->txDepth  = 1;
    $this->txDoomed = false;
  }

  /** Open transactions, nested ones included (see beginTransaction()). */
  private int $txDepth = 0;

  /** A nested transaction was rolled back: the outer one cannot commit any more. */
  private bool $txDoomed = false;

  /**
   * Commits a started transaction
   * 
   * @return void
   */
  public function commit(): void
  {
    if ($this->txDepth > 1) {
      $this->txDepth--;
      return;
    }
    $this->txDepth = 0;
    if ($this->txDoomed) {
      $this->txDoomed = false;
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw new \RuntimeException('Transaction rolled back: a nested transaction failed.');
    }
    $this->pdo->commit();
  }

  /**
   * Whether a transaction is open. MySQL and MariaDB commit by themselves after a
   * DDL statement (CREATE VIEW, CREATE TRIGGER, DROP…), so the transaction a caller
   * began may already be gone when it comes to commit.
   */
  public function inTransaction(): bool
  {
    return $this->pdo->inTransaction();
  }

  /**
   * Rolls back a started transaction
   * 
   * @return void
   */
  public function rollBack(): void
  {
    // Rolling back a nested transaction dooms the outer one, which stays open
    // until its own rollBack() (or commit(), which then refuses).
    if ($this->txDepth > 1) {
      $this->txDepth--;
      $this->txDoomed = true;
      return;
    }
    $this->txDepth  = 0;
    $this->txDoomed = false;
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
    $dsn = null;
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
   * Reads DB connection parameters from the app's config file on disk.
   *
   * The config file location has changed across the v4→v5 migration sequence
   * (see also Load::resolveMainConfig() and JwtManager::getSecret()):
   *
   *   pre-M016  : projects/{app}/cfg/app_data.json   (v4 legacy name)
   *   post-M016 : projects/{app}/cfg/config.json     (renamed by M016)
   *   post-M018 : projects/{app}/config.json         (moved to project root by M018)
   *
   * The three candidates are probed newest-first so already-migrated apps pay
   * only one extra stat() call, while legacy apps continue to work.
   *
   * @param string $app  Application identifier (subdirectory name under projects/).
   * @return array       Associative connection-data array (db_engine, db_path, …).
   *
   * @todo Once the minimum supported installation has run M016 + M018, drop
   *       the two legacy candidates and keep only "{base}/config.json".
   */
  private function getConnectionDataFromCfg(string $app): array
  {
    $cfg = [];
    $base = $this->path_to_root . "projects/{$app}";
    $file = null;
    foreach ([
      "{$base}/config.json",         // post-M018: file at project root
      "{$base}/cfg/config.json",     // post-M016, pre-M018: file in cfg/
      "{$base}/cfg/app_data.json",   // pre-M016: v4 legacy filename
    ] as $candidate) {
      if (file_exists($candidate)) {
        $file = $candidate;
        break;
      }
    }

    if ($file === null) {
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
      \SQL\Fold::register($this->pdo, $this->db_engine);
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
    if ($this->spatialExtension !== null) {
      return $this->spatialExtension;
    }
    // PostgreSQL: a failed statement aborts the open transaction, and this probe
    // can run inside one (a write wrapped by records_write). Ask the catalogue
    // instead of calling a function that may not exist.
    if ($this->db_engine === 'pgsql') {
      try {
        $this->spatialExtension = !empty($this->pdo->query(
          "SELECT 1 FROM pg_extension WHERE extname = 'postgis'"
        )->fetchAll());
      } catch (\PDOException $e) {
        $this->spatialExtension = false;
      }
      return $this->spatialExtension;
    }
    try {
      $this->pdo->query("SELECT ST_GeomFromText('POINT(0 0)')");
      $this->spatialExtension = true;
    } catch (\PDOException $e) {
      $this->spatialExtension = false;
    }
    return $this->spatialExtension;
  }

  /** Result of hasSpatialExtension(), looked up once per connection. */
  private ?bool $spatialExtension = null;
}
