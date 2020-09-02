<?php
/**
 * Main database connection class
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			31/mar/2011
 * @uses			\Exception
 * @uses			\PDOException
 * @uses			APP
 */

class DB implements \DB\DB\DBInterface
{

	/**
	 *
	 * Database instance
	 * @var object
	 */
	private $db;
	private $db_engine;
	private $app;
	private $log;

	/**
	 *
	 * Load connection info and starts PDO object
	 * @param string $app	application to work with
	 * @param array $custom_connection
	 * 		db_engine
	 * 		db_path, for sqlite
	 * 		db_name, for mysql and pgsql
	 * 		db_username, for mysql and pgsql
	 * 		db_password, for mysql and pgsql
	 * 		db_host, for mysql and pgsql
	 * 		db_port, for mysql and pgsql, optional
	 * @throws \Exception
	 */
	public function __construct(string $app = null, array $custom_connection = null)
	{
		$this->app = $app;

		if (!$this->app){
			throw new \Exception("No valid app provided: cannot start database object");
		}
		if ($custom_connection){
			$cfg = $custom_connection;
		} else if ($this->app){
			$cfg = $this->getConnectionDataFromCfg($this->app);
		} else {
			throw new \Exception("Cannot resolve DB connection information");
		}

		list ($db_engine, $dsn, $username, $password) = $this->validateParseConnectionData($cfg);

		$this->initializePDO($db_engine, $dsn, $username, $password);
	}

	public function setLog(Monolog\Logger $log)
	{
		$this->log = $log;
	}

	private function validateParseConnectionData(array $cfg): array
	{        
        if (!$cfg['db_engine']){
            throw new \Exception(tr::get('missing_db_engine'));
        }
        
        if( !in_array($cfg['db_engine'], ['sqlite', 'mysql', 'pgsql'])) {
            throw new \Exception(tr::get('db_engine_not_supported', [$cfg['db_engine']]));
        }
        
        // Set DSN for sqlite
        if ( $cfg['db_engine'] === 'sqlite') {
            if (!$cfg['db_path']){
                throw new \Exception( tr::get('missing_sqlite_file'));
            }
            $dsn = "{$cfg['db_engine']}:{$cfg['db_path']}";
        }
        
        if (!$dsn) {
            
            if (!$cfg['db_name']){
                throw new \Exception( tr::get('missing_db_name') );
            }
            
            if (!$cfg['db_username']){
                throw new \Exception( tr::get('missing_db_username') );
            }
            
            if (!$cfg['db_password']){
                throw new \Exception(tr::get('missing_db_password'));
            }
            
            if (!$cfg['db_host']){
                $cfg['db_host'] = '127.0.0.1';
            }
            
            $dsn = "{$cfg['db_engine']}:host={$cfg['db_host']};dbname={$cfg['db_name']};" .
                ($cfg['db_port'] ?  "port={$cfg['db_port']};" : '') .
                "options='--client_encoding=UTF8'";
                // "charset=utf8";
		}

		if (!$cfg['db_engine'] || !$dsn){
			throw new \Exception('Not found any connection data');
		}

        return [
            $cfg['db_engine'],
            $dsn,
            $cfg['db_username'],
            $cfg['db_password']
        ];
	}

	private function getConnectionDataFromCfg(string $app): array
	{
		$cfg = [];
		$file = __DIR__ . "/../projects/{$app}/cfg/app_data.json";
        
        if (!file_exists($file) ) {
			throw new \Exception("Missing configuration file $file");
		}

		$cfg = json_decode(file_get_contents(__DIR__ . "/../projects/{$app}/cfg/app_data.json"), true);
		
		if (!is_array($cfg)){
			throw new \Exception(tr::get('invalid_configuration_file', [$connection_file]) );
		}

        if (file_exists(__DIR__ . "/../projects/{$app}/db/bdus.sqlite")) {
            $cfg['db_path'] = __DIR__ . "/../projects/{$app}/db/bdus.sqlite";
        }
        
        return $cfg;
	}

