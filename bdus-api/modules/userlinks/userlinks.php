<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 17, 2012
 */

class userlinks_ctrl
{
	
	public static function get_all_tables()
	{
		try
		{
			$ret['status'] = 'success';
			$ret['info'] = cfg::getNonPlg();
		}
		catch (myException $e)
		{
			$e->log();
			$ret['status'] = 'error';
			$ret['info'] = $e->getMessage();
		}
	
		echo json_encode($ret);
	
	}
	
	
	public static function link($post)
	{
		try
		{
			$record = new Record($post['thistb'], $post['thisid'], new DB());
			foreach($post['id'] as $id)
			{
				if ($record->addUserLink($post['tb'], $id))
				{
					$ok[] = true;
				}
				else
				{
					$no[] = true;
				}
			}
			
			if (!$no)
			{
				utils::response('all_links_saved');
			}
			else if ($no && $ok)
			{
				utils::log(cont($ok) . ' links were saved, ' . count($no) . ' links were not!');
				utils::response('some_links_saved', 'error');
			}
			else
			{
				utils::response('no_link_saved', 'error');
			}
		}
		catch(myException $e)
		{
			$e->log();
			utils::response('no_link_saved', 'error');
		}
	}
	
	public static function delete($id)
	{
		$record = new Record('no importance', false, new DB());
		
		if ($record->deleteUserLink($id))
		{
			utils::response('ok_userlink_erased');
		}
		else
		{
			utils::response('error_userlink_erased');
		}
	}
	
	public static function show($tb, $id, $context)
	{
		$record = new Record($tb, $id, new DB());
		
		$links = $record->getUserLinks();
		
		if ($links)
		{
			$tmp = array();
			
			foreach ($links as $link)
			{
				$tmp[] = '<li>' .
						'<span class="btn-link userlink_read" data-tb="' . $link['tb'] . '" data-id="' . $link['ref_id'] . '">' . 
							cfg::tbEl($link['tb'], 'label') . ', id:' . $link['ref_id'] .
						'</span>' 
					. ( ($context == 'edit') ? ' [<span class="btn-link userlink_delete" data-id="' . $link['id'] . '">' . tr::get('erase') . '</span>]' : '')
					. '</li>';
			}
		}
		
		$html = '<p><i class="glyphicon glyphicon-link"></i>  <strong>' . tr::get('user_links') . '</strong></p>'
				. ( $tmp ? '<ul>' . implode('', $tmp) . '</ul>' : tr::get('no_user_links'))
				. ( ($context == 'edit') ? 
						'<p>'
							. '<span class="btn btn-default btn-sm userlink_reload" data-table="' . $tb . '" data-id="' . $id . '"><i class="glyphicon glyphicon-repeat"></i> ' . tr::get('reload') . '</span>'
							. '<span class="btn btn-default btn-sm userlink_add" data-table="' . $tb . '" data-id="' . $id . '"><i class="glyphicon glyphicon-plus"></i> ' . tr::get('add') . '</span>'
						. '</p>' : '');
		
		echo $html;
	}
}