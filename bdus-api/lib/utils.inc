<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 */

class utils
{
  /**
   * If error or users log files is grown bigger than 5MB (5242880B) these
   * files will be packed and compressed
   */
	public static function packLog()
	{
		try{
			
			$files['main_error'] = MAIN_DIR . 'logs/error.log';
			$files['users'] = MAIN_DIR . 'logs/users.log';
			
			if (defined('PROJ_DIR'))
			{
				$files[$_SESSION['app'] . '-error'] = PROJ_DIR . 'error.log';
				$files[$_SESSION['app'] . '-history'] = PROJ_DIR . 'history.log';
			}
			
			foreach ($files as $name => $path)
			{
				if (!file_exists($path))
				{
					continue;
				}
				
				if (filesize($path) > 5242880 /* 5 MB */)
				{
					utils::write_in_file(MAIN_DIR . 'logs/'. $name . '-' . microtime(1) . '.log', file_get_contents($path), true);
					utils::write_in_file($path, '');
				}
			}
		}
		catch (myException $e)
		{
			$e->log();
		}
	}
	
  /**
   * Checks if current installation is the official online installation or a 
   * local one. The check is performed on host name
   * @return boolean
   */
	public static function is_online()
	{
		return preg_match('/bradypus.net/', $_SERVER['HTTP_HOST']);
	}
  
	/**
	 * Logs $data to error log, for debuggin purposes
	 * @param mixed $data
	 */
	public static function log($data)
	{
		if (!is_string($data))
		{
			$data = var_export($data, true);
		}
		error_log($data);
	}
  
	/**
	 * 
	 * Echoes reference to js files .
	 * If type is false the precompiled compact archive (1 minified file) will be requested
	 * If type['mini'] the compact archive (1 minified file) will be created
	 * If type['debug'] the single files will be requested
	 *  
	 * @param array $files	array of js files (with .js extension) to be requested 
	 * @param string type 	defines the way js will be requested. Can be: mini | debug | false
	 */
	public static function js( $files, $type = false )
	{
    $js_name = md5(implode('', $files));
    
		if ( $type['mini'] || !file_exists('./js/' . $js_name . '.js'))
		{
      utils::emptyDir('js');
			$str = "/*\n * BraDypUS javascripts minified archive includes different sources and licenses"
			. "\n * For details on external libraries (copyrights and licenses) please consult the Credits information"
			. "\n * Created: " . microtime(1) . "\n*/";
	
			foreach ($files as $file) {
				
				$file = ltrim($file);
				
				if ( file_exists( './js-sources/' . $file ) )
				{
					$str .= "\n". JSMin::minify ( file_get_contents ( './js-sources/' . $file ) );
				}
			}
			self::write_in_file ( './js/' . $js_name . '.js', $str);
	
			echo '<script language="JavaScript" type="text/JavaScript" src="./js/' . $js_name . '.js"></script>';
	
		}
		else if ( $type['debug'] )
		{
			foreach ( $files as $file )
			{
				$file = ltrim($file);
				
				if ( file_exists( './js-sources/' . $file ) )
				{
					echo "\n  ".'<script language="JavaScript" type="text/JavaScript" src="./js-sources/' . $file .'?_' . str_replace('.', null, microtime(1)). '"></script>';
				}
			}
		}
		else
		{
			echo '<script language="JavaScript" type="text/JavaScript" src="./js/' . $js_name . '.js"></script>' . "\n";
		}
	}
	
	/**
	 * 
	 * Echoes reference to css files and favicon in page.
	 * If type is false the precompiled compact archive (1 minified file) will be requested
	 * If type['mini'] the compact archive (1 minified file) will be created
	 * If type['debug'] the single files will be requested
	 *  
	 * @param array $files	array of css files (with .css extension) to be requested 
	 * @param string type 	defines the way js will be requested. Can be: mini | debug | false
	 */
	
