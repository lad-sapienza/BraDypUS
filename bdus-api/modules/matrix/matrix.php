<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 16, 2012
 */

class matrix_ctrl extends Controller
{
	public function show()
	{
		$tb = $this->get['tb'];
		$where = $this->get['query'];
		
		try
		{
			$where = $where ? base64_decode($where) : '1=1';
			
			$dotText = $this->createDotContentNew($tb, $where);
			
			$safeDotTex = str_replace(
				[ "'", "concentrate=true;", "\n" ],
				[ "\'", '', '' ],
				trim($dotText)
			);
			
			$this->render('matrix', 'matrix', array(
				'dotText' => $safeDotTex,
				'selfPath' => MOD_DIR . 'matrix/',
				'tb' => $tb,
				'query_arrived' => $where
			));
		}
		catch (\Exception $e)
		{
			$this->log->error($e);
			echo '<div class="alert alert-danger"> '
					. '<strong>' . tr::get('attention') . ':</strong> ' . tr::get($e->getMessage()) . '</p>'
				. '</div>';
		}
	}

	private function createDotContentNew(string $tb, string $where = null): string
    {
        $tbbis = $tb . 'bis';
        $const = get_defined_constants();
        $rsfld = cfg::tbEl($tb, 'rs');
        $q = <<<EOD
SELECT
    {$tb}.id as firstid,
    rs.first as firstlabel,
    {$tbbis}.id as secondid,
    rs.second as secondlabel,
    rs.relation as rel
FROM
{$const['PREFIX']}rs as rs

LEFT JOIN {$tb} ON  rs.tb = '{$tb}' AND rs.first = {$tb}.{$rsfld}
LEFT JOIN {$tb} as {$tbbis} ON  rs.tb = '{$tb}' AND rs.second = {$tbbis}.{$rsfld}

WHERE {$tb}.id IN
(SELECT id FROM {$tb} WHERE {$where})
EOD;
        $res = $this->db->query($q);

        if (!is_array($res)) {
            throw new \Exception('query_produced_no_result');
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
}