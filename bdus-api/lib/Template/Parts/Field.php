<?php

namespace Tempate\Parts;

use DB\DB;
use Config\Config;

class Field
{
	private $cfg;

    public static function showAll( ) : string
    {
		self::$cfg = self::parseCfg( 
			$fld, 
			$tb, 
			$db_data,
			$context, 
			$formatting,
			$plg_index,
			$db,
			$cfg
		);

		$label = self::label();
		$content = in_array($context, ['read', 'preview']) ? self::contentRead() : self::contentEdit();
		return self::main_wrapper($label . $content);
	}

	private static function label(string $context) : ?string
	{
		if (!isset(self::$cfg['label']) || self::$cfg['label'] === '.') {
            return null;
		}
		if ($context !== 'read' && $context !== 'preview' && isset(self::$cfg['help'])) {
			$help = self::$cfg['help'];
		}

        return '<label> ' .
                ($help ? '<span class="help" data-content="' . str_replace('"', '\"', $help). '">' : '') .
                $label .
                ($help ? ' <i class="glyphicon glyphicon-info-sign"></i>' : '') .
            ' </label>';
	}

	private static function main_wrapper( string $content)
	{
		$html='<div';

        if (self::$cfg['width'] || self::$cfg['display']) {
            //style
            $html .= 'style=" '
                . (self::$cfg['width'] ? 'width:' . self::$cfg['width'] . '; ' : '')
                . (self::$cfg['display'] ? ' '. self::$cfg['display'] . ' ' : '')
            . '"';
		}
		
		$html .= 'class="form_el' .
				(self::$cfg['div_class'] ? ' ' . $setting['div_class'] : '') . '" '
			. '>'
			. $content .
			'</div>';
	}

	private function parseCfg(
		string $fld, 
		string $tb, 
		string $db_data,
		string $context, 
		string $formatting = null, 
		int $plg_index = null, 
		DB $db, 
		Config $cfg)
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


        // GET DATA FROM CONFIGURATION
        $config = $cfg->get("tables.{$tb}.fields.{$fld}");

		if (!is_array($config)) {
            $config = [];
		}
		
		//merge check array to string
        if (is_array($config['check'])) {
            $config['check'] = implode(' ', $config['check']);
		}
		
		// Parse $formatting string
		$tmpl_info = [];
        if ($formatting) {
            $tmpl_data_arr = explode(';', $formatting);
            foreach ($tmpl_data_arr as $tmpl_data) {
                list($key, $val) = explode(':', trim($tmpl_data));
                $tmpl_info[trim($key)] = trim($value);
                unset($key);
                unset($value);
            }
        }

        // Merge config & formatting data
        $setting = array_merge($config, $tmpl_info);

        // FIELDNAME
        $setting['fieldname'] = $fld;
		
		// NAME
        $setting['name'] = $plg_index ?
                    'plg[' . $tb . '][id:' . $plg_index . '][' . $fld. ']':
                    'core[' . $tb . '][' . $fld. ']';

        $setting['name'] .= $setting['type'] == 'multi_select' ? '[]' : '';

		// CHANGEONCHANGE
		$setting['changeonchange'] = $plg_index ?
                    'plg[' . $tb . '][id:' . $plg_index . '][id]':
                    'core[' . $tb . '][' . $fld. ']';

		// ID
		$setting['id'] = str_replace(['_', '.'], null, uniqid($tb . $fld, true));
		
		// DISPLAY
        if ($setting['hide']) {
            $setting['display'] = ' display: none; ';
        }

        // READONLY
        if ($setting['readonly']) {
            $setting['readonly'] = ' readonly="readonly" ';
        }

        // DISABLED
        if ($setting['disabled']) {
            $setting['disabled'] = ' disabled="disabled" ';
		}
		
        // DISABLED
        if ($setting['maxlength']) {
            $setting['maxlength'] = ' maxlength="' . $setting['maxlength'] . '" ';
        }


        // menu_values are not set in read/preview contexts
        if ($context !== 'read' && $context !== 'preview') {
            $setting['menu_values'] = [];
        }

		// DATA
		// 1. edit with def_value AND force_default
        if (
            $context === 'edit'       &&
            !empty($setting['def_value'])   && 
            $setting['force_default']
        ) {
            $setting['data'] = self::setDefault($setting['def_value']);
		
		// 2. add_new with def_value
        } else if ( 
			$context === 'add_new'	&& 
			$setting['def_value'] 
		) {
            $setting['data'] = self::setDefault($setting['def_value']);

        } else {
			$setting['data'] = $db_data;

			// edit with def_value and no db data
            if (
				$context === 'edit'	&& 
				empty($setting['data'])		&&
				$setting['def_value']
			){
                // def_value is set, no value has been entered previously and context is edit
                $setting['data'] = self::setDefault($setting['def_value']);
            }
        }

		// MENU VALUES
		// 1. multiselect with db data
        if ($setting['type'] === 'multi_select' && $setting['data']) {
            $data_arr = explode(';', $setting['data']);
			foreach ($data_arr as $v) {
				$v = trim($v);
				if (empty($v)) {
					continue;
				}
				if ( is_array($setting['menu_values']) && !in_array($v, $setting['menu_values'])) {
					$setting['menu_values'][$v] = $v;
				}
			}
		// 2. id_from_tb with db data
        } elseif ($setting['id_from_tb'] && $setting['data']) {
            $sql = "SELECT id, " . $cfg->get("tables.{$setting['id_from_tb']}.id_field") . " as val " .
                "FROM " . $setting['id_from_tb'] . " " .
                "WHERE id = ?";
            $res = $db->query($sql, [$setting['data']]);

            if ($res[0]) {
                $setting['menu_values'][$res[0]['id']] = $res[0]['val'];
            }
        } else {
            if (
				$setting['data']	&& 
				is_array($setting['menu_values']) && 
				!in_array($setting['data'], $setting['menu_values'])
			) {
                $setting['menu_values'][$setting['data']] = $setting['data'];
            }
        }

        //HTML_DATA
        $setting['html_data']	=	($setting['data'] && $setting['data'] !== '') ? @htmlentities($setting['data'], ENT_QUOTES, 'UTF-8') : '';


        # fine funzione
        return $setting;
	}

	
	private static function setDefault(string $def_value) : string 
	{
        switch ($def_value) {
            case '%today%':
                return date('Y-m-d');
            case '%current_user%':
                return $_SESSION['user']['name'];
            default:
                return $def_value;
        }
    }
}