	public static function css ( $files, $type )
	{
		if ( $type['mini'])
		{
			$str = "/*\n * BraDypUS css minified archive includes different sources and licenses"
			. "\n * For details on external libraries (copyrights and licenses) please consult the Credits information"
			. "\n * Created: " . microtime(1) . "\n*/\n";
      
      
      try
      {
        $opts = array(
          'compress'=>true
        );
        $parser = new Less_Parser($opts);
        $parser->parseFile( "./css-less/main.less");
        $css = $parser->getCss();
        file_put_contents("./css/mini.css", $str . $css);
      }
      catch (Exception $e)
      {
        error_log($e->getTraceAsString());
      }
		}
    echo '<link type="text/css" media="all" rel="stylesheet" href="./css/mini.css'
      . ($type['debug'] ? '?_' . str_replace('.', null, microtime(1)) : '')
      . '" />';
    
		echo "\n  ".'<link rel="shortcut icon" href="./img/favicon.ico">';
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
		if ( !$gz )
		{
			$f = @fopen($file, 'w');
			
			if (!$f) return false;
			
			if ($text)
			{
				if ( !@fwrite ( $f, $text ) ) return false;
			}
			
			@fclose($f);
			
			return true;
		}	
		else
		{
			$file .= '.gz';
			
			$gzh = @gzopen($file,'w9');
			
			if ( !$gzh ) return false;
			 
			if ( !@gzwrite($gzh, $text) ) return false;
			
			@gzclose($gzh);
			
			return true;
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
		if (!$input)
		{
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
	
		if ($input == 'all' && !$translate)
		{
			return $privilege_array;
		}
		
		if ($input == 'all' && $translate)
		{
			foreach ($privilege_array as $id=>$val)
			{
				$tr[$id] = tr::get($val);
			}
			
			return $tr;
		}
		
		if (in_array ($input, $privilege_array))
		{
			return array_search($input, $privilege_array);
		}

		if (in_array($input, array_keys($privilege_array)))
		{
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
		
		if (!$privilege)
		{
			$privilege = 'edit';
		}
		
		if ($_SESSION['user']['privilege'] == 0)
		{
			return false;
		}
		
		switch($privilege)
		{
			case 'enter':
				return ($_SESSION['user']['privilege'] < 39);
				break;
				
			case 'read':
			case 'preview':
				// read & preview can be global or table oriented
				return ($_SESSION['user']['privilege'] < 31);
				break;
				
				
			case 'add_new':
				return ($_SESSION['user']['privilege'] < 26);
				break;
				
			case 'edit':
				return ($_SESSION['user']['privilege'] < 21 || ($creator  && $creator == $_SESSION['user']['id'] && $_SESSION['user']['privilege'] < 26));
				break;
				
			case 'multiple_edit':
				return ($_SESSION['user']['privilege'] < 21);
				break;
				
			case 'admin':
				return ($_SESSION['user']['privilege'] < 11);
				break;
				
			case 'super_admin':
				return ( ($_SESSION['user']['privilege'] < 11 && !self::is_online()) || $_SESSION['user']['privilege'] < 2);
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
		
		if ( $handle )
		{
			while ( false !== ( $file = readdir ( $handle ) ) )
			{
				if (!in_array($file, $dont_consider))
				{
					$dir_content[] = $file;
				}
			}
			
			closedir($handle);
			
			return $dir_content;
		}
		else
		{
			return false;
		} 
	} // end of dirContent

	
	/**
	 * 
	 * Empties a directory recursively.
	 * By default (no dir specified) the user temporary folder will be emptied!
	 * @param string $dir	directory to empty
	 * @param string $delete	if true directory will be deleted
	 * @throws myException
	 */
	public static function emptyDir( $dir = false, $delete = false )
	{
		!$dir ? $dir = PROJ_TMP_DIR : ''; 
		
		$files_in_tmp = self::dirContent( $dir );
		
		if ( $files_in_tmp[0] )
		{
			foreach ( $files_in_tmp as $file )
			{
				if ( is_dir ( $dir . '/' . $file ) )
				{
					self::emptyDir( $dir . '/' . $file, true );
				}
				else
				{
					if ( !@unlink ( $dir . '/' . $file ) )
					{
						throw new myException(tr::sget('cannot_delete_file', $file));
					}
				}
			}
		}
		
		if ( $dir != PROJ_TMP_DIR && $delete)
		{
			if ( !@rmdir( $dir ) )
			{
				throw new myException(tr::sget('cannot_delete_dir', $dir));
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
		if ($decode)
		{
			$text = base64_decode($text);
		}
		if (!$html)
		{
			if ($text != '')
			{
				$text = @htmlentities($text, ENT_QUOTES, 'UTF-8');
			}
		}
		$text = self::linkify($text);
		
		return nl2br($text);
	}
	
	/**
	 * 
	 * Transforms plain-text links to html links
	 * @param string $text		text to check
	 * @param boolean $escape	text is escaped?
	 */
	public static function linkify($text, $escape = false)
	{
		if ($escape)
		{
			$text = preg_replace("/(http:\/\/)([^ \t\r\n$\)]+)/i", "<a href=\\\"\\0\\\" target=\\\"_blank\\\">\\0</a>", $text);
			$text = preg_replace("/([a-z0-9\._-]+)(@[a-z0-9\.-_]+)(\.{1}[a-z]{2,6})/i", "<a href=\\\"mailto:\\1\\2\\3\\\">\\1\\2\\3</a>", $text);
		}
		else
		{
			$text = preg_replace("/(http:\/\/)([^ \t\r\n$\)]+)/i", "<a href=\"\\0\" target=\"_blank\">\\0</a>", $text);
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
		return PROJ_FILES_DIR . $file_id . '.' . $file_ext;
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
	public static function response($text, $status = false, $dont_translate = false, $other_args = false)
	{
		if (!$status)
		{
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
   * Minifies all module javascript files. Return true on success or false on error.
   * @return boolean
   * @throws myException
   */
	public static function compressModScripts()
	{
		$modules = self::dirContent(MOD_DIR);
	
		foreach ($modules as $mod)
		{
			try
			{
				if (is_dir(MOD_DIR . $mod))
				{
					$js_file = MOD_DIR . $mod . '/' . $mod. '.js';
						
					$compress_file = MOD_DIR . $mod . '/' . $mod . '.mini.js';
						
					if (file_exists($js_file))
					{
						$str = "/*\n * copyright BraDypUS \n * Created: " . microtime(1) . "\n*/";
						$str .= JSMin::minify ( file_get_contents ( $js_file ) );
	
						if (!self::write_in_file($compress_file, $str))
						{
							throw new myException('Can not write compressed file ' . $compress_file);
						}
					}
				}
			}
			catch (myException $e)
			{
				$e->log();
				$error = true;
			}
		}
		try
		{
			utils::emptyDir(MAIN_DIR . 'cache', false);
		}
		catch (myException $e)
		{
			$e->log();
			$error = true;
		}
	
		return $error ? false : true;
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
		return array_filter($arr, $callback);
	}
}