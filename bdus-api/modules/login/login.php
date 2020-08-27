<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 15, 2012
 */

class login_ctrl extends Controller
{

	public function loginForm()
	{
		$app_data = json_decode(file_get_contents(MAIN_DIR . "projects/{$this->request['app']}/cfg/app_data.json"), true);

		$this->render('login', 'login', [
			'appdata' => $app_data,
		]);
	}



	public function newUserForm()
	{
		$this->render('login', 'new_user', [
			'app' => $this->get['app'],
		]);
	}

	public function resetPwd()
	{
		$user = new User($this->db);

		$res = $user->getUser(array('email'=>$this->get['address']));

		if ($res[0]) {
			if ($this->get['token'] == $user->getToken($res[0])) {
				$this->render('login', 'reset_pwd', array(
						'user' => $res[0],
						'app' => $this->request['app']
				));
			}
		} else {
			echo '<h3 class="text-error">' . tr::get('email_not_found') . '</h3>';
		}
	}



	public function addUser()
	{
		$post = $this->post;

		if (!$post['app'] || !$post['name'] || !$post['email'] || !$post['password'] || !$post['password2']) {
			utils::response('all_fields_required', 'error');
			return;
		} else if ($post['password'] != $post['password2']) {
			utils::response('pass_empty_or_not_match', 'error');
			return;
		} else {
			try {
				$user = new User($this->db);

				$res = $user->insert($post['name'], $post['email'], $post['password']);

				if ($res) {
					// email to user
					$to = $post['email'];
					$subject = tr::get('new_user_email_subject');
					$message = tr::get('new_user_email_text', [$post['app']])
							. "\n" . tr::get('email_signature');
					$headers = 'From: ' . $post['app'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $post['app'] . '_db@bradypus.net' . "\r\n";

					@mail($to, $subject, $message, $headers);

					// email to admins

					$admins = $user->getUserByPriv('adm', '<=');

					foreach($admins as $adm)
					{
						$to = $adm['email'];
						$message = tr::get('new_user_adm_email_text', [ $post['app'], $post['app'], $post['name'], $post['email'] ])
							. "\n" . tr::get('email_signature');

						@mail($to, $subject, $message, $headers);
					}

					echo json_encode(array('text'=>tr::get('ok_user_add', [ $post['email'] ]), 'status'=>'success'));
					return;
				} else {
					utils::response('error_user_add', 'error');
					return;
				}
			} catch(myException $e) {
				utils::response($e->getMessage(), 'error');
				return;
			}

		}
	}


	public function out()
	{
		$user_id = $_SESSION['user']['id'];
		$user = new User($this->db);
		$user->logout();
		$this->log->info("User {$user_id} logged out");
	}


	public function autolog()
	{
		if (cookieAuth::get()) {
			utils::response('Authenticated');
			return;
		}

		try {
			if (file_exists(MAIN_DIR . "projects/{$this->get['app']}/cfg/app_data.json")) {

				$app_data = json_decode(file_get_contents(MAIN_DIR . "projects/{$this->get['app']}/cfg/app_data.json"), true);

				$user = new User($this->db);

				$user->login(null, null, null, $app_data['auth_login_as_user']);
				$this->log->info("User {$_SESSION['user']['id']} logged in");

				utils::response('Authenticated');
			}
		} catch(myException $e) {
			utils::response($e->getMessage(), 'error');
		}
	}

	public function auth()
	{
		try {
			$user = new User($this->db);
			$user->login($this->post['email'], $this->post['password'], $this->post['remember']);
			$this->log->info("User {$_SESSION['user']['id']} logged in");
			$obj['status'] = 'ok';
		} catch (myException $e) {
			$obj['status'] = 'no';
			$obj['verbose'] = $e->getMessage();
		}

		echo json_encode($obj);
	}

	public function select_app()
	{
		try {
			$availables_DB = utils::dirContent(MAIN_DIR . "projects");

			if (!$availables_DB OR !is_array($availables_DB)) {
				throw new myException(tr::get('no_app'));
				return;
			}

			asort($availables_DB);

			foreach ($availables_DB as $db) {
				$appl = json_decode(file_get_contents(MAIN_DIR . "projects/$db/cfg/app_data.json"), true);

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

		} catch (myException $e) {
			$this->log->error($e);
		}
	}

	public function changePwd()
	{
		$user = new User($this->db);

		if ($user->update($this->post['id'], false, false, $this->post['pwd'])) {
			utils::response('ok_password_update');
		} else {
			utils::response('error_password_update', true);
		}

	}


	public  function sendToken()
	{
		$user = new User($this->db);

		$res = $user->getUser(array('email'=>$this->get['email']));

		if ($res[0]) {
			$token = $user->getToken($res[0]);

			$to = $this->get['email'];
			$subject = tr::get('lost_password_email_subject');
			$message = tr::get('lost_password_email_text', [ 'https://db.bradypus.net/?app=' . $this->get['app'] . '&address=' . $this->get['email'] . '&token=' . $token ])
				. "\n" . tr::get('email_signature');
			$headers = 'From: ' . $this->get['app'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $this->get['app'] . '@bradypus.net' . "\r\n";


			$resp = mail($to, $subject, $message, $headers);

			if ($resp) {
				utils::response('anything');
			} else {
				utils::response('error_sending_email', true);
			}
		} else {
			utils::response('email_not_found', true);
		}
	}

}
