<?php
/**
 * Class used to create a new Project from scratch or to migrate projects from v2.7
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Aug 13, 2012
 */

class makeProject
{
	private $app, $msg, $ini;
	
	/**
	 * Sets main variable and does nothing else
	 * 
	 * @param string $proj project name
	 * @param string $migration_db_ini full path to DB connection file
	 */
	public function __construct($proj, $migration_db_ini = false)
	{
		$this->app = $proj;
		
		$migration_db_ini ? $this->ini = $migration_db_ini : '';
	}
	
	/**
	 * Migrates old app to new app: everuthng will be migrated: tables, plugins, files, RS, etc.
	 * 
	 * @param string $basepath relative path to old project files
	 * @param array $tables array with full tables name (prefix + __ + table name)
	 * @param array $plugins array with plugins names, without prefix 
	 * @param array $structure_array associative array with full table name as index and id field as value
	 * @param string $rs_table, if available the name of the stratigraphic relationship (no prefix)
	 */
	public function migrateApp($basepath, $tables, $plugins, $structure_array, $rs_table = false)
	{
		set_time_limit(0);
			
		$this->createCore();
			
		$this->migrateStructure($structure_array, $rs_table);
			
		foreach ($tables as $tb)
		{
			$this->migrateTable($tb);
			$this->migrateTbConfig($tb, $basepath . '/config');
		}
			
		foreach ($plugins as $plg)
		{
			$this->migrateTable($this->app . '__' . $plg);
			$this->migratePlgConfig($plg, $basepath . '/plugins');
		}
			
		$this->migrateFiles($basepath . '/files');
			
		$this->migrateTablesCfg($basepath, $this->app);
	}
	
	private function setMsg($str, $type = false)
	{
		$str = '<p class="' . ($type ? 'text-' . $type : '') . '">' . $str . '</p>';
		$this->msg[] = $str;
	}
	
