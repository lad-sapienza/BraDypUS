<?php
/**
 * Main database connection class
 * 
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			All rights reserved
 * @since			31/mar/2011
 * @uses			connect.ini
 */

class DB
{
	
	/**
	 * 
	 * Database instance
	 * @var object
	 */
	private $db, $db_engine, $app;
	
	/**
	 * 
	 * Load connection info and starts PDO object
	 * @param string $app	application to work with
	 * @param string $custom_connection
	 * @throws myException
	 */
	public function __construct($app = false, $custom_connection = false)
	{
		$this->app = $app ? $app : $_SESSION['app'];
		
		$this->parseStart($app, $custom_connection);
	}
	
	/**
	 * Parses conncetion data and starts PDO
	 * @param string $app
	 * @param string $custom_connection
	 * @throws myException
	 */
	private function parseStart($app = false, $custom_connection = false)
	{
		/*
		 * check for connection data
		* 1) proj data
		* 2) system data
		* 3) proj sqlite db
		*/
		if ($custom_connection AND file_exists($custom_connection))
		{
			$connection_file = $custom_connection;
		}
		else if (file_exists(PROJ_DB . 'connect.ini'))
		{
			$connection_file = PROJ_DB . 'connect.ini';
		}
		else if (file_exists(MAIN_DIR . 'connect.ini'))
		{
			$connection_file = MAIN_DIR . 'connect.ini';
		}
		else if (!$app AND defined('PROJ_DB') AND file_exists(PROJ_DB . 'bdus.sqlite'))
		{
			$connection_string = 'sqlite:' . PROJ_DB . 'bdus.sqlite';
			$this->db_engine = 'sqlite';
		}
		elseif ($app AND file_exists(PROJS_DIR . $app . '/db/bdus.sqlite'))
		{
			$connection_string = 'sqlite:' . PROJS_DIR . $app . '/db/bdus.sqlite';
			$this->db_engine = 'sqlite';
		}
		else
		{
			throw new myException(tr::get('no_connect_data'));
		}
		
		/*
		 * write connection string
		*/
		if ($connection_file)
		{
			$connection_arr = parse_ini_file( $connection_file , true);
			
			if (in_array($_SERVER['HTTP_HOST'], array_keys($connection_arr)))
			{
				$connection_arr = $connection_arr[$_SERVER['HTTP_HOST']];
			}
			else if (is_array($connection_arr['%']))
			{
				$connection_arr = $connection_arr['%'];
			}
			else
			{
				throw new myException(tr::sget('no_connect_data_for_host', $_SERVER['HTTP_HOST']));
			}
			
				
			if ( !$connection_arr['host'] )
			{
				$connection_arr['host'] = 'localhost';
			}
			
			$this->db_engine = $connection_arr['driver'];
			
			switch($connection_arr['driver'])
			{
				case 'mysql':
				case false:
					$connection_string = 'mysql:host=' . $connection_arr['host'] . ';dbname=' . $connection_arr['db'] . ';charset=UTF-8';
					break;
					
				default:
					throw new myException(tr::sget('driver_not_supported', $connection_arr['driver']));
					break;
			}
		}
		
		if (!$connection_string)
		{
			throw new myException('no_connect_data');
		}
		
		/*
		 * start PDO
		*/
		try
		{
			/**
			 *  Check if MYSQL_ATTR_INIT_COMMAND method exists (for systems without MySQL)
			 *  http://stackoverflow.com/questions/2424343/undefined-class-constant-mysql-attr-init-command-with-pdo
			 */
			
			$dbOptions = method_exists('PDO', 'MYSQL_ATTR_INIT_COMMAND') ? array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") : array(1002 => 'SET NAMES utf8');
			
			$this->db = new PDO(
					$connection_string,
					$connection_arr['user'],
					$connection_arr['password'],
					$dbOptions
					);
			
			$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			if ($this->db_engine == 'sqlite')
			{
				$this->db->query('PRAGMA encoding = "UTF-8"');
			}
			return $this;
		}
		catch (PDOException $e)
		{
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
	
	public function getById($tb, $id, $field = false)
	{
		$q = 'SELECT ' . ($field ? '`' . $field . '`' : ' * ') . ' FROM `' . $tb . '` WHERE `id` = ' . $id;
		$res = $this->query($q, false, 'read');
		
		if ($res)
		{
			return ($field ? $res[0][$field] : $res[0]);
		}
		else
		{
			return false;
		}
	}

	public function doQuery($sql)
	{
		try{
			$this->db->exec($sql);
		}
		catch (PDOException $e)
		{
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
	 * @param string $type			one of read | id | boolean | affected, integer, or false
	 * @param boolean $fetch_style	if false an associative array will be returned else a numeric array
	 */
	public function query($query, $values = false, $type = false, $fetch_style = false )
	{
		try
		{
			$sql = $this->db->prepare($query);
			
			if ( !$values ) $values = array();
		
			$flag = $sql->execute($values);
			
			if(defined('PROJ_HISTORY') AND !preg_match("/SELECT/i", $query) AND !preg_match("/SHOW TABLES/i", $query))
			{
				$message = date('c') . ' ' . $_SESSION['user']['name'] . ', '
					. 'query_text:[' . $query. '], '
					. 'values:[' . (json_encode($values)) . '], '
					. 'type:[' . $type . ']' . "\n";
				error_log($message, 3, PROJ_HISTORY);
			}
			
			if (is_int($type))
			{
				return $sql->fetchColumn($type);
			}
			
			switch ($type)
			{
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
		}
		catch (PDOException $e)
		{
			$err_txt = 'q:[' . $query. '], '
				. 'v:[' . (json_encode($values)) . '], '
				. 't:[' . $type . ']';
			error_log($err_txt);
			throw new myException($e);
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
		if ($this->db_engine == 'mysql')
		{
			$tmp = $this->db->query("DESCRIBE `$tb`");
			return $tmp;
		}
		else
		{
			return false;
		}
	}

}
?>