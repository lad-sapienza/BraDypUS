<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 10, 2012
 */

use \DB\System\Manage;

class User extends \Bdus\Controller
{
	/**
	 * Returns user list.
	 *
	 * v5 JSON shape:
	 * {
	 *   "admin":     bool,
	 *   "can_write": bool,
	 *   "users": [
	 *     {
	 *       "id":              int,
	 *       "name":            string,
	 *       "email":           string,
	 *       "privilege":       string,   // translated label
	 *       "privilege_value": int,      // raw numeric level
	 *       "editable":        bool,
	 *       "groups":          int[]     // ids of the groups the user belongs to
	 *     }
	 *   ]
	 * }
	 */
	public function showList()
	{
		$data = [
			'admin'     => \Auth\Authorization::can('admin'),
			'can_write' => \Auth\Authorization::can('add_new'),
		];

		if (\Auth\Authorization::can('admin')) {
			$sys_manager = new Manage($this->db);
			// getBySQLSafe: tolerates M039/M040 columns not existing yet on this
			// app (migrations apply only after a successful login) — see
			// Manage::getBySQLSafe() and Login::authenticate().
			$all_users   = $sys_manager->getBySQLSafe('bdus_users', '1=1', [], [
				'failed_login_count', 'locked_until', 'reset_token_hash', 'reset_token_expires',
			]);

			// Group memberships, one query for the whole list (none before M046).
			$groupsOf = [];
			try {
				foreach ($this->db->query('SELECT user_id, group_id FROM bdus_user_groups ORDER BY group_id', [], 'read') ?: [] as $m) {
					$groupsOf[(int) $m['user_id']][] = (int) $m['group_id'];
				}
			} catch (\Throwable $e) {
				// table missing: migration pending
			}

			foreach ($all_users as $user) {
				$data['users'][] = [
					'id'              => $user['id'],
					'name'            => $user['name'],
					'email'           => $user['email'],
					'privilege'       => \Auth\Authorization::privilege($user['privilege'], true),
					'privilege_value' => (int) $user['privilege'],
					'editable'        => (\Auth\Authorization::can('admin') && $user['privilege'] >= \Auth\CurrentUser::privilege()),
					'groups'          => $groupsOf[(int) $user['id']] ?? [],
					'oauth_provider'  => $user['oauth_provider'] ?? null,
				];
			}
		} else {
			$data['users'][] = [
				'id'              => \Auth\CurrentUser::id(),
				'name'            => \Auth\CurrentUser::get('name'),
				'email'           => \Auth\CurrentUser::get('email'),
				'privilege'       => \Auth\Authorization::privilege(\Auth\CurrentUser::privilege(), true),
				'privilege_value' => \Auth\CurrentUser::privilege(),
				'editable'        => true,
				'groups'          => [],
			];
		}
		$data["status"] = "success";
		$this->returnJson($data);
	}

	/**
	 * Is the current user allowed to act on user $id's account (delete it, end its
	 * session)? Admins only, and never on someone more privileged than themselves.
	 * An id that does not exist passes: the caller reports it as it always did.
	 */
	private function mayManageUser(int $id): bool
	{
		if (!\Auth\Authorization::can('admin')) {
			return false;
		}
		$target = $this->db->query('SELECT privilege FROM bdus_users WHERE id = ?', [$id], 'read')[0] ?? null;
		return !$target || (int) $target['privilege'] >= (int) \Auth\CurrentUser::privilege();
	}

	/**
	 * Deletes a user (admins only, not users more privileged than the caller).
	 *
	 * DELETE /api/user/{id}
	 */
	public function deleteOne($id = false)
	{
		if (!$id) {
			$id = $this->get['id'];
		}
		$id = (int) $id;

		if (!$this->mayManageUser($id)) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		try {
			$sys_manager = new Manage($this->db);

			$ret = $sys_manager->deleteRow('bdus_users', $id);

			if ($ret) {
				$this->returnJson(['status' => 'success', 'code' => 'user_deleted']);
			} else {
				throw new \Exception('User deletion query returned false');
			}
		} catch (\Throwable $e) {
			$this->returnJson(['status' => 'error', 'code' => 'user_not_deleted']);
			$this->log->error($e);
		}
	}

