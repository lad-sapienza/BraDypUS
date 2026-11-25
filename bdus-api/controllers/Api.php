<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * v5 API key management controller.
 *
 * This controller manages REST API keys for authenticated (logged-in) admin
 * users through the Vue config UI.  The public REST API itself lives in
 * lib/API/V1/ and is completely separate from this controller.
 *
 * All endpoints require admin privilege (\Auth\Authorization::can('admin')).
 * Plain-text keys are generated here and returned ONCE — they are never
 * stored.  Only the SHA-256 hash is persisted in {prefix}api_keys.
 */

use \DB\System\Manage;

class Api extends \Bdus\Controller
{
    // ── v5 methods ────────────────────────────────────────────────────────────

    /**
     * GET — list all API keys for this app (key_hash excluded).
     *
     * Response: { status: 'success', keys: [ {...}, ... ] }
     */
    public function listKeys(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        // `SELECT *`, not the columns the structure declares: the list must work before the
        // migration that adds the newest one (disabled_at, M055) has run.
        $rows = $this->db->query('SELECT * FROM bdus_api_keys ORDER BY created_at DESC', [], 'read') ?: [];

        $groups = [];
        try {
            foreach ($this->db->query('SELECT id, name FROM bdus_groups', [], 'read') ?: [] as $g) {
                $groups[(int) $g['id']] = $g['name'];
            }
        } catch (\Throwable $e) {
            // groups table missing (migration pending): no group names
        }

        $result = array_map(function (array $r) use ($groups): array {
            unset($r['key_hash']);
            $r['disabled_at'] = $r['disabled_at'] ?? null;   // column added by M055
            $r['legacy_privilege'] = isset($r['legacy_privilege']) ? (int) $r['legacy_privilege'] : null;   // M058
            $r['is_revoked']  = !empty($r['revoked_at']);
            $r['is_disabled'] = !empty($r['disabled_at']);
            $r['is_active']   = !$r['is_revoked'] && !$r['is_disabled'];
            $r['group_id']   = !empty($r['group_id']) ? (int) $r['group_id'] : null;
            $r['group_name'] = $r['group_id'] ? ($groups[$r['group_id']] ?? null) : null;
            return $r;
        }, $rows);

        $this->returnJson(['status' => 'success', 'keys' => $result]);
    }

    /**
     * POST { label } — create a new API key and return the plain-text key ONCE.
     *
     * Response: { status: 'success', code: 'ok_api_key_created', id, label, key }
     */
    public function createKey(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $label = trim($this->post['label'] ?? '');
        if (!$label) {
            $this->returnJson([
                'status' => 'error',
                'code'   => 'parameter_missing',
                'detail' => 'label',
            ]);
            return;
        }

        // Privilege defaults to READ (30). ENTER (39) is the tier of a key whose whole access
        // comes from its group; the rules are the same when a key is edited (checkAccess()).
        $privilege = isset($this->post['privilege'])
            ? (int) $this->post['privilege']
            : \Auth\Authorization::READ;
        $groupId = isset($this->post['group_id']) && $this->post['group_id'] !== ''
            ? (int) $this->post['group_id']
            : null;
        if ($code = $this->checkAccess($privilege, $groupId)) {
            $this->returnJson(['status' => 'error', 'code' => $code]);
            return;
        }

        // Generate a cryptographically random 64-char hex key.
        $plainKey = bin2hex(random_bytes(32));
        $keyHash  = hash('sha256', $plainKey);

        $manage = new Manage($this->db);
        $row    = [
            'key_hash'   => $keyHash,
            'label'      => $label,
            'created_by' => \Auth\CurrentUser::id(),
            'created_at' => time(),
            'privilege'  => $privilege,
        ];
        if ($groupId !== null) {
            $row['group_id'] = $groupId; // column added by M046
        }
        $id     = $manage->addRow('bdus_api_keys', $row);

        $this->returnJson([
            'status'    => 'success',
            'code'      => 'ok_api_key_created',
            'id'        => $id,
            'label'     => $label,
            'privilege' => $privilege,
            'group_id'  => $groupId,
            'key'       => $plainKey, // shown ONCE — never stored in plain text
        ]);
    }

    /**
     * The rules for the access of a key, the same on creation and on edit.
     *
     * @return string|null  the error code, or null when the combination is acceptable
     */
    private function checkAccess(int $privilege, ?int $groupId): ?string
    {
        // 10 admin, 25 edit, 30 read, 39 enter: a key whose whole access comes from its group
        if (!in_array($privilege, [
            \Auth\Authorization::ADM, \Auth\Authorization::CREATE,
            \Auth\Authorization::READ, \Auth\Authorization::ENTER,
        ], true)) {
            return 'invalid_privilege';
        }
        // Optional group: its rules scope the key to tables and rows.
        if ($groupId !== null && !$this->groupExists($groupId)) {
            return 'group_not_found';
        }
        // A tier-39 key has no access of its own, so it needs a group...
        if ($privilege === \Auth\Authorization::ENTER && $groupId === null) {
            return 'group_required';
        }
        // ...and an admin key bypasses rules, so a group would mean nothing.
        if ($privilege === \Auth\Authorization::ADM && $groupId !== null) {
            return 'invalid_privilege';
        }
        return null;
    }

