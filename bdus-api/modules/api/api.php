<?php

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

class api_ctrl extends Controller
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

        $manage = new Manage($this->db);
        $rows   = $manage->getBySQL('bdus_api_keys', '1=1 ORDER BY created_at DESC') ?: [];

        $result = array_map(function (array $r): array {
            unset($r['key_hash']);
            $r['is_active'] = !$r['revoked_at'];
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

        // Validate privilege: must be one of the three allowed levels.
        // Default to READ (30) when not supplied.
        $allowedPrivileges = [\UAC\UAC::ADM, \UAC\UAC::CREATE, \UAC\UAC::READ]; // 10, 25, 30
        $privilege = isset($this->post['privilege'])
            ? (int) $this->post['privilege']
            : \UAC\UAC::READ;
        if (!in_array($privilege, $allowedPrivileges, true)) {
            $this->returnJson([
                'status' => 'error',
                'code'   => 'invalid_privilege',
            ]);
            return;
        }

        // Generate a cryptographically random 64-char hex key.
        $plainKey = bin2hex(random_bytes(32));
        $keyHash  = hash('sha256', $plainKey);

        $manage = new Manage($this->db);
        $id     = $manage->addRow('bdus_api_keys', [
            'key_hash'   => $keyHash,
            'label'      => $label,
            'created_by' => \Auth\CurrentUser::id(),
            'created_at' => time(),
            'privilege'  => $privilege,
        ]);

        $this->returnJson([
            'status'    => 'success',
            'code'      => 'ok_api_key_created',
            'id'        => $id,
            'label'     => $label,
            'privilege' => $privilege,
            'key'       => $plainKey, // shown ONCE — never stored in plain text
        ]);
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

        $id = (int)($this->post['id'] ?? 0);
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

        $id = (int)($this->post['id'] ?? 0);
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
