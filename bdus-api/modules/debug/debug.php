<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 */

class debug_ctrl extends Controller
{
	public function read()
	{
		$this->render(
				'debug', 
				'debug', 
				array(
						'tr'=> new tr(), 
						'canAdmin' => utils::canUser('admin')
						)
				);
		
	}
	
	public function ajax()
	{
		$tail = new PHPTail( ERROR_LOG );
		
		echo $tail->getNewLines($this->get['lastSize'], $this->get['grep'], $this->get['invert']);
	}
	
	public function delete()
	{
		if (utils::write_in_file(ERROR_LOG))
		{
			echo json_encode(array('text' => tr::get('log_delete'), 'status'=>'success'));
		}
		else
		{
			echo json_encode(array('text' => tr::get('log_not_deleted'), 'status'=>'error'));
		}
	}
}