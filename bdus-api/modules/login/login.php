<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 15, 2012
 */

 use DB\System\Manage;

class login_ctrl extends Controller
{

	public function addUser()
	{
		$post = $this->post;
		
		// Check required fields
		if (!$post['app'] || !$post['name'] || !$post['email'] || !$post['password'] || !$post['password2']) {
			$this->response('all_fields_required', 'error');
			return false;
		}

		// Check matching passwords
		if ($post['password'] !== $post['password2']) {
			$this->response('pass_empty_or_not_match', 'error');
			return false;
		}

		// Check valid email
		if (filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
			$this->response( 'email_not_valid', 'error', [$post['email']]);
		}
		
		if (\utils::isDuplicateEmail($this->db, $post['email'])) {
			$this->response('email_present', 'error', [$post['email']]);
		}
		
		try {

			$sys_manager = new Manage($this->db);
			$res = $sys_manager->addRow('bdus_users', [
				'name', 
				'email' => $post['email'], 
				'password' => \utils::encodePwd($post['password']), 
				'privilege' => 40
			]);
			
			if ($res) {
				// email to user
				$to = $post['email'];
				$subject = 'New user registration';
				$message = "Your account for {$post['app']} has been created.\nThank you for registering.";
				$headers = 'From: ' . $post['app'] . '@bdus.cloud' . "\r\n" . 'Reply-To: ' . $post['app'] . '_db@bdus.cloud' . "\r\n";

				@mail($to, $subject, $message, $headers);

				// email to admins

				$admins = $sys_manager->getBySQL('bdus_users', 'privilege <= ?', [
					\utils::privilege('admin')
				]);

				foreach($admins as $adm) {
					$to = $adm['email'];
					$message = "A new user {$post['name']} ({$post['email']}) has registered on {$post['app']}.";

					@mail($to, $subject, $message, $headers);
				}
				$this->response( 'ok_user_add', 'success', [ $post['email'] ]);
				return true;
			} else {
				$this->response('error_user_add', 'error');
				return false;
			}
		} catch(\Throwable $e) {
			$this->response($e->getMessage(), 'error');
			return false;
		}
		
	}

	public function out(): void
	{
		// JWT logout is client-side (clear sessionStorage).
		// This endpoint exists solely for server-side logging.
		$user_id = \Auth\CurrentUser::id();
		if ($user_id) {
			$this->log->info("User {$user_id} logged out");
		}
		$this->response('ok', 'success');
	}

	/**
	 * Refresh the JWT for the currently authenticated user.
	 * constants.php has already validated the incoming token, so we just
	 * re-issue one with a fresh expiry.
	 */
	public function refresh(): void
	{
		if (!\Auth\CurrentUser::isAuthenticated()) {
			http_response_code(401);
			$this->response('unauthorized', 'error');
			return;
		}
		$token = \JWT\JwtManager::generate(\Auth\CurrentUser::get(), APP);
		$this->response('ok', 'success', null, ['token' => $token]);
	}

	public function auth(): void
	{
		try {
			$user = $this->authenticate($this->post['email'], $this->post['password']);
			\DB\System\Migrate::run($this->db, $this->log);
			$this->log->info("User {$user['id']} logged into " . APP);
			$token = \JWT\JwtManager::generate($user, APP);
			$this->response('ok', 'success', null, ['token' => $token]);
		} catch (\Exception $e) {
			$this->log->error($e);
			$this->response($e->getMessage(), 'error');
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('generic_error', 'error');
		}
	}