	/**
	 * Returns system message array as html (if formatted is true) or array of messages 
	 * 
	 * @param boolean $formatted
	 * @return string
	 */
	public function getMsg($formatted = false)
	{
		return ($formatted ? '<ul>' . implode('</li><li>', $this->msg) . '</ul>' : $this->msg);
	}

	
	/**
	 * Migrates all attached files form old app to new app
	 * @param string $orig_path relative path to old project files
	 */
	public function migrateFiles($orig_path = false)
	{
		$db = new DB($this->app);
		
		$files = $db->query('SELECT * FROM `' . $this->app . '__files` WHERE 1');
		
		if (!$files)
		{
			$this->setMsg('No files in ' . $this->app, 'warning');
			return;
		}
		
		foreach($files as $f)
		{
			$orig = ($orig_path ? $orig_path : PROJS_DIR . $this->app . '/files') . '/' . $f['filename'] . '_' . $f['id'] . '.' . $f['ext'];
			$new = PROJS_DIR . $this->app . '/files/' . $f['id'] . '.' . $f['ext'];
			
			if (!file_exists($orig))
			{
				error_log('File ' . $orig . ' does not exist');
				$error[] = true;
				continue;
			}
			if ($orig_path)
			{
				copy($orig, $new) ? $ok[] = true : $error[] = true;
			}
			else
			{
				rename($orig, $new) ? $ok[] = true : $error[] = true;
			}
		}
		
		$this->setMsg('FILE migration: ' . count($ok) . ' sucessfully renamed; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * 
	 * @param string $tb
	 * @throws myException
	 * @return void|makeProject
	 */
	public function migrateTable($tb)
	{
		if (!$this->ini)
		{
			throw new myException('Missing path to database connection data');
		}
		$odb = new DB($this->app, $this->ini);
		
		$tb_str = $odb->query('SHOW COLUMNS FROM `' . $tb . '`');
		
		$fields_data = array();
		
		//array_push($fields_data, array('id', 'ID', 'INTEGER'));
		
		foreach ($tb_str as $fld)
		{
			if ($fld['Field'] == 'table_link')
			{
				$is_plugin = true;
			}
			
			
			if ($fld['Field'] != 'key' && $fld['Field'] != 'table_link' && $fld['Field'] != 'key_link')
			{
				if (preg_match('/tinyint/i', $fld['Type']) || preg_match('/int/i', $fld['Type']) || preg_match('/year/i', $fld['Type']))
				{
					array_push($fields_data, array($fld['Field'], $fld['Field'], 'INTEGER'));
				}
				else if (preg_match('/varchar/i', $fld['Type']) || preg_match('/longtext/i', $fld['Type']) || preg_match('/mediumtext/i', $fld['Type']) || preg_match('/enum/i', $fld['Type']) )
				{
					array_push($fields_data, array($fld['Field'], $fld['Field']));
				}
				else if (preg_match('/date/i', $fld['Type']))
				{
					array_push($fields_data, array($fld['Field'], $fld['Field'], 'DATETIME'));
				}
				else if (preg_match('/float/i', $fld['Type']) || preg_match('/double/i', $fld['Type']) || preg_match('/decimal/i', $fld['Type']))
				{
					array_push($fields_data, array($fld['Field'], $fld['Field'], 'REAL'));
				}
				else
				{
					throw new myException('Type ' . $fld['Type'] . ' not implemented');
				}
			}
		}
		$this->createTable($tb, $fields_data, $is_plugin);

		$this->createTmpl($tb, $fields_data);
		
		$old_content = $odb->query('SELECT * FROM `' . $tb . '` WHERE 1', false, 'read', true);
		
		if (!$old_content)
		{
			$this->setMsg('Table ' . $tb . ' has no data to copy to new database!', 'warning');
			return;
		}
		
		for ($x = 0; $x < count($old_content[0]); $x++)
		{
			$col[] = '?'; 
		}
		
		$ndb = new DB($this->app);
		
		foreach ($old_content as $r)
		{
			$ndb->query('INSERT INTO `' . $tb . '` VALUES (' . implode(', ', $col) .')', $r, 'boolean') ? $ok[] = true : $error[] = true;
		}
		
		$this->setMsg('Table ' . $tb . ': ' . count($ok) . ' records migrated; ' . count($error) . ' records NOT migrated', 'info');
		
		return $this;
	}
	
	/**
	 * 
	 * Migrates structure tables from  v 2.7 to v3
	 * @param array $id_fields
	 * @param string $rs_table
	 * @return makeProject
	 */
	public function migrateStructure($id_fields = false, $rs_table = false)
	{
		if (!$this->ini)
		{
			throw new myException('Missing path to database connection data');
		}
		
		$odb = new DB($this->app, $this->ini);
		
		$ndb = new DB($this->app);
		
		$this->links($odb, $ndb);
		
		$this->geodata($odb, $ndb);
		
		$id_fields ? $this->userlinks($odb, $ndb, $id_fields) : '';
		
		$rs_table ? $this->rs($odb, $ndb, $rs_table) : '';
		
		$this->users($odb, $ndb);
		
		$this->vocabularies($odb, $ndb);
		
	}
	
	public function migrateTablesCfg($path, $prefix)
	{
		$new = array();
		$config = false;
		$tables = false;
		
		require $path . '/config/tables.inc';
		
		foreach ($tables as $tb => $data)
		{
			if ($tb == $prefix . '__allegati')
			{
				$tmp = array(
						"name" => $prefix . "__files",
						"label" => "Files",
						"order" => "id",
						"preview" => array("id", "filename", "ext", "keywords"),
						"tmpl_edit" => "files",
						"tmpl_read" => "files",
						"id_field" => "id");
			}
			else
			{
				$tmp = array();
				
				$tmp['name'] = $tb;
				$tmp['label'] = $data['name'];
				$tmp['order'] = $data['order'];
				$tmp['id_field'] = $data['id_field'];
				$data['sequenza'] ? $tmp['preview'] = utils::csv_explode($data['sequenza']) : '';
				$data['plugins'] ? $tmp['plugin'] = utils::csv_explode($data['plugins']) : '';
				if ($tmp['plugin'])
				{
					foreach ($tmp['plugin'] as &$plg)
					{
						$plg = $prefix . '__' . $plg;
					}
				}
				
				
				// links
				if (is_array($data['links']))
				{
					$tmp['link'] = array();
					
					foreach($data['links'] as $lnk)
					{
						$lnk_parts = utils::csv_explode($lnk, '=');
						
						//$link[$x]['other_tb'] = $lnk_parts[0];
						
						if (!preg_match('/' . $prefix . '__/', $lnk_parts[0]))
						{
							$link['other_tb'] = $prefix . '__' . $lnk_parts[0];
						} else {
							$link['other_tb'] = $lnk_parts[0];
						}
						
						$lnk_fld = utils::csv_explode($lnk_parts[1]);
						
						$x = 0;
						foreach ($lnk_fld as $fld)
						{
							$final_flds = utils::csv_explode($fld, ':');
							$link['fld'][$x]['my'] = $final_flds[0];
							$link['fld'][$x]['other'] = $final_flds[1];
							$x++;
						}
						
						array_push($tmp['link'], $link);
					}
				}
			}
			
			array_push($new, $tmp);
		}
		
		
		
		$plgs = utils::dirContent($path . '/plugins');
		foreach ($plgs as $plg)
		{
			if (strpos($plg, '.inc') > 0 && $plg != 'rs.inc')
			{
				require $path . '/plugins/' . $plg;
				$tmp = array();
				$tmp['name'] = $prefix . '__' . $config['id'];
				$tmp['label'] = $config['name'];
				$tmp['is_plugin'] = '1';
				array_push($new, $tmp);
			}
		}
		
		$new = array('tables' => $new);
		
		/*
		echo PROJS_DIR . $this->app . '/cfg/tables.json';
		echo '<pre>' . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</pre>';
		*/
		
		if (utils::write_formatted_json(PROJS_DIR . $this->app . '/cfg/tables.json', $new))
		{
			$this->setMsg('Config tables.json updated!', 'success');
		}
		else
		{
			$this->setMsg('ERROR: config tables.json NOT updated', 'error');
		}
		
		
	}
	
	public function migratePlgConfig($tb, $orig_path)
	{
		$new = array();
		$config = false;
		
		require $orig_path . '/' . $tb . '.inc';
		
		if (!is_array($config['table_fields']))
		{
			error_log($orig_path . '/' . $tb . '.inc');
			error_log(var_export($config, true));
			
		}
		foreach($config['table_fields'] as $fld_id => $data)
		{
			array_push($new, $this->migrateFld($fld_id, $data));
		}
		
		if (file_exists(PROJS_DIR . $this->app . '/cfg/' . $tb . '.json'))
		{
			rename(PROJS_DIR . $this->app . '/cfg/' . $tb . '.json', PROJS_DIR . $this->app . '/cfg/' . $tb . '.json.txt');
		}
		
		if (utils::write_formatted_json(PROJS_DIR . $this->app . '/cfg/' . $tb . '.json', $new))
		{
			$this->setMsg('Config file for table ' . $tb . ' created!', 'success');
		}
		else
		{
			$this->setMsg('ERROR: Config file for table ' . $tb . ' NOT created!', 'error');
		}
	}
	
	
	public function migrateTbConfig($tb, $orig_path)
	{
		require $orig_path . '/' . $tb . '.inc';
		
		$new = array();
		
		foreach($field as $fld_id => $data)
		{
			array_push($new, $this->migrateFld($fld_id, $data));
		}
		
		
		if (file_exists(PROJS_DIR . $this->app . '/cfg/' . str_replace($this->app . '__', null, $tb) . '.json'))
		{
			rename(PROJS_DIR . $this->app . '/cfg/' . str_replace($this->app . '__', null, $tb) . '.json', PROJS_DIR . $this->app . '/cfg/' . str_replace($this->app . '__', null, $tb) . '.json.txt');
		}
		if (utils::write_formatted_json(PROJS_DIR . $this->app . '/cfg/' . str_replace($this->app . '__', null, $tb) . '.json', $new))
		{
			$this->setMsg('Config file for table ' . str_replace($this->app . '__', null, $tb) . ' created!', 'success');
		}
		else
		{
			$this->setMsg('ERROR: Config file for table ' . str_replace($this->app . '__', null, $tb) . ' NOT created!', 'error');
			
		}
	}
	
	private function migrateFld($fld_id, $data)
	{
		$tmp = array();
		
		$fld_id == 'key' ? $fld_id = 'id' : '';
		
		$tmp['name'] = $fld_id;
		
		$tmp['label'] = $data['label'];
		
		switch($data['tipo'])
		{
			case 'small_text':
				$tmp['type'] = 'text';
				break;
					
			case 'dd_menu':
				$tmp['type'] = 'select';
				break;
			
			case 'boolean':
				$tmp['type'] = 'boolean';
				break;
				
			case 'dd_menu+':
			case 'combo':
				$tmp['type'] = 'combo_select';
				break;
					
			case 'more_options':
				$tmp['type'] = 'multi_select';
				break;
					
			case 'date':
			case 'long_text':
				$tmp['type'] = $data['tipo'];
				break;
					
			default:
				throw new Exception('Type: ' . $data['tipo'] . ' not supported');
				break;
		}
			
		$data['dic']				? $tmp['vocabulary_set'] = $data['dic'] : '';
		$data['from_table']			? $tmp['get_values_from_tb'] = $this->app . '__' .$data['from_table'] : '';
		$data['ajax_check']			? $tmp['check'] = utils::csv_explode($data['ajax_check']) : '';
		$data['def_value']			? $tmp['def_value'] = $data['def_value'] : '';
		$data['forced_value']		? $tmp['def_value'] = $data['forced_value'] : '';
		$data['readonly'] == 'SI'	? $tmp['readonly'] = '1' : '';
		$data['disabled'] == 'SI'	? $tmp['disabled'] = '1' : '';
		$data['display'] == 'NO'	? $tmp['hide'] = '1' : '';
		$data['help']				? $tmp['info'] = $data['help'] : '';
		
		return $tmp;
	}

	
	
	/**
	 * Creates core tables, folders and files
	 * @return makeProject
	 */
	public function createCore()
	{
		foreach($this->getDirs() as $dir)
		{
			if (!is_dir($dir))
			{
				if (mkdir($dir, 0777, true))
				{
					$this->setMsg('Dir ' . $dir . ' created', 'success');
				}
				else
				{ 
					$this->setMsg('Error! Dir ' . $dir . ' NOT created', 'error');
				}
			}
			else
			{
				$this->setMsg('Dir ' . $dir . ' already present', 'warning');
			}

			chmod($dir, 0777);
		}
		
		foreach($this->getFiles() as $file)
		{
			if ($file['content'])
			{
				if(utils::write_in_file($file['name'], $file['content']))
				{
					$this->setMsg('File ' . $file['name'] . ' created', 'success');
				}
				else
				{
					$this->setMsg('File ' . $file['name'] . ' NOT created', 'error');
				}
			}
			else
			{
				if(touch($file['name']))
				{
					$this->setMsg('File ' . $file['name'] . ' created', 'success');
				}
				else
				{
					$this->setMsg('File ' . $file['name'] . ' NOT created', 'error');
				}
			}
			
			chmod($file['name'], 0777);
		}
		
		$db = new DB($this->app);
		
		foreach($this->getSysTb() as $tb=>$sql)
		{
			if ($db->query($sql, false, 'boolean'))
			{
				$this->setMsg('Table ' . $tb . ' created', 'success');
			}
			else
			{
				$this->setMsg('Error! table ' . $tb . ' not created', 'error');
			}
		}
		
		return $this;
	}

	/**
	 * Creates table and cfg data
	 * @param string $tb
	 * @param array $fields 0 => fld_id, 1 => fld_label, 2 => fld_type (optional, default: text)
	 * @param boolean $isplugin
	 * @return makeProject
	 */
	public function createTable($tb, $fields, $isplugin = false)
	{
		$tb_obj = array();
	
		foreach($fields as $fld)
		{
			$sql_part[] = ' "' . $fld[0] . '"' . ( $fld[2] ? ' ' . $fld[2] . ' ' : ' TEXT ') . ' ';
				
			$arr['name'] 	= $fld[0];
			$arr['label']	= $fld[1];
			$arr['type']	= ($fld[2] == 'DATETIME' ? 'date' : 'text');
			($fld[2] == 'INTEGER' || $fld[2] == 'REAL') ? $arr['check'] = array('int') : '';
			$tb_obj[] = $arr;
			
			$fld[0] == 'creator' ? $arr['hide'] = true : '';
			unset($arr);
		}
	
		$file = PROJS_DIR . $this->app . '/cfg/' . str_replace($this->app . '__', null, $tb) . '.json';
	
		if (utils::write_formatted_json($file, $tb_obj))
		{
			$this->setMsg('File ' . $file . ' created', 'success');
		}
		else
		{
			$this->setMsg('Error! File ' . $file . ' NOT created', 'error');
		}
	
		$sql = 'CREATE TABLE "' . $tb . '" ("id" INTEGER PRIMARY KEY,'
			. ($isplugin ? ' `table_link` TEXT, `id_link` INTEGER, ' : '')
			. implode(', ', $sql_part)
			. ' )';
		
		$db = new DB($this->app);
	
		if (($db->query('DROP TABLE IF EXISTS "' . $tb . '"', false, 'boolean') AND $db->query($sql, false, 'boolean')) )
		{
			$this->setMsg('Table ' . $tb . ' created', 'success');
		}
		else
		{
			$this->setMsg('Error! Table ' . $tb . ' NOT created', 'error');
		}
		
		$json = json_decode(file_get_contents(PROJS_DIR . $this->app . '/cfg/tables.json'), true);
	
		if ($isplugin)
		{
			$array = array('name'=>$tb, 'label'=>$tb);
				
			if (!is_array($json['plugins']))
			{
				$json['plugins'] = array();
			}
			foreach ($json['plugins'] as &$tbdata)
			{
				if ($tbdata['name'] == $tb)
				{
					$tbdata = $array;
					$found = true;
				}
			}
			if (!$found)
			{
				array_push($json['plugins'], $array);
			}
		}
		else
		{
			$array = array('name'=>$tb, 'label'=>$tb, 'order'=>'id', 'id_field'=>'id', 'preview' => 'id');
				
			if (!is_array($json['tables']))
			{
				$json['tables'] = array();
			}
			foreach ($json['tables'] as &$tbdata)
			{
				if ($tbdata['name'] == $tb)
				{
					$tbdata = $array;
					$found = true;
				}
			}
			if (!$found)
			{
				array_push($json['tables'], $array);
			}
		}
	
		if (utils::write_formatted_json(PROJS_DIR . $this->app . '/cfg/tables.json', $json) )
		{
			$this->setMsg('Config file TABLES updated for ' . $tb, 'success');
		}
		else
		{
			$this->setMsg('Error! Config file TABLES NOT created for ' . $tb, 'error');
		}
		
		return $this;
	}
	
	/**
	 * Creates template file
	 * @param string $tb
	 * @param array $fields
	 * @return makeProject
	 */
	public function createTmpl($tb, $fields)
	{
		$html = '<div class="row">' .
				"\n\t" . '<div class="{{ print.cell(9) }}">' . 
				"\n\t\t" . '{{ print.image_thumbs }}';
		foreach($fields as $fld)
		{
			$html .= "\n\t\t" . '{{ print.fld(\'' . $fld[0] . '\') }}';
		}
		$html .=  "\n\t" . '</div>' . 
				"\n\t" . '<div class="{{ print.cell(3) }}">' .
				"\n\t\t" . '{{ print.links }}' .
				"\n\t\t" . '{{ print.geodata }}' .
				"\n\t\t" . '{{ print.permalink }}' .
				"\n\t" . '</div>' .
				"\n" . '</div>';
		
		$tmpl_name = 'auto_' . str_replace($this->app . '__', null, $tb);
	
		if (utils::write_in_file( PROJS_DIR . $this->app . '/templates/' . $tmpl_name . '.twig', $html) )
		{
			$this->setMsg('Created: ' . PROJS_DIR . $this->app . '/templates/' . $tmpl_name . '.twig', 'success');
		}
		else
		{
			$this->setMsg('Error! Not created: ' . PROJS_DIR . $this->app . '/templates/' . $tmpl_name . '.twig'. 'error');
		}
		
		return $this;
	}	
	
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 */
	private function vocabularies(DB $odb, DB $ndb)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__vocabularies`');
	
		$voc_res = $odb->query('SELECT * FROM `' . $this->app . '__dic` WHERE 1');
	
		if (!$voc_res)
		{
			$this->setMsg('No Vocabularies to migrate in ' . $this->app, 'warning');
			return;
		}
	
		foreach ($voc_res as $r)
		{
			$q = 'INSERT INTO `' . $this->app. '__vocabularies` '
			. '(`voc`, `def`, `sort`)'
			. ' VALUES '
			. '(:voc, :def, :sort)';
	
			$values = array(
					':voc' 	=>$r['dic'],
					':def'	=>$r['def'],
					':sort'	=>$r['order']
			);
	
			$ndb->query($q, $values, 'boolean') ? $ok[] = true : $error[] = true;
		}
		
		$this->setMsg('VOCABULARIES migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 */
	private function users(DB $odb, DB $ndb)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__users` WHERE `id` > 1');
	
		$users_res = $odb->query('SELECT * FROM `' . $this->app . '__users` WHERE `email` != \'jbogdani@gmail.com\'');
		
		if (!$users_res)
		{
			$this->setMsg('No USERS to migrate in ' . $this->app, 'warning');
			return;
		}
	
		foreach ($users_res as $r)
		{
			$q = 'INSERT INTO `' . $this->app. '__users` '
			. '(`name`, `email`, `password`, `privilege`)'
			. ' VALUES '
			. '(:name, :email, :password, :privilege)';
	
			$values = array(
					':name' 	=>$r['nome'],
					':email'	=>$r['email'],
					':password'	=>$r['password'],
					':privilege'=>$r['privilege']
			);
	
			$ndb->query($q, $values, 'boolean') ? $ok[] = true : $error[] = true;
		}
		
		$this->setMsg('USERS migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 * @param string $rs_table
	 */
	private function rs(DB $odb, DB $ndb, $rs_table)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__rs`');
	
		$rs_res = $odb->query('SELECT * FROM `' . $this->app . '__rs` WHERE 1');
		
		if (!$rs_res)
		{
			$this->setMsg('No RS to migrate in ' . $this->app, 'warning');
			return;
		}
		
		$ndb->beginTransaction();
	
		foreach ($rs_res as $r)
		{
			$q = 'INSERT INTO `' . $this->app. '__rs` '
				. '(`id`, `tb`, `first`, `second`, `relation`)'
				. ' VALUES '
				. '(:id, :tb, :first, :second, :relation)';
	
			$values = array(
					':id' 		=>$r['key'],
					':tb' 		=>$rs_table,
					':first'	=>$r['uno'],
					':second'	=>$r['due'],
					':relation'	=> ( $r['id_rap'] + 1 )
			);
			
			$ndb->query($q, $values, 'boolean') ? $ok[] = true : $error[] = true;
			
		}
		$ndb->commit();
		
		$this->setMsg('RS migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 * @param unknown_type $id_fields
	 */
	private function userlinks(DB $odb, DB $ndb, $id_fields)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__userlinks`');
	
		$links_res = $odb->query('SELECT * FROM `' . $this->app . '__links` WHERE 1');
		
		if (!$links_res)
		{
			$this->setMsg('No LINKS to migrate in ' . $this->app, 'warning');
			return;
		}
	
		foreach ($links_res as $r)
		{
			$key = $odb->query('SELECT `key` FROM `' . $r['nome_tabella'] . '` WHERE `' . $id_fields[$r['nome_tabella']] . '` = :id_dest' , array(':id_dest'=> $r['id_dest']));
			
			$q = 'INSERT INTO `' . $this->app. '__userlinks` '
				. '(`tb_one`, `id_one`, `tb_two`, `id_two`)'
				. ' VALUES '
				. '(:tb_one, :id_one, :tb_two, :id_two)';
	
			if (is_null($key[0]['key']))
			{
				error_log('ORPHAN: SELECT `key` FROM `' . $r['nome_tabella'] . '` WHERE `' . $id_fields[$r['nome_tabella']] . '` = \'' . $r['id_dest'] . "'");
				continue;
			}
	
			$values = array(
					':tb_one' 	=>$this->app . '__files',
					':id_one'	=>$r['key_allegati'],
					':tb_two'	=>$r['nome_tabella'],
					':id_two'	=>$key[0]['key']
			);
	
			$ndb->query($q, $values, 'boolean') ? $ok[] = true : $error[] = false;
		}
		
		$this->setMsg('USERLINKS migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 * @throws myException
	 */
	private function geodata(DB $odb, DB $ndb)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__geodata`');
		try
		{
			$geodata_res = $odb->query('SELECT `key`, `table_link`, `key_link`, asText(`geometry`) as geometry, `geo_el_elips`, `geo_el_asl` FROM `' . $this->app . '__geodata` WHERE 1');
			
			if(!$geodata_res)
			{
				throw new myException();
			}
		}
		catch (myException $e)
		{
			$this->setMsg('No GEODATA to migrate in ' . $this->app, 'warning');
			return;
		}
		
		foreach($geodata_res as $r)
		{
			$q = 'INSERT INTO `' . $this->app. '__geodata` '
			. '(`id`, `table_link`, `id_link`, `geometry`, `geo_el_elips`, `geo_el_asl`)'
			. ' VALUES '
			. '(:id, :table_link, :id_link, :geometry, :geo_el_elips, :geo_el_asl)';
			
			$values = array(
					':id' 			=>$r['key'],
					':table_link' 	=>$r['table_link'],
					':id_link'		=>$r['key_link'],
					':geometry'		=>$r['geometry'],
					':geo_el_elips'	=>$r['geo_el_elips'],
					':geo_el_asl'	=>$r['geo_el_asl']
			);
			
			$ndb->query($q, $values, 'boolean') ? $ko[] = true : $error[] = true;
		}
		
		$this->setMsg('GEODATA migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * 
	 * @param DB $odb
	 * @param DB $ndb
	 */
	private function links(DB $odb, DB $ndb)
	{
		$ndb->query('DELETE FROM `' . $this->app . '__files`');
		
		
		$allegati_res = $odb->query('SELECT * FROM `' . $this->app . '__allegati` WHERE 1');
		
		if (!$allegati_res)
		{
			$this->setMsg('No FILES to migrate in ' . $this->app, 'warning');
			return;
		}
		
		foreach ($allegati_res as $r)
		{
			$q = 'INSERT INTO `' . $this->app. '__files` '
				. '(`id`, `creator`, `ext`, `keywords`, `description`, `printable`, `filename`)'
				. ' VALUES '
				. '(:id, :creator, :ext, :keywords, :description, :printable, :filename)';
				
			$values = array(
					':id' 			=>$r['key'],
					':creator' 		=>$r['creator'],
					':ext'			=>$r['ext'],
					':keywords'		=>$r['key_words'],
					':description'	=>$r['descrizione'],
					':printable'	=>$r['printable'],
					':filename' 	=>$r['prefix']
			);
				
			$ndb->query($q, $values, 'boolean') ? $ok[] = true : $error[] = true;
		}
		
		$this->setMsg('FILES migration: ' . count($ok) . ' succedded; ' . count($error) . ' failed', 'info');
	}
	
	/**
	 * Returns array with app folders
	 * @return multitype:string
	 */
	private function getDirs()
	{
		$proj_dir = PROJS_DIR . $this->app;
	
		return array(
				$proj_dir,
				$proj_dir . '/backups',
				$proj_dir . '/cfg',
				$proj_dir . '/db',
				$proj_dir . '/export',
				$proj_dir . '/files',
				$proj_dir . '/templates',
				$proj_dir . '/tmp',
				$proj_dir . '/geodata'
		);
	}
	
	/**
	 * Returns array with path to files to create
	 * @return multitype:multitype:string
	 */
	private function getFiles()
	{
		$proj_dir = PROJS_DIR . $this->app;
	
		return array(
				array('name' =>$proj_dir . '/error.log'),
				array('name'=>$proj_dir . '/welcome.html', 'content'=>'<h1>' . strtoupper($this->app) . '</h1><h3>A BraDypUS database</h3><hr />'),
				array('name'=>$proj_dir . '/cfg/app_data.json', 'content'=>'{"lang": "it","name": "' . $this->app . '","definition": "A BraDypUS database","status": "on","offline": "0","dot_path": "\/usr\/local\/bin\/"}'),
				array('name'=>$proj_dir . '/cfg/tables.json', 'content' => '{"tables": [{"name": "' . $this->app . '__files","label": "Files","order": "id","preview": ["id","filename","ext","keywords"],"tmpl_edit": "files","tmpl_read": "files","id_field": "id"}]}'),
				array('name'=>$proj_dir . '/cfg/files.json', 'content'=>'[ { "name": "id", "label": "ID", "type": "text", "readonly": true }, { "name": "ext", "label": "Extension", "type": "text", "check": ["not_empty"], "readonly": true }, { "name": "filename", "label": "Filename", "type": "text", "check": ["not_empty"], "readonly": true }, { "name": "keywords", "label": "Keywords", "type": "text" }, { "name": "description", "label": "Description", "type": "long_text" }, { "name": "printable", "label": "Printable", "type": "boolean" }]'),
				array('name'=>$proj_dir . '/db/bdus.sqlite'),
				array('name'=>$proj_dir . '/templates/files.twig', 'content'=>'<div class="row">' .
						"\n\t" . '<div class="{{ print.cell(3) }}">' .
							"\n\t\t" . '{{ print.image_thumbs(2) }}' .
						"\n\t" . '</div>' .
						"\n\n\t" . '<div class="{{ print.cell(4) }}">' .
							"\n\t\t{{ print.fld('id') }}" .
							"\n\t\t{{ print.fld('filename') }}" .
							"\n\t\t{{ print.fld('ext') }}" .
							"\n\t\t{{ print.fld('keywords') }}" .
							"\n\t\t{{ print.fld('description') }}".
							"\n\t\t{{ print.fld('printable') }}" .
						"\n\t</div>" .
						
						"\n\n\t" . '<div class="{{ print.cell(3) }}">' . 
							"\n\t\t{{ print.links }}" .
						"\n\t</div>" . 
					"\n</div>")
		);
	}
	
	/**
	 * return array with SQL for system tables
	 * @return multitype:string
	 */
	private function getSysTb()
	{
		return array(
				'files'			=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__files" ("id" INTEGER PRIMARY KEY ,"creator" TEXT,"ext" TEXT,"keywords" TEXT,"description" TEXT,"printable" INTEGER,"filename" TEXT)',
				'geodata'		=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__geodata" ("id" INTEGER PRIMARY KEY ,"table_link" TEXT,"id_link" INTEGER,"geometry" TEXT,"geo_el_elips" INTEGER,"geo_el_asl" INTEGER)',
				'queries'		=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__queries" ("id" INTEGER PRIMARY KEY ,"user_id" INTEGER,"date" DATE,"name" TEXT,"text" TEXT,"table" TEXT,"is_global" INTEGER)',
				'users'			=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__users" ("id" INTEGER PRIMARY KEY ,"name" TEXT,"email" TEXT,"password" TEXT,"privilege" INTEGER,"settings" TEXT)',
				'vocabularies'	=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__vocabularies" ("id" INTEGER PRIMARY KEY ,"voc" TEXT,"def" TEXT,"sort" INTEGER)',
				'userlinks'		=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__userlinks" ("id" INTEGER PRIMARY KEY  NOT NULL , "tb_one" TEXT NOT NULL , "id_one" INTEGER NOT NULL , "tb_two" TEXT NOT NULL , "id_two" INTEGER NOT NULL, "sort" INTEGER)',
				'charts'		=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__charts" ("id" INTEGER PRIMARY KEY  NOT NULL ,"user_id" INTEGER,"name" TEXT,"query" TEXT,"date" DATETIME)',
				'rs'			=> 'CREATE TABLE IF NOT EXISTS "' . $this->app . '__rs" ("id" INTEGER PRIMARY KEY ,"tb" TEXT,"first" TEXT,"second" TEXT, "relation" INTEGER)',
				'user JBogdani'	=>'INSERT INTO "' . $this->app . '__users" VALUES ("1","Julian Bogdani","jbogdani@gmail.com","0b6a63765cf0acb1022fc7c84ed8dcb104f221ed","1","");'
		);
	}
}