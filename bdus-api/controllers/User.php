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
	 *       "override_count":  int       // number of per-table overrides
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
			$all_users   = $sys_manager->getBySQL('bdus_users', '1=1');

			foreach ($all_users as $user) {
				// Count per-table privilege overrides for the badge indicator.
				$overrides = $this->db->query(
					"SELECT COUNT(*) AS cnt FROM bdus_user_table_privs WHERE user_id = ?",
					[(int) $user['id']],
					'read'
				);
				$data['users'][] = [
					'id'              => $user['id'],
					'name'            => $user['name'],
					'email'           => $user['email'],
					'privilege'       => \Auth\Authorization::privilege($user['privilege'], true),
					'privilege_value' => (int) $user['privilege'],
					'editable'        => (\Auth\Authorization::can('admin') && $user['privilege'] >= \Auth\CurrentUser::privilege()),
					'override_count'  => (int) ($overrides[0]['cnt'] ?? 0),
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
				'override_count'  => 0,
			];
		}
		$data["status"] = "success";
		$this->returnJson($data);
	}

	/**
	 * Deletes a user and all their per-table privilege overrides.
	 *
	 * GET ?obj=user_ctrl&method=deleteOne&id=ID
	 */
	public function deleteOne($id = false)
	{
		if (!$id) {
			$id = $this->get['id'];
		}
		$id = (int) $id;
		try {
			$sys_manager = new Manage($this->db);

			// Cascade: remove per-table overrides before deleting the user.
			$this->db->query(
				"DELETE FROM bdus_user_table_privs WHERE user_id = ?",
				[$id],
				'boolean'
			);

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

		try {
			$sys_manager = new Manage($this->db);

			foreach ($data as $key => &$value) {
				if ($key === 'password') {
					if ($value && $value !== '') {
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

				// Privilege change → invalidate the user's current session immediately.
				if ($ret && array_key_exists('privilege', $data) && !$isOwnUser) {
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

		if (!$id) {
			$this->returnJson(['status' => 'error', 'code' => 'missing_id']);
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

	// ── Per-table privilege overrides ─────────────────────────────────────────

	/**
	 * Returns all per-table privilege overrides for a user.
	 *
	 * GET ?obj=user_ctrl&method=getTablePrivileges&user_id=ID
	 *
	 * Success:
	 * {
	 *   "status": "success",
	 *   "code":   "table_privileges",
	 *   "data": [
	 *     { "id": int, "user_id": int, "table_name": string,
	 *       "privilege": int, "subset": string|null }
	 *   ]
	 * }
	 */
	public function getTablePrivileges(): void
	{
		if (!\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$userId = (int) ($this->get['user_id'] ?? 0);
		if (!$userId) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		try {
			$rows = $this->db->query(
				"SELECT id, user_id, table_name, privilege, subset
				 FROM bdus_user_table_privs
				 WHERE user_id = ?
				 ORDER BY table_name",
				[$userId],
				'read'
			) ?: [];
			$this->returnJson(['status' => 'success', 'code' => 'table_privileges', 'data' => $rows]);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

	/**
	 * Upserts a per-table privilege override for a user.
	 * If a row for (user_id, table_name) already exists it is updated;
	 * otherwise a new row is inserted.
	 *
	 * POST ?obj=user_ctrl&method=saveTablePrivilege
	 * Body: { user_id, table_name, privilege, subset? }
	 *
	 * Success: { "status": "success", "code": "privilege_saved", "id": int }
	 */
	public function saveTablePrivilege(): void
	{
		if (!\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$userId    = (int)   ($this->post['user_id']    ?? 0);
		$tableName = trim(    $this->post['table_name'] ?? '');
		$privilege = (int)   ($this->post['privilege']  ?? 0);
		$subset    = trim(    $this->post['subset']      ?? '') ?: null;

		if (!$userId || !$tableName || !$privilege) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		try {
			// Check for existing row (upsert by application logic — no UNIQUE constraint in DB).
			$existing = $this->db->query(
				"SELECT id FROM bdus_user_table_privs
				 WHERE user_id = ? AND table_name = ?",
				[$userId, $tableName],
				'read'
			);

			if (!empty($existing)) {
				$rowId = (int) $existing[0]['id'];
				$this->db->query(
					"UPDATE bdus_user_table_privs
					 SET privilege = ?, subset = ?
					 WHERE id = ?",
					[$privilege, $subset, $rowId],
					'boolean'
				);
				$this->returnJson(['status' => 'success', 'code' => 'privilege_saved', 'id' => $rowId]);
			} else {
				$rowId = $this->db->query(
					"INSERT INTO bdus_user_table_privs
					 (user_id, table_name, privilege, subset) VALUES (?, ?, ?, ?)",
					[$userId, $tableName, $privilege, $subset],
					'id'
				);
				$this->returnJson(['status' => 'success', 'code' => 'privilege_saved', 'id' => (int) $rowId]);
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

	/**
	 * Deletes a per-table privilege override by its id.
	 *
	 * GET ?obj=user_ctrl&method=deleteTablePrivilege&id=ID
	 * Success: { "status": "success", "code": "privilege_deleted" }
	 */
	public function deleteTablePrivilege(): void
	{
		if (!\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$id = (int) ($this->get['id'] ?? 0);
		if (!$id) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		try {
			$affected = $this->db->query(
				"DELETE FROM bdus_user_table_privs WHERE id = ?",
				[$id],
				'affected'
			);

			if ($affected > 0) {
				$this->returnJson(['status' => 'success', 'code' => 'privilege_deleted']);
			} else {
				$this->returnJson(['status' => 'error', 'code' => 'not_found']);
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}
}
