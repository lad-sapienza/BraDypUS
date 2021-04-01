<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */


namespace Template\Parts;

use SQL\SafeQuery;
use SQL\ShortSql\ParseShortSql;
use Config\Config;

class Links
{
    public static function showAll( array $corelinks = [], array $backlinks = [], string $context, string $tb, int $id, Config $cfg) : string
    {
        $html = '<fieldset class="links">'
            . '<legend>' . \tr::get('links_avaliable') . '</legend>';

        if (empty($corelinks) && empty($backlinks)) {
            $html .= '<p>' . \tr::get('no_system_links') . '</p>';
        } else {
            $html .= '<p>' 
                    . '<i class="fa fa-link"></i> '
                    . '<strong>' . \tr::get('system_links'). '</strong>'
            . '</p>';
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
        $html = '';
        
        foreach ($backlinks as $dest_tb => $l_arr) {
            if ((int)$l_arr['tot'] > 0) {
                $html .= \tr::get('links_in_table', [$l_arr['tot'], $l_arr['tb_label']]) . ':';
            }
            foreach ($l_arr['data'] as $v) {
                $html .= '<li><span class="btn-link" onclick="' 
                            . "api.record.read('{$dest_tb}', ['{$v['id']}']);"
                        .'" href="javascript:void(0)">'
                    . $cfg->get( "tables.{$dest_tb}.label") . ': ' . ($v['label'] )
                . '</span></li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    public static function showCoreLinks (array $corelinks, Config $cfg ) : string
    {
        $html = '<ul>';
        foreach ($corelinks as $dest_tb => $l_arr) {
            $prefix = \str_replace($l_arr['tb_stripped'], '', $l_arr['tb_id']);
            $parseShortSql = new ParseShortSql($prefix, $cfg);
            $parseShortSql->parseAll("@{$l_arr['tb_stripped']}~?{$l_arr['where']}");
            list($where_sql, $v) = $parseShortSql->getSql(true);

            $html .= '<li>' .
                '<span class="btn-link" '
                 . 'onclick="' .
                    "api.showResults('{$dest_tb}', 'type=obj_encoded&obj_encoded=" . SafeQuery::encode($where_sql, $v) . "&total={$l_arr['tot']}', '" . \tr::get('saved_queries') . " (". $cfg->get("tables.{$dest_tb}.label") . ")');"
                . '" href="javascript:void(0)">' .
                    \tr::get('links_in_table', ['<strong>' . $l_arr['tot'] . '</strong>', '<strong>' . $cfg->get("tables.$dest_tb.label") . '</strong>'])
                . '</span>';

            if ($cfg->get("tables.$dest_tb.rs")) {
                $html .= ' (<span class="btn-link" onclick="api.record.showMatrix(\'' . $dest_tb . '\', \'' . SafeQuery::encode($where_sql, $v) . '\')">' 
                        . \tr::get('harris_matrix') 
                    . '</span>)';
            }
            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }
}