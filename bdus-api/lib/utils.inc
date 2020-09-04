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
	 * Converts array or object to json and writes it to file
	 * @param string $file
	 * @param mixed $obj
	 */
	public static function write_formatted_json($file, $obj)
	{
		$text = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		return self::write_in_file($file, $text);
	}

	/**
	 *
	 * Writes text in file. If file does not exit, it will be created
	 * @param string $file	complete path to file
	 * @param string $text	text to write in file
	 */
	public static function write_in_file ( $file, $text = false, $gz = false )
	{
		try {

			if ( !$text )	$text = '';

			if ( $gz )		$file .= '.gz';

			$f = $gz ? @gzopen($file,'w9') : @fopen($file, 'w');
			if ( !$f )		throw new Exception("Cannot open $file");

			$write = $gz ? @gzwrite ( $gzh, $text ) : @fwrite ( $f, $text );
			if ( !$write )	throw new Exception("Cannot write on file $file");

            if ($gz) @gzclose($gzh);
            else @fclose($f);

			if ( !file_exists($file) ) throw new Exception("File $file still does not exist");

			return true;

		} catch (\Throwable $th) {
			return false;
		}
		
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
				$tr[$id] = tr::get($val);
			}

			return $tr;
		}

		if (in_array ($input, $privilege_array)) {
			return array_search($input, $privilege_array);
		}

		if (in_array($input, array_keys($privilege_array))) {
			return $translate ? tr::get($privilege_array[$input]) : $privilege_array[$input];
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
						throw new \Exception(tr::get('cannot_delete_file', [$file]));
					}
				}
			}
		}

		if ( $dir !== PROJ_TMP_DIR && $delete) {
			if ( !@rmdir( $dir ) ) {
				throw new \Exception(tr::get('cannot_delete_dir', [$dir]));
			}
		}

	} //end of emptyDir

	/**
	 *
	 * Returns an array of given string, using givel delimiter
	 * @param string $string
	 * @param string $separator
	 */
	public static function csv_explode($string, $delimiter = ',') {

		$string = str_replace($delimiter .' ', $delimiter, $string);

		if ( preg_match('/' . $delimiter . '/i', $string) )
		{
			return array_filter(explode($delimiter, $string));

		}
		else
		{
			return array($string);
		}
	}

	/**
	 *
	 * Decodes text (if decode is true)
	 * Transforms text in html characters (if html is true)
	 * Linkifies texts and applies nl2br
	 * @param string $text		text to format
	 * @param boolean $html
	 * @param boolean $decode
	 */
	public static function format_text($text, $html = false, $decode = false)
	{
		if ($decode) {
			$text = base64_decode($text);
		}
		if (!$html && $text !== '') {
			$text = @htmlentities($text, ENT_QUOTES, 'UTF-8');
		}
		$text = self::linkify($text);

		$text = self::add_db_links($text);

		$text = nl2br($text);

		return $text;
	}

	/**
	 * Replaces in text patterns like @{table-name}.{id-value} with direct links to the relative view record page
	 * @param string $text [description]
	 * @since 2019-07-12
	 */
	private static function add_db_links($text){
		return preg_replace_callback(
			'/@([a-z]+)\.([0-9]+)(\[([^\]]+)\])?/',
	    function($m){
	      $p = PREFIX;
	      if ($m[3] && $m[4]){
	        return "<span class=\"btn-link\" onclick=\"api.record.read('{$p}{$m[1]}', ['{$m[2]}'], true)\">{$m[4]}</span>";
	      } else {
	        return "<span class=\"btn-link\" onclick=\"api.record.read('{$p}{$m[1]}', ['{$m[2]}'], true)\">{$m[1]}.{$m[2]}</span>";
	      }
	    },
			$text);
	}

	/**
	 *
	 * Transforms plain-text links to html links
	 * @param string $text		text to check
	 * @param boolean $escape	text is escaped?
	 */
	public static function linkify($text, $escape = false)
	{
		if ($escape) {
			$text = preg_replace("/(https?:\/\/)([^ \t\r\n$\)]+)/i", "<a href=\\\"\\0\\\" target=\\\"_blank\\\">\\0</a>", $text);
			$text = preg_replace("/([a-z0-9\._-]+)(@[a-z0-9\.-_]+)(\.{1}[a-z]{2,6})/i", "<a href=\\\"mailto:\\1\\2\\3\\\">\\1\\2\\3</a>", $text);
		} else {
			$text = preg_replace("/(https?:\/\/)([^ \t\r\n$\)]+)/i", "<a href=\"\\0\" target=\"_blank\">\\0</a>", $text);
			$text = preg_replace("/([a-z0-9\._-]+)(@[a-z0-9\.-_]+)(\.{1}[a-z]{2,6})/i", "<a href=\"mailto:\\1\\2\\3\">\\1\\2\\3</a>", $text);
		}
		return $text;
	}

	/**
	 *
	 * Returns formatted full path for file
	 * @param int $file_id		file id
	 * @param string $file_ext	file extensione
	 */
	public function get_file_path($file_id, $file_ext)
	{
		return PROJ_DIR . 'files/' . $file_id . '.' . $file_ext;
	}

	/**
	 * Returns text, stripslashed and without php tags
	 * @param string $text
	 * @return mixed
	 */
	public static function clean_text_from_php($text)
	{
		$text = stripslashes($text);

		$forbidden = array('<?php', '<?', '?>');

		return str_replace($forbidden, null, $text);
	}

	/**
	 * Echoes JSON encoded simple response
	 * @param string $text text to show up
	 * @param string $status	success|error(|warning|info)
	 * @param boolean $dont_translate if true text will not be translated
	 * @param array $other_args some other arguments to return
	 */
	public static function response(string $text, $status = false, bool $dont_translate = false, array $other_args = []): void
	{
		if (!$status) {
			$status = 'success';
		}
		$response['status'] = $status;
		$response['text'] = $dont_translate ? $text : tr::get($text);
		$other_args ? $response = array_merge($response, $other_args) : '';

		echo json_encode($response);
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
				. '<strong>' . tr::get('attention') . ':</strong> ' . ($dont_translate ? $text : tr::get($text)) . '</p>'
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
		foreach ($arr as &$a)
		{
			if (is_array($a))
			{
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
}
