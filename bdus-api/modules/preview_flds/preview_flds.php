<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2013
 * @license			All rights reserved
 * @since			May 17, 2013
 */

class preview_flds_ctrl extends Controller
{
	public function showForm()
	{
		foreach(cfg::tbEl('all', 'label') as $tb_id => $tb_name)
		{
			$tb[] = array(
					'id' => $tb_id,
					'name' => $tb_name,
					'flds' => cfg::fldEl($tb_id, 'all', 'label')
					);
		}
		
		$this->render('preview_flds', 'form', array(
				'tb_data' => $tb,
				'pref_data' => pref::get('preview'),
				'tr' => new tr()
				));
	}
	
	
	
	public function save()
	{
		
		if (is_array($this->post) && !empty($this->post))
		{
			foreach($this->post as $tb => $data)
			{
				foreach ($data as $fld => $on)
				{
					$pref[$tb][] = $fld;
				}
			}
		}
		else
		{
			$pref = null;
		}
		
		pref::set('preview', $pref);
	}
}