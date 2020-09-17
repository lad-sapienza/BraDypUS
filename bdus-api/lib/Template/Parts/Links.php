<?php

namespace Template\Parts;

use SQL\SafeQuery;
use Config\Config;

class Links
{
    public static function showAll( array $corelinks = [], array $backlinks = [], string $context, string $tb, int $id, Config $cfg) : string
    {
        $html = '<fieldset class="links">'
            . '<legend>' . \tr::get('links_avaliable') . '</legend>';

        if (empty($corelinks) && empty($backlinks)) {
            $html .= '<p>' . \tr::get('no_system_links') . '</p>';
        }

        if (!empty($corelinks) ) {
            $html .= self::showCoreLinks($corelinks, $cfg);
        }
        if (!empty($backlinks)) {
            $html .= self::showBackLinks($backlinks, $cfg);
        }
        $html .= self::showUserLinks ($context, $tb, $id );

        $html .= '</fieldset>';
        return $html;

    }

    public static function showUserLinks (string $context, string $tb, int $id ) : string
    {
        // TODO: no lazy loading.
        return '<div class="showUserLinks" data-context="' . $context . '" data-tb="' . $tb . '" data-id="' . $id . '"></div>';
    }


    public static function showBackLinks (array $backlinks, Config $cfg ) : string
    {
        $html .= '<ul>';

        foreach ($backlinks as $dest_tb => $l_arr) {
            foreach ($l_arr as $v) {
                $html .= '<li><span class="btn-link" onclick="' 
                            . "api.record.read('{$dest_tb}', ['{$v['id']}'], " . ($c['label'] ? 'true' : ''). ");"
                        .'" href="javascript:void(0)">'
                    . $cfg->get( "tables.{$dest_tb}.label") . ':' . ($v['label'] ?: $v['id'])
                . '</span></li>';
            }
        }
        $html .= '</ul>';

        return $html;
    }

    public static function showCoreLinks (array $corelinks, Config $cfg ) : string
    {
        $html = '<p>' 
                    . '<i class="glyphicon glyphicon-link"></i> '
                    . '<strong>' . \tr::get('system_links'). '</strong>'
            . '</p>'
            . '<ul>';
        foreach ($corelinks as $dest_tb => $l_arr) {
            $html .= '<li>' .
                '<span class="btn-link" '
                 . 'onclick="' .
                    "api.showResults('{$dest_tb}', 'type=obj_encoded&obj_encoded=" . SafeQuery::encode($l_arr['where'], $l_arr['values']) . "&total={$l_arr['tot']}', '" . \tr::get('saved_queries') . " (". $cfg->get("tables.{$dest_tb}.label") . ")');"
                . '" href="javascript:void(0)">' .
                    \tr::get('links_in_table', ['<strong>' . $l_arr['tot'] . '</strong>', '<strong>' . $cfg->get("tables.$dest_tb.label") . '</strong>'])
                . '</span>';

            if ($cfg->get("tables.$dest_tb.rs")) {
                $html .= ' (<span class="btn-link" onclick="api.record.showMatrix(\'' . $dest_tb . '\', \'' . SafeQuery::encode($l_arr['where'], $l_arr['values']) . '\')">' 
                        . \tr::get('harris_matrix') 
                    . '</span>)';
            }
            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }
}