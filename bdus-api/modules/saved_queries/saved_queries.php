<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

class saved_queries_ctrl
{
	public static function getById($id)
	{
		$savedQ = new SavedQueries(new DB());
		
		$res = $savedQ->getById($id);
		
		if ($res[0])
		{
			echo json_encode(array('status' => 'success', 'tb'=>$res[0]['table'], 'text'=>urlencode(base64_encode($res[0]['text']))));
		}
		else
		{
			echo json_encode(array('status'=>'error'));
		}
	}
	
	public static function showAll()
	{
		$savedQ = new SavedQueries(new DB());
		
		$res = $savedQ->getAll();
		
		if (count($res) == 0)
		{
			//echo '<div class="alert">' . tr.get('no_saved_queries') . '</div>';
			return;
		}
		
		
		$html = '<h2>' . tr::get('saved_queries') . '</h2>' .
      '<table class="saved_q table table-bordered table-striped">';
		foreach($res as $q)
		{
			$html .= '<tr>' .
        '<th>' . $q['id'] . '</th>' .
        '<td>' . $q['name'] . '</td>' .
        '<td>' . cfg::tbEl($q['table'], 'label') . '</td>' .
        '<td>' . $q['date'] . '</td>' .
        '<td class="buttons">' .
          '<div class="btn-group">' .
            '<a data-action="execute" data-tb="' . $q['table'] . '" ' .
              'data-text="' . urlencode(base64_encode($q['text'])) . '" ' .
              'class="btn btn-success">' .
              '<i class="glyphicon glyphicon-play"></i> ' .
                tr::get('execute_query') . 
              '</a>' .
						// only owners can delete queries
						( ( $q['is_global'] == 0) ? 
                    ' <a data-action="share" data-id="' . $q['id'] . '" ' .
                      'class="btn btn-info"><i class="glyphicon glyphicon-star"></i> ' . 
                        tr::get('share') .
                    '</a>' 
                  : '' ) .
						( ( $q['is_global'] == 1 AND $_SESSION['user']['id'] == $q['user_id']) ? 
                  ' <a data-action="unshare" data-id="' . $q['id'] . '" ' .
                    'class="btn btn-warning"><i class="glyphicon glyphicon-star-empty"></i> ' . 
                      tr::get('unshare') . '</a>' 
                  : '' ) .
            ( ($_SESSION['user']['id'] == $q['user_id']) ? 
                  ' <a data-action="erase" data-id="' . $q['id'] . '" ' .
                  'class="btn btn-danger"><i class="glyphicon glyphicon-trash"></i> ' . 
                  tr::get('erase') . '</a>' : '') .

						'</div>' .
					'</td>' .
			'</tr>';
		}
		$html .= '</table>';
	
		echo $html;
		
	}
	
	
	public static function actions($action, $id_tb, $name = false, $text = false)
	{
		try
		{
			$savedQ = new SavedQueries(new DB());
		
			switch($action)
			{
				case 'share':
					if ($savedQ->share($id_tb))
					{
						$msg = array('status'=>'success', 'text'=>tr::get('ok_sharing_query'));
					}
					else
					{
						$msg = array('status'=>'error', 'text'=>tr::get('error_sharing_query'));
					}
					break;
						
				case 'unshare':
					if ($savedQ->unshare($id_tb))
					{
						$msg = array('status'=>'success', 'text'=>tr::get('ok_unsharing_query'));
					}
					else 
					{
						$msg = array('status'=>'error', 'text'=>tr::get('error_unsharing_query'));
					}
					break;
						
				case 'erase':
					if($savedQ->erase($id_tb))
					{
						$msg = array('status'=>'success', 'text'=>tr::get('ok_erasing_query'));
					}
					else
					{
						$msg = array('status'=>'error', 'text'=>tr::get('error_erasing_query'));
					}
					break;
		
				case 'save':
					if ($savedQ->save($name, $text, $id_tb) )
					{
						$msg = array('status'=>'success', 'text'=>tr::get('ok_saving_query'));
					}
					else
					{
						$this->msg = array('status'=>'error', 'text'=>tr::get('error_saving_query'));
					}
					break;
			}
		}
		catch (myException $e)
		{
			$e->log();
		}
		
		echo json_encode($msg);
	}

}