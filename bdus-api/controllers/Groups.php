<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus\Controllers;

use Auth\Access;

/**
 * Groups, group rules, group membership and table locks (#78). Admin only.
 *
 * A rule grants one action (create | read | update | delete) on one table to a
 * group, optionally limited to the rows matching a JSON filter (same syntax as
 * the record filters; `$CURRENT_USER` is the only variable). A lock freezes the
 * rows matching a filter against update and delete for everyone but admins.
 * See bdus-docs/dev/groups-permissions-design.md.
 *
 * Errors follow the usual envelope: HTTP 200, `status: error`, i18n `code`.
 */
class Groups extends \Bdus\Controller
{
    private const NAME_MAX = 100;

    // ── Groups ────────────────────────────────────────────────────────────────

    /** GET /api/groups → { groups: [{ id, name, description, members, rules, keys }] } */
    public function listGroups(): void
    {
        if (!$this->isAdmin()) {
            return;
        }

        $rows = $this->db->query(
            'SELECT g.id, g.name, g.description,
                    (SELECT COUNT(*) FROM bdus_user_groups ug WHERE ug.group_id = g.id)  AS member_count,
                    (SELECT COUNT(*) FROM bdus_group_rules r  WHERE r.group_id  = g.id)  AS rule_count,
                    (SELECT COUNT(*) FROM bdus_api_keys k     WHERE k.group_id  = g.id AND k.revoked_at IS NULL) AS key_count
               FROM bdus_groups g ORDER BY g.name',
            [], 'read'
        ) ?: [];

        $this->returnJson(['status' => 'success', 'groups' => array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'members'     => (int) $r['member_count'],
            'rules'       => (int) $r['rule_count'],
            'keys'        => (int) $r['key_count'],
        ], $rows)]);
    }

    /** GET /api/group/{id} → { group: { id, name, description, members: [...], rules: [...] } } */
    public function getGroup(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $group = $this->loadGroup();
        if (!$group) {
            return;
        }
        $id = (int) $group['id'];

        $members = $this->db->query(
            'SELECT u.id, u.name, u.email FROM bdus_user_groups ug
               JOIN bdus_users u ON u.id = ug.user_id
              WHERE ug.group_id = ? ORDER BY u.name',
            [$id], 'read'
        ) ?: [];

        $rules = array_map(fn($r) => [
            'table'  => $r['table_name'],
            'action' => $r['action'],
            'filter' => ($r['filter'] !== null && $r['filter'] !== '') ? json_decode($r['filter'], true) : null,
        ], $this->db->query(
            'SELECT table_name, action, filter FROM bdus_group_rules WHERE group_id = ? ORDER BY table_name, action',
            [$id], 'read'
        ) ?: []);

        $this->returnJson(['status' => 'success', 'group' => [
            'id'          => $id,
            'name'        => $group['name'],
            'description' => $group['description'],
            'members'     => array_map(fn($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'email' => $m['email']], $members),
            'rules'       => $rules,
        ]]);
    }

    /** POST /api/groups { name, description? } → { id } */
    public function createGroup(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        [$name, $description] = $this->groupFields() ?? [null, null];
        if ($name === null) {
            return;
        }

        $id = (int) $this->db->query(
            'INSERT INTO bdus_groups (name, description) VALUES (?, ?)',
            [$name, $description], 'id'
        );
        $this->returnJson(['status' => 'success', 'code' => 'group_created', 'id' => $id]);
    }

    /** PUT /api/group/{id} { name, description? } */
    public function updateGroup(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $group = $this->loadGroup();
        if (!$group) {
            return;
        }
        [$name, $description] = $this->groupFields((int) $group['id']) ?? [null, null];
        if ($name === null) {
            return;
        }

        $this->db->query(
            'UPDATE bdus_groups SET name = ?, description = ? WHERE id = ?',
            [$name, $description, (int) $group['id']], 'boolean'
        );
        $this->returnJson(['status' => 'success', 'code' => 'group_updated']);
    }

    /**
     * DELETE /api/group/{id} — removes the group with its memberships and rules.
     * API keys that belonged to it lose their group (they keep their tier, so a
     * tier-39 key ends up with no access at all).
     */
    public function deleteGroup(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $group = $this->loadGroup();
        if (!$group) {
            return;
        }
        $id = (int) $group['id'];

        $this->db->beginTransaction();
        try {
            $this->db->query('UPDATE bdus_api_keys SET group_id = NULL WHERE group_id = ?', [$id], 'boolean');
            $this->db->query('DELETE FROM bdus_user_groups WHERE group_id = ?', [$id], 'boolean');
            $this->db->query('DELETE FROM bdus_group_rules WHERE group_id = ?', [$id], 'boolean');
            $this->db->query('DELETE FROM bdus_groups WHERE id = ?', [$id], 'boolean');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'db_error']);
            return;
        }
        $this->returnJson(['status' => 'success', 'code' => 'group_deleted']);
    }

    // ── Membership ────────────────────────────────────────────────────────────

    /** PUT /api/group/{id}/members { user_ids: [int] } — replaces the group's members */
    public function setMembers(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $group = $this->loadGroup();
        if (!$group) {
            return;
        }
        $userIds = $this->intList($this->post['user_ids'] ?? null);
        if ($userIds === null || !$this->allExist('bdus_users', $userIds)) {
            $this->returnJson(['status' => 'error', 'code' => $userIds === null ? 'parameter_missing' : 'user_not_found']);
            return;
        }

        $id = (int) $group['id'];
        $this->replace(function () use ($id, $userIds) {
            $this->db->query('DELETE FROM bdus_user_groups WHERE group_id = ?', [$id], 'boolean');
            foreach ($userIds as $uid) {
                $this->db->query('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (?, ?)', [$uid, $id], 'boolean');
            }
        }, 'members_saved');
    }

    /** GET /api/user/{id}/groups → { groups: [group id] } */
    public function getUserGroups(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $userId = (int) ($this->get['id'] ?? 0);
        if (!$this->allExist('bdus_users', [$userId])) {
            $this->returnJson(['status' => 'error', 'code' => 'user_not_found']);
            return;
        }
        $rows = $this->db->query('SELECT group_id FROM bdus_user_groups WHERE user_id = ? ORDER BY group_id', [$userId], 'read') ?: [];
        $this->returnJson(['status' => 'success', 'groups' => array_map('intval', array_column($rows, 'group_id'))]);
    }

    /** PUT /api/user/{id}/groups { group_ids: [int] } — replaces the user's groups */
    public function setUserGroups(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $userId = (int) ($this->get['id'] ?? 0);
        if (!$this->allExist('bdus_users', [$userId])) {
            $this->returnJson(['status' => 'error', 'code' => 'user_not_found']);
            return;
        }
        $groupIds = $this->intList($this->post['group_ids'] ?? null);
        if ($groupIds === null || !$this->allExist('bdus_groups', $groupIds)) {
            $this->returnJson(['status' => 'error', 'code' => $groupIds === null ? 'parameter_missing' : 'group_not_found']);
            return;
        }

        $this->replace(function () use ($userId, $groupIds) {
            $this->db->query('DELETE FROM bdus_user_groups WHERE user_id = ?', [$userId], 'boolean');
            foreach ($groupIds as $gid) {
                $this->db->query('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (?, ?)', [$userId, $gid], 'boolean');
            }
        }, 'groups_saved');
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    /**
     * PUT /api/group/{id}/rules { rules: [{ table, action, filter? }] } — replaces
     * the group's whole rule set (what the permissions grid sends). `filter` is a
     * JSON filter, or null/absent for "all rows"; `create` takes no filter.
     */
    public function setRules(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $group = $this->loadGroup();
        if (!$group) {
            return;
        }
        $rules = $this->post['rules'] ?? null;
        if (!is_array($rules)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'rules']);
            return;
        }

        $rows = [];
        foreach ($rules as $i => $rule) {
            $row = is_array($rule) ? $this->normaliseRule($rule) : ['code' => 'invalid_rule', 'detail' => "rule #{$i}"];
            if (isset($row['code'])) {
                $this->returnJson(['status' => 'error'] + $row);
                return;
            }
            $key = $row[0] . '|' . $row[1];
            if (isset($rows[$key])) {
                $this->returnJson(['status' => 'error', 'code' => 'duplicate_rule', 'detail' => "{$row[0]} {$row[1]}"]);
                return;
            }
            $rows[$key] = $row;
        }

        $id = (int) $group['id'];
        $this->replace(function () use ($id, $rows) {
            $this->db->query('DELETE FROM bdus_group_rules WHERE group_id = ?', [$id], 'boolean');
            foreach ($rows as [$table, $action, $filter]) {
                $this->db->query(
                    'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                    [$id, $table, $action, $filter], 'boolean'
                );
            }
        }, 'rules_saved');
    }

    // ── Locks ─────────────────────────────────────────────────────────────────

    /** GET /api/locks → { locks: [{ id, table, filter, note }] } */
    public function listLocks(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $rows = $this->db->query('SELECT id, table_name, filter, note FROM bdus_table_locks ORDER BY table_name, id', [], 'read') ?: [];
        $this->returnJson(['status' => 'success', 'locks' => array_map(fn($r) => [
            'id'     => (int) $r['id'],
            'table'  => $r['table_name'],
            'filter' => json_decode((string) $r['filter'], true),
            'note'   => $r['note'],
        ], $rows)]);
    }

    /** POST /api/locks { table, filter, note? } → { id } */
    public function createLock(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $lock = $this->lockFields();
        if ($lock === null) {
            return;
        }

        $id = (int) $this->db->query(
            'INSERT INTO bdus_table_locks (table_name, filter, note) VALUES (?, ?, ?)',
            $lock, 'id'
        );
        $this->returnJson(['status' => 'success', 'code' => 'lock_created', 'id' => $id]);
    }

    /** PUT /api/lock/{id} { table, filter, note? } */
    public function updateLock(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $id = (int) ($this->get['id'] ?? 0);
        if (!$this->allExist('bdus_table_locks', [$id])) {
            $this->returnJson(['status' => 'error', 'code' => 'lock_not_found']);
            return;
        }
        $lock = $this->lockFields();
        if ($lock === null) {
            return;
        }

        $this->db->query(
            'UPDATE bdus_table_locks SET table_name = ?, filter = ?, note = ? WHERE id = ?',
            array_merge($lock, [$id]), 'boolean'
        );
        $this->returnJson(['status' => 'success', 'code' => 'lock_updated']);
    }

    /** DELETE /api/lock/{id} */
    public function deleteLock(): void
    {
        if (!$this->isAdmin()) {
            return;
        }
        $id = (int) ($this->get['id'] ?? 0);
        if (!$this->allExist('bdus_table_locks', [$id])) {
            $this->returnJson(['status' => 'error', 'code' => 'lock_not_found']);
            return;
        }
        $this->db->query('DELETE FROM bdus_table_locks WHERE id = ?', [$id], 'boolean');
        $this->returnJson(['status' => 'success', 'code' => 'lock_deleted']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Emits the refusal and returns false unless the caller is an admin. */
    private function isAdmin(): bool
    {
        if (\Auth\Authorization::can('admin')) {
            return true;
        }
        $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
        return false;
    }

    /** The group named by the {id} URL variable, or null after emitting `group_not_found`. */
    private function loadGroup(): ?array
    {
        $id  = (int) ($this->get['id'] ?? 0);
        $row = $id ? ($this->db->query('SELECT id, name, description FROM bdus_groups WHERE id = ?', [$id], 'read')[0] ?? null) : null;
        if (!$row) {
            $this->returnJson(['status' => 'error', 'code' => 'group_not_found']);
        }
        return $row;
    }

    /**
     * Validated [name, description] from the request, or null after emitting the error.
     *
     * @param int $selfId group being renamed (its own name is not a clash)
     */
    private function groupFields(int $selfId = 0): ?array
    {
        $name = trim((string) ($this->post['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'name']);
            return null;
        }
        $taken = $this->db->query('SELECT id FROM bdus_groups WHERE name = ? AND id <> ?', [$name, $selfId], 'read');
        if (!empty($taken)) {
            $this->returnJson(['status' => 'error', 'code' => 'group_name_taken']);
            return null;
        }
        $description = trim((string) ($this->post['description'] ?? ''));
        return [$name, $description === '' ? null : $description];
    }

    /**
     * Validated [table, filter JSON, note] of a lock from the request, or null after emitting the error.
     * A lock needs a non-empty filter: an empty one would silently freeze the whole table.
     */
    private function lockFields(): ?array
    {
        $table  = (string) ($this->post['table'] ?? '');
        $filter = $this->post['filter'] ?? null;

        if (!$this->isDataTable($table)) {
            $this->returnJson(['status' => 'error', 'code' => 'unknown_table', 'detail' => $table]);
            return null;
        }
        if (!is_array($filter) || empty($filter)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_filter', 'detail' => 'a lock needs a non-empty filter']);
            return null;
        }
        if (($why = Access::checkFilter($this->db, $this->cfg, $table, $filter)) !== null) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_filter', 'detail' => $why]);
            return null;
        }
        $note = trim((string) ($this->post['note'] ?? ''));
        return [$table, json_encode($filter, JSON_UNESCAPED_UNICODE), $note === '' ? null : $note];
    }

    /**
     * [table, action, filter JSON|null] for one submitted rule, or an error
     * envelope fragment ['code' => …, 'detail' => …].
     */
    private function normaliseRule(array $rule): array
    {
        $table  = (string) ($rule['table']  ?? '');
        $action = (string) ($rule['action'] ?? '');
        $filter = $rule['filter'] ?? null;

        if (!$this->isDataTable($table)) {
            return ['code' => 'unknown_table', 'detail' => $table];
        }
        if (!in_array($action, Access::ACTIONS, true)) {
            return ['code' => 'invalid_rule', 'detail' => "action '{$action}'"];
        }
        if ($filter === null || $filter === [] || $filter === '') {
            return [$table, $action, null];
        }
        if ($action === 'create') {
            return ['code' => 'invalid_rule', 'detail' => 'create takes no filter'];
        }
        if (!is_array($filter)) {
            return ['code' => 'invalid_filter', 'detail' => "{$table} {$action}"];
        }
        if (($why = Access::checkFilter($this->db, $this->cfg, $table, $filter)) !== null) {
            return ['code' => 'invalid_filter', 'detail' => "{$table} {$action}: {$why}"];
        }
        return [$table, $action, json_encode($filter, JSON_UNESCAPED_UNICODE)];
    }

    /** A table of the application's data (not a system table). */
    private function isDataTable(string $table): bool
    {
        return $table !== ''
            && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
            && !str_starts_with($table, 'bdus_')
            && !empty($this->cfg->get("tables.{$table}"));
    }

    /** Distinct positive ints from a request value, or null when it is not a list. */
    private function intList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return array_values(array_unique(array_filter(array_map('intval', $value), fn($v) => $v > 0)));
    }

    /** True when every id exists in $table. */
    private function allExist(string $table, array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $found = $this->db->query("SELECT COUNT(*) AS n FROM {$table} WHERE id IN ({$marks})", array_values($ids), 'read');
        return (int) ($found[0]['n'] ?? 0) === count(array_unique($ids));
    }

    /** Runs a replace-all write in a transaction and answers with $okCode. */
    private function replace(callable $write, string $okCode): void
    {
        $this->db->beginTransaction();
        try {
            $write();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'db_error']);
            return;
        }
        $this->returnJson(['status' => 'success', 'code' => $okCode]);
    }
}
