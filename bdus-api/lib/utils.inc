<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 */

use DB\DBInterface;

class utils
{

  /**
   * Checks if current installation is the official online installation or a
   * local one. The check is performed on host name
   * @return boolean
   */
	public static function is_online() : bool
	{
		$host = $_SERVER['HTTP_HOST'];
		return !(
			strpos($host, 'localhost')		!== false ||
			strpos($host, '127.0')			!== false ||
			strpos($host, '192.168')		!== false ||
			strpos($host, 'bradypus.net') 	=== false 
		);
	}

	/**
	 *
	 * Prints out a message, using core.message abstraction layer.
	 * The following messages types are availables
	 * 		notice (default type)
	 * 		success
	 * 		warning
	 * 		error
	 * @param string $text	text of the message to be shown
	 * @param string $type	notice (default) | success | warning | error
	 * @param boolean $sticky	true or false (default).
	 */
	public static function message($text, $type = false, $sticky = false)
	{
		$html = '<script>'
			. ' core.message('
				. "'" . str_replace("'", "\'", $text) . "'"
				. ($type ? ", '" . $type . "'" : ', false')
				. ($sticky ? ", true" : '')
				. ')'
		. '</script>';

		return $html;
	}

	/**
	 *
	 * A map of privileges: translates privilege code to string and string to code
	 * If $input is false will be set with logged user privilege code
	 * if $input is all all array will be returned
	 * If $input is a string the corrispondent code will be returned
	 * If $input is an integer the corrispondent string will be returned
	 * @param mixed $input initial privilege
	 * @param boolean $translate
	 */
	public static function privilege($input = false, $translate = false)
	{
		if (!$input) {
			$input = $_SESSION['user']['privilege'];
		}

		$privilege_array = array(
				1	=> 'super_admin',
				10	=> 'admin',
				20	=> 'writer',
				25	=> 'self_writer',
				30	=> 'reader',
				40	=>'waiting'
		);

		if ($input === 'all' && !$translate) {
			return $privilege_array;
		}

		if ($input === 'all' && $translate) {
			foreach ($privilege_array as $id=>$val) {
				$tr[$id] = \tr::get($val);
			}

			return $tr;
		}

		if (in_array ($input, $privilege_array)) {
			return array_search($input, $privilege_array);
		}

		if (in_array($input, array_keys($privilege_array))) {
			return $translate ? \tr::get($privilege_array[$input]) : $privilege_array[$input];
		}

		return false;
	}


	/**
	 *
	 * Checks if user has a certail privilege. Returns true or false
	 * @param string $privilege		privilege (verbose) to check. One of: enter|read|review|add_new|multiple_edit|admin|super_admin
	 * @param string $creator		row creator (self-editor)
	 */
	public static function canUser($privilege = false, $creator = false)
	{
    	if (defined('PROJ_DIR')) {
      		$app_status = strtolower(cfg::main('status'));
    	} else {
      		$app_status = 'on';
    	}

	    $user_priv = $_SESSION['user']['privilege'];


		if (!$privilege) {
			$privilege = 'edit';
		}

		if (!$user_priv || $user_priv === 0) {
			return false;
		}

		switch($privilege) {
			case 'enter':
				return ($app_status !== 'off' && $user_priv < 39);
				break;

			case 'read':
			case 'preview':
				// read & preview can be global or table oriented
				return ($app_status !== 'off' && $user_priv < 31);
				break;


			case 'add_new':
				return ($app_status !== 'frozen' && $user_priv < 26);
				break;

			case 'edit':
				return $app_status !== 'frozen' && ($user_priv < 21 || ($creator  && $creator == $_SESSION['user']['id'] && $user_priv < 26));
				break;

			case 'multiple_edit':
				return ($app_status !== 'frozen' && $user_priv < 21);
				break;

			case 'admin':
				return ($app_status !== 'frozen' && $user_priv < 11);
				break;

			case 'super_admin':
				return ( ($user_priv < 11 && !self::is_online()) || $user_priv < 2);
				break;

			default:
				return false;
				break;
		}
	}

	/**
	 *
	 * Returns  an array with all content (not . or ..) of $dir
	 * @param string $dir	path to dir
	 */
	public static function dirContent ( $dir )
	{
		$dont_consider = array(
			'.', '..', '.DS_Store', 'undefined', '.svn'
		);
		$handle = @opendir($dir);

		if ( $handle ) {

			while ( false !== ( $file = readdir ( $handle ) ) ) {
				if (!in_array($file, $dont_consider)) {
					$dir_content[] = $file;
				}
			}

			closedir($handle);

			return $dir_content;
		} else {
			return false;
		}
	} // end of dirContent


	/**
	 *
	 * Empties a directory recursively.
	 * By default (no dir specified) the user temporary folder will be emptied!
	 * @param string $dir	directory to empty
	 * @param string $delete	if true directory will be deleted
	 * @throws \Exception
	 */
	public static function emptyDir( $dir = false, $delete = false, $limit2current = false )
	{
		!$dir ? $dir = PROJ_TMP_DIR : '';

		$files_in_tmp = self::dirContent( $dir );

		if ( $files_in_tmp[0] ) {
			foreach ( $files_in_tmp as $file ) {
				if ( is_dir ( $dir . '/' . $file ) ) {
					if( !$limit2current ) {
						self::emptyDir( $dir . '/' . $file, true );
					}
				} else {
					if ( !@unlink ( $dir . '/' . $file ) ) {
						throw new \Exception(\tr::get('cannot_delete_file', [$file]));
					}
				}
			}
		}

		if ( $dir !== PROJ_TMP_DIR && $delete) {
			if ( !@rmdir( $dir ) ) {
				throw new \Exception(\tr::get('cannot_delete_dir', [$dir]));
			}
		}

	} //end of emptyDir

