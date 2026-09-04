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

	/**
	 * Public self-registration — gated on two independent things, both of
	 * which the admin must have explicitly opted into:
	 *   - Config\AppSettings.allow_self_registration for this app (M041)
	 *   - Mail\Mailer::isConfigured() at the instance level (RESEND_API_KEY)
	 * Either missing means the feature simply isn't offered, not a broken form.
	 */
	public function register(): void
	{
		if (!$this->db) {
			$this->returnJson(['status' => 'error', 'code' => 'app_not_found']);
			return;
		}

		$post     = $this->post;
		$app      = (string) ($post['app'] ?? '');
		$name     = trim((string) ($post['name'] ?? ''));
		$email    = trim((string) ($post['email'] ?? ''));
		$password = (string) ($post['password'] ?? '');

		if (!$app || !$name || !$email || !$password) {
			$this->returnJson(['status' => 'error', 'code' => 'all_fields_required']);
			return;
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$this->returnJson(['status' => 'error', 'code' => 'email_not_valid']);
			return;
		}
		if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
			$this->returnJson(['status' => 'error', 'code' => 'password_too_short']);
			return;
		}

		if (empty(\Config\AppSettings::get($this->db)['allow_self_registration'])) {
			$this->returnJson(['status' => 'error', 'code' => 'registration_not_available']);
			return;
		}
		if (!\Mail\Mailer::isConfigured()) {
			$this->returnJson(['status' => 'error', 'code' => 'email_not_configured']);
			return;
		}
		if (\Bdus\Utils::isDuplicateEmail($this->db, $email)) {
			$this->returnJson(['status' => 'error', 'code' => 'email_present']);
			return;
		}

		try {
			$sys_manager = new Manage($this->db);
			$sys_manager->addRow('bdus_users', [
				'name'      => $name,
				'email'     => $email,
				'password'  => \Auth\Password::hash($password),
				'privilege' => 40,
			]);

			$this->sendRegistrationEmails($sys_manager, $app, $name, $email);

			$this->returnJson(['status' => 'success', 'code' => 'ok_user_add']);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'error_user_add']);
		}
	}

	/**
	 * Best-effort: the account already exists at this point, so a mail
	 * failure here must not make registration look like it failed.
	 */
	private function sendRegistrationEmails(Manage $sys_manager, string $app, string $name, string $email): void
	{
		try {
			$appName = $this->cfg->get('main.name') ?: $app;
			$lang    = \Mail\Templates::langFromHeader($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);

			$user = \Mail\Templates::registrationConfirmation($lang, $appName);
			\Mail\Mailer::send($email, $user['subject'], $user['html']);

			$admins = $sys_manager->getBySQLSafe('bdus_users', 'privilege <= ?', [
				\Auth\Authorization::privilege('admin'),
			], self::MAY_BE_MISSING_COLUMNS);

			$notice = \Mail\Templates::registrationAdminNotice($lang, $appName, $name, $email);
			foreach ($admins as $adm) {
				if (!empty($adm['email'])) {
					\Mail\Mailer::send($adm['email'], $notice['subject'], $notice['html']);
				}
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
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
			// Expected auth outcomes (wrong credentials, missing input, unknown
			// app) are client errors, not server faults. Log a terse line and
			// never pass the exception object to the logger: its stack trace
			// captures authenticate()'s arguments, i.e. the plaintext password.
			$expected = ['app_not_found', 'email_password_needed', 'login_data_not_valid', 'account_locked'];
			if (in_array($e->getMessage(), $expected, true)) {
				$this->log->info(
					'Failed login for ' . ($this->post['email'] ?? '(no email)') . ': ' . $e->getMessage()
				);
			} else {
				$this->log->error($e);
			}
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

		// Instance-wide, not per-app: whether email is configured at all
		// (RESEND_API_KEY/MAIL_FROM_ADDRESS). Lets the frontend hide
		// "forgot password?"/"create account" outright for the common case
		// of a deploy that never set these — per-app self-registration
		// gating (bdus_cfg_app.allow_self_registration) still needs a real
		// attempt, since checking it here would mean a DB round-trip per app.
		$this->returnJson([
			'status'          => 'success',
			'apps'            => $data,
			'mail_configured' => \Mail\Mailer::isConfigured(),
		]);
	}

	// Reset tokens are single-use, random, hashed at rest (never the raw
	// token) and short-lived. TTL is also the resend cooldown window: a
	// still-valid token is never silently replaced/re-emailed within
	// RESET_TOKEN_COOLDOWN_SECONDS of being issued.
	private const RESET_TOKEN_TTL_SECONDS      = 3600;
	private const RESET_TOKEN_COOLDOWN_SECONDS = 60;
	private const MIN_PASSWORD_LENGTH          = 8;

	/** Columns that may not exist yet on this app — see Manage::getBySQLSafe(). */
	private const MAY_BE_MISSING_COLUMNS = [
		'failed_login_count', 'locked_until', 'reset_token_hash', 'reset_token_expires',
	];

	/**
	 * Requests a password-reset email. Always responds with the same generic
	 * success code regardless of whether the email matches an account, to
	 * avoid leaking which addresses are registered.
	 */
	public function requestPasswordReset(): void
	{
		if (!$this->db) {
			$this->returnJson(['status' => 'error', 'code' => 'app_not_found']);
			return;
		}

		$app   = (string) ($this->post['app'] ?? '');
		$email = trim((string) ($this->post['email'] ?? ''));

		if (!$app || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$this->returnJson(['status' => 'error', 'code' => 'email_password_needed']);
			return;
		}
		if (!\Mail\Mailer::isConfigured()) {
			$this->returnJson(['status' => 'error', 'code' => 'email_not_configured']);
			return;
		}

		$generic = ['status' => 'success', 'code' => 'password_reset_requested'];

		try {
			$sys_manager = new Manage($this->db);
			$rows = $sys_manager->getBySQLSafe('bdus_users', 'email = ?', [$email], self::MAY_BE_MISSING_COLUMNS);
			$user = $rows[0] ?? null;

			if ($user && array_key_exists('reset_token_hash', $user)) {
				$now              = time();
				$currentExpiry    = (int) ($user['reset_token_expires'] ?? 0);
				$issuedRecently   = $currentExpiry > $now + (self::RESET_TOKEN_TTL_SECONDS - self::RESET_TOKEN_COOLDOWN_SECONDS);

				if (!$issuedRecently) {
					$token = bin2hex(random_bytes(32));
					$sys_manager->editRow('bdus_users', $user['id'], [
						'reset_token_hash'    => hash('sha256', $token),
						'reset_token_expires' => $now + self::RESET_TOKEN_TTL_SECONDS,
					]);

					$appName  = $this->cfg->get('main.name') ?: $app;
					$lang     = \Mail\Templates::langFromHeader($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
					$resetUrl = $this->frontendOrigin() . "/{$app}/reset-password?" . http_build_query([
						'email' => $email, 'token' => $token,
					]);

					$mail = \Mail\Templates::passwordReset($lang, $appName, $resetUrl);
					\Mail\Mailer::send($email, $mail['subject'], $mail['html']);
				}
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			// Still fall through to the generic response below.
		}

		$this->returnJson($generic);
	}

	/**
	 * Consumes a reset token and sets the new password. A valid reset also
	 * lifts any anti-brute-force lock — it proves account ownership just as
	 * well as a correct password would.
	 */
	public function confirmPasswordReset(): void
	{
		if (!$this->db) {
			$this->returnJson(['status' => 'error', 'code' => 'app_not_found']);
			return;
		}

		$email    = trim((string) ($this->post['email'] ?? ''));
		$token    = (string) ($this->post['token'] ?? '');
		$password = (string) ($this->post['password'] ?? '');

		if (!$email || !$token) {
			$this->returnJson(['status' => 'error', 'code' => 'invalid_or_expired_reset_token']);
			return;
		}
		if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
			$this->returnJson(['status' => 'error', 'code' => 'password_too_short']);
			return;
		}

		try {
			$sys_manager = new Manage($this->db);
			$rows = $sys_manager->getBySQLSafe('bdus_users', 'email = ?', [$email], self::MAY_BE_MISSING_COLUMNS);
			$user = $rows[0] ?? null;

			$storedHash = $user['reset_token_hash'] ?? '';
			$expires    = (int) ($user['reset_token_expires'] ?? 0);

			if (!$user || $storedHash === '' || $expires < time() || !hash_equals($storedHash, hash('sha256', $token))) {
				$this->returnJson(['status' => 'error', 'code' => 'invalid_or_expired_reset_token']);
				return;
			}

			$data = [
				'password'            => \Auth\Password::hash($password),
				'reset_token_hash'    => '',
				'reset_token_expires' => 0,
			];
			if (array_key_exists('locked_until', $user)) {
				$data['failed_login_count'] = 0;
				$data['locked_until']       = 0;
			}
			$sys_manager->editRow('bdus_users', $user['id'], $data);

			$this->returnJson(['status' => 'success', 'code' => 'ok_password_update']);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'error_password_update']);
		}
	}

	/**
	 * Best-effort origin of the SPA making this request, used to build
	 * absolute links in emails. Prefers the Origin header (set by the
	 * browser on the fetch() that hit this endpoint — exactly where the
	 * SPA is being served from); falls back to reconstructing from
	 * X-Forwarded-Proto/Host the same way OAuth::externalScheme() does,
	 * for the rare client that omits Origin on a same-origin POST.
	 */
	private function frontendOrigin(): string
	{
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ($origin !== '') {
			return rtrim($origin, '/');
		}

		$fwd    = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
		$scheme = in_array($fwd, ['http', 'https'], true)
			? $fwd
			: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

		return "{$scheme}://{$host}";
	}

	/**
	 * Authenticate a user by email + password.
	 * Returns a clean user array (no password, no settings) on success,
	 * or throws on failure.
	 */
	// Anti-brute-force: lock an account out after this many consecutive
	// failed attempts, for this long. Per-email, not per-IP — the real
	// client IP can't be trusted without a reverse proxy in front (the
	// installs this protects are exactly the ones without one), and
	// locking the targeted account is what actually stops a brute-force.
	private const MAX_FAILED_LOGIN_ATTEMPTS = 5;
	private const LOCKOUT_SECONDS           = 15 * 60;

	private function authenticate(string $email, string $password): array
    {
		if (!$this->db) {
			throw new \Exception('app_not_found');
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password)) {
			throw new \Exception('email_password_needed');
		}

		$sys_manager = new Manage($this->db);

		// M039/M040 columns may not have run yet on this app — migrations
		// apply only after a successful login, so this SELECT must tolerate
		// them not existing yet, or every login breaks with a SQL error
		// before an admin ever gets the chance to apply the pending upgrade.
		// Throttling simply stays off until then.
		$rows = $sys_manager->getBySQLSafe('bdus_users', 'email = ?', [$email], self::MAY_BE_MISSING_COLUMNS);
		$res  = $rows[0] ?? null;
		$throttlingReady = $res !== null && array_key_exists('locked_until', $res);

		if ($res && $throttlingReady) {
			$res = $this->clearExpiredLock($sys_manager, $res);
			if ((int)($res['locked_until'] ?? 0) > time()) {
				throw new \Exception('account_locked');
			}
		}

		if (!$res || !\Auth\Password::verify($password, $res['password'])) {
			if ($res && $throttlingReady) {
				$this->registerFailedLogin($sys_manager, $res);
			}
			throw new \Exception('login_data_not_valid');
		}

		if ($throttlingReady && (int)($res['failed_login_count'] ?? 0) !== 0) {
			$sys_manager->editRow('bdus_users', $res['id'], ['failed_login_count' => 0, 'locked_until' => 0]);
		}

		// Silently migrate legacy SHA1 hash to bcrypt on successful login
		if (strlen($res['password']) === 40) {
			$sys_manager->editRow('bdus_users', $res['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
		}

		unset($res['password'], $res['settings']);
		return $res;
	}

	// A lock that has already expired is cleared eagerly (DB + in-memory
	// row) so the caller always evaluates the current attempt against a
	// fresh window, instead of instantly re-locking on the first post-
	// expiry failure.
	//
	// Note: cleared to 0, not null — Manage::editRow() uses isset() to
	// decide which columns to write, which silently drops null values.
	private function clearExpiredLock(Manage $sys_manager, array $res): array
	{
		$lockedUntil = (int)($res['locked_until'] ?? 0);
		if ($lockedUntil > 0 && $lockedUntil <= time()) {
			$sys_manager->editRow('bdus_users', $res['id'], ['failed_login_count' => 0, 'locked_until' => 0]);
			$res['failed_login_count'] = 0;
			$res['locked_until']       = 0;
		}
		return $res;
	}

	private function registerFailedLogin(Manage $sys_manager, array $res): void
	{
		$count = (int)($res['failed_login_count'] ?? 0) + 1;
		$data  = ['failed_login_count' => $count];
		if ($count >= self::MAX_FAILED_LOGIN_ATTEMPTS) {
			$data['locked_until'] = time() + self::LOCKOUT_SECONDS;
		}
		$sys_manager->editRow('bdus_users', $res['id'], $data);
	}

}
