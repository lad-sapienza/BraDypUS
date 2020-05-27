<?php
/**
 * Main database connection class
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			31/mar/2011
 * @uses			connect.ini
 */

class DB_connection
{
	/**
	 * Return aray with connection data such as:
	 *   driver: sqlite|mysql|pgsql
	 *   dns: string of data source name
	 *   user: database username
	 *   password: database password
	 *
	 * @param string|false $app		Application name, if missing session data will be used
	 * @param string|false $custom_connection Path to custom configuration file
	 * @return array Array with connection data: driver and dns are always supplied; user and password are supplied for mysql and pgsql
	 */
	public static function getConnectionString($app = false, $custom_connection = false)
	{
		/*
		 * check for connection data
		* 1) proj data
		* 2) system data
		* 3) proj sqlite db
		*/
		/**
		 * Compatibility note:
		 * From version 3.15 connection information is stored in the main cfg file
		 * No more support for connect.ini is provided
		 * Connection information is *always* based on project cfg file and is no more application related
		 * (ie. no more MAIN_DIR . 'connect.ini' is supported)
		 */
		$data = self::getData($app, $custom_connection);

		if (!$data['driver'] || !$data['dsn']){
			throw new myException('no_connect_data');
		}

		return [
			'driver' => $data['driver'],
			'dsn' => $data['dsn'],
			'username' => $data['db_username'],
			'password' => $data['db_password']
		];

        		
	}

	/**
	 * Connection options priority:
	 * 1. custom_connection_file
	 * 2a. PROJ_DB . 'bdus.sqlite'
	 * 2b. PROJS_DIR . $app . '/db/bdus.sqlite'
	 * 3a. PROJ_CFG_APPDATA
	 * 3b. PROJS_DIR . $app . 'cfg/app_data.json'
	 *
	 * @param boolean $app
	 * @param boolean $custom_connection
	 * @return Array
	 */
	private static function getData($app = false, $custom_connection_file = false)
	{
		if ($custom_connection_file and !file_exists($custom_connection_file)) {

			$connection_file = $custom_connection_file;
			
        } else if (!$app AND defined('PROJ_DB') AND file_exists(PROJ_DB . 'bdus.sqlite')) {

			$db_path = PROJ_DB . 'bdus.sqlite';

			$driver = 'sqlite';

		} elseif ($app AND file_exists(PROJS_DIR . $app . '/db/bdus.sqlite')) {

			$db_path = PROJS_DIR . $app . '/db/bdus.sqlite';

			$driver = 'sqlite';

		} else if (defined('PROJ_CFG_APPDATA')){

			$connection_file = PROJ_CFG_APPDATA;

		} else if (defined('PROJS_DIR') && $app ) {

			$connection_file = PROJS_DIR . $app . 'cfg/app_data.json';

		} else {

			throw new myException(tr::get('no_connect_data'));

		}

		$cfg = $connection_file ? json_decode(file_get_contents($connection_file, true)) : ['db_engine' => $driver, 'db_path' => $db_path];

		return self::validateData($cfg);

	}


	private static function validateData($cfg)
	{
		if (!$cfg['db_engine']){
			// TODO: translate
			throw new myException("Missing DB Engine");
		}
		if( !in_array($cfg['db_engine'], ['sqlite', 'mysql', 'pgsql'])) {
			throw new myException(tr::sget('driver_not_supported', $cfg_data_arr['driver_not_supported']));
		}

		if ($cfg['db_engine'] === 'sqlite' && $cfg['db_path']) {
			$dsn = "{$cfg['db_engine']}:{$cfg['db_path']}";
		}

		if (!$dsn) {

			if (!$cfg['db_name']){
				// TODO: translate
				throw new myException("Missing DB Name");
			}

			if (!$cfg['db_username']){
				// TODO: translate
				throw new myException("Missing DB Username");
			}

			if (!$cfg['db_password']){
				// TODO: translate
				throw new myException("Missing DB Password");
			}

			if (!$cfg['db_host']){
				$cfg['db_host'] = 'localhost';
			}

			$dsn = "{$cfg_data_arr['db_engine']}:host={$cfg_data_arr['db_host']};dbname={$cfg_data_arr['db_name']};" .
				($cfg['db_port'] ?  "port={$cfg['db_port']};" : '') .
				"charset=UTF-8";

		}
		
		
		return [
			'driver' => $cfg['db_engine'],
			'dsn' => $dsn,
			'username' => $cfg['db_username'],
			'password' => $cfg['db_password']
		];
		
	}

}
?>
