<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 15, 2012
 */

 use DB\System\Manage;

class Login extends \Bdus\Controller
{

	public function addUser()
	{
		$post = $this->post;
		
		// Check required fields
		if (!$post['app'] || !$post['name'] || !$post['email'] || !$post['password'] || !$post['password2']) {
			$this->returnJson(['status' => 'error', 'code' => 'all_fields_required']);
			return false;
		}

		// Check matching passwords
		if ($post['password'] !== $post['password2']) {
			$this->returnJson(['status' => 'error', 'code' => 'pass_empty_or_not_match']);
			return false;
		}

		// Check valid email
		if (filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
			$this->returnJson(['status' => 'error', 'code' => 'email_not_valid']);
		}
		
		if (\Bdus\Utils::isDuplicateEmail($this->db, $post['email'])) {
			$this->returnJson(['status' => 'error', 'code' => 'email_present']);
		}
		
		try {

			$sys_manager = new Manage($this->db);
			$res = $sys_manager->addRow('bdus_users', [
				'name', 
				'email' => $post['email'], 
				'password' => \Auth\Password::hash($post['password']), 
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
					\Auth\Authorization::privilege('admin')
				]);

				foreach($admins as $adm) {
					$to = $adm['email'];
					$message = "A new user {$post['name']} ({$post['email']}) has registered on {$post['app']}.";

					@mail($to, $subject, $message, $headers);
				}
				$this->returnJson(['status' => 'success', 'code' => 'ok_user_add']);
				return true;
			} else {
				$this->returnJson(['status' => 'error', 'code' => 'error_user_add']);
				return false;
			}
		} catch(\Throwable $e) {
			$this->returnJson(['status' => 'error', 'code' => $e->getMessage()]);
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
		$this->returnJson(['status' => 'success', 'code' => 'ok']);
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
			$this->returnJson(['status' => 'error', 'code' => 'unauthorized']);
			return;
		}
		$token = \JWT\JwtManager::generate(\Auth\CurrentUser::get(), APP);
		$this->returnJson(['status' => 'success', 'code' => 'ok', 'token' => $token]);
	}

	public function auth(): void
	{
		try {
			$user = $this->authenticate($this->post['email'], $this->post['password']);

			$pending = \DB\System\Migrate::listPending($this->db);

			if (!empty($pending)) {
				$isAdmin = (int)($user['privilege'] ?? 99) <= 10;
				if ($isAdmin) {
					// Admin: issue token and signal that a minor upgrade is pending.
					// Migrations run only via POST /api/upgrade/minor (single migration point).
					$token = \JWT\JwtManager::generate($user, APP);
					$this->log->info("User {$user['id']} logged into " . APP . " (minor upgrade pending)");
					$this->returnJson([
						'status'  => 'success',
						'code'    => 'ok',
						'token'   => $token,
						'upgrade' => ['type' => 'minor', 'pending' => $pending],
					]);
				} else {
					// Non-admin: cannot enter the app until an admin runs the upgrade.
					$this->returnJson(['status' => 'error', 'code' => 'upgrade_pending']);
				}
				return;
			}

			// No pending migrations: stamp the current version so listApps() badge
			// stays accurate even when there is nothing to migrate.
			// Non-fatal: a failure here must not block the login.
			try { \DB\System\Migrate::run($this->db, $this->log); } catch (\Throwable $ignored) {}
			$this->log->info("User {$user['id']} logged into " . APP);
			$token = \JWT\JwtManager::generate($user, APP);
			$this->returnJson(['status' => 'success', 'code' => 'ok', 'token' => $token]);
		} catch (\Exception $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => $e->getMessage()]);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'generic_error']);
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
		$availables_DB = \Bdus\Utils::dirContent(MAIN_DIR . "projects");
		$data = [];

		$currentVersion = \DB\System\Migrate::readCurrentVersion();

		if ($availables_DB && is_array($availables_DB)) {
			asort($availables_DB);

			foreach ($availables_DB as $db) {
				// Probe config in newest-first order to handle all migration states:
				//   post-M018 → projects/{app}/config.json        (project root)
				//   post-M016 → projects/{app}/cfg/config.json    (inside cfg/)
				//   pre-M016  → projects/{app}/cfg/app_data.json  (v4 legacy name)
				$base = MAIN_DIR . "projects/$db";
				$cfg  = null;
				foreach ([
					"$base/config.json",
					"$base/cfg/config.json",
					"$base/cfg/app_data.json",
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

				// ── Upgrade status (no DB query) ────────────────────────────────
				// 'bdus_version' absent in config → v4 app → major upgrade required.
				// Same major but lower minor/patch → minor migrations pending.
				$upgrade = null;
				if ($currentVersion !== null) {
					$storedVersion = $appl['bdus_version'] ?? null;
					if ($storedVersion === null) {
						$upgrade = 'major';
					} else {
						$storedMajor   = (int) explode('.', (string) $storedVersion)[0];
						$currentMajor  = (int) explode('.', $currentVersion)[0];
						if ($storedMajor < $currentMajor) {
							$upgrade = 'major';
						} elseif (version_compare((string) $storedVersion, $currentVersion, '<')) {
							$upgrade = 'minor';
						}
					}
				}

				// ── OAuth providers ─────────────────────────────────────────────
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
					'upgrade'    => $upgrade,
					'oauth'      => $oauthProviders,
				];
			}
		}

		$this->returnJson([ 'status' => 'success', 'apps' => $data]);
	}

	public function changePwd()
	{
		$id = (int) $this->post['id'];
		$password = \Auth\Password::hash( $this->post['pwd'] );

		$sys_manager = new Manage($this->db);
		$res = $sys_manager->editRow('bdus_users', $id, ['password' => $password]);

		if ( $res ) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_password_update']);
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'error_password_update']);
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
				$this->returnJson(['status' => 'success', 'code' => 'anything']);
			} else {
				$this->returnJson(['status' => 'error', 'code' => 'error_sending_email']);
			}
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'email_not_found']);
		}
	}

	/**
	 * Authenticate a user by email + password.
	 * Returns a clean user array (no password, no settings) on success,
	 * or throws on failure.
	 */
	private function authenticate(string $email, string $password): array
    {
		if (!$this->db) {
			throw new \Exception('app_not_found');
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password)) {
			throw new \Exception('email_password_needed');
		}

		$sys_manager = new Manage($this->db);
		$rows = $sys_manager->getBySQL('bdus_users', 'email = ?', [$email]);
		$res  = $rows[0] ?? null;

		if (!$res || !\Auth\Password::verify($password, $res['password'])) {
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
