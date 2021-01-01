<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */


namespace Template\Parts;

use SQL\SafeQuery;
use Config\Config;

class Geodata
{
    public static function showAll( array $gdata = [], string $tb, int $id, string $plg_html = null, Config $cfg) : string
    {
        if (!empty($gdata)) {
            $view_this = '<p>' . \tr::get('x_geodata_available', ['<strong>' . count($gdata) . '</strong>'])
            . ' <span class="btn btn-link" onclick="core.runMod(\'geoface\', [\'' . $tb . '\', \'' . SafeQuery::encode(" id=$id ") . '\']);">' 
                . \tr::get('view_on_map') 
            . '</span></p>';
        }

        $view_all = '<p><span class="btn btn-link" onclick="core.runMod(\'geoface\', \'' . $tb . '\');">' 
            . \tr::get('view_all_records_on_map') 
        . '</span></p>';


        if ($cfg->get('tables.' . PREFIX . 'geodata.is_plugin')) {
            // https://stackoverflow.com/a/5139071/586449
            return substr_replace(
                $plg_html,
                $view_this . $view_all . '</fieldset>',
                strrpos($plg_html, '</fieldset>'),
                strlen('</fieldset>')
            );
        } else {
            return '<fieldset class="geodata">' .
                    '<legend>' . \tr::get('geodata') . '</legend>' .
                    (!empty($gdata) ? $plg_html . $view_this : '<p>' . \tr::get('no_geodata_available') . '</p>') .
                    $view_all .
                '</fieldset>';
        }
    }
}