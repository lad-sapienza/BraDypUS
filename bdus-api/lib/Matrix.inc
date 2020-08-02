<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 16, 2012
 */


class Matrix
{
    private $dotfile;

    private $dotcontent;

    public function __construct($tb, $where_q = false, DB $db)
    {
        $query = "SELECT * FROM `" . PREFIX . "rs` WHERE `tb` = '" . $tb . "'";

        $where = $where_q ? base64_decode($where_q) : '1';

        $this->dotcontent = $this->createDotContentNew($tb, $where, $db);
    }

    private function createDotContentNew($tb, $where = false, DB $db)
    {
        $tbbis = $tb . 'bis';
        $const = get_defined_constants();
        $rsfld = cfg::tbEl($tb, 'rs');
        $q = <<<EOD
SELECT
`{$tb}`.`id` as `firstid`,
`rs`.`first` as `firstlabel`,
`{$tbbis}`.id as `secondid`,
`rs`.`second` as `secondlabel`,
`rs`.`relation` as `rel`

FROM
`{$const['PREFIX']}rs` as rs

LEFT JOIN {$tb} ON  rs.tb = '{$tb}' AND `rs`.`first` = `{$tb}`.`{$rsfld}`
LEFT JOIN {$tb} as {$tbbis} ON  rs.tb = '{$tb}' AND `rs`.`second` = `{$tbbis}`.`{$rsfld}`

WHERE `{$tb}`.`id` IN
(SELECT `id` FROM {$tb} WHERE {$where})
EOD;
        $res = $db->query($q);

        if (!is_array($res)) {
            throw new myException('query_produced_no_result');
        }

        $dotrows = [];
        $nodes = [];


        foreach ($res as $r) {
            if (!$r['secondid']){
                $r['secondid'] = $r['secondlabel'];
            }

            if ( $r['rel'] > 0 && $r['rel'] < 5 ) {

                array_push($dotrows, "  \"{$r['secondid']}\" -> \"{$r['firstid']}\";");

            } elseif ( $r['rel'] > 4 && $r['rel'] < 9 ) {

                array_push($dotrows, "  \"{$r['firstid']}\" -> \"{$r['secondid']}\";");

            }

            // Add first element to nodes array, if not exists
            if (!isset($nodes[$r['firstid']])) {
                $nodes[$r['firstid']] = [$r['firstlabel']];
            }

            if ($r['rel'] > 8) {
                array_push($nodes[$r['firstid']], $r['secondlabel']);
            } else {
                if (!isset($nodes[$r['secondid']])) {
                    $nodes[$r['secondid']] = [$r['secondlabel']];
                }
            }

        }

        $dot = "digraph G {\n" .
                "  concentrate=true;\n";

        foreach ($nodes as $id => $labels) {
        	$dot .= '  ' . $id . '[label="' . implode('=', $labels) . '"];' . "\n";
        }

        $dot .= implode("\n", $dotrows) . "\n}";

        return $dot;

    }


    public function getDotContent()
    {
        return $this->dotcontent;
    }
}