	/**
	 * Returns JSON list of available applications (no auth required).
	 * Used by the Vue frontend to populate the app selector on the login page.
	 *
	 * GET ?obj=login_ctrl&method=listApps
	 * Response: { apps: [ { db: string, name: string, definition: string }, ... ] }
	 */
	public function listApps(): void
	{
		$availables_DB = \utils::dirContent(MAIN_DIR . "projects");
		$data = [];

		if ($availables_DB && is_array($availables_DB)) {
			asort($availables_DB);

			foreach ($availables_DB as $db) {
				// Probe config in newest-first order to handle all migration states:
				//   post-M018 → projects/{app}/config.json        (project root)
				//   post-M016 → projects/{app}/cfg/config.json    (inside cfg/)
				//   pre-M016  → projects/{app}/cfg/app_data.json  (v4 legacy name)
				// Login runs before migrations, so every app must be found regardless
				// of which migrations have already been applied.
				// @todo Once all installations have run M016 + M018, keep only
				//       "$base/config.json" and remove the two legacy candidates.
				$base = MAIN_DIR . "projects/$db";
				$cfg  = null;
				foreach ([
					"$base/config.json",         // post-M018: file at project root
					"$base/cfg/config.json",     // post-M016, pre-M018: file in cfg/
					"$base/cfg/app_data.json",   // pre-M016: v4 legacy filename
				] as $candidate) {
					if (file_exists($candidate)) { $cfg = $candidate; break; }
				}
				if (!$cfg) {
					continue;
				}
				$appl = json_decode(file_get_contents($cfg), true);
				if (!is_array($appl)) {
					continue;
				}
				// Report which OAuth providers are configured for this app
				$oauthProviders = [];
				foreach (['google', 'orcid'] as $prov) {
					$creds = $appl['oauth'][$prov] ?? null;
					if (is_array($creds) && !empty($creds['client_id']) && !empty($creds['client_secret'])) {
						$oauthProviders[] = $prov;
					}
				}

				$data[] = [
					'db'         => $db,
					'name'       => strtoupper($appl['name'] ?? $db),
					'definition' => $appl['definition'] ?? '',
					'oauth'      => $oauthProviders,
				];
			}
		}

		$this->returnJson([ 'status' => 'success', 'apps' => $data]);
	}

	public function changePwd()
	{
		$id = (int) $this->post['id'];
		$password = \utils::encodePwd( $this->post['pwd'] );

		$sys_manager = new Manage($this->db);
		$res = $sys_manager->editRow('bdus_users', $id, ['password' => $password]);

		if ( $res ) {
			$this->response('ok_password_update', 'success');
		} else {
			$this->response('error_password_update', 'error');
		}

	}


	public  function sendToken()
	{
		$sys_manager = new Manage($this->db);
		$res = $sys_manager->getBySQL('bdus_users', 'email = ?', [$this->get['email']]);

		if ($res[0]) {
			$token = $this->getToken($this->db->getApp(), $res[0]);

			$to = $this->get['email'];
			$subject = 'Password reset request';
			$resetUrl = 'https://bdus.cloud/db/?app=' . $this->get['app'] . '&address=' . $this->get['email'] . '&token=' . $token;
			$message = "Click the following link to reset your password: {$resetUrl}";
			$headers = 'From: ' . $this->get['app'] . '@bdus.cloud' . "\r\n" . 'Reply-To: ' . $this->get['app'] . '@bdus.cloud' . "\r\n";


			$resp = mail($to, $subject, $message, $headers);

			if ($resp) {
				$this->response('anything', 'success');
			} else {
				$this->response('error_sending_email', 'error');
			}
		} else {
			$this->response('email_not_found', 'error');
		}
	}

	/**
	 * Authenticate a user by email + password.
	 * Returns a clean user array (no password, no settings) on success,
	 * or throws on failure.
	 */
	private function authenticate(string $email, string $password): array
    {
		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password)) {
			throw new \Exception('email_password_needed');
		}

		$sys_manager = new Manage($this->db);
		$rows = $sys_manager->getBySQL('bdus_users', 'email = ?', [$email]);
		$res  = $rows[0] ?? null;

		if (!$res || !\utils::verifyPassword($password, $res['password'])) {
			throw new \Exception('login_data_not_valid');
		}

		// Silently migrate legacy SHA1 hash to bcrypt on successful login
		if (strlen($res['password']) === 40) {
			$sys_manager->editRow('bdus_users', $res['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
		}

		unset($res['password'], $res['settings']);
		return $res;
	}
	
	private function getToken( string $app, array $user_data ) : string
	{
		unset($user_data['settings']);

		return substr(
			base64_encode(
				$app . implode('', $user_data)
			),  5,  10 );
	}

}
