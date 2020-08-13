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

    private function pagination($data, $self, $context)
    {
        $html = '<fieldset>'
            . '<legend>' . tr::get('rs') . '</legend>'
            . '<table cellpadding="5" cellspacing="5" class="table-responsive">'
                . '<tr>'
                    . '<td class="r1" style="width:25%"><strong>' . tr::get('is_covered_by') . '</strong><br />' . ($data[1] ? implode('', $data[1]) : '') . '</td>'
                    . '<td class="r2" style="width:25%"><strong>' . tr::get('is_cut_by') . '</strong><br />' . ($data[2] ? implode('', $data[2]) : '') . '</td>'
                    . '<td class="r3" style="width:25%"><strong>' . tr::get('carries') . '</strong><br />' . ($data[3] ? implode('', $data[3]) : '') . '</td>'
                    . '<td class="r4" style="width:25%"><strong>' . tr::get('is_filled_by') . '</strong><br />' . ($data[4] ? implode('', $data[4]) : '') . '</td>'
                . '</tr>'

                . '<tr>'
                    . '<td class="r9"><strong>' . tr::get('is_the_same_as') . '</strong><br />' . ($data[9] ? implode('', $data[9]) : '') . '</td>'
                    . '<td colspan="2" style="text-align:center; font-size:1.5em;"><strong><br />' . $self . '</td>'
                    . '<td class="r10"><strong>' . tr::get('is_bound_to') . '</strong><br />' . ($data[10] ? implode('', $data[10]) : '') . '</td>'
                . '</tr>'

                . '<tr>'
                    . '<td class="r5"><strong>' . tr::get('covers') . '</strong><br />' . ($data[5] ? implode('', $data[5]) : '') . '</td>'
                    . '<td class="r6"><strong>' . tr::get('cuts') . '</strong><br />' . ($data[6] ? implode('', $data[6]) : '') . '</td>'
                    . '<td class="r7"><strong>' . tr::get('leans_against') . '</strong><br />' . ($data[7] ? implode('', $data[7]) : '') . '</td>'
                    . '<td class="r8"><strong>' . tr::get('fills') . '</strong><br />' . ($data[8] ? implode('', $data[8]) : '') . '</td>'

                . '</tr>';
        if ($context == 'edit') {
            $html .= '<tr>' .
                '<td colspan="4" class="new_rel" style="padding:20px 0 0 0;">' .
                    '<div class="form-inline">' .
                        '<div class="form-group">' .
                            ' <select class="rel">';

            foreach ($this->rels(true) as $id=>$rel) {
                $html .= '<option value="' . $id . '">' . $rel . '</option>';
            }

            $html .= '</select>' .
                        '</div>' .
                        '<div class="form-group">' .
                            ' <input type="text" class="second" />' .
                        '</div>' .
                        ' <button type="button" class="btn btn-default save">' . tr::get('save') . '</button>' .
                        '</div>' .
                    '</td>' .
                '</tr>';
        }
        $html .= '</table>'
            . '</fieldset>';

        return $html;
    }

    public function deleteRS()
    {
        $id = $this->get['id'];
        
        $record = new Record('no importance', false, new DB());

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

        $record = new Record($tb, $id, new DB);

        $res = $record->getRS();

        if ($res && is_array($res)) {
            foreach ($res as $rel) {
                $delete = ($context == 'edit') ? ' <a href="javascript:void(0)" class="delete" data-id="' . $rel['id'] . '" title="' . tr::get('erase') . '">[x]</a>' : '';

                if ($id == $rel['first']) {
                    $data[$rel['relation']][] = '<div class="rsEl"><span class="a">' . $rel['second'] . '</span>' . $delete . '</div>';
                } elseif ($id == $rel['second'] and $rel['relation'] < 5) {
                    $data[($rel['relation'] + 4)][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                } elseif ($id == $rel['second'] and $rel['relation'] < 9) {
                    $data[($rel['relation'] - 4)][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                } else {
                    $data[$rel['relation']][] = '<div class="rsEl"><span class="a">' . $rel['first'] . '</span>' . $delete . '</div>';
                }
            }
        }

        echo $this->pagination($data, $id, $context);
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

        $record = new Record($tb, false, new DB());

        $id = $record->addRS($first, $relation, $second);

        if (!$id) {
            utils::response('relation_already_exist', 'error');
        } else {
            utils::response('ok_relation_add', 'success', false, [ 'id' => $id ]);
        }
    }
}
