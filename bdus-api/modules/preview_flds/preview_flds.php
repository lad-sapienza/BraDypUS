<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			May 17, 2013
 */

class preview_flds_ctrl extends Controller
{
	public function showForm()
	{
		$tb_list = $this->cfg->get('tables.*.label');
		
		foreach($tb_list as $tb_id => $tb_name) {
			$tb[] = [
				'id' => $tb_id,
				'name' => $tb_name,
				'flds' => $this->cfg->get("tables.{$tb_id}.fields.*.label")
			];
		}
		
		$this->render('preview_flds', 'form', [
			'tb_data' => $tb,
			'pref_data' => \pref::get('preview'),
		]);
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
		
		\pref::set('preview', $pref);
	}
}