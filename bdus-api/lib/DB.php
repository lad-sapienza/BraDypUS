<?php
/**
 * Main database connection class
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			31/mar/2011
 * @uses			DB_connection
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
	 * @param string $custom_connection
	 * @throws \Exception
	 */
	public function __construct(string $app = null, string $custom_connection = null)
	{
		$this->app = $app ?: defined('APP') ? APP : false;

		if (!$this->app){
			throw new \Exception("No valid app provided: cannot start database object");
		}

		$this->parseStart($this->app, $custom_connection);
	}

	public function setLog(Monolog\Logger $log)
	{
		$this->log = $log;
	}

	/**
	 * Parses conncetion data and starts PDO
	 * @param string $app
	 * @param string $custom_connection
	 * @throws \Exception
	 */
	private function parseStart(string $app, string $custom_connection = null)
	{
		try {

			$d = DB_connection::getConnectionString($app, $custom_connection);
			$driver = $d['driver'];
			$dsn = $d['dsn'];
			$user = $d['username'];
			$password = $d['password'];

			$this->db_engine = $driver;

			/**
			 *  Check if MYSQL_ATTR_INIT_COMMAND method exists (for systems without MySQL)
			 *  http://stackoverflow.com/questions/2424343/undefined-class-constant-mysql-attr-init-command-with-pdo
			 */

			$dbOptions = [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false
			];

			$this->db = new PDO( $dsn, $user, $password, $dbOptions );

			
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
			$this->log->error($e);
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
