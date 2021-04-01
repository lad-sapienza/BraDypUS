<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */


namespace Template\Parts;

use DB\DB;
use Config\Config;

use Template\Parts\FormElement;

use SQL\SafeQuery;

class Field
{
    private $fld;
    private $tb;
    private $db_data;
    private $context; 
    private $plg_index;
    private $settings;
	private $cfg;
	private $db;
    
    public function __construct(
        string $fld, 
        string $tb, 
        string $db_data = null, // new record
        string $context, 
        string $formatting = null, 
        int $plg_index = null, 
        DB $db, 
        Config $cfg)
    {
        $this->fld = $fld;
        $this->tb = $tb;
        $this->db_data = $db_data;
        $this->context = $context;
        $this->plg_index = $plg_index;
        $this->cfg = $cfg;
        $this->db = $db;
        $this->settings = $this->parseCfg($formatting);
    }

    public function show() : string
    {
        $label = $this->label();
		$content = in_array($this->context, ['read', 'preview']) ? $this->contentRead() : $this->contentEdit();
		return $this->main_wrapper($label . $content);
    }
    


    private function contentRead() : ?string
    {
        // Return null if no data are available
        if ($this->settings['data'] === false || $this->settings['data'] === null || $this->settings['data'] === '') {
            return null;
        }

        $html = '<div class="field_content" data-name="' . $this->settings['name'] . '"' .
            ($this->settings['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
        '>';

        // Val is always an array, for easy parsing: exploded values for multi_select od single value array
        $val = ($this->settings['type'] === 'multi_select') ? \utils::csv_explode($this->settings['data'], ';') : [$this->settings['data']];

        
        // 1. Boolean values: convert 1|0/false to yes|no
        if ($this->settings['type'] === 'boolean') {
            $html .=  ((!$this->settings['data'] || $this->settings['data'] === 0) ? \tr::get('no') : \tr::get('yes'));
        }
        // 3. All other data types
        else {
            // 3.1 Field is populated with values get by another table.field
            if ($this->settings['get_values_from_tb']) {
                list($otherTb, $otherFld) = $from_table_arr = array_map('trim', explode(':', $this->settings['get_values_from_tb']));

                /*
                 * if otherTb = thisTb most probably it's a reference used for auto-populating menus (combobox field)
                 *	No difference if otherFld is the same or different from thisFld.
                 *	In both cases a link to a query result will be shown.
                 *	The case of otherFld != thisField should be very rare if never used
                 *
                 * If otherTb != thisTb most probably it's not only a index link, but also a logic (data structure) link
                 *	If otherFld is otherTb's id_field, most probably this is a logic (data structure link).
                 *	Anyway otherFld value is unique, so it's useless showing a query result. Just show the record!
                 *	Otherwise otherFld most probably is not unique, so query result will be shown;
                 */

                // 3.1.1 otherTb = thisTb  or otherTb != thisTb and otherFld is not other table's id field
                if ($otherTb === $this->tb || ($otherTb !== $this->tb && $otherFld !== $this->cfg->get("tables.{$otherTb}.id_field"))) {
                    foreach ($val as $v) {
                        $html .= "<span class=\"a\" " .
                            " onclick=\"api.showResults('" . $otherTb. "', 'type=obj_encoded', '" . \tr::get('contextual_search_in', [$this->cfg->get("tables.$otherTb.label")]) . "', {obj_encoded: '" . SafeQuery::encode( $otherFld . " = ?", [$v]). "'})\">" .
                            $this->format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
                // 3.1.2 otherTb != thisTb  or otherTb = thisTb and otherFld is other table's id field
                else {
                    foreach ($val as $v) {
                        $html .= '<span class="a" onclick="api.record.read(\'' . $otherTb . '\', [\'' . $v . '\'], true)">' .
                            $this->format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
            }
            // 3.2 Field is populated with ID values get by another table. Other table's id field will be used as a label
            elseif ($this->settings['id_from_tb']) {
                foreach ($val as $v) {
                    
                    $query = 'SELECT ' .
                            $this->cfg->get("tables." . $this->settings['id_from_tb']. ".id_field") .' AS id_from_tb_val ' .
                            'FROM ' . $this->settings['id_from_tb'] . ' ' .
                            'WHERE id = ?';
                    $res = $this->db->query($query, [ (int)$v ], 'read');

                    if ($res[0]['id_from_tb_val']) {
                        $html .= '<span class="a" onclick="api.record.read(\'' . $this->settings['id_from_tb'] . '\', [' . $v . '])">'
                                . $this->format_text($res[0]['id_from_tb_val'])
                            . '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
            }
            // 3.3 All other field types
            else {
                foreach ($val as $v) {
                    if ($this->settings['active_link']) {
                        $html .= "<span class=\"a\" " .
                            " onclick=\"api.showResults('" . $this->tb. "', 'type=obj_encoded', '" . \tr::get('contextual_search_in', [$this->cfg->get("tables." . $this->tb .".label")] ) . "', {obj_encoded: '" . SafeQuery::encode(
                                    "" . $this->settings['fieldname'] . " LIKE '%;" . $v . "' " .
                                    "OR " . $this->settings['fieldname'] . " LIKE '" . $v . ";%' " .
                                    "OR " . $this->settings['fieldname'] . " = '" . $v . "' "
                                ). "'})\">" .
                           $this->format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    } else {
                        $html .= $this->format_text($v) . ($v !== end($val) ? '; ' : '');
                    }
                }
            }
        }

        return $html . '</div>';
    }

    public function format_text($text, $html = false)
	{
		if ($text !== '') {
			$text = @htmlentities($text, ENT_QUOTES, 'UTF-8');
        }

        // Linkify
        $text = preg_replace("/(https?:\/\/)([^ \t\r\n$\)]+)/i", "<a href=\"\\0\" target=\"_blank\">\\0</a>", $text);
        $text = preg_replace("/([a-z0-9\._-]+)(@[a-z0-9\.-_]+)(\.{1}[a-z]{2,6})/i", "<a href=\"mailto:\\1\\2\\3\">\\1\\2\\3</a>", $text);

		// Add DB links
		$text = preg_replace_callback(
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

		$text = nl2br($text);

		return $text;
	}


    private function contentEdit()
    {
        $formElement = new FormElement($this->settings, $this->context);

        $html = '<div class="field_content">';

        //core info (HTML), to use
        
        switch ($this->settings['type']) {
            case 'text':
                $html .= $formElement->Input();
                break;

            case 'date':
                $html .= $formElement->Date();
                break;

            case 'long_text':
                $html .= $formElement->Textarea();
                break;

            case 'select':
                $html .= $formElement->Select();
                break;

            case 'combo_select':
                $html .= $formElement->ComboSelect();
                break;

            case 'multi_select':
                $html .= $formElement->MultiSelect();
                break;

            case 'boolean':
                $html .= $formElement->Boolean($core, $this->settings);
                break;

            case 'slider':
                $html .= $formElement->Slider();
                break;
        }

        $html .= "</div>";

        return $html;
    }

	private function label() : ?string
	{
		if (!isset($this->settings['label']) || $this->settings['label'] === '.') {
            return null;
		}
		if ($this->context !== 'read' && $this->context !== 'preview' && isset($this->settings['help'])) {
			$help = $this->settings['help'];
		}

        return '<label> ' .
                ($help ? '<span class="help" data-content="' . str_replace('"', '\"', $help). '">' : '') .
                $this->settings['label'] .
                ($help ? ' <i class="fa fa-info-circle"></i>' : '') .
            ' </label>';
	}

	private function main_wrapper( string $content)
	{
		$html='<div ';

        if ($this->settings['width'] || $this->settings['display']) {
            //style
            $html .= 'style=" '
                . ($this->settings['width'] ? 'width:' . $this->settings['width'] . '; ' : '')
                . ($this->settings['display'] ? ' '. $this->settings['display'] . ' ' : '')
            . '"';
		}
		
		$html .= 'class="form_el' .
				($this->settings['div_class'] ? ' ' . $this->settings['div_class'] : '') . '" '
			. '>'
			. $content .
            '</div>';
        
        return $html;
	}

	private function parseCfg( string $formatting = null ) {
        /*
         * $settings indexes from config file
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
         * $settings indexes from template
         *		all previous +
         *		width
         *		height
         *		div_class
         *		onchange
         *		ondblclick
         *
         * calculated $settings indexes
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
        $config = $this->cfg->get("tables.{$this->tb}.fields.{$this->fld}");

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
            $tmpl_data_arr = array_map('trim', explode(';', $formatting));
            foreach ($tmpl_data_arr as $tmpl_data) {
                list($key, $val) = array_map('trim', explode(':', $tmpl_data));
                $tmpl_info[$key] = $val;
                unset($key);
                unset($value);
            }
        }
        // Merge config & formatting data
        $settings = array_merge($config, $tmpl_info);
        if ($settings['div_class'] && \preg_match('/span-([0-9]{1,2})/', $settings['div_class'])) {
            error_log("Deprecation warning: the template used for table `{$this->tb}` uses span-n as div_class for field `{$this->fld}`. The class name has been deprecated and should be replaced with col-sm-n");
            $settings['div_class'] = preg_replace('/span-([0-9]{1,2})/', 'col-sm-$1', $settings['div_class']);
        }

        // FIELDNAME
        $settings['fieldname'] = $this->fld;
		
		// NAME
        $settings['name'] = $this->plg_index ?
                    'plg[' . $this->tb . '][id:' . $this->plg_index . '][' . $this->fld. ']':
                    'core[' . $this->tb . '][' . $this->fld. ']';

        $settings['name'] .= $settings['type'] == 'multi_select' ? '[]' : '';

		// CHANGEONCHANGE
		$settings['changeonchange'] = $this->plg_index ?
                    'plg[' . $this->tb . '][id:' . $this->plg_index . '][id]':
                    'core[' . $this->tb . '][' . $this->fld. ']';

		// ID
		$settings['id'] = str_replace(['_', '.'], null, uniqid($this->tb . $this->fld, true));
		
		// DISPLAY
        if ($settings['hide']) {
            $settings['display'] = ' display: none; ';
        }

        // READONLY
        if ($settings['readonly']) {
            $settings['readonly'] = ' readonly="readonly" ';
        }

        // DISABLED
        if ($settings['disabled']) {
            $settings['disabled'] = ' disabled="disabled" ';
		}
		
        // DISABLED
        if ($settings['max_length']) {
            $settings['maxlength'] = ' maxlength="' . $settings['max_length'] . '" ';
        }


        // menu_values are not set in read/preview contexts
        if ($this->context !== 'read' && $this->context !== 'preview') {
            $settings['menu_values'] = [];
        }

		// DATA
		// 1. edit with def_value AND force_default
        if (
            $this->context === 'edit'       &&
            !empty($settings['def_value'])  && 
            $settings['force_default']
        ) {
            $settings['data'] = $this->setDefault($settings['def_value']);
		
		// 2. add_new with def_value
        } else if ( 
			$this->context === 'add_new'	&& 
			$settings['def_value'] 
		) {
            $settings['data'] = $this->setDefault($settings['def_value']);

        } else {
			$settings['data'] = $this->db_data;

			// edit with def_value and no db data
            if (
				$this->context === 'edit'	&& 
				empty($settings['data'])	&&
				$settings['def_value']
			){
                // def_value is set, no value has been entered previously and context is edit
                $settings['data'] = $this->setDefault($settings['def_value']);
            }
        }

		// MENU VALUES
		// 1. multiselect with db data
        if ($settings['type'] === 'multi_select' && $settings['data']) {
            $data_arr = explode(';', $settings['data']);
			foreach ($data_arr as $v) {
				$v = trim($v);
				if (empty($v)) {
					continue;
				}
				if ( is_array($settings['menu_values']) && !in_array($v, $settings['menu_values'])) {
					$settings['menu_values'][$v] = $v;
				}
			}
		// 2. id_from_tb with db data
        } elseif ($settings['id_from_tb'] && $settings['data']) {
            $sql = "SELECT id, " . $this->cfg->get("tables." . $settings['id_from_tb'] .".id_field") . " as val " .
                "FROM " . $settings['id_from_tb'] . " " .
                "WHERE id = ?";
            $res = $this->db->query($sql, [$settings['data']]);

            if ($res[0]) {
                $settings['menu_values'][$res[0]['id']] = $res[0]['val'];
            }
        } else {
            if (
				$settings['data']	&& 
				is_array($settings['menu_values']) && 
				!in_array($settings['data'], $settings['menu_values'])
			) {
                $settings['menu_values'][$settings['data']] = $settings['data'];
            }
        }

        //HTML_DATA
        $settings['html_data']	=	($settings['data'] && $settings['data'] !== '') ? @htmlentities($settings['data'], ENT_QUOTES, 'UTF-8') : '';


        # fine funzione
        return $settings;
	}

	
	private function setDefault(string $def_value) : string 
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