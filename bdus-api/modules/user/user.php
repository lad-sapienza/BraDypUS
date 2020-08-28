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
		$data = [ 'admin' => utils::canUser('admin') ];

		if (utils::canUser('admin')) {
			$user_obj = new User($this->db);
			
			$all_users = $user_obj->getUser('all');
			
			foreach( $all_users as $user ) {
				$data['users'][] = [
					'id' => $user['id'],
					'name' => $user['name'],
					'email' => $user['email'],
					'privilege' => utils::privilege($user['privilege'], true),
					'editable' => (utils::canUser('admin') && $user['privilege'] >= $_SESSION['user']['privilege'])
				];
			}
		} else {
			$data['users'][] = [
				'id' 		=> $_SESSION['user']['id'],
				'name' 		=> $_SESSION['user']['name'],
				'email' 	=> $_SESSION['user']['email'],
				'privilege' => utils::privilege($_SESSION['user']['privilege'], true),
				'editable' 	=> true
			];
		}
		
    
    	$this->render('user', 'showList', $data);
	}
	
	public function deleteOne($id = false)
	{
		if (!$id){
			$id = $this->get['id'];
		}
		try {
			$user = new User($this->db);
		
			$ret = $user->delete($id);
		
			if ($ret) {
				$res['message'] = tr::get('user_deleted');
				$res['status'] = 'success';
			} else {
				throw new \Exception('User deletion query returned false');
			}
		} catch (\Exception $e) {
			$res['message'] = tr::get('user_not_deleted');
			$res['status'] = 'error';
		
			$this->log->error($e);
		}
		
		echo json_encode($res);
	}
	
	public function showUserForm()
	{
		$id = $this->get['id'];
    
		if (isset($id) && $id !== $_SESSION['user']['id'] && !utils::canUser('admin')){
			echo tr::get('not_enough_privilege');
			return;
		}
		
		if ($id) {
			$user = new User($this->db);
			$one_user = $user->getUser( [ 'id' => $id ] );
			$one_user = $one_user[0];
		} else {
			$one_user = [];
		}
		
		
		$data = [
			'id' => $one_user['id'],
			'name' => $one_user['name'],
			'email' => $one_user['email'],
			'avatar' => md5( strtolower( trim( $one_user['email'] ) ) )
		];

		foreach (utils::privilege('all', true) as $k => $str) {
			if ($k >= $_SESSION['user']['privilege']) {
				$data['privileges'][] = [
					'id'	=> $one_user['id'],
					'value' => $k,
					'label' => $str,
					'selected' => ($k === $one_user['privilege'])
				];
			}
		}
		
    	$this->render('user', 'showUserForm', $data);
		
	}
	
	public function saveUserData()
	{
    	$data = $this->post;
		try {
			$sys_manager = new \Db\System\Manage($this->db, $this->prefix);

			foreach ($data as $key => &$value) {
				if ($key === 'password'){
                    if ($value && $value !== '') {
                        $value = sha1($value);
                    } else {
						unset($data[$key]);
					}
				}
			}

			if ($data['id'] && !empty($data['id'])) {
				// Edit existing user
				$ret = $sys_manager->editRow('users', $data['id'], $data);
			} else {
				// Add new user
				$ret = $sys_manager->addRow('users', $data);
			}
		
			if ($ret) {
				utils::response('user_data_saved');
			} else {
				throw new \Exception('Query returned false');
			}
		}
		catch (\Exception $e)
		{
			utils::response('user_data_not_saved', 'error');
			$this->log->error($e);
		}
	}
}