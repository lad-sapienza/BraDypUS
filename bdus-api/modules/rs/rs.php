<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 14, 2012
 */

class rs_ctrl extends Controller
{
    private static function rels($translate = false)
    {
        $rels = [
          1 => 'is_covered_by',
          2 => 'is_cut_by',
          3 => 'carries',
          4 => 'is_filled_by',

          5 => 'covers',
          6 => 'cuts',
          7 => 'leans_against',
          8 => 'fills',

          9 => 'is_the_same_as',
          10 => 'is_bound_to'
        ];

        if (!$translate) {
            return $rels;
        } else {
            foreach ($rels as $k=>$r) {
                $tr[$k] = tr::get($r);
            }
            return $tr;
        }
    }

    public function deleteRS()
    {
        $id = $this->get['id'];

        $record = new Record('no importance', false, $this->db, $this->cfg);

        if ($record->deleteRS($id)) {
            utils::response('ok_relation_erased');
        } else {
            utils::response('error_relation_erased');
        }
    }


    public function getAllRS()
    {
        $tb = $this->get['tb'];
        $id = $this->get['id'];
        $context = $this->get['context'];

        $record = new Record($tb, $id, $this->db, $this->cfg);

        $res = $record->getRS();

        if ($res && is_array($res)) {
            foreach ($res as $rel) {
                $delete = ($context == 'edit') ? ' <a href="javascript:void(0)" class="delete" data-id="' . $rel['id'] . '" title="' . tr::get('erase') . '">[x]</a>' : '';

                if ($id === $rel['first']) {
                    $data[$rel['relation']][] = '<div class="rsEl"><span class="a">' . $rel['second'] . '</span>' . $delete . '</div>';
                } elseif ($id === $rel['second'] and $rel['relation'] < 5) {
                    $data[($rel['relation'] + 4)][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                } elseif ($id === $rel['second'] and $rel['relation'] < 9) {
                    $data[($rel['relation'] - 4)][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                } else {
                    $data[$rel['relation']][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                }
            }
        }

        $this->render('rs', 'mainRsTmpl', [
            'context' => $context,
            'rels' => $this->rels(true),
            'data' => $data,
            'self' => $id,
        ]);
    }


    public function saveNewRS()
    {
        $tb = $this->get['tb'];
        $first = $this->get['first'];
        $relation = $this->get['relation'];
        $second = $this->get['second'];

        if (!$tb ) throw new Exception("Missing tb");
        if (!$first ) throw new Exception("Missing first");
        if (!$second ) throw new Exception("Missing second");
        if (!$relation ) throw new Exception("Missing relation");

        $record = new Record($tb, false, $this->db, $this->cfg);

        $id = $record->addRS($first, $relation, $second);

        if (!$id) {
            utils::response('relation_already_exist', 'error');
        } else {
            utils::response('ok_relation_add', 'success', false, [ 'id' => $id ]);
        }
    }
}
