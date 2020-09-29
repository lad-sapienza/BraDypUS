<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

 use \Db\System\Manage;


class user_ctrl extends Controller
{
	public function showList()
	{
		$data = [ 'admin' => \utils::canUser('admin') ];

		if (\utils::canUser('admin')) {
			$sys_manager = new DB\System\Manage($this->db, $this->prefix);
			$all_users = $sys_manager->getBySQL('users', '1=1');
			
			foreach( $all_users as $user ) {
				$data['users'][] = [
					'id' => $user['id'],
					'name' => $user['name'],
					'email' => $user['email'],
					'privilege' => \utils::privilege($user['privilege'], true),
					'editable' => (\utils::canUser('admin') && $user['privilege'] >= $_SESSION['user']['privilege'])
				];
			}
		} else {
			$data['users'][] = [
				'id' 		=> $_SESSION['user']['id'],
				'name' 		=> $_SESSION['user']['name'],
				'email' 	=> $_SESSION['user']['email'],
				'privilege' => \utils::privilege($_SESSION['user']['privilege'], true),
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
			$sys_manager = new Manage($this->db, $this->prefix);
			$ret = $sys_manager->deleteRow('users', $id);
		
			if ($ret) {
				$res['message'] = \tr::get('user_deleted');
				$res['status'] = 'success';
			} else {
				throw new \Exception('User deletion query returned false');
			}
		} catch (\Throwable $e) {
			$res['message'] = \tr::get('user_not_deleted');
			$res['status'] = 'error';
		
			$this->log->error($e);
		}
		
		echo json_encode($res);
	}
	
	public function showUserForm()
	{
		$id = $this->get['id'];
    
		if (isset($id) && $id !== $_SESSION['user']['id'] && !\utils::canUser('admin')){
			echo \tr::get('not_enough_privilege');
			return;
		}
		
		if ($id) {
			$sys_manager = new Manage($this->db, $this->prefix);
			$one_user = $sys_manager->getById('users', $id);
		} else {
			$one_user = [];
		}
		
		
		$data = [
			'id' => $one_user['id'],
			'name' => $one_user['name'],
			'email' => $one_user['email'],
			'avatar' => md5( strtolower( trim( $one_user['email'] ) ) )
		];

		foreach (\utils::privilege('all', true) as $k => $str) {
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
			$sys_manager = new Manage($this->db, $this->prefix);

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
				if (\utils::isDuplicateEmail( $this->db, $this->prefix, $data['email'], $data['id'] ) ) {
					$this->response('email_present', 'error', [$data['email']]);
					return;
				}
				$ret = $sys_manager->editRow('users', $data['id'], $data);
			} else {
				if (\utils::isDuplicateEmail( $this->db, $this->prefix, $data['email'] ) ) {
					$this->response('email_present', 'error', [$data['email']]);
					return;
				}
				// Add new user
				$ret = $sys_manager->addRow('users', $data);
			}
		
			if ($ret) {
				$this->response('user_data_saved', 'success');
			} else {
				throw new \Exception('Query returned false');
			}
		} catch (\Throwable $e){
			$this->log->error($e);
			$this->response('user_data_not_saved', 'error');
		}
	}
}