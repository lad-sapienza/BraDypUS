<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */
class vocabularies_ctrl extends Controller
{
	public function show()
	{
		$vocs = new Vocabulary($this->db);
		
		$this->render('vocabularies', 'list', [
			'vocs' => $vocs->getAll(),
		]);
	}
	
	public function add_new_form()
	{
		$voc = $this->get['voc'] ?: false ;

		if (!$voc) {
			$vocs = new Vocabulary($this->db);
			$all_vocs = $vocs->getAllVoc();
		}
		
		$this->render('vocabularies', 'new_form', [
			'voc' => $voc,
			'all_vocs' => $all_vocs,
		]);
	}
	
	
	public function getAllVoc()
	{
		$voc = new Vocabulary($this->db);
		
		$all_vocs = $voc->getAllVoc();
	
		if (!$all_vocs)
		{
			$resp = array('status'=>'error', 'text'=>tr::get('no_voc_available'));
		}
		else
		{
			$resp = array('status'=>'success', 'data'=>$all_vocs);
		}
		echo json_encode($resp);
	}

	public function getAll()
	{
		$voc = new Vocabulary($this->db);
		
		$all_vocs = $voc->getAll();
	
		if (!$all_vocs)
		{
			$resp = array('status'=>'error', 'text'=>tr::get('no_voc_available'));
		}
		else
		{
			$resp = array('status'=>'success', 'data'=>$all_vocs);
		}
		echo json_encode($resp);
	}
	
	public function edit()
	{
		//necessary parameters: id, def
		$voc = new Vocabulary($this->db);

		if ($voc->update($this->get['id'], $this->get['val'])) {
			$resp = array('status'=>'success', 'text'=>tr::get('ok_def_update'));
		} else {
			$resp = array('status'=>'error', 'text'=>tr::get('error_def_update'));
		}
			
		echo json_encode($resp);
				
	}
	
	public function erase()
	{
		$voc = new Vocabulary($this->db);
		
		if ($voc->erase($this->get['id']))
		{
			$resp = array('status'=>'success', 'text'=>tr::get('ok_def_erase'));
		}
		else
		{
			$resp = array('status'=>'error', 'text'=>tr::get('error_def_erase'));
		}
		
		echo json_encode($resp);
				
	}
	
	public function add()
	{
		// necessary parameters: voc, def
		
		$voc = new Vocabulary($this->db);
		
		if ($voc->add($this->get['voc'], $this->get['def']))
		{
			$resp = array('status'=>'success', 'text'=>tr::get('ok_def_added'));
		}
		else
		{
			$resp = array('status'=>'error', 'text'=>tr::get('error_def_added'));
		}
		echo json_encode($resp);
	}
	
	public function sort()
	{
		$voc = new Vocabulary($this->db);
		
		foreach($this->post as $arr)
		{
			if ($voc->sort($arr)) {
				$resp = array('status'=>'success', 'text'=>tr::get('ok_sort_update'));
			} else {
				$resp = array('status'=>'error', 'text'=>tr::get('error_sort_update'));
			}
		}
		echo json_encode($resp);
	}
}