	/**
	 * Returns data for a single user form (new or existing).
	 *
	 * v5 JSON shape:
	 * {
	 *   "id":         int|null,
	 *   "name":       string,
	 *   "email":      string,
	 *   "avatar":     string,
	 *   "privileges": [ { "value": int, "label": string, "selected": bool } ]
	 * }
	 *
	 * GET ?obj=user_ctrl&method=showUserForm[&id=ID]
	 */
	public function showUserForm()
	{
		$id = $this->get['id'] ?? null;

		if ($id && $id != \Auth\CurrentUser::id() && !\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		if ($id) {
			$sys_manager = new Manage($this->db);
			$one_user    = $sys_manager->getById('bdus_users', (int) $id);
		} else {
			$one_user = [];
		}

		$data = [
			'id'        => $one_user['id']        ?? null,
			'name'      => $one_user['name']       ?? '',
			'email'     => $one_user['email']      ?? '',
			'privilege' => $one_user['privilege']  ?? null,
			'avatar'    => md5(strtolower(trim($one_user['email'] ?? ''))),
		];

		foreach (\Auth\Authorization::privilege('all', true) as $k => $str) {
			if ($k >= \Auth\CurrentUser::privilege()) {
				$data['privileges'][] = [
					'value'    => $k,
					'label'    => $str,
					'selected' => ($k === ($one_user['privilege'] ?? null)),
				];
			}
		}

		$data["status"] = "success";
		$this->returnJson($data);
	}

	/**
	 * Saves user basic data (name, email, password, privilege).
	 *
	 * POST ?obj=user_ctrl&method=saveUserData
	 *
	 * Access rules:
	 *  - Only admins (privilege <= 10) can create users or edit other users.
	 *  - Non-admins may update their own name/email/password but cannot
	 *    change their privilege level.
	 *  - A non-admin changing their own password must send `current_password`.
	 *  - A password change bumps token_version (old tokens stop working); when
	 *    the user changed their own, a fresh `token` is returned.
	 *  - Nobody can set a user's privilege to a value lower (= more powerful)
	 *    than their own, preventing admins from escalating to super_admin.
	 */
	public function saveUserData()
	{
		$data      = $this->post;
		$isAdmin   = \Auth\Authorization::can('admin');
		$isNewUser = empty($data['id']);
		$isOwnUser = !$isNewUser && (int)$data['id'] === \Auth\CurrentUser::id();

		// Only admins can create new users or edit other users.
		if (!$isAdmin && ($isNewUser || !$isOwnUser)) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		// Privilege changes are admin-only; additionally nobody can set a
		// privilege numerically lower (= more powerful) than their own.
		if (array_key_exists('privilege', $data)) {
			if (!$isAdmin || (int)$data['privilege'] < \Auth\CurrentUser::privilege()) {
				$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
				return;
			}
		}

		// Never a column: consumed here only.
		$currentPassword = (string) ($data['current_password'] ?? '');
		unset($data['current_password']);

		$passwordChanged = !$isNewUser && !empty($data['password']);

		// A user changing their own password must prove they know the current
		// one (a stolen token alone is not enough). Admins are exempt.
		if ($passwordChanged && $isOwnUser && !$isAdmin) {
			if ($currentPassword === '') {
				$this->returnJson(['status' => 'error', 'code' => 'current_password_required']);
				return;
			}
			$rows = $this->db->query(
				'SELECT password FROM bdus_users WHERE id = ?',
				[\Auth\CurrentUser::id()],
				'read'
			);
			if (!$rows || !\Auth\Password::verify($currentPassword, (string) ($rows[0]['password'] ?? ''))) {
				$this->returnJson(['status' => 'error', 'code' => 'current_password_wrong']);
				return;
			}
		}

		try {
			$sys_manager = new Manage($this->db);

			foreach ($data as $key => &$value) {
				if ($key === 'password') {
					if ($value && $value !== '') {
						if (strlen((string) $value) < Login::MIN_PASSWORD_LENGTH) {
							$this->returnJson(['status' => 'error', 'code' => 'password_too_short']);
							return;
						}
						$value = password_hash($value, PASSWORD_DEFAULT);
					} else {
						unset($data[$key]);
					}
				}
			}

			if (!$isNewUser) {
				// Edit existing user
				if (\Bdus\Utils::isDuplicateEmail($this->db, $data['email'], $data['id'])) {
					$this->returnJson(['status' => 'error', 'code' => 'email_present']);
					return;
				}
				$ret = $sys_manager->editRow('bdus_users', (int) $data['id'], $data);

				// Privilege change (on another user) or password change → invalidate
				// the user's already-issued tokens immediately.
				if ($ret && ((array_key_exists('privilege', $data) && !$isOwnUser) || $passwordChanged)) {
					$this->db->query(
						'UPDATE bdus_users SET token_version = token_version + 1 WHERE id = ?',
						[(int) $data['id']],
						'boolean'
					);
				}
			} else {
				if (\Bdus\Utils::isDuplicateEmail($this->db, $data['email'])) {
					$this->returnJson(['status' => 'error', 'code' => 'email_present']);
					return;
				}
				$ret = $sys_manager->addRow('bdus_users', $data);
			}

			if ($ret) {
				// For new users, include the generated ID so callers can reference it.
				$extra = $isNewUser ? ['id' => $ret] : [];

				// The caller just invalidated their own tokens by changing their
				// password: hand them a fresh one so they stay logged in.
				if ($passwordChanged && $isOwnUser) {
					$rows = $this->db->query('SELECT * FROM bdus_users WHERE id = ?', [(int) $data['id']], 'read');
					$extra['token'] = \JWT\JwtManager::generate($rows[0], APP);
				}
				$this->returnJson(['status' => 'success', 'code' => 'user_data_saved', ...$extra]);
			} else {
				throw new \Exception('Query returned false');
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'user_data_not_saved']);
		}
	}

	/**
	 * Revokes the active session of a user by incrementing their token_version.
	 * The next request carrying the old token will receive a 401 token_invalidated.
	 *
	 * POST /api/user/{id}/revoke   (admin only; cannot revoke your own session)
	 */
	public function revokeToken()
	{
		$id = (int) ($this->get['id'] ?? 0);

		if (!\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		if (!$id) {
			$this->returnJson(['status' => 'error', 'code' => 'missing_id']);
			return;
		}

		if (!$this->mayManageUser($id)) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		if ($id === \Auth\CurrentUser::id()) {
			$this->returnJson(['status' => 'error', 'code' => 'cannot_revoke_own_token']);
			return;
		}

		try {
			$affected = $this->db->query(
				'UPDATE bdus_users SET token_version = token_version + 1 WHERE id = ?',
				[$id],
				'affected'
			);

			if ($affected > 0) {
				$this->returnJson(['status' => 'success', 'code' => 'token_revoked']);
			} else {
				$this->returnJson(['status' => 'error', 'code' => 'user_not_found']);
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'revoke_failed']);
		}
	}
}
