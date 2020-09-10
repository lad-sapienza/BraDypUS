<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 21, 2012
 * @uses 			DEBUG_ON
 * @uses 			MAIN_DIR
 * @uses 			PROJ_DIR
 * @uses 			PREFIX
 * @uses 			\Exception
 */

class cfg
{
	/**
	 * Main configuration container
	 * @var array
	 */
	private static $data;

	/**
	 * Loads data in self::$data
	 * @param  string|false $app          application id
	 * @param  boolean $force_reload if true the configuration data will be forced to load from config files
	 */
	public static function load(string $app = null, bool $force_reload = false)
	{
		if (!self::$data['tables'] || $force_reload || DEBUG_ON) {

			if ($app) {
				$app_data = MAIN_DIR . "projects/{$app}/cfg/app_data.json";
			} else if (defined('PROJ_DIR')) {
				$app_data = PROJ_DIR . 'cfg/app_data.json';
			} else {
				throw new \Exception("Cannot find path to app_data");
			}

			if (!file_exists($app_data)) {
				throw new \Exception("The app data file $app_data was not found");
			}

			self::$data['main'] = json_decode(file_get_contents($app_data), true);

			if (!file_exists(PROJ_DIR . 'cfg/tables.json')) {
				throw new \Exception('Configuration file tables.json is missing');
			}
			$tablesJSON = json_decode(file_get_contents(PROJ_DIR . 'cfg/tables.json'), true);

			if (!$tablesJSON || empty($tablesJSON)) {
				throw new \Exception('Invalid json: ' . PROJ_DIR . 'cfg/tables.json');
			}

			self::$data['table'] = $tablesJSON['tables'];

			// single tables
			$tables_array = self::tbEl('all', 'label');

			//single plugins
			$all_fields = $tables_array;

			foreach ($all_fields as $tb=>$tb_name) {

				$cfgfile = PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $tb) . '.json';

				if ( !file_exists( $cfgfile) ) {

					if (file_exists( PROJ_DIR . 'cfg/' . $tb . '.json' )) {
						$cfgfile = PROJ_DIR . 'cfg/' . $tb . '.json';
					} else {
						throw new \Exception( \tr::get('config_file_missing', [$cfgfile]));
					}
				}

				self::$data['tables'][$tb] = json_decode(file_get_contents($cfgfile), true);
			}
		}
	}

	/**
	 * Writes data to cfg files
	 * @param string $what	main, table, of a valid table name
	 * @throws \Exception
	 */
	public static function toFile($what)
	{
		switch($what) {

			case 'main':
				if (!\utils::write_formatted_json(PROJ_DIR . 'cfg/app_data.json', self::$data['main'])) {
					throw new \Exception('Can not write in ' . PROJ_DIR . 'cfg/app_data.json');
				}
				break;

			case 'table':
				$arr['tables'] = self::$data['table'];

				if (!\utils::write_formatted_json(PROJ_DIR . 'cfg/tables.json', $arr)) {
					throw new \Exception('Can not write in ' . PROJ_DIR . 'cfg/tables.json');
				}
				break;

			default:
				$file = PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $what) . '.json';

				if (!is_array(self::$data['tables'][$what]) ) {
					throw new \Exception('Empty array of data for table ' . $what);
				}

				if (!\utils::write_formatted_json($file, self::$data['tables'][$what])) {
					throw new \Exception('Can not write in ' . $file);
				}

				break;
		}
	}


	/**
	 *
	 * Returns information about main.xml If file does not exist an exception will be thrown
	 * @param string $el	element (tag) to be returned. If false an array of all elements will be returned
	 * @throws \Exception
	 */
	public static function main($el = false)
	{
		if (!self::$data) {
			self::load();
		}

		if ($el) {
			return self::$data['main'][$el];
		} else {
			return self::$data['main'];
		}
	}

	public static function setMain($data)
	{
		self::$data['main'] = $data;
		self::toFile('main');
	}


	public static function setTb($post_data)
	{
		$changed = false;
		// Table is not in list. Add it
		if(!in_array($post_data['name'], self::tbEl('all', 'name'))) {
			array_push(self::$data['table'], $post_data);
			$changed = true;
		} else {
			foreach (self::$data['table'] as &$tb_data) {
				// Updates available table config
				if ($tb_data['name'] === $post_data['name']) {
					$tb_data = $post_data;
					$changed = true;
				}
			}
		}

		if ($changed) {
			self::toFile('table');
		}
	}



	public static function setFld(string $tb, string $fld_name, array $post_data)
	{
		$changed = false;
		
		// New table
		if (!isset(self::$data['tables'][$tb]) || !is_array(self::$data['tables'][$tb])) {
			self::$data['tables'][$tb] = [];
		}
		
		$found = false;
		foreach (self::$data['tables'][$tb] as $index => $column_data) {
			if( $column_data['name'] === $fld_name ){
				self::$data['tables'][$tb][$index] = $post_data;
				$changed = true;
				$found = true;
			}
		}
		// New column
		if (!$found){
			array_push(self::$data['tables'][$tb], $post_data);
			$changed = true;
		}

		if ($changed) {
			self::toFile($tb);
		}
	}

	/**
	 *
	 * Returns configuration information about the field
	 * Four different scenarios:
	 * 	a) tb  = all AND el !== all : looking for an element in all tables. An array is returned
	 *  b) tb !== all AND el  = all : looking for all elements of a table. An array is returned
	 *  c) tb !== all and el !== all : looking for an element of a table. An array or string is returned
	 * @param string $tb table name
	 * @param string $el element name
	 * @param string $type_filter	filter on field type to use in getting tables if $tb = all
	 */
	public static function tbEl ( $tb, $el )
	{
		if (!self::$data) {
			self::load();
		}

		$xml = self::$data['table'];
		if (!is_array($xml)){
			return [];
		}

		if ( $tb === 'all' AND $el !== 'all') {
			foreach ( $xml as $arr ) {
				$res[$arr['name']] = $arr[$el];
			}
			return $res;

		} else if ( $tb !== 'all' AND $el === 'all') {
			foreach ( $xml as $arr ) {
				if ( $arr['name'] === $tb ) {
					return $arr;
				}
			}
		} else if ( $tb !== 'all' AND $el !== 'all') {
			foreach ( $xml as $arr ) {
				if ( $arr['name'] === $tb ) {
					return $arr[$el];
				}
			}
		}
		else {
			return $xml;
		}
	}

	public static function deleteTb($tb)
	{
		unset(self::$data['tables'][$tb]);
		$index = false;
		foreach (self::$data['table'] as $tmp_index => $tb_data) {
			if ($tb_data['name'] === $tb) {
				$index = $tmp_index;
			}
		}
		if ($index) {
			unset(self::$data['table'][$index]);
			self::toFile('table');
		}
		unlink(PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $tb) . '.json');
	}

	public static function renameTb(string $old_name, string $new_name)
	{
		self::$data['tables'][$new_name] = self::$data['tables'][$old_name];
		unset(self::$data['tables'][$old_name]);

		foreach (self::$data['table'] as $tmp_index => &$tb_data) {
			if ($tb_data['name'] === $old_name) {
				$tb_data['name'] = $new_name;
				self::toFile('table');
			}
		}
		rename(
			PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $old_name) . '.json',
			PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $new_name) . '.json'
		);
	}

	public static function deleteFld(string $tb, string $fld)
	{
		if ( !isset(self::$data['tables'][$tb]) || !is_array(self::$data['tables'][$tb])) {
			throw new \Exception("Invalid table $tb");
		}
		$index = false;
		foreach (self::$data['tables'][$tb] as $index_arr => $column_data) {
			if ( $column_data['name'] === $fld ) {
				$index = $index_arr;
			}
		};
		if (!$index){
			throw new \Exception("Invalid field $fld in table $tb");
		}
		unset(self::$data['tables'][$tb][$index]);
		self::toFile($tb);
		
	}


	public static function renameFld(string $tb, string $old_name, string $new_name)
	{
		foreach (self::$data['tables'][$tb] as $index_arr => &$column_data) {
			if ( $column_data['name'] === $old_name ) {
				$column_data['name'] = $new_name;
				self::toFile($tb);
			}
		};
	}

}