	/**
	 * Parses conncetion data and starts PDO
	 * @param array $connection_data
	 * @throws \Exception
	 */
	private function initializePDO(string $db_engine, string $dsn, string $user = null, string $password = null)
	{
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

			$this->db = new \PDO( $dsn, $user, $password, $dbOptions );

			
			if ($this->db_engine == 'sqlite') {
				$this->db->query('PRAGMA encoding = "UTF-8"');
				$this->db->query('PRAGMA foreign_keys = ON;');
			}
			return $this;

		} catch (\PDOException $e) {

			throw new \Exception($e);

		}
	}

	/**
	 * returns current app name
	 */
	public function getApp(): string
	{
		return $this->app;
	}

	/**
	 *
	 * Returns current database engine
	 */
	public function getEngine(): string
	{
		return $this->db_engine;
	}

	public function execInTransaction(string $sql): bool
	{
		$ret = false;
		try {
			$this->db->beginTransaction();
			$ret = $this->db->exec($sql);
			$this->db->commit();
		} catch (\Throwable $th) {
			$this->db->rollBack();
			$this->log->error($th);
		}
		return ($ret !== false);
	}

	public function exec(string $sql): bool
	{
		try {
			return $this->db->exec($sql) !== false;
		} catch (\PDOException $e) {
			throw new \Exception($e);
		}
	}

	public function backupBeforeEdit (string $table, int $id, string $query, array $values = []): void
	{
		try {
			// Get record from database
			$rows = $this->query( 'SELECT * FROM ' . $table . ' WHERE id = ?', [ $id ] );
		  
			if(!is_array($rows)) {
				$rows = [];
			}
		
			foreach ($rows as $r) {
				$dt = new DateTime();

				$insertSQL = "INSERT INTO " . PREFIX . "versions ( user, time, tb, rowid, content, editsql, editvalues ) VALUES (?, ?, ?, ?, ?, ? ,?)";
				$insertValues = [
					$_SESSION['user']['id'],
					$dt->format('U'),
					$table,
					($r['id'] ?: ''),
					json_encode($r),
					$query,
					json_encode($values)
				];
				$this->query($insertSQL, $insertValues);
			}
		} catch (\Throwable $th) {
			$this->log->error($th);
		}
	}

	/**
	 *
	 * Prepares and runs a query statement and returns, dependin on $type:
	 * 		array with output if read or false
	 * 		last inserted id id id
	 * 		boolean if boolean
	 * Uses prepare and execute statement.
	 * @param string $query			query string
	 * @param array $values			values to use with query string
	 * @param string $type			one of read (default value) | id | boolean | affected, integer, or false
	 * @param boolean $fetch_style	if false an associative array will be returned else a numeric array
	 */
	public function query(string $query, array $values = null, string $type = null, bool $fetch_style = false )
	{
		try {

			$query = trim($query);

			$sql = $this->db->prepare($query);

			if ( !$values ) $values = [];

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
					$fetch_style  = $fetch_style ? PDO::FETCH_NUM : PDO::FETCH_ASSOC;
					return $sql->fetchAll($fetch_style);
					break;

				case 'id':
					return $this->db->lastInsertId();
					break;

				case 'affected':
					return $sql->rowCount();
					break;
			}
		} catch (\PDOException $e) {
			$this->log->info($e, [$query, $values, $type, $fetch_style]);
			throw new \Exception( tr::get('db_generic_error') );
		}
	}

	/**
	 *
	 * Starts a transaction
	 */
	public function beginTransaction()
	{
		$this->db->beginTransaction();
	}

	/**
	 * commits a started transaction
	 */
	public function commit()
	{
		$this->db->commit();
	}

	/**
	 *
	 * Rolls back a started transaction
	 */
	public function rollBack()
	{
		$this->db->rollBack();
	}

}
?>