	/**
	 *
	 * Returns an array of given string, using givel delimiter
	 * @param string $string
	 * @param string $separator
	 */
	public static function csv_explode(string $string, string $delimiter = ','): array
	{
		return array_filter(array_map('trim', explode($delimiter, $string)), 'strlen');
	}

  /**
   * Returns or echoes (depending on $echo parameter) a well formatted HTML
   * string with .text-error CSS class and message
   * @param string $text  Text to be displayed/returned
   * @param boolean $echo If true the text will be echoed, otherwize it will be returned
   * @param boolean $dont_translate If false (default) the text will translated,
   *      if true the text will be showed as is
   * @return string Well formatted HTML string
   */
	public static function alert_div($text, $echo = false, $dont_translate = false)
	{
		$str = '<div class="text-danger">'
				. '<strong>' . \tr::get('attention') . ':</strong> ' . ($dont_translate ? $text : \tr::get($text)) . '</p>'
			. '</div>';

		if ($echo)
			echo $str;
		return $str;
	}
	

  /**
   * Recursively filters array using an optional callback function
   *  If no callback function is defined the resulting array will contain only
   *  not null/empty values of the original array. Key-value association is mantained
   * @param array $arr  Original array to filter
   * @param mixed $callback Callback function, default false. Can be a function or a function name
   * @return array  Filtered array
   */
	public static function recursiveFilter($arr, $callback = false)
	{
		foreach ($arr as &$a) {
			if (is_array($a)) {
				$a = self::recursiveFilter($a, $callback);
			}
		}
    	return $callback ? array_filter($arr, $callback ) : array_filter($arr);
	}

	

	/**
	 * Converts a multidimensional array to GeoJSON
	 *
	 * @param string $tb	Table name
	 * @param array $rows	Array or database data
	 * @return array		Valid geoJSON as array
	 */
	public static function mutliArray2GeoJSON(string $tb, array $rows): array
    {
        $geo = [
            'type' => 'FeatureCollection',
            'features' => []
        ];


        if (!is_array($rows)) {
            throw new Exception('Input data is not an array');
        }

        foreach ($rows as $r) {
            $arr = [];
            if (!is_array($r)) {
                throw new Exception('Input data is not a multidimensional array');
            }

            if (!$r['geometry'] && !$r[$tb . '.geometry']) {
                // single row error does not block entire process
                error_log('No valid geometry column found in row: ' . var_export($r, true));
                continue;
            }

            $arr['type']		= 'Feature';
            $arr['geometry']	= json_decode(\Symm\Gisconverter\Gisconverter::wktToGeojson($r['geometry'] ? $r['geometry'] : $r[$tb . '.geometry']), true);

            unset($r['geometry']);
            if ($r) {
                $arr['properties']	= $r;
            }

            array_push($geo['features'], $arr);
		}
		
		return $geo;
	}
	
	public static function jsonForTabletop(DBInterface $db, string $tb, array $params): string
	{
		$fields = ['id', 'channel', 'level', 'message', 'time'];

		$q = 'SELECT * FROM ' . $tb . ' WHERE ';

		$v = [];
		$w = [];

		if ($params['sSearch']) {
			foreach ($fields as $f) {
				$w[] = "$f LIKE ?";
				$v[] = "%{$params['sSearch']}%";
			}
			$q .= implode(' OR ', $w);
		} else {
			$q .= ' 1=1';
		}

		$response['sEcho'] = intval($params['sEcho']);
		$response['query_arrived'] = $q;

		if ( isset($params['iTotalRecords']) ) {
			$response['iTotalRecords'] = $params['iTotalRecords'];
		} else {
			$res_tot = $db->query('SELECT count(id) as tot FROM ' . $tb . ' WHERE 1=1');
			$response['iTotalRecords'] = $res_tot[0]['tot'];
		}

		$response['iTotalDisplayRecords'] = $response['iTotalRecords'];

		if (isset($params['iSortCol_0'])) {
			$q .= ' ORDER BY ' . $fields[$params['iSortCol_0']] . ' ' . ($params['sSortDir_0']==='asc' ? 'asc' : 'desc');
		} else {
			$q .= ' ORDER BY id DESC';
		}

		if (isset($params['iDisplayStart']) && $params['iDisplayLength'] !== '-1') {
			$q .= ' LIMIT ' . $params['iDisplayLength'] . ' OFFSET ' . $params['iDisplayStart'];
		} else {
			$q .= ' LIMIT 30 OFFSET 0 ';
		}

		$response['query_executed'] = $q;

		$response['aaData'] = $db->query($q, $v);

		foreach ($response['aaData'] as $id => &$row) {
			$date = new DateTime();
			$date->setTimestamp($row['time']);
			$row['time'] = $date->format('Y-m-d H:i:s');
			$response['aaData'][$id]['DT_RowId'] = $row['id'];
		}

		return json_encode($response);
	}

	public static function encodePwd( string $password ) : string
    {
        return sha1($password);
	}
	
	public static function isDuplicateEmail( \DB\DB $db, string $prefix, string $email, int $id = null ) : bool
    {
        $partial = [ "email = ? " ];
        $values = [ $email ];

        
        if ($id) {
            array_push($partial, "id != ? ");
            array_push($values, $id);
        }
        $res = $db->query(
            'SELECT count(*) as tot FROM ' . $prefix . 'users WHERE ' . implode(" AND ", $partial) . ' LIMIT 1 OFFSET 0', 
            $values
        );

        return ($res[0]['tot'] > 0);
	}

	public function debug($d, bool $echo = false) : void
	{
		$json = json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
		if ($echo){
			echo "<pre>$json</pre>";
		} else {
			error_log( 'DEBUG: ' . $json );
		}
		
	}
}