    /** The key row, or the error code to answer with (a revoked key is final: nothing changes it). */
    private function editableKey(): array|string
    {
        // the id of the route wins over one a body might carry
        $id = (int) ($this->get['id'] ?? ($this->request['id'] ?? 0));
        if (!$id) {
            return 'parameter_missing';
        }
        $row = $this->db->query('SELECT * FROM bdus_api_keys WHERE id = ?', [$id], 'read')[0] ?? null;
        if ($row === null) {
            return 'not_found';
        }
        return !empty($row['revoked_at']) ? 'api_key_revoked' : $row;
    }

    /**
     * PUT { label?, privilege?, group_id? } — change a key without recreating it. Only what is
     * sent changes (group_id null or '' removes the group); the result must follow the rules of
     * creation. Takes effect on the key's next request: rights are read from the table each time.
     * A revoked key cannot be edited.
     *
     * Response: { status: 'success', code: 'ok_api_key_updated', id, label, privilege, group_id }
     */
    public function editKey(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $key = $this->editableKey();
        if (is_string($key)) {
            $this->returnJson(['status' => 'error', 'code' => $key] + ($key === 'parameter_missing' ? ['detail' => 'id'] : []));
            return;
        }

        $label = $key['label'];
        if (array_key_exists('label', $this->post)) {
            $label = trim((string) $this->post['label']);
            if ($label === '') {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'label']);
                return;
            }
        }
        $privilege = array_key_exists('privilege', $this->post) ? (int) $this->post['privilege'] : (int) $key['privilege'];
        $groupId   = !empty($key['group_id']) ? (int) $key['group_id'] : null;
        if (array_key_exists('group_id', $this->post)) {
            $groupId = $this->post['group_id'] === null || $this->post['group_id'] === '' ? null : (int) $this->post['group_id'];
        }
        if ($code = $this->checkAccess($privilege, $groupId)) {
            $this->returnJson(['status' => 'error', 'code' => $code]);
            return;
        }

        try {
            $this->db->query(
                'UPDATE bdus_api_keys SET label = ?, privilege = ?, group_id = ? WHERE id = ?',
                [$label, $privilege, $groupId, (int) $key['id']],
                'boolean'
            );
        } catch (\Throwable $e) {
            $this->log?->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_saved']);
            return;
        }
        $this->log?->info(sprintf(
            'API key #%d edited: level %d→%d, group %s→%s',
            $key['id'], (int) $key['privilege'], $privilege, $key['group_id'] ?: 'none', $groupId ?? 'none'
        ));

        $this->returnJson([
            'status' => 'success', 'code' => 'ok_api_key_updated',
            'id' => (int) $key['id'], 'label' => $label, 'privilege' => $privilege, 'group_id' => $groupId,
        ]);
    }

    /**
     * POST — switch a key off (disabled_at) until it is enabled again. Unlike revoking, this is
     * reversible; a revoked key stays revoked.
     *
     * Response: { status: 'success', code: 'ok_api_key_disabled' }
     */
    public function disableKey(): void
    {
        $this->setDisabled(true);
    }

    /** POST — switch a disabled key back on. Response: { status: 'success', code: 'ok_api_key_enabled' } */
    public function enableKey(): void
    {
        $this->setDisabled(false);
    }

    private function setDisabled(bool $disable): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $key = $this->editableKey();
        if (is_string($key)) {
            $this->returnJson(['status' => 'error', 'code' => $key] + ($key === 'parameter_missing' ? ['detail' => 'id'] : []));
            return;
        }
        try {
            // an already disabled key keeps the time it was switched off
            $this->db->query(
                $disable
                    ? 'UPDATE bdus_api_keys SET disabled_at = ? WHERE id = ? AND disabled_at IS NULL'
                    : 'UPDATE bdus_api_keys SET disabled_at = NULL WHERE id = ?',
                $disable ? [time(), (int) $key['id']] : [(int) $key['id']],
                'boolean'
            );
        } catch (\Throwable $e) {
            // typically: M055 has not been applied yet
            $this->log?->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_saved']);
            return;
        }
        $this->log?->info(sprintf('API key #%d %s', $key['id'], $disable ? 'disabled' : 'enabled'));
        $this->returnJson(['status' => 'success', 'code' => $disable ? 'ok_api_key_disabled' : 'ok_api_key_enabled']);
    }

    private function groupExists(int $id): bool
    {
        try {
            return !empty($this->db->query('SELECT id FROM bdus_groups WHERE id = ?', [$id], 'read'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * POST { id } — revoke an API key (sets revoked_at timestamp).
     *
     * Response: { status: 'success', code: 'ok_api_key_revoked' }
     */
    public function revokeKey(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $id = (int)($this->request['id'] ?? 0);
        if (!$id) {
            $this->returnJson([
                'status' => 'error',
                'code'   => 'parameter_missing',
                'detail' => 'id',
            ]);
            return;
        }

        $manage = new Manage($this->db);
        $manage->editRow('bdus_api_keys', $id, ['revoked_at' => time()]);

        $this->returnJson(['status' => 'success', 'code' => 'ok_api_key_revoked']);
    }

    /**
     * POST { id } — permanently delete an API key record.
     *
     * Response: { status: 'success', code: 'ok_api_key_deleted' }
     */
    public function deleteKey(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $id = (int)($this->request['id'] ?? 0);
        if (!$id) {
            $this->returnJson([
                'status' => 'error',
                'code'   => 'parameter_missing',
                'detail' => 'id',
            ]);
            return;
        }

        $manage = new Manage($this->db);
        $manage->deleteRow('bdus_api_keys', $id);

        $this->returnJson(['status' => 'success', 'code' => 'ok_api_key_deleted']);
    }
}
