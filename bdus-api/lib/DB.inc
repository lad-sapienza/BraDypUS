<?php
/**
 * Main database connection class
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			31/mar/2011
 * @uses			DB_connection
 */

class DB
{

	/**
	 *
	 * Database instance
	 * @var object
	 */
	private $db;
	private $db_engine;
	private $app;
	private $stop_versioning = false;

	/**
	 *
	 * Load connection info and starts PDO object
	 * @param string $app	application to work with
	 * @param string $custom_connection
	 * @throws myException
	 */
	public function __construct($app = false, $custom_connection = false)
	{
		$this->app = $app ? $app : APP;

		$this->parseStart($this->app, $custom_connection);
	}

	/**
	 * Sets $stop_versioning to true and prevents versioning of the recprds
	 *
	 * @return void
	 */
	public function dont_version()
	{
		$this->stop_versioning = true;
	}
	
	/**
	 * Sets $stop_versioning to false and permits versioning of the recprds
	 *
	 * @return void
	 */
	public function do_version()
	{
		$this->stop_versioning = false;
	}

	/**
	 * Parses conncetion data and starts PDO
	 * @param string $app
	 * @param string $custom_connection
	 * @throws myException
	 */
	private function parseStart($app = false, $custom_connection = false)
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

		} catch( myException $e ) {

			throw new myException($e);
			
		} catch (PDOException $e) {

			throw new myException($e);

		}
	}

	/**
	 * returns current app name
	 */
	public function getApp()
	{
		return $this->app;
	}

	/**
	 *
	 * static method to initialize the database object
	 */
	public static function start()
	{
		return new self();
	}

	/**
	 *
	 * Returns current database engine
	 */
	public function getEngine()
	{
		return $this->db_engine;
	}

	public function getById($tb, $id, $field = "*")
	{
		$q = 'SELECT ' . $field . ' FROM ' . $tb . ' WHERE id = ' . $id;
		$res = $this->query($q, false, 'read');

		if ($res) {
			return ($field ? $res[0][$field] : $res[0]);
		} else {
			return false;
		}
	}

	public function doQuery($sql)
	{
		try {
			$this->db->exec($sql);
		} catch (PDOException $e) {
			throw new myException($e);
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
	public function query($query, $values = false, $type = false, $fetch_style = false )
	{
		try {

			$query = trim($query);

			$sql = $this->db->prepare($query);

			if ( !$values ) $values = [];

			if (!$this->stop_versioning){
				if(!preg_match("/^SELECT/i", $query) AND !preg_match("/^SHOW TABLES/i", $query) AND $_SESSION['user']['id']) {
					Meta::addVersion($_SESSION['user']['id'], null, $query, $values);
				}
			}

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
		} catch (PDOException $e) {
			Meta::logException($e);
			throw new myException( tr::get('db_generic_error') );
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

	/**
	 *
	 * returns table structure (only for mysql database)
	 * @param string $tb	table name
	 */
	public function columns($tb)
	{
		if ($this->db_engine == 'mysql') {
			$tmp = $this->db->query("DESCRIBE {$tb}");
			return $tmp;
		} else {
			// pgsql: SELECT * FROM information_schema.columns WHERE table_name = '$tb';
			// sqlite: PRAGMA table_info('$tb')
			return false;
		}
	}

}
?>
