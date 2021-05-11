<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Template;

use \Record\Read;
use \Config\Config;
use \DB\DB;

use Template\TemplateInterface;
use Template\Parts\{
    Links, 
    Rs, 
    Images, 
    Field
};

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Template implements TemplateInterface
{
    protected $context;
    protected $record;
    protected $cfg;
    protected $db;

    protected $tb;
    protected $settings;
    protected $current_plugin;
    protected $current_plugin_index;


    public function __construct(string $context, Read $record, DB $db, Config $cfg)
    {
        $this->record = $record;
        $this->context = $context;
        $this->db = $db;
        $this->cfg = $cfg;
    }

    public function cell(string $nr) : string
    {
        return "col-sm-$nr";
    }

    public function permalink() : ?string
    {
        if ($this->context === 'add_new') {
            return null;
        }
        return '<div class="permalink">' .
        '<a href="' . "./#/" . $this->cfg->get('main.name') . '/' . str_replace(PREFIX, '', $this->record->getTb()) . '/' . $this->record->getCore('id', true)  . '">PERMALINK</a>' .
      '</div>';
    }

    public function links() : ?string
    {
        if ($this->context === 'add_new') {
            return null;
        }
        if ($this->record->getCore('id', true) === null) {
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
        $corelinks = $this->record->getLinks();


        $html = Links::showAll(
            $corelinks, 
            $backlinks, 
            $this->context,
            $this->record->getTb(),
            $this->record->getCore('id', true), 
            $this->cfg
        );
        return $html;
    }

    public function userlinks()
    {
        if ($this->context !== 'add_new') {
            return Links::showUserLinks(
                $this->context,
                $this->record->getTb(),
                $this->record->getCore('id', true)
            );
        }
    }

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

    public function rs() : ?string
    {
        $rs_fld = $this->cfg->get("tables.{$this->record->getTb()}.rs");
        if (
            $this->context === 'add_new'
            ||
            ! $this->record->getCore($rs_fld, true) // In multiple edit the value is not set
        ) {
            return null;
        }

        if (!$rs_fld) {
            return null;
        }

        return Rs::showAll(
            $this->context,
            $this->record->getTb(),
            $this->record->getCore($rs_fld, true)
        );
    }

    public function image_thumbs(int $max = 2) : ?string
    {
        // get file data
        $data_arr = (array)$this->record->getFiles();

        return Images::showAll(
            $data_arr,
            $max,
            $this->record->getTb(),
            $this->record->getCore('id', true),
            $this->context,
            \utils::canUser('edit', $this->record->getCore('creator', true) ),
            $this->cfg->get('main.name')
        );
    }

    public function plg_fld(string $fieldname, string $formatting = null) : string
    {
        return $this->getFld(
            $fieldname,
            $formatting,
            $this->current_plugin,
            $this->current_plugin_index
        );
    }

    public function fld(string $fieldname, string $formatting = null) : string
    {
        return $this->getFld(
            $fieldname,
            $formatting
        );
    }
    private function getFld(string $fieldname, string $formatting = null, string $plg_name = null, int $plg_index = null) : string
    {
        // TODO: $this->record->getPlugin does not exist
        $db_data = ($plg_name && isset($plg_index)) ? $this->record->getPlugin($plg_name, $plg_index, $fieldname) : $this->record->getCore($fieldname, true);
        
        $tb = $plg_name ?: $this->record->getTb();
        
        $fld = new Field(
            $fieldname,
            $tb,
            $db_data,
            $this->context,
            $formatting,
            $plg_index,
            $this->db,
            $this->cfg
        );
        return $fld->show();
    }

    protected function showPlgRow($plg, $index)
    {
        $html = '<div class="pluginrow">' .
                    '<fieldset>';

        if (file_exists(PROJ_DIR . 'templates/' . str_replace(PREFIX, '', $plg) . '.twig')) {
            $this->current_plugin_index = (int) $index;

            $html .= $this->plg_fld('id', false);

            $twig = new Environment(new FilesystemLoader(PROJ_DIR . 'templates/'), unserialize(CACHE));

            $html .= $twig->render(str_replace(PREFIX, '', $plg) . '.twig', array(
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
                $html .= $this->getFld($fld_n, null, $plg, (int)$index);
            }
        }

        $html .= '</fieldset>' .
                '</div>';

        return $html;
    }

    public function geodata() : ?string
    {
        return $this->plg('geodata');
    }

    public function plg($plg) : ?string
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
        if (empty($plg_array) && $this->context === 'read') {
            return null;
        }

        // 1. No records found in database, but context is add_new or edit => show empty form:
        if (empty($plg_array) && ($this->context === 'edit' || $this->context === 'add_new')) {
            $html .= $this->showPlgRow($plg, uniqid(999)) . '<br/>';
        }

        // 2. Some records returned from database
        if (is_array($plg_array['data']) and !empty($plg_array['data'])) {
            foreach ($plg_array['data'] as $index => $plg_data) {
                $this->current_plugin_index = (int) $index;
                $html .= $this->showPlgRow($plg, $index) .
                    (($this->context === 'add_new' || $this->context === 'edit') ? '<button type="button" class="deleteplg btn btn-sm btn-default"><i class="fa fa-minus"></i></button>' : '') .
                    '<br/>';
            }
        }

        // Add row button, if context is edit or add_new
        if ($this->context === 'edit' or $this->context === 'add_new') {
            $html .= '<div class="plg_container">' .
                    '</div>'.
                    '<button type="button" class="addplg btn btn-sm btn-default"><i class="fa fa-plus"></i></button>';
        }
        $html .= '</fieldset>';

        $this->current_plugin = null;
        $this->current_plugin_index = null;
        return $html;
    }

    

    public function value($fld, $plg = false) : string
    {
        $val = $plg ? $this->record->getPlugin($this->current_plugin, $this->current_plugin_index, $fld) : $this->record->getCore($fld, true);
        return $val ?: '';
    }
}