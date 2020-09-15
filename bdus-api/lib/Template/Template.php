<?php
namespace Template;

use Record\Read;
use Config\Config;
use DB\DB;

use Tempate\Parts\Links;
use Tempate\Parts\Geodata;
use Tempate\Parts\Rs;
use Tempate\Parts\Images;
use Tempate\Parts\Field;

use Monolog\Logger;

class Template implements Template\TemplateInterface
{
    protected $context;
    protected $record;
    protected $cfg;
    protected $db;

    protected $tb;
    protected $settings;
    protected $current_plugin;
    protected $current_plugin_index;


    public function __construct( string $context, Read $record, DB $db, Config $cfg )
    {
        $this->record = $record;
        $this->context = $context;
        $this->db = $db;
        $this->cfg = $cfg;
    }

    public function cell( string $nr ) : string
    {
        return "col-sm-$nr";
    }

    public function sqlSum(string $tb, string $fld, string $filter = null)
    {
        return null;
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::simpleSum()
     */
    public function simpleSum( string $fields) : int
    {
        $flds_arr = explode(",", $fields);
        $sum = 0;

        if (!empty($flds_arr)) {
            foreach ($flds_arr as $fld) {
                $sum += (int)$this->record->getCore($fld, true);
            }
        }
        return $sum;
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::permalink()
     */
    public function permalink() : ?string
    {
        if ($this->context === 'add_new') {
            return null;
        }
        return '<div class="permalink">' .
        '<a href="' . "./#/" . APP . '/' . $this->record->getTb() . '/' . $this->record->getCore('id', true)  . '">PERMALINK</a>' .
      '</div>';
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::links()
     */
    public function links() : ?string
    {
        if ($this->context === 'add_new') {
            return null;
        }
        // Back-link table is a plugin table that servers as 1-n pivot.
        // Given two tables, prefix__aa and prefix__bb
        // and a plugin table prefix__m_bb, referred from prefix_aa
        // and prefix_m_bb has a field named with the singular form of bb
        // populated withthe same value of id_field field of aa, eg.
        // 		table a__teachers
        // 		has plugin table plugin a__m_classes
        // 		that has fields a__m_classes.class and a__m_classes.hour
        // 		table classes has id_field name with same content as a__m_classes.class

        $backlinks = $this->record->getBackLinks();

        // GET CORE LINKS
        $corelinks = $this->record->getCoreLinks();


        $html = Links::showAll($corelinks, $backlinks, $this->cfg);

        // SHOW USER LINKS
        $html .= $this->userLinks();

        return $html;
    }

    public function userlinks()
    {
        if ($this->context !== 'add_new') {
            return Links::showUserLinks(
                $this->context,
                $this->record->getTb(),
                $this->record->getCore('id', true),
                $this->cfg
            );
        }
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::geodata()
     */
    public function geodata() : ?string
    {
        if ($this->context === 'add_new') {
            return null;
        }
        return Geodata::showAll(
            $this->record->getGeodata(),
            $this->record->getTb(),
            $this->record->getCore('id', true),
            $this->plg('geodata'),
            $this->cfg
        );
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::showall()
     */
    public function showall() : string
    {
        $tb = $this->record->getTb();

        $html = $this->image_thumbs();
        $html .= '<div class="row">' .
                '<div class="col-sm-6">' .
            '<fieldset>';
        // CORE
        $flds_arr = $this->cfg->get("tables.{$tb}.fields.*.name");
        foreach ($flds_arr as $fld) {
            $html .= $this->fld($fld);
        }
        $html .= '</fieldset>';
        // RS
        $html .= $this->rs();
        $html .= '</div>'; // end of .col-sm-6
        $html .= '<div class="col-sm-4">';
        //LINKS
        $html .= $this->links();
        //GEODATA
        $html .= $this->geodata();
        // PLUGINS
        $plg_arr = $this->cfg->get("tables.{$tb}.plugin");
        if (is_array($plg_arr)) {
            foreach ($plg_arr as $plg) {
                $html .= $this->plg($plg);
            }
        }
        //PERMALINK
        $html .= $this->permalink();
        $html .= '</div>';// end of .col-sm-4
        $html .= '</div>';// end of .row

        return $html;
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::rs()
     */
    public function rs()
    {
        if ($this->context == 'add_new') {
            return null;
        }
        $rs_fld = $this->cfg->get("tables.{$this->tb}.rs");

        if ($rs_fld) {
            Rs::showAll( 
                $this->context,
                $this->record->getTb(),
                $this->record->getCore($rs_fld, true)
            );
        }
    }

    /**
     * (non-PHPdoc)
     * @see TemplateInterface::image_thumbs()
     */
    public function image_thumbs(int $max = 2) : ?string
    {
        // get file data
        $data_arr = (array)$this->record->getFullFiles();

        return Images::showAll(
            $data_arr,
            $max,
            $this->record->getTb(),
            $this->record->getCore('id', true),
            $this->context,
            \utils::canUser('edit', $this->record->getCore('creator', true) )
        );
    }

    public function plg_fld(string $fieldname, string $formatting = null) : string
    {
        return $this->fld(
            $fieldname, 
            $formatting, 
            $this->current_plugin, 
            $this->current_plugin_index
        );
    }

    public function fld(string $fieldname, string $formatting = null, string $plg_name = null, int $plg_index = null) : string
    {   
        // TODO: $this->record->getPlugin does not exist
        $db_data = ($plg_name && $plg_index) ? $this->record->getPlugin($plg_name, $plg_index, $fld) : $this->record->getCore($fld, true);
        $tb = $plg_name ?: $this->record->getTb();

        return Field::showAll(
            $fieldname, 
			$tb, 
            $db_data,
			$this->context, 
			$formatting,
			$plg_index,
			$this->db,
			$this->cfg
        );
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

        if (file_exists(PROJ_DIR . 'templates/' . str_replace(PREFIX, null, $plg) . '.twig')) {
            
            $html .= $this->fld('id', false, $plg, $index);

            $this->current_plugin_index = $index;

            $twig = new \Twig\Environment( new \Twig\Loader\FilesystemLoader(PROJ_DIR . 'templates/'), unserialize(CACHE) );

            $html .= $twig->render(str_replace(PREFIX, null, $plg) . '.twig', array(
                    'print' => $this,
                    'plg'=> $plg,
                    'index' => $index,
                    'uid' => uniqid()
            ));
        } else {
            $plg_fields = $this->cfg->get("tables.$plg.fields.*.label");

            if (!is_array($plg_fields) || empty($plg_fields)) {
                return;
            }

            foreach ($plg_fields as $fld_n => $fld_l) {
                $html .= $this->fld($fld_n, false, $plg, $index);
            }
        }

        $html .= '</fieldset>' .
                '</div>';

        return $html;
    }

    /**
     * (non-PHPdoc)
     * @see iTemTemplateInterfaceplate::plg()
     */
    public function plg($plg)
    {
        if (!preg_match('/' . PREFIX . '/', $plg)) {
            $plg = PREFIX . $plg;
        }

        $this->current_plugin = $plg;

        // Start writing plugin container
        $html = '<fieldset class="plugin ' . $plg . '">' .
                '<legend>' . $this->cfg->get("tables.$plg.label"). '</legend>';

        // Get records from database
        $plg_array = $this->record->getPlugin($plg);

        //0. Stop rendering if context is READ and no data are available
        if (empty($plg_array) && $this->context == 'read') {
            return;
        }

        // 1. No records found in database, but context is add_new or edit => show empty form:
        if (empty($plg_array) and ($this->context == 'edit' or $this->context == 'add_new')) {
            $html .= $this->showPlgRow($plg, uniqid()) .
                    '<br/>';
        }

        // 2. Some records returned from database
        if (is_array($plg_array) and !empty($plg_array)) {
            foreach ($plg_array as $index => $plg_data) {
                $this->current_plugin_index = $index;
                $html .= $this->showPlgRow($plg, $index) .
                    (($this->context == 'add_new' or $this->context == 'edit') ?'<button type="button" class="deleteplg btn btn-sm btn-default"><i class="glyphicon glyphicon-minus"></i></button>' : '') .
                    '<br/>';
            }
        }

        // Add row button, if context is edit or add_new
        if ($this->context == 'edit' or $this->context == 'add_new') {
            $html .= '<div class="plg_container">' .
                    '</div>'.
                    '<button type="button" class="addplg btn btn-sm btn-default"><i class="glyphicon glyphicon-plus"></i></button>';
        }
        $html .= '</fieldset>';

        $this->current_plugin = false;
        $this->current_plugin_index = false;
        return $html;
    }

    

    public function value($fld, $plg = false)
    {
        return  $plg ? $this->record->getPlugin($this->current_plugin, $this->current_plugin_index, $fld) : $this->record->getCore($fld, true);
    }


    protected function HtmlContentRead($setting)
    {
        $html = '<div class="field_content" data-name="' . $setting['name'] . '"' .
            ($setting['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
        '>';

        // Val is always an array, for easy parsing: exploded values for multi_select od single value array
        $val = ($setting['type'] == 'multi_select') ? \utils::csv_explode($setting['data'], ';') : [$setting['data']];

        // 1. Boolean values: convert 1|0/false to yes|no
        if ($setting['type'] === 'boolean') {
            $html .=  ((!$setting['data'] || $setting['data'] === 0) ? \tr::get('no') : \tr::get('yes'));
        }
        // 2. Return false if data is empty
        elseif ($setting['data'] === false || $setting['data'] === null || $setting['data'] === '') {
            return false;
        }

        // 3. All other data types
        else {
            // 3.1 Field is populated with values get by another table.field
            if ($setting['get_values_from_tb']) {
                $from_table_arr = \utils::csv_explode($setting['get_values_from_tb'], ':');

                $otherTb = $from_table_arr[0];
                $otherFld = $from_table_arr[1];

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
                            " onclick=\"api.showResults('" . $otherTb. "', 'type=obj_encoded', '" . \tr::get('contextual_search_in', [$this->cfg->get("tables.$otherTb.label")]) . "', {obj_encoded: '" . \SQL\SafeQuery::encode( $otherFld . " = ?", [$v]). "'})\">" .
                            \utils::format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
                // 3.1.2 otherTb != thisTb  or otherTb = thisTb and otherFld is other table's id field
                else {
                    foreach ($val as $v) {
                        $html .= '<span class="a" onclick="api.record.read(\'' . $otherTb . '\', [\'' . $v . '\'], true)">' .
                            \utils::format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
            }
            // 3.2 Field is populated with ID values get by another table. Other table's id field will be used as a label
            elseif ($setting['id_from_tb']) {
                foreach ($val as $v) {
                    
                    $query = 'SELECT ' . $this->cfg->get("tables.{$setting['id_from_tb']}.id_field") . ' AS id_from_tb_val '.
                            'FROM ' . $setting['id_from_tb'] . ' ' .
                            'WHERE id = ?';
                    $res = $this->db->query($query, [ (int)$v ], 'read');

                    if ($res[0]['id_from_tb_val']) {
                        $html .= '<span class="a" onclick="api.record.read(\'' . $setting['id_from_tb'] . '\', [' . $v . '])">'
                                . \utils::format_text($res[0]['id_from_tb_val'])
                            . '</span>' . (next($val) === false ? ' ' : ';');
                    }
                }
            }
            // 3.3 All other field types
            else {
                foreach ($val as $v) {
                    if ($setting['active_link']) {
                        $html .= "<span class=\"a\" " .
                            " onclick=\"api.showResults('" . $this->tb. "', 'type=obj_encoded', '" . \tr::get('contextual_search_in', [$this->cfg->get("tables.{$this->tb}.label")] ) . "', {obj_encoded: '" . \SQL\SafeQuery::encode(
                                    "" . $setting['fieldname'] . " LIKE '%;" . $v . "' " .
                                    "OR " . $setting['fieldname'] . " LIKE '" . $v . ";%' " .
                                    "OR " . $setting['fieldname'] . " = '" . $v . "' "
                                ). "'})\">" .
                           \utils::format_text($v) .
                            '</span>' . (next($val) === false ? ' ' : ';');
                    } else {
                        $html .= \utils::format_text($v) . ($v !== end($val) ? '; ' : '');
                    }
                }
            }
        }

        return $html . '</div>';
    }

    protected function HtmlContentEdit($setting)
    {
        //div containing field
        $html = '<div class="field_content">';

        //core info (HTML), to use
        $core  = ' name="'. $setting['name'] .'" '

            . ' id="'. $setting['id'] .'" ' .

            ' autocomplete="on" '

            . ($setting['onchange'] ? 'onchange="' . $setting['onchange']. '" ' : '')

            . ($setting['pattern'] ? ' mypattern="' . $setting['pattern']. '"' : '')

            . (($setting['def_value'] && $this->context === 'add_new') ? ' changed="auto"' : '')

            . ( $setting['def_value'] && $this->context === 'edit' && $setting['force_default'] ? ' changed="auto"' : '')

            . $setting['disabled']

            . $setting['readonly']

            . ($setting['ondblclick'] ? ' ondblclick="'. $setting['ondblclick'] .'"' : '')

            . ($setting['check'] ? ' check="'. $setting['check'] .'"' : '');

        if (preg_match('/plg/', $setting['name'])) {
            $core .= 'data-changeonchange ="' . $setting['changeonchange'] . '"';
        }

        switch ($setting['type']) {
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
    protected function htmlInput($core, $setting)
    {
        return '<input ' . $core .
                $setting['maxlength'] .
                ' type="text" ' .
                'value="' . $setting['html_data'] . '" ' .
                'class="form-control"' .
                ($setting['direction'] === 'rtl' ? ' style="direction: rtl;" ' : '') .
                '>';
    }

    protected function htmlDate($core, $setting)
    {
        return '<input type="date" ' .
                'class="form-control" ' .
                $core .
                $setting['maxlength'] .
                ($setting['direction'] === 'rtl' ? ' style="direction: rtl;" ' : '') .
                'value="' . $setting['html_data'] . '">';
    }

    protected function htmlTextarea($core, $setting)
    {
        return '<textarea ' .
                $core .
                ($setting['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
                $setting['maxlength'] .
                ($setting['height'] ? 'rows="' . $setting['height'] . '"': '') .
                ' class="form-control">' .
                    $setting['data'] .
             '</textarea>';
    }

    protected function htmlSelect($core, $setting, $is_multi = false, $is_combo = false)
    {
        $html = '<select ' . $core .
            ($setting['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
            ($is_multi ? ' multiple="multiple" ' : '') .
            ($is_combo ? ' data-tags="true" ' : '') .
            ($setting['vocabulary_set'] ? ' data-context="vocabulary_set" data-att="' . $setting['vocabulary_set'] . '"' : '') .
            ($setting['get_values_from_tb'] ? ' data-context="get_values_from_tb" data-att="' . $setting['get_values_from_tb'] . '"' : '') .
            ($setting['id_from_tb'] ? ' data-context="id_from_tb" data-att="' . $setting['id_from_tb'] . '"' : '') .
            ' class="form-control">' .

            '<option></option>';

        if (is_array($setting['menu_values'])) {
            foreach ($setting['menu_values'] as $k => $v) {
                $html .= '<option value="' . $k . '" selected="selected" >' . $v . '</option>';
            }
        }

        $html .= '</select>';

        return $html;
    }

    protected function htmlComboSelect($core, $setting)
    {
        return $this->htmlSelect($core, $setting, false, true);
    }

    protected function htmlMultiSelect($core, $setting)
    {
        return $this->htmlSelect($core, $setting, true, true);
    } // fine htmlMultiSelect


    protected function htmlBoolean($core, $setting)
    {
        return '<select ' . $core . ' class="form-control">'
            . '<option value="1"' . (($setting['data'] === 1) ? ' selected ': '') . '>' . \tr::get('yes') . '</option>'
            . '<option value="0"' . ((!$setting['data'] === 0 or $setting['data']==0) ? ' selected ': '') . '>' . \tr::get('no') . '</option>'
        . '</select>';
    } // fine htmlBoolean

    /**
     *
     * Returns html code that shows an input class
     * @param string $core
     * @param array $setting
     */
    protected function htmlSlider($core, $setting)
    {
        return '<input type="range" class="slider" ' . $core . ' value="' . $setting['data'] . '"'
            .  ($setting['min'] ? 'min="' . $setting['min']. '"' : '')
            .  ($setting['max'] ? 'max="' . $setting['max']. '"' : '')
            . ' />';
    }// fine slider
}
