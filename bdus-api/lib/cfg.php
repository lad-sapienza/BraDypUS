<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 21, 2012
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
	public static function load($app = false, $force_reload = false)
	{
		if (!self::$data['tables'] || $force_reload || DEBUG_ON) {

			if ($app) {
				$app_data = PROJS_DIR . $app . '/cfg/app_data.json';
			} else if (defined('PROJ_DIR')) {
				$app_data = PROJ_DIR . 'cfg/app_data.json';
			}

			if (!file_exists($app_data)) {
				throw new myException(tr::sget('file_doesnt_exist', $app_data));
			}

			self::$data['main'] = json_decode(file_get_contents($app_data), true);

			if($app) {
				return;
			}

			if (!defined('PROJ_CFG_TB')) {
				throw new myException('tb_config_missing');
			}
			$tablesJSON = json_decode(file_get_contents(PROJ_CFG_TB), true);

			if (!$tablesJSON || empty($tablesJSON)) {
				throw new myException('Invalid json: ' . PROJ_CFG_TB);
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
						throw new myException(tr::sget('config_file_missing', $cfgfile));
					}
				}

				self::$data['tables'][$tb] = json_decode(file_get_contents($cfgfile), true);
			}
		}
	}

	/**
	 * Writes data to cfg files
	 * @param string $what	main, table, of a valid table name
	 * @throws myException
	 */
	public static function toFile($what)
	{
		switch($what) {

			case 'main':
				if (!utils::write_formatted_json(PROJ_DIR . 'cfg/app_data.json', self::$data['main'])) {
					throw new myException('Can not write in ' . PROJ_DIR . 'cfg/app_data.json');
				}
				break;

			case 'table':
				$arr['tables'] = self::$data['table'];

				if (!utils::write_formatted_json(PROJ_CFG_TB, $arr)) {
					throw new myException('Can not write in ' . PROJ_CFG_TB);
				}
				break;

			default:
				$file = PROJ_DIR . 'cfg/' . str_replace(PREFIX, null, $what) . '.json';

				if (!is_array(self::$data['tables'][$what]) || !file_exists($file)) {
					throw new myException('Table ' . $what . ' does not exist');
				}

				if (!utils::write_formatted_json($file, self::$data['tables'][$what])) {
					throw new myException('Can not write in ' . $file);
				}

				break;
		}
	}


	/**
	 *
	 * Returns information about main.xml If file does not exist an exception will be thrown
	 * @param string $el	element (tag) to be returned. If false an array of all elements will be returned
	 * @throws myException
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

		if(!in_array($post_data['name'], self::tbEl('all', 'name'))) {
			array_push(self::$data['table'], $post);
			$changed = true;
		} else {
			foreach (self::$data['table'] as &$tb_data) {
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



	public static function setFld($tb, $fld_name = false, $post_data)
	{
		$changed = false;

		if (!$fld_name) {
			array_push(self::$data['tables'][$tb], $post_data);
			$changed = true;
		} else {
			foreach(self::$data['tables'] as $sess_tb=>$data_arr) {
				foreach ($data_arr as $index=>$data) {
					if ($sess_tb === $tb && $data['name'] === $fld_name) {
						self::$data['tables'][$sess_tb][$index] = $post_data;
						$changed = true;
					}
				}
			}
		}

		if ($changed) {
			self::toFile($tb);
		}
	}

	/**
	 *
	 * Returns configuration information about the field
	 * Four different scenarios:
	 * 	a) fld  = all and el  = all	: looking fo all elements in all fields. An array is returned
	 *  b) fld  = all and el !== all	: looking for an element in all fields. An (associative) array is returned.
	 *  c) fld !== all and el  = all : looking for all elements of a field. An associative array is returned.
	 *  d) fdl !== all and el !== all : looking for one element in one field. A string is returned.
	 * @param string $tb	table to search in
	 * @param string $fld	field to search in or 'all'
	 * @param string $el	element to retrieve or 'all'
	 */
	public static function fldEl ( $tb, $fld = 'all', $el = 'all' )
	{
		if (!self::$data) {
			self::load();
		}

		$xml = self::$data['tables'][$tb];

		if ( $fld === 'all' AND $el === 'all' ) {
			return $xml;
		} else if (!is_array($xml)) {
			return false;
		} else if ( $fld === 'all' AND $el !== 'all' ) {

			foreach ($xml as $v) {
				$res[$v['name']] =  $v[$el];
			}
			return $res;
		} else if ( $fld !== 'all' AND $el === 'all' ) {

			foreach ( $xml as $arr ) {
				if ($arr['name'] === $fld ) {
					return $arr;
				}
			}
		} else if ( $fld !== 'all' AND $el !== 'all' ) {

			foreach ( $xml as $arr ) {
				if ($arr['name'] === $fld ) {
					return $arr[$el];
				}
			}
		}
	}

	/**
	 * Returns array of plugin tables
	 */
	public static function getPlg()
	{
		$all = self::tbEl('all', 'label');

		foreach($all as $name=>$label) {
			if (self::tbEl($name, 'is_plugin')) {
				$ret[$name] = $label;
			}
		}

		return $ret;
	}

	/**
	 * Returns array of plugin tables
	 */
	public static function getNonPlg()
	{
		$all = self::tbEl('all', 'label');

		foreach($all as $name=>$label) {
			if (!self::tbEl($name, 'is_plugin')) {
				$ret[$name] = $label;
			}
		}

		return $ret;
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

	public static function getPreviewFlds($tb)
	{
		$pref_preview = pref::get('preview');

		if (is_array($pref_preview) && is_array($pref_preview[$tb])) {
			return $pref_preview[$tb];
		} else {
			return self::tbEl($tb, 'preview');
		}
	}

}
