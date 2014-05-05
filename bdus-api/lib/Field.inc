<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			See file LICENSE distributed with this code
 * @uses			cfg
 */

class Field implements iTemplate
{
	
	protected $tb, $context, $record, $db, $settings, $current_plugin, $current_plugin_index;
	// old: $db_data > $record
	
	
	public function __construct($context, Record $record)
	{
		$this->tb = $record->getTb();

		$this->context = $context;
		
		$this->record = $record;
		
		$this->db = $record->getDB();
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::cell()
	 */
	public function cell($nr)
	{
		return "col-sm-$nr";
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::sqlSum()
	 */
	public function sqlSum($tb, $fld, $filter = false)
	{
		try
		{
			if ($filter)
			{
				$wh_arr = utils::csv_explode($filter, ';');
				if (is_array($wh_arr) && !empty($wh_arr))
				{
					foreach($wh_arr as $w)
					{
						$w_arr = utils::csv_explode($w, '=');
						
						$tmp_where[] = "`{$w_arr[0]}` = '" . $this->record->getCore($w_arr[1]) . "'";
					}
				}
				$final_where = implode(' AND ', $tmp_where);
			}
			else
			{
				$final_where = '1';
			}
			
			
			$sql = "SELECT SUM(`$fld`) AS `total` FROM `" . PREFIX . "__$tb` WHERE $final_where";
			
			$res = $this->db->query($sql);
			
			if (is_array($res) && !empty($res))
			{
				return $res[0]['total'];
			}
		}
		catch (myException $e)
		{
			$e->log();
		}
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::simpleSum()
	 */
	public function simpleSum($fields)
	{
		$flds_arr = utils::csv_explode($fields);
		$sum = 0;
		
		if (is_array($flds_arr) && !empty($flds_arr))
		{
			foreach($flds_arr as $fld)
			{
				$sum += (int)$this->record->getCore($fld);
			}
		}
		 return $sum;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::permalink()
	 */
	public function permalink()
	{
		if ($this->context != 'add_new')
		{
			return '<div class="permalink">' .
        '<a href="' . "./#/" . PREFIX . '/' . str_replace(PREFIX . '__', null, $this->tb) . '/' . $this->record->getCore('id')  . '">PERMALINK</a>' .
      '';
		}
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::links()
	 */
	public function links()
	{
		if($this->context == 'add_new')
		{
			return;
		}
		
		$html = '<fieldset class="links">'
			. '<legend>' . tr::get('links_avaliable') . '</legend>';
		
		// GET CORE LINKS
		$corelinks = $this->record->getCoreLinks();
		
		// SHOW CORELINKS
		$html .= '<i class="glyphicon glyphicon-link"></i> <strong>' . tr::get('core_links'). '</strong><br />';
		
		if (is_array($corelinks))
		{
			$html .= '<ul>';
			foreach($corelinks as $dest_tb=>$l_arr)
			{
				$html .= '<li><span class="btn-link" onclick="' .
         "api.showResults('" . $dest_tb . "', 'type=encoded&q_encoded=" . base64_encode($l_arr['query']) . "', '" . tr::get('saved_queries') . " (". cfg::tbEl($dest_tb, 'label') . ")');" .
          '" href="javascript:void(0)">' . 
          tr::sget('links_in_table', array('<strong>' . $l_arr['tot'] . '</strong>', '<strong>' . cfg::tbEl($dest_tb, 'label') . '</strong>')) .
          '</span>';
        if (cfg::tbEl($dest_tb, 'rs'))
        {
          $html .= ' (<span class="btn-link" onclick="api.record.showMatrix(\'' . $dest_tb . '\', \'' . base64_encode($l_arr['query']) . '\')">' . tr::get('harris_matrix'). '</span>)';
        }
         $html .= '</li>';
			}
			$html .= '</ul>';
		}
		else
		{
			$html .= '<p>' . tr::get('no_core_links') . '</p>';
		}
		
		// SHOW USER LINKS
		$html .= '<div class="showUserLinks" data-context="' . $this->context . '" data-tb="' . $this->tb . '" data-id="' . $this->record->getCore('id') . '"></div>';
		$html .= '</fieldset>';
		
		return $html;
	}
	
	public function userlinks()
	{
		if ($this->context != 'add_new')
		{
			return '<div class="showUserLinks" data-context="' . $this->context . '" data-tb="' . $this->tb . '" data-id="' . $this->record->getCore('id') . '"></div>';
		}
	}

	/**
	 * (non-PHPdoc)
	 * @see iTemplate::geodata()
	 */
	public function geodata()
	{
		if($this->context == 'add_new')
		{
			return;
		}
		
		$gdata = $this->record->getGeodata();
		
		$html = '<fieldset class="geodata">'
			. '<legend>' . tr::get('geodata') . '</legend>';
		
		if (is_array($gdata))
		{
			$html .= '<p>' . tr::sget('x_geodata_available', '<strong>' . count($gdata) . '</strong>')
			. ' <span class="btn btn-link" onclick="core.runMod(\'geoface2\', [\'' . $this->tb . '\', \'' . base64_encode(' `id`= ' . $this->record->getCore('id') . ' ') . '\']);">' . tr::get('view_on_map') . '</span></p>';
		}
		else
		{
			$html .= '<p>' . tr::get('no_geodata_available') . '</p>';
		}
		
		$html .= '<p><span class="btn btn-link" onclick="core.runMod(\'geoface2\', \'' . $this->tb . '\');">' . tr::get('view_all_records_on_map') . '</span></p>';

		$html .= '</fieldset>';
		
		return $html;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::showall()
	 */
	public function showall()
	{
		$html = $this->image_thumbs();
	
		$html .= '<div class="row">' .
				'<div class="col-sm-6">'
		. '<fieldset>';
	
		// CORE
		$flds_arr = cfg::fldEl($this->tb, 'all', 'name');
	
		foreach ($flds_arr as $fld)
		{
			$html .= $this->fld($fld);
		}
		$html .= '</fieldset>'
		
		// RS
		. $this->rs()
		
		. '</div>';
	
		$html .= '<div class="col-sm-4">';
	
		//LINKS
		$html .= $this->links();
		
		//GEODATA
		$html .= $this->geodata();
	
		// PLUGINS
		$plg_arr = cfg::tbEl($this->tb, 'plugin');
	
		if (is_array($plg_arr))
		{
			foreach ($plg_arr as $plg)
			{
				$html .= $this->plg($plg);
			}
		}
		
		//PERMALINK
		$html .= $this->permalink();
		
		$html .= '</div>';
	
		$html .= '</div>';
	
	
		return $html;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::rs()
	 */
	public function rs()
	{
		if($this->context == 'add_new')
		{
			return;
		}
		$rs_fld = cfg::tbEl($this->tb, 'rs');
		
		if ($rs_fld)
		{
			return '<div class="showRS" data-context="' . $this->context. '" data-table="' . $this->tb . '" data-id="' . $this->record->getCore($rs_fld) . '"></div>';
		}
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::image_thumbs()
	 */
	public function image_thumbs($max = false)
	{
		// get file data
		$data_arr = $this->record->getFullFiles();
		
		// set default maximum no of images to show
		if (!$max)
		{
			$max = 2;
		}
		
		// max can not be bigger than the no of images found in the DB
		if ($max == 'all' || $max > count($data_arr))
		{
			$max = count($data_arr);
		}
		
		
		// start writing main container. Styles are handled via CSS
		$html = '<div class="file_thumbnails clearfix">';
		
		
		
		
		// 1. Some images are found in the database
		if (is_array($data_arr) AND !empty($data_arr[0]))
		{
			// show images (div.db_images)
			for ($x = 0; $x < $max; $x++)
			{
				$html .= '<div class="db_images img_container">' . images::getThumbHtml($data_arr[$x]) . '</div>';
			}
		}
		
		// write anyway service div
		$html .= '<div class="service">';
				
		
		// 2. If some images are found in DB and current table is not FILES the "Show Gallery Button" will be displayed in th lower div
		if (is_array($data_arr) && !empty($data_arr[0]) && $this->tb != PREFIX . '__files')
		{
			$html .= '<div class="clearfix gal_button">' . 
					'<button type="button" class="btn btn-sm btn-info service" onclick="api.file.show_gallery(\'' . $this->tb . '\', \'' . $this->record->getCore('id') . '\');"><i class="glyphicon glyphicon-white glyphicon-file"></i> ' .
						tr::sget('x_files_linked', count($data_arr)) .
					'</button>' .
					'</div>';
		}
		
		// 3. Show image replace button if context is EDIT and current table is FILES (no need to check if there are any files found in DB. One file is allways present)
		if ($this->tb == PREFIX . '__files' && $this->context == 'edit')
		{
			$uid = uniqid('newfile');
		
			$html .= '<div id="' . $uid . '" class="load_img_placeholder">sostituisci l\'immagine</div>' .
					'<script>' .
						"api.fileUpload($('#" . $uid . "'), 'controller.php?obj=file_ctrl&method=upload', {" .
							"'button_text': '" . tr::get('replace_file', true). "'," .
							"'complete': function(id, fileName, resp){" .
								"$('#{$uid}').parents('form').find('input[name*=filename]').val(resp.filename).attr('changed', 'auto');" .
								"$('#{$uid}').parents('form').find('input[name*=ext]').val(resp.ext).attr('changed', 'auto');" .
								"$('#{$uid}').parents('form').find('div.file_thumbnails').html(resp.thumbnail);" .
							"}" .
						"});" .
					'</script>';
		}
		
		// 4. Show "upload new file" button if context is ADD_NEW and current table is FILES
		if ($this->tb == PREFIX . '__files' && $this->context == 'add_new')
		{
			$uid = uniqid('newfile');
		
			$html .= '<div id="' . $uid . '" class="load_img_placeholder"></div>' .
						'<script>' .
							"api.fileUpload($('#" . $uid . "'), 'controller.php?obj=file_ctrl&method=upload', {" .
								"'limit2one': true," .
								"'complete': function(id, fileName, resp){" .
									"$('#{$uid}').parents('form').find('input[name*=filename]').val(resp.filename).attr('changed', 'auto');" .
									"$('#{$uid}').parents('form').find('input[name*=ext]').val(resp.ext).attr('changed', 'auto');" .
									"if (resp.success){" .
										"$('#{$uid}').html('div.file_thumbnails').html(resp.thumbnail);" .
									"}" .
								"}" .
							"});" .
						'</script>';
		}
		
		
		// 5. Attach file to record if current table is not FILES, context is EDIT and user can edit
		if ($this->tb != PREFIX . '__files' && $this->context == 'edit' && utils::canUser('edit', $this->record->getCore('creator')))
		{
			$uid = uniqid('newfile');
				
			$html .= '<div id="' . $uid . '" class="load_img_placeholder"></div>' .
					'<script>' .
						"api.fileUpload($('#" . $uid . "'), 'controller.php?obj=file_ctrl&method=uploadLink&dest_table=" . $this->tb . "&dest_id=" . $this->record->getId() . "', {" .
							"'button_text' : '" . tr::get('click_drag_link_file', true) . "'," .
							"'limit2one': true," .
							"'complete': function(id, fileName, resp){" .
								"if(resp.status == 'success'){" .
									"$('#{$uid}').parents('div.service').before('<div class=\"uploaded_images img_container\">' + resp.thumbnail + \"</div>\");" .
								"}" .
								"core.message(resp.text, resp.status);" .
							"}" .
						"});" .
					'</script>';
		}
		
		
		// end of service div
		$html .= '</div>';
		
		// end of main file container
		$html .= '</div>';
		
		return $html;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::plg_fld()
	 */
	public function plg_fld($fieldname, $formatting = false)
	{
		return $this->fld($fieldname, $formatting, $this->current_plugin, $this->current_plugin_index);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::fld()
	 */
	public function fld($fieldname, $formatting = false, $plg_name = false, $plg_index = false)
	{
		// get settings
		$setting = $this->setParam($fieldname, $formatting, $plg_name, $plg_index);
		
		if ($this->context == 'read' || $this->context == 'preview')
		{

			$html = $this->HtmlStartDiv($setting)
			. $this->HtmlLabel($setting['label'])
			. $this->HtmlContentRead($setting)
			. $this->HtmlEndDiv();
		}
		else
		{
			$html = $this->HtmlStartDiv($setting)
			. $this->HtmlLabel($setting['label'], $setting['help'])
			. $this->HtmlContentEdit($setting)
			. $this->HtmlEndDiv();
		}
		return $html;
	}
	
	/**
	 * Returns html for a single plugin row
	 * @param string $plg plugin name
	 * @param mixed $index plugin row index
	 * @return void|string
	 */
	protected function showPlgRow($plg, $index)
	{
		$html = '<div class="pluginrow">' .
					'<fieldset>';
		
		if (file_exists(PROJ_TMPL_DIR . str_replace(PREFIX . '__', null, $plg) . '.twig'))
		{
			$settings = unserialize(CACHE);
			$settings['autoescape'] = false;
			
      $html .= $this->fld('id', false, $plg, $index);
      
			$twig = new Twig_Environment(new Twig_Loader_Filesystem(PROJ_TMPL_DIR), $settings);
			
			$html .= $twig->render(str_replace(PREFIX . '__', null, $plg) . '.twig', array(
					'print' => $this,
					'plg'=> $plg,
					'index' => $index
			));
		}
		else 
		{
			$plg_fields = cfg::fldEl($plg, 'all', 'label');
			
			if (!is_array($plg_fields) || empty($plg_fields))
			{
				return;
			}
			
			foreach ($plg_fields as $fld_n => $fld_l)
			{
				$html .= $this->fld($fld_n, false, $plg, $index);
			}
		}
		
		$html .= '</fieldset>' .
				'</div>';
		
		return $html;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see iTemplate::plg()
	 */
	public function plg($plg)
	{
		if(!preg_match('/' . PREFIX . '__/', $plg))
		{
			$plg = PREFIX . '__' . $plg;
		}
		
		$this->current_plugin = $plg;
		
		// Start writing plugin container
		$html = '<fieldset class="plugin ' . $plg . '">' .
				'<legend>' . cfg::tbEl($plg, 'label'). '</legend>';
		
		// Get records from database
		$plg_array = $this->record->getPlugin($plg);
		
		//0. Stop rendering if context is READ and no data are available
		if (empty($plg_array) && $this->context == 'read')
		{
			return;
		}
		
		// 1. No records found in database, but context is add_new or edit => show empty form:
		if ( empty($plg_array) AND ($this->context == 'edit' OR $this->context == 'add_new') )
		{
			$html .= $this->showPlgRow($plg, uniqid()) .
					'<br/>';
		}
		
		// 2. Some records returned from database
		if (is_array($plg_array) AND !empty($plg_array))
		{
			foreach($plg_array as $index => $plg_data)
			{
				$this->current_plugin_index = $index;
				$html .= $this->showPlgRow($plg, $index) .
					( ( $this->context == 'add_new' OR $this->context == 'edit') ?'<button type="button" class="deleteplg btn btn-sm btn-default"><i class="glyphicon glyphicon-minus"></i></button>' : '' ) .
					'<br/>';
			}
		}
		
		// Add row button, if context is edit or add_new 
		if ($this->context == 'edit' OR $this->context == 'add_new')
		{
			$html .= '<div class="plg_container">' .
					'</div>'. 
					'<button type="button" class="addplg btn btn-sm btn-default"><i class="glyphicon glyphicon-plus"></i></button>';
		}
		$html .= '</fieldset>';
		
		$this->current_plugin = false;
		$this->current_plugin_index = false;
		return $html;
	}
	
	
	/**
	 * set parameters for $fld, given the context ($type = core | plugin) and formating data (opt).
	 * If is present a table name ($tb) thi will be processed and not the current one.
	 *
	 * @param string $fld			field name
	 * @param string $formatting	layout info (att:value; att:value;), optional
	 * @param string $plg_name		plugin name, if false I'dealing with core!
	 * @param int	$plg_index		plugin row index
	 */
	protected function setParam($fld, $formatting, $plg_name = false, $plg_index = false)
	{
		/*
		 * $setting indexes from config file
		 * name
		 * label
		 * type
		 * check (array)
		 * get_values_from
		 * dic
		 * help
		 * hide
		 * readonly
		 * maxlength
		 * db_type
		 * min
		 * max
		 * pattern
		 * 
		 * $setting indexes from template
		 *		all previous + 
		 *		width
		 *		height
		 *		div_class
		 *		onchange
		 *		ondblclick
		 * 
		 * calculated $setting indexes
		 * 		fieldname
		 * 		name
		 * 		id
		 * 		menu_values
		 * 		data
		 * 		html_data
		 * 		display
		 *		readonly
		 *		disabled
		 * 		maxlength
		 * 
		 */
		

		// get $config data from cfg object (file config)
		if ($plg_name)
		{
			$config = cfg::fldEl ($plg_name, $fld, 'all');
		}
		else
		{
			$config = cfg::fldEl ($this->tb, $fld, 'all');
		}
		//merge check array to string
		if (is_array($config['check']))
		{
			$config['check'] = implode(' ', $config['check']);
		}
		
		
		// get $tmpl_info data from template input
		if ($formatting)
		{
			$tmpl_data_arr = utils::csv_explode ($formatting, ';'); 
			foreach ( $tmpl_data_arr as $tmpl_data)
			{
				$tmp_arr = utils::csv_explode ($tmpl_data, ':');
				$tmpl_info[$tmp_arr[0]] = $tmp_arr[1];
				unset($tmp_arr);
			}
		}
		
		// merge template settings to core settings
		if (!is_array($config))
		{
			$config = array();
		}
		
		if ($tmpl_info)
		{
			$setting = array_merge($config, $tmpl_info);
		}
		else
		{
			$setting = $config;
		}
		
		
		// add html utility settings
		$setting['fieldname'] = $fld;
		
		if ($plg_name)
		{
				$setting['name'] = 'plg[' . $plg_name . '][id:' . $plg_index . '][' . $fld. ']';
		}
		else
		{
			$setting['name'] = 'core[' . $this->tb . '][' . $fld. ']';
		}
		
		$setting['id'] = str_replace(array('_', '.'), null, $this->tb . $fld . microtime(1) . rand(1, 9999));
		
		
		// menu_values are no set in read/preview contexts
		if ($this->context != 'read' AND $this->context != 'preview')
		{
			// vocabulary_set
			if ($setting['vocabulary_set'])
			{
        $final_query = "SELECT `def` FROM `" . PREFIX . "__vocabularies` WHERE `voc`='" . $setting['vocabulary_set'] . "' ORDER BY `sort`";
			}
			//get_values_from_tb
			else if ($setting['get_values_from_tb'])
			{
				$from_table_arr = utils::csv_explode ($setting['get_values_from_tb'], ':');
				
				$final_query = "SELECT `" . $from_table_arr[1] . "` FROM `" . $from_table_arr[0] . "` GROUP BY `" . $from_table_arr[1] . "` ORDER BY `" . $from_table_arr[1] . "` ASC";
			}
			else if ($setting['id_from_tb'])
			{
				$id_field = cfg::tbEl($setting['id_from_tb'], 'id_field');
				
				$fields  = ($id_field != 'id') ? 'id`, `' . $id_field : '';
				
				$final_query = 'SELECT `' . $fields . '` FROM `' . $setting['id_from_tb'] . '` WHERE 1 ORDER BY `' . $id_field . '`';
			}
			
			if ($final_query)
			{
				$res = $this->db->query($final_query, false, 'read', true);
				
				if (is_array($res))
				{
					foreach ($res as $key=>$val)
					{
						$setting['menu_values'][$val[0]] = $val[1] ? $val[1] : $val[0];
					}
				}
			}      
		}
		
		// DATA
		if ( $setting['def_value'] AND $this->context == 'add_new' )
		{
			/*
			 * ie. I'm entering e new record and the field has a def_value
			 * new I'm entering e new plugin and the field has a def_vale
			 */
			switch($setting['def_value'])
			{
				case '%today%':
					$setting['data'] = date('Y-m-d');
					break;
					
				case '%current_user%':
					$setting['data'] = $_SESSION['user']['name'];
					break;
						
				default:
					$setting['data'] = $setting['def_value'];
					break;
						
			}
			
		}
		else
		{
		
			if ($plg_index !== false AND $plg_name)
			{
				$setting['data']	=	$this->record->getPlugin($plg_name, $plg_index, $fld);
			}
			else
			{
				$setting['data']	=	$this->record->getCore($fld);
			}
		}
		
		// ADD DATA TO MENU VALUES, IF NOT PRESENT
    if ($setting['type'] == 'multi_select')
    {
      if ($setting['data'])
      {
        $data_arr = utils::csv_explode($setting['data'], ';');
        if (is_array($data_arr))
        {
          foreach ($data_arr as $v)
          {
            if (is_array($setting['menu_values']) && !in_array($v, $setting['menu_values']))
            {
              array_push($setting['menu_values'], $v);
            }
          }
        }
      }
    }
    else
    {
      if ($setting['data'] && is_array($setting['menu_values']) && !in_array($setting['data'], $setting['menu_values']))
      {
        array_push($setting['menu_values'], $setting['data']);
      }
    }
		
		//HTML_DATA
		$setting['html_data']	=	($setting['data'] AND $setting['data'] != '') ? @htmlentities($setting['data'], ENT_QUOTES, 'UTF-8') : '';
		
				
		// DISPLAY
		if		($setting['hide'])			$setting['display'] = ' display: none; ';

		// READONLY
		if		($setting['readonly'])		$setting['readonly'] = ' readonly="readonly" ';

		// DISABLED
		if 		($setting['disabled'])		$setting['disabled'] = ' disabled="disabled" ';
		
		//MAXLENGTH
		$setting['maxlength'] = $setting['maxlength'] ? ' maxlength = "' . $setting['maxlength'] . '"' : '';
		

		# fine funzione
		return $setting;
	}



	/**
	 *
	 * @param $setting		array of settings
	 * @return string		html
	 */
	protected function HtmlStartDiv($setting){
		$html='<div ';
		
		if ($setting['width'] || $setting['display'])
		{
			//style
			$html .= 'style=" '//padding:10px 20px 0 0; '
		
				//style > width
				. ($setting['width'] ? 'width:' . $setting['width'] . '; ' : '') 
			
				//style > display
				. $setting['display'] 
			
			// end of style
			. '"';
		}
			
			// class
		//TODO: experiment
		if ($setting['div_class'])
		{
			$setting['div_class'] = preg_replace('/span-([0-9]{1,2})/', $this->cell('$1'), $setting['div_class']);
		}
		$html .= 'class="form_el'. ($setting['div_class'] ? ' ' . $setting['div_class'] : '').'" '
			
			//end tag
			. '>';

		return $html;
	}

	protected function HtmlEndDiv(){

		$html = '</div>';

		return $html;
	}
	
	protected function HtmlLabel($label = false, $help = false)
	{

		if ($label AND $label != '.')
		{
			return '<label> '
					. ( $help ? '<span class="help" title="' . str_replace('"', '\"', $help). '">' : '')
					. $label
					. ($help ? ' <i class="glyphicon glyphicon-info-sign"></i>' : '')
				. ' </label>';
		}
	}


	protected function HtmlContentRead($setting)
	{
		$html = '<div class="field_content" data-name="' . $setting['name'] . '">';
		
		if ($setting['type'] == 'boolean')
		{
			$html .=  ( (!$setting['data'] || $setting['data'] === 0)	? tr::get('no') : tr::get('yes') );
		}
		else if ($setting['data']!=='' OR (is_numeric($setting['data']) AND $setting['data']> 0 ))
		{
			$from_table_arr = $setting['get_values_from_tb']  ? utils::csv_explode ($setting['get_values_from_tb'], ':') : false;
			
			// da qui
			if ($setting['get_values_from_tb'])
			{
				
				$otherTb = $from_table_arr[0];
				$otherFld = $from_table_arr[1];
				
				/*
				 * if otherTb == thisTb most probably it's a reference used for indexing (comobox field)
				 *	No difference if otherFld is the same or different from thisFld.
				 *	In both cases a link to a query result will be shown.
				 *	Usually if otherFlf == thisFld the feature is used in combobox fields, in order to autoindex fields
				 *	The case of otherFld != thisField should be very rare if never used
				 * 
				 * If otherTb != thisTb most probably it's not only a index link, but also a logic (data structure) link
				 *	If otherFld is otherTb's id_field, most probably this is a logic (data structure link).
				 *	Anyway otherFld value is unique, so it's useless showing a query result. Just show the record!
				 *	Otherwise otherFld most probably is not unique, so query result will be shown;
				 */
				if ($otherTb == $this->tb || ($otherTb != $this->tb && $otherFld != cfg::tbEl($otherTb, 'id_field')))
				{
					$html .= "<span class=\"a\" " .
						" onclick=\"api.showResults('" . $otherTb. "', 'type=encoded', '" . tr::sget('contextual_search_in', cfg::tbEl($otherTb, 'label')) . "', {q_encoded: '" . base64_encode("`" . $otherFld . "` = '" . $setting['data'] . "'"). "'})\">" . 
						utils::format_text($setting['data']) .
						'</span>';
				}
				else
				{
					$html .= '<span class="a" onclick="api.record.read(\'' . $otherTb . '\', [\'' . $setting['data'] . '\'], true)">' .
						utils::format_text($setting['data']) .
						'</span>';
				}
			}
			else if ($setting['id_from_tb'])
			{
				$query = 'SELECT `' . cfg::tbEl($setting['id_from_tb'], 'id_field') . '` FROM `' . $setting['id_from_tb'] . '` WHERE `id` = ' . $setting['data'];
				$res = $this->db->query($query, false, 'read', true);
				if ($res[0][0])
				{
					$html .= '<span class="a" onclick="api.record.read(\'' . $setting['id_from_tb'] . '\', [' . $setting['data'] . '])">'
							. utils::format_text($res[0][0])
						. '</span>';
				}
			}
      else if ($setting['type'] == 'multi_select')
      {
        $data_arr = utils::csv_explode($setting['data'], ';');
        
        if (is_array($data_arr))
        {
          foreach ($data_arr as $v)
          {
            if ($setting['active_link'])
            {
              $tmp_html[] = "<span class=\"a\" " .
                " onclick=\"api.showResults('" . $this->tb. "', 'type=encoded', '" . tr::sget('contextual_search_in', cfg::tbEl($this->tb, 'label')) . "', {q_encoded: '" . base64_encode(
                    "`" . $setting['fieldname'] . "` LIKE '%;" . $v . "' " .
                    "OR `" . $setting['fieldname'] . "` LIKE '" . $v . ";%' " .
                    "OR `" . $setting['fieldname'] . "` = '" . $v . "' "
                  ). "'})\">" . 
                utils::format_text($v) .
                '</span>';
            }
            else
            {
              $tmp_html[] =  utils::format_text($v);
            }
          }
        }
        
        if ($tmp_html)
        {
          $html .= implode($tmp_html, ', ');
        }
        else
        {
          $html .= utils::format_text($setting['data']);
        }
      }
			else
			{
				/*
				 * If cfg active_link, show link
				 */
				if ($setting['active_link'])
				{
					$html .= "<span class=\"a\" " .
						" onclick=\"api.showResults('" . $this->tb. "', 'type=encoded', '" . tr::sget('contextual_search_in', cfg::tbEl($this->tb, 'label')) . "', {q_encoded: '" . base64_encode("`" . $setting['fieldname'] . "` = '" . $setting['data'] . "'"). "'})\">" . 
						utils::format_text($setting['data']) .
						'</span>';
				}
				else
				{
					$html .=  utils::format_text($setting['data']);
				}
			}
		}
		else
		{
			return false;
		}
		
		return $html . '</div>';
	}

	protected function HtmlContentEdit($setting)
	{
		//div containing field
		$html='<div class="field_content">';

		//core info (HTML), to use
		$core  = ' name="'. $setting['name'] .'" '
			
			. ' id="'. $setting['id'] .'" ' .
			
			' autocomplete="on" '
			
			. ( $setting['onchange'] ? 'onchange="'. $setting['onchange']. '" ' : '') 
			
			. ( $setting['pattern'] ? ' mypattern="' . $setting['pattern']. '"' : '')
			
			. $setting['disabled']
			
			. $setting['readonly'];

		$core .= $setting['ondblclick'] ? ' ondblclick="'. $setting['ondblclick'] .'"' : '';
		
		$core .= $setting['check'] ? ' check="'. $setting['check'] .'"' : '';
		
		if (preg_match('/plg/', $setting['name']))
		{
			$core .= 'data-changeonchange ="' . str_replace($setting['fieldname'], 'id', $setting['name']). '"';
		}

		switch($setting['type'])
		{
			case 'text':
				$html.=$this->htmlInput($core, $setting);
				break;
				
			case 'date':
				$html.=$this->htmlDate($core, $setting);
				break;
				
			case 'long_text':
				$html.=$this->htmlTextarea($core, $setting);
				break;
				
			case 'select':
				$html.=$this->htmlSelect($core, $setting);
				break;
				
			case 'combo_select':
				$html.=$this->htmlComboSelect($core, $setting);
				break;
				
			case 'multi_select':
				$html.=$this->htmlMultiSelect($core, $setting);
				break;
				
			case 'boolean':
				$html.=$this->htmlBoolean($core, $setting);
				break;
				
			case 'slider':
				$html .= $this->htmlSlider($core, $setting);
				break;
		}
		$html.="</div>";

		return $html;
	}

	/*
	 * html piccoli: casi vari di tipi di campo
	 */
	protected function htmlInput($core, $setting) {
		
		return '<input ' . $core . $setting['maxlength'] . ' type="text"  value="' . $setting['html_data'] . '"  style="width:95%"  />';
	}
	
	protected function htmlDate($core, $setting) {
		
		return '<input type="text" class="date" ' . $core . $setting['maxlength'] . 'value="' . $setting['html_data'] . '" style="width:95%" />';
	}
	
	protected function htmlTextarea($core, $setting) {
		
		return '<textarea ' . 
			$core . 
			$setting['maxlength'] . 
			( $setting['height'] ? 'rows="' . $setting['height'] . '"': '') . 
			' style="width:95%">' .
			 
			 	$setting['data'] . 
			 '</textarea>';
	}

	protected function htmlSelect($core, $setting) {
		
		$html = '<select ' . $core . '>'
			. '<option value=""></option>';
		
		if (is_array($setting['menu_values']))
		{
			foreach ($setting['menu_values'] as $k => $v)
			{
				$html .= '<option value="' . $k . '"';
				
				if ($setting['data'] !== NULL && $setting['data'] == $k )
				{
					$html .= ' selected="selected" ';
				}
				$html .= '>' . $v . '</option>';
			}
		}
			
		$html .= '</select>';
		
		return $html;
	}

	protected function htmlComboSelect($core, $setting) {
		
		$html = '<input type="text" class="combobox" style="width:95%;" ' .  $core . $setting['maxlength'] . ' value="' . $setting['data'] . '"/>';
		
		$html .= '<datalist for="' . $setting['id'] . '">';

		if (is_array($setting['menu_values']) && !empty($setting['menu_values']))
		{
			foreach ( $setting['menu_values'] as $v )
			{
				$html .= '<option>' . $v . '</option>';
			}
		}

		$html .= '</datalist>';

		return $html;
	}

	protected function htmlMultiSelect($core, $setting){
		
		$html = '<input type="text" class="multiselect" style="width:95%" ' . $core . $setting['maxlength'] . ' value="' . $setting['html_data'] . '" />';

		$html .= '<datalist for="' . $setting['id'] . '">';
		
		if (is_array($setting['menu_values']) && !empty($setting['menu_values']))
		{
			
			foreach ( $setting['menu_values'] as $v )
			{
				$html .= '<option>' . $v . '</option>';
			}
		}

		$html .= '</datalist>';
		
		return $html;

	}// fine htmlMultiSelect


	protected function htmlBoolean($core, $setting) {
		
		return '<select ' . $core . '>'
			. '<option value="1"' . ( ($setting['data'] === 1) ? ' selected ': '' ) . '>' . tr::get('yes') . '</option>'
			. '<option value="0"' . ( (!$setting['data'] === 0 OR $setting['data']==0) ? ' selected ': '' ) . '>' . tr::get('no') . '</option>'
		. '</select>';
	} // fine htmlBoolean
	
	/**
	 * 
	 * Returns html code that shows an input class 
	 * @param string $core
	 * @param array $setting 
	 */
	protected function htmlSlider($core, $setting){
		
		return '<input type="text" class="slider" style="width:95%" ' . $core . ' value="' . $setting['html_data'] . '"'
			.  ($setting['min'] ? 'min="' . $setting['min']. '"' : '')
			.  ($setting['max'] ? 'max="' . $setting['max']. '"' : '')
			. ' />';
	}// fine slider
	
}
?>
