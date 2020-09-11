<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 15, 2012
 */

 use DB\System\Manage;

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
		$sys_manager = new Manage($this->db, $this->prefix);

		$res = $sys_manager->getBySQL('users', 'email = ?', [ $this->get['address'] ] ); 
		if ($res[0]) {
			if ($this->get['token'] == $this->getToken($this->db->getApp(), $res[0])) {
				$this->render('login', 'reset_pwd', array(
						'user' => $res[0],
						'app' => $this->request['app']
				));
			}
		} else {
			echo '<h3 class="text-error">' . \tr::get('email_not_found') . '</h3>';
		}
	}


	public function addUser()
	{
		$post = $this->post;
		
		// Check required fields
		if (!$post['app'] || !$post['name'] || !$post['email'] || !$post['password'] || !$post['password2']) {
			\utils::response('all_fields_required', 'error');
			return false;
		}

		// Check matching passwords
		if ($post['password'] !== $post['password2']) {
			\utils::response('pass_empty_or_not_match', 'error');
			return false;
		}

		// Check valid email
		if (filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
			\utils::response( \tr::get('email_not_valid', [$post['email']]), 'error', true);
		}
		
		if (\utils::isDuplicateEmail($this->db, $this->prefix, $post['email'])) {
			\utils::response( \tr::get('email_present', [$post['email']]), 'error', true);
        }
		try {

			
			$sys_manager = new Manage($this->db, $this->prefix);
			$res = $sys_manager->addRow('users', [
				'name', 
				'email' => $post['email'], 
				'password' => \utils::encodePwd($post['password']), 
				'privilege' => 40
			]);
			
			if ($res) {
				// email to user
				$to = $post['email'];
				$subject = \tr::get('new_user_email_subject');
				$message = \tr::get('new_user_email_text', [$post['app']])
						. "\n" . \tr::get('email_signature');
				$headers = 'From: ' . $post['app'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $post['app'] . '_db@bradypus.net' . "\r\n";

				@mail($to, $subject, $message, $headers);

				// email to admins

				$admins = $sys_manager->getBySQL('users', 'privilege <= ?', [
					\utils::privilege('adm')
				]);

				foreach($admins as $adm) {
					$to = $adm['email'];
					$message = \tr::get('new_user_adm_email_text', [ $post['app'], $post['app'], $post['name'], $post['email'] ])
						. "\n" . \tr::get('email_signature');

					@mail($to, $subject, $message, $headers);
				}
				\utils::response( 
					\tr::get('ok_user_add', [ $post['email'] ]), 
					'success'
				);
				return true;
			} else {
				\utils::response('error_user_add', 'error');
				return false;
			}
		} catch(\Throwable $e) {
			\utils::response($e->getMessage(), 'error');
			return false;
		}
		
	}

	public function out()
	{
		try {
			$user_id = $_SESSION['user']['id'];
			\pref::save2DB($this->db);
			\utils::emptyDir(PROJ_TMP_DIR);
			$this->deleteOldSessions();
			\cookieAuth::destroy();
			$_SESSION = [];
			session_destroy();
			$this->log->info("User {$user_id} logged out");
		} catch (\Throwable $th) {
			$this->log->error($th);
		}
		
	}


	public function autolog()
	{
		if (\cookieAuth::get()) {
			\utils::response('Authenticated');
			return;
		}

		try {
			if (file_exists(MAIN_DIR . "projects/{$this->get['app']}/cfg/app_data.json")) {

				$app_data = json_decode(file_get_contents(MAIN_DIR . "projects/{$this->get['app']}/cfg/app_data.json"), true);

				$auth_login_as_user = null;

                if (isset($app_data['auth_login_as_user'])) {
                    $auth_login_as_user = (int)$app_data['auth_login_as_user'];
                }
				$this->login(null, null, null, $auth_login_as_user);
				$this->log->info("User {$_SESSION['user']['id']} logged in");

				\utils::response('Authenticated');
			}
		} catch(\Throwable $e) {
			\utils::response($e->getMessage(), 'error');
		}
	}

	public function auth()
	{
		try {
			$this->login($this->post['email'], $this->post['password'], $this->post['remember']);
			$this->log->info("User {$_SESSION['user']['id']} logged in");
			\utils::response('Go ahead', 'success');
			$obj['status'] = 'success';
		} catch (\Exception $e) {
			$this->log->error($e);
			\utils::response($e->getMessage(), 'error');
		} catch (\Throwable $e) {
			$this->log->error($e);
			\utils::response(\tr::get('generic_error'), 'error');
		}
	}

	public function select_app()
	{
		try {
			$availables_DB = \utils::dirContent(MAIN_DIR . "projects");
			
			$data = [];

			if ($availables_DB && is_array($availables_DB)) {
				asort($availables_DB);

				foreach ($availables_DB as $db) {
					$appl = json_decode(file_get_contents(MAIN_DIR . "projects/$db/cfg/app_data.json"), true);

					$data[] = array(
							'db' => $db,
							'definition' => $appl['definition'],
							'name' => strtoupper($appl['name'])
					);
				}
			}

			$this->render('login', 'select_app', [
				'data' => $data,
				'app' => $this->get['app'],
				'choose_db' => \tr::get('choose_db'),
				'version' => version::current(),
				'create_app' => file_exists('./UNSAFE_permit_app_creation') || !$availables_DB
			]);

		} catch (\Exception $e) {
			$this->log->error($e);
		}
	}

	public function changePwd()
	{
		$id = (int) $this->post['id'];
		$password = \utils::encodePwd( $this->post['pwd'] );

		$sys_manager = new Manage($this->db, $this->prefix);
		$res = $sys_manager->editRow('users', $id, ['password' => $password]);

		if ( $res ) {
			\utils::response('ok_password_update');
		} else {
			\utils::response('error_password_update', true);
		}

	}


	public  function sendToken()
	{
		$sys_manager = new Manage($this->db, $this->prefix);
		$res = $sys_manager->getBySQL('users', 'email = ?', [$this->get['email']]);

		if ($res[0]) {
			$token = $this->getToken($this->db->getApp(), $res[0]);

			$to = $this->get['email'];
			$subject = \tr::get('lost_password_email_subject');
			$message = \tr::get('lost_password_email_text', [ 'https://db.bradypus.net/?app=' . $this->get['app'] . '&address=' . $this->get['email'] . '&token=' . $token ])
				. "\n" . \tr::get('email_signature');
			$headers = 'From: ' . $this->get['app'] . '@bradypus.net' . "\r\n" . 'Reply-To: ' . $this->get['app'] . '@bradypus.net' . "\r\n";


			$resp = mail($to, $subject, $message, $headers);

			if ($resp) {
				\utils::response('anything');
			} else {
				\utils::response('error_sending_email', true);
			}
		} else {
			\utils::response('email_not_found', true);
		}
	}

	private function deleteOldSessions()
    {
        $maxlife = 24*60*60; // 24h
        $sessions = \utils::dirContent(MAIN_DIR . 'sessions');

        if ($sessions && is_array($sessions)) {
            foreach ($sessions as $s) {
                $filetime = filectime(MAIN_DIR . 'sessions/' . $s);

                if ($filetime && $filetime < time() - $maxlife) {
                    @unlink(MAIN_DIR . 'sessions/' . $s);
                }
            }
        }
	}
	
	private function login(string $email = null, string $password = null, string $remember = null, int $user_id = null): bool
    {
        if (!$email && !$password && $remember) {
            if (\cookieAuth::get()) {
                return true;
            }
		}
		
		$sys_manager = new Manage($this->db, $this->prefix);

        if ($user_id) {
			$res = $sys_manager->getById('users', $user_id);
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password)) {
			// get user data
			$res = $sys_manager->getBySQL(
				'users', 
				'email = ? AND password = ?', 
				[ $email, \utils::encodePwd($password)]);
			$res = $res[0];
        } else {
            throw new \Exception(\tr::get('email_password_needed'));
        }

        if ($res) {
            // remove password from array
            unset($res['password']);

            // Set user preferences
            if ( isset($res['settings']) ) {
                $sett_arr = unserialize($res['settings']);
                if (is_array($sett_arr) && !empty($sett_arr)){
                    foreach ($sett_arr as $key => $value) {
                        \pref::set($key, $value);
                    }
                }
            }

            // remove settings from array
            unset($res['settings']);

            // assign user data to session variable
            $_SESSION['user'] = $res;

            if ($remember && $remember !== 'false') {
                \cookieAuth::set();
            }
            return true;
        } else {
            throw new \Exception(\tr::get('login_data_not_valid'));
        }
	} // end of login
	
	private function getToken( string $app, array $user_data ) : string
	{
		unset($user_data['settings']);

		return substr(
			base64_encode(
				$app . implode('', $user_data)
			),  5,  10 );
	}

}
