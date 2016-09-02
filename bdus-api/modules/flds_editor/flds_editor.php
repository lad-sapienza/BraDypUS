<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 24, 2012
 */

class flds_editor_ctrl extends Controller
{
	public function tb_list()
	{

		echo $this->render('flds_editor', 'main', array(
				'uid' => uniqid('main'),
				'tbs_data' => cfg::tbEl('all', 'label')
				));
	}

	public function save()
	{
		$post = $this->post;
		try
		{
			$post = utils::recursiveFilter($post);

			$tb = $post['tb_name'];
			$fld = $post['fld_name'];
			unset($post['tb_name']);
			unset($post['fld_name']);

			if (!$post['name'] || !$post['type'])
			{
				throw new myException('Both field name and field type are required');
			}

			cfg::setFld($tb, $fld, $post);

			utils::response('ok_save_fld_data');
		}
		catch(myException $e)
		{
			$e->log();
			utils::response('error_save_fld_data', 'error');
		}

	}

	public function show_list()
	{
		$tb = $this->get['tb'];

		echo $this->render('flds_editor', 'fld_list', array(
				'tr' => new tr(),
		 		'uid' => uniqid('flded'),
		 		'tb' => $this->get['tb'],
		 		'label' => cfg::tbEl($tb, 'label'),
				'all_fields' => cfg::fldEl($tb, 'all', 'all')
		 ));
	}

	public function show_form()
	{
		$tb = $this->get['tb'];
		$fld = $this->get['fld'];

		$data = $fld ? $data = cfg::fldEl($tb, $fld, 'all'): array();

		$html = '<form action="javascript:void(0)">'
			. '<button type="submit" style="display:none;"></button>'
			. '<input type="hidden" name="tb_name" value="' . $tb . '" />'
			. ($fld != 'false' ? '<input type="hidden" name="fld_name" value="' . $fld . '" />' : '')

			. '<table class="table table-hover table-bordered table-striped">'
				. '<th>Fieldname</th>'
				. '<th style="width:400px">Values</th>'
				. '<th>Help</th>';

		foreach ($this->fld_structure() as $id => $arr)
		{
			$html .= '<tr class="tr_' . $id . '">'
				. '<th>' . ($arr['required'] ? '*' : '') . $id . '</th><td>' ;

			switch($arr['type'])
			{
				case 'input':
					$html .= '<input class="input_' . $id. '" name="' . $id . '" type="text" ' . (($id == 'name' && $data[$id]) ? ' readonly="true" ' : ''). ' value="' . $data[$id] . '" />';
					break;

				case 'select':
					$html .= '<select class="input_' . $id. '" name="' . $id . '">'
							. '<option></option>';
							if (isset($arr['values']) && is_array($arr['values']))
							{
								foreach ($arr['values'] as $val)
								{
									$html .= '<option' . ($val == $data[$id] ? ' selected="true"' : '') . '>' . $val . '</option>';
								}
							}
					$html .= '</select>';
					break;

				case 'multi_select':

					is_array($data[$id]) ? array_push($data[$id], '') : $data[$id] = array('');

					foreach($data[$id] as $v)
					{
						$html .= '<select class="input_' . $id. '" name="' . $id . '[]">'
							. '<option></option>';
							foreach ($arr['values'] as $val)
							{
								$html .= '<option' . ($val == $v ? ' selected="true"' : '') . '>' . $val . '</option>';
							}
						$html .= '</select><br />';
					}
					break;

				default;
				break;
			}

			$html .= '</td>'
					. '<td>' . $arr['help'] . '</td>'
				. '</tr>';
		}
		$html .= '</table></form>';

		echo $html;
	}


	private function fld_structure()
	{
		$voc = new Vocabulary(new DB());

		$all_voc = $voc->getAllVoc();

		$fld_el = array(
			'name' => array(
				'type'=>'input',
				'help'=>'Field id',
				'required'=>true),
			'label' => array(
				'type'=>'input',
				'help'=>'Field label',
				'required'=>false),
			'type' => array(
				'type'=>'select',
				'values'=>array('text', 'date', 'long_text', 'select', 'combo_select', 'multi_select', 'boolean', 'slider'),
				'help'=>'Field type',
				'required'=>true),
			'vocabulary_set' => array(
				'type'=>'select',
				'values' =>$all_voc,
				'help'=>'Select if field type is selct, combo_select or multiselect',
				'required'=>false),
			'get_values_from_tb' => array(
				'type'=>'input',
				'help'=>'Select if field type is selct, combo_select or multiselect. Syntax: {prefix}__{tablename}:{fieldid}, eg.: sitarc__siti:id_sito',
				'required'=>false),
		'id_from_tb' => array(
				'type'=>'select',
				'help'=>'Select if field type is selct, combo_select or multiselect. Syntax: {prefix}__{tablename}:{fieldid}, eg.: sitarc__siti:id_sito',
				'values' => cfg::tbEl('all','name'),
				'required'=>false),
			'check' => array(
				'type'=>'multi_select',
				'values'=>array('int', 'email', 'no_dupl', 'not_empty', 'range', 'regex'),
				'help'=>'Real time (on value change & on form submission) controls for form field values',
				'required'=>false),
			'active_link' =>array(
				'type' => 'select',
				'values' => array(true),
				'help' => 'If true field value will have a link to a query showing all similar records',
				'required' => false),
			'readonly' => array(
				'type'=>'select',
				'values'=>array(true),
				'help'=>'If true field value will be visible, its value will send, but can not be edited by users',
				'required'=>false),
			'disabled' => array(
				'type'=>'select',
				'values'=>array(true),
				'help'=>'If true field value will be visible, but its value will not be send, and can not be edited by users',
				'required'=>false),
			'hide' => array(
				'type'=>'select',
				'values'=>array(true),
				'help'=>'If true field value will not be visible',
				'required'=>false),
			'def_value' => array(
				'type'=>'input',
				'help'=>'Default value. The following variables can be used: %today%, %current_user%',
				'required'=>false),
			'max_length' => array(
				'type'=>'input',
				'help'=>'Maximum fields length. An integer should be entered',
				'required'=>false),
			'db_type'=> array('type'=>'select',
				'values'=>array('TEXT', 'INTEGER', 'DATETIME'),
				'help'=>'Database field type',
				'required'=>false),
			'min'=> array(
				'type'=>'input',
				'help'=>'Minimum allowed value, for sliders. An integer should be entered',
				'required'=>false),
			'max' => array(
				'type'=>'input',
				'help'=>'Maximum allowed value, for sliders. An integer should be entered',
				'required'=>false),
			'pattern' => array(
				'type'=>'input',
				'help'=>'Regex pattern to use for field value evaluation',
				'required'=>false),
			'help' => array(
				'type'=>'input',
				'help'=>'Help text to show to users',
				'required'=>false)
				);

		return $fld_el;
	}

}
