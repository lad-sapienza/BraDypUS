<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

class user_ctrl extends Controller
{
	public function showList()
	{
		$data = array(
				'tr_name' => tr::get('name'),
				'tr_email' => tr::get('email'),
				'tr_privilege' => tr::get('privilege'),
				'tr_edit' => tr::get('edit'),
				'tr_erase' => tr::get('erase'),
				'tr_add_new_user' => tr::get('add_new_user'),
				'admin' => utils::canUser('admin')
				);
		if (!utils::canUser('admin'))
		{
			$data['users'][] = array(
					'id' => $_SESSION['user']['id'],
					'name' => $_SESSION['user']['name'],
					'email' => $_SESSION['user']['email'],
					'privilege' => utils::privilege($_SESSION['user']['privilege'], true),
					'editable' => true
					);
		}
		else
		{
			$user_obj = new User(new DB());
			
			$all_users = $user_obj->getUser('all');
			
			foreach( $all_users as $user )
			{
				$data['users'][] = array(
						'id' => $user['id'],
						'name' => $user['name'],
						'email' => $user['email'],
						'privilege' => utils::privilege($user['privilege'], true),
						'editable' => (utils::canUser('admin') && $user['privilege'] >= $_SESSION['user']['privilege'])
						);
			}
		}
		
    
    $this->render('user', 'users_list', $data);
	}
	
	public static function erase($id)
	{
		try
		{
			$user = new User(new DB());
		
			$ret = $user->delete($id);
		
			if ($ret)
			{
				$res['message'] = tr::get('user_deleted');
				$res['status'] = 'success';
			}
			else
			{
				throw new myException('User deletion query returned false');
			}
		}
		catch (myException $e)
		{
			$res['message'] = tr::get('user_not_deleted');
			$res['status'] = 'error';
		
			$e->log();
		}
		
		echo json_encode($res);
	}
	
	public function editUI()
	{
    
    $id = $this->request['param'][0];
    
		if ($id != $_SESSION['user']['id'] && !utils::canUser('admin'))
		{
			echo tr::get('not_enough_privilege');
			return;
		}
		
		if ($id)
		{
			$user = new User(new DB());
		
			$one_user = $user->getUser(array('id'=>$id));
		
			$one_user = $one_user[0];
		}
		else
		{
			$one_user = array();
		}
		
		
		$data = array(
				'tr_name' => tr::get('name'),
				'tr_email' => tr::get('email'),
				'tr_password' => tr::get('password'),
				'tr_privilege' => tr::get('privilege'),
				'id' => $one_user['id'],
				'name' => $one_user['name'],
				'email' => $one_user['email'],
				'avatar' => md5( strtolower( trim( $one_user['email'] ) ) )
				);
		foreach (utils::privilege('all', true) as $k => $str)
		{
			if ($k >= $_SESSION['user']['privilege'])
			{
				$data['privileges'][] = array(
						'id'	=> $one_user['id'],
						'value' => $k,
						'label' => $str,
						'selected' => ($k == $one_user['privilege'])
						);
			}
		}
		
    $this->render('user', 'user_form', $data);
		
	}
	
	public function edit()
	{
    $data = $this->post;
		try
		{
			$user = new User(new DB());
		
			if ($data['id'] && !empty($data['id']))
			{
				$ret = $user->update($data['id'], $data['name'], $data['email'], $data['password'], $data['privilege']);
			}
			else
			{
				$ret = $user->insert($data['name'], $data['email'], $data['password'], $data['privilege']);
			}
		
			if ($ret)
			{
				utils::response('user_data_saved');
			}
			else
			{
				throw new myException('Quesry returned false');
			}
		}
		catch (myException $e)
		{
			utils::response('user_data_not_saved', 'error');
			$e->log();
		}
	}
}