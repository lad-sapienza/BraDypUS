<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 15, 2012
 */

class login_ctrl extends Controller
{
	
	public function loginForm()
	{
		$app_data = json_decode(file_get_contents(PROJS_DIR . $this->request['app'] . '/cfg/app_data.json'), true);
		
		$this->render('login', 'login', array(
				'tr' => new tr(),
				'appdata' => $app_data,
				'uid' => uniqid('login')
		));
	}
	
	
	
	public function newUserForm()
	{
		$this->render('login', 'new_user', array(
				'tr' => new tr(),
				'app' => $this->get['app'],
				'uid' => uniqid('new_user')
		));
	}
	
	public function resetPwd()
	{
		$user = new User(new DB($this->get['app']));
	
		$res = $user->getUser(array('email'=>$this->get['email']));
	
		if ($res[0])
		{
			if ($this->get['token'] == $user->getToken($res[0]))
			{
				$this->render('login', 'reset_pwd', array(
						'tr' => new tr(),
						'user' => $res[0],
						'app' => $this->get['app']
				));
			}
		}
		else
		{
			echo '<h3 class="text-error">' . tr::get('email_not_found') . '</h3>';
		}
	}
	
	
	
	public function addUser()
	{
		$post = $this->post;
		
		if (!$post['loginapp'] || !$post['name'] || !$post['email'] || !$post['password'] || !$post['password2'])
		{
			utils::response('all_fields_required', 'error');
			return;
		}
		else if ($post['password'] != $post['password2'])
		{
			utils::response('pass_empty_or_not_match', 'error');
			return;
		}
		else
		{
			try
			{
				$user = new User(new DB($post['loginapp']));
			
				$res = $user->insert($post['name'], $post['email'], $post['password']);
				
				if ($res)
				{
					// email to user
					$to = $post['email'];
					$subject = tr::get('new_user_email_subject');
					$message = tr::sget('new_user_email_text', $post['loginapp'])
							. tr::get('email_signature');
					$headers = 'From: ' . $post['loginapp'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $post['loginapp'] . '_db@bradypus.net' . "\r\n";
						
					@mail($to, $subject, $message, $headers);
					
					// email to admins
					
					$admins = $user->getUserByPriv('adm', '<=');
					
					foreach($admins as $adm)
					{
						$to = $adm['email'];
						$message = tr::sget('new_user_adm_email_text', array($post['loginapp'], $post['loginapp'], $post['name'], $post['email']))
							. tr::get('email_signature');
						
						@mail($to, $subject, $message, $headers);
					}
					
					echo json_encode(array('text'=>tr::sget('ok_user_add', $post['email']), 'status'=>'success'));
					return;
				}
				else
				{
					utils::response('error_user_add', 'error');
					return;
				}
			}
			catch(myException $e)
			{
				utils::response($e->getMessage(), 'error');
				return;
			}
			
		}
	}
	
	
	public function out()
	{
		try
		{
			$user = new User(new DB());
			$user->logout();
		}
		catch (myException $e)
		{
			User::forceLogOut();
		}
	}
	
	
	public function autolog()
	{
		if (cookieAuth::get())
		{
			utils::response('Authenticated');
			return;
		}
		
		try
		{
			if (file_exists(PROJS_DIR . $this->get['app'] . '/cfg/app_data.json'))
			{
				$app_data = json_decode(file_get_contents(PROJS_DIR . $this->get['app'] . '/cfg/app_data.json'), true);
				
				$user = new User(new DB($this->get['app']));
				
				$user->login(false, false, false, $app_data['auth_login_as_user']);
				
				utils::response('Authenticated');
			}
		}
		catch(myException $e)
		{
			utils::response($e->getMessage(), 'error');
		}
	}
	
	public function auth()
	{
		try
		{
			$user = new User(new DB($this->post['loginapp']));
			$user->login($this->post['email'], $this->post['password'], $this->post['remember']);
			$obj['status'] = 'ok';
		}
		catch (myException $e)
		{
			$obj['status'] = 'no';
			$obj['verbose'] = $e->getMessage();
		}
		
		echo json_encode($obj);
	}
	
	public function select_app()
	{
		try
		{
			$availables_DB = utils::dirContent(PROJS_DIR);
			
			if (!$availables_DB OR !is_array($availables_DB))
			{
				throw new myException(tr::get('no_app'));
				return;
			}
		
			asort($availables_DB);
		
			foreach ($availables_DB as $db)
			{
				$appl = json_decode(file_get_contents(PROJS_DIR . $db . '/cfg/app_data.json'), true);
		
				$data[] = array(
						'db' => $db,
						'definition' => $appl['definition'],
						'name' => strtoupper($appl['name'])
				);
			}
			
			$this->render('login', 'select_app', array(
					'data' => $data,
					'app' => $this->get['app'],
					'choose_db' => tr::get('choose_db'),
					'version' => version::current()
					));
		
		}
		catch (myException $e)
		{
			$e->log();
		}
	}
	
	public function changePwd()
	{
		$user = new User(new DB($this->post['app']));
		
		if ($user->update($this->post['id'], false, false, $this->post['pwd']))
		{
			utils::response('ok_password_update');
		}
		else
		{
			utils::response('error_password_update', true);
		}
		
	}
	
	
	public  function sendToken()
	{
		$user = new User(new DB($this->get['app']));
		
		$res = $user->getUser(array('email'=>$this->get['email']));
		
		if ($res[0])
		{
			$token = $user->getToken($res[0]);
			
			$to = $this->get['email'];
			$subject = tr::get('lost_password_email_subject');
			$message = tr::sget('lost_password_email_text', 'http://db3.bradypus.net/?loginapp=' . $this->get['app'] . '&address=' . $this->get['email'] . '&token=' . $token)
				. tr::get('email_signature');
			$headers = 'From: ' . $this->get['app'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $this->get['app'] . '@bradypus.net' . "\r\n";
			

			$resp = mail($to, $subject, $message, $headers);
			
			if ($resp)
			{
				utils::response('anything');
			}
			else
			{
				utils::response('error_sending_email', true);
			}
		}
		else
		{
			utils::response('email_not_found', true);
		}
	}
	
}