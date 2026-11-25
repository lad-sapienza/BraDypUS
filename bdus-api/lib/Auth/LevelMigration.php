<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

use Auth\Verify\LevelGroupComparison;
use Config\Config;
use DB\DBInterface;

/**
 * Moves users and API keys from a data level (Writer 20, Self writer 25, Reader 30)
 * to level Enter plus the system group that stands for it, and back.
 *
 * A move happens only when {@see LevelGroupComparison} finds no difference between
 * the level and the group on any table and action: the person or key keeps exactly
 * the access they had. Otherwise nothing is written and the differences are returned.
 *
 * What the move remembers (M058), so it can be undone:
 *  - users: `legacy_privilege` (the level) and `legacy_group_id` (the group the move
 *    added; NULL when the user was already a member, so undoing leaves it alone);
 *  - keys: `legacy_privilege`. A key has one group only, so a key that already has
 *    one is never moved.
 *
 * Every method answers `['ok' => bool, 'code' => string, …]`; refusals write nothing.
 * Rights are read from the tables on each request, so a move takes effect at once.
 *
 * Codes: moved | restored | user_not_found | key_not_found | api_key_revoked |
 * not_data_level | group_missing | not_equivalent | key_has_group | not_moved |
 * level_changed | db_error
 */
class LevelMigration
{
    private const ENTER = 39;

    private LevelGroupComparison $comparison;

    public function __construct(
        private readonly DBInterface $db,
        Config $cfg
    ) {
        $this->comparison = new LevelGroupComparison($db, $cfg);
    }

    public function moveUser(int $id): array
    {
        $row = $this->comparison->compareUser($id);
        if ($row === null) {
            return ['ok' => false, 'code' => 'user_not_found'];
        }
        if ($row['group'] === null) {
            return ['ok' => false, 'code' => 'not_data_level', 'level' => $row['level']];
        }
        if ($row['group_id'] === null) {
            return ['ok' => false, 'code' => 'group_missing', 'group' => $row['group']];
        }
        if ($row['equivalent'] !== true) {
            return ['ok' => false, 'code' => 'not_equivalent', 'differences' => $row['differences']];
        }

        $gid = (int) $row['group_id'];
        return $this->transaction(function () use ($id, $row, $gid) {
            $member = !empty($this->db->query(
                'SELECT 1 AS x FROM bdus_user_groups WHERE user_id = ? AND group_id = ?', [$id, $gid], 'read'
            ));
            $this->db->query(
                'UPDATE bdus_users SET privilege = ?, legacy_privilege = ?, legacy_group_id = ? WHERE id = ?',
                [self::ENTER, $row['level'], $member ? null : $gid, $id], 'boolean'
            );
            if (!$member) {
                $this->db->query('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (?, ?)', [$id, $gid], 'boolean');
            }
            return ['ok' => true, 'code' => 'moved', 'id' => $id, 'level' => $row['level'], 'group' => $row['group']];
        });
    }

    public function restoreUser(int $id): array
    {
        $u = $this->db->query(
            'SELECT privilege, legacy_privilege, legacy_group_id FROM bdus_users WHERE id = ?', [$id], 'read'
        )[0] ?? null;
        if ($u === null) {
            return ['ok' => false, 'code' => 'user_not_found'];
        }
        if ($u['legacy_privilege'] === null) {
            return ['ok' => false, 'code' => 'not_moved'];
        }
        // someone changed the level by hand after the move: that choice wins
        if ((int) $u['privilege'] !== self::ENTER) {
            return ['ok' => false, 'code' => 'level_changed'];
        }

        return $this->transaction(function () use ($id, $u) {
            $this->db->query(
                'UPDATE bdus_users SET privilege = ?, legacy_privilege = NULL, legacy_group_id = NULL WHERE id = ?',
                [(int) $u['legacy_privilege'], $id], 'boolean'
            );
            if ($u['legacy_group_id'] !== null) {
                $this->db->query(
                    'DELETE FROM bdus_user_groups WHERE user_id = ? AND group_id = ?',
                    [$id, (int) $u['legacy_group_id']], 'boolean'
                );
            }
            return ['ok' => true, 'code' => 'restored', 'id' => $id, 'level' => (int) $u['legacy_privilege']];
        });
    }

    public function moveKey(int $id): array
    {
        $row = $this->comparison->compareKey($id);
        if ($row === null) {
            return ['ok' => false, 'code' => 'key_not_found'];
        }
        if ($row['revoked']) {
            return ['ok' => false, 'code' => 'api_key_revoked'];
        }
        if ($row['has_group']) {
            return ['ok' => false, 'code' => 'key_has_group'];
        }
        if ($row['group'] === null) {
            return ['ok' => false, 'code' => 'not_data_level', 'level' => $row['level']];
        }
        if ($row['group_id'] === null) {
            return ['ok' => false, 'code' => 'group_missing', 'group' => $row['group']];
        }
        if ($row['equivalent'] !== true) {
            return ['ok' => false, 'code' => 'not_equivalent', 'differences' => $row['differences']];
        }

        return $this->transaction(function () use ($id, $row) {
            $this->db->query(
                'UPDATE bdus_api_keys SET privilege = ?, group_id = ?, legacy_privilege = ? WHERE id = ?',
                [self::ENTER, (int) $row['group_id'], $row['level'], $id], 'boolean'
            );
            return ['ok' => true, 'code' => 'moved', 'id' => $id, 'level' => $row['level'], 'group' => $row['group']];
        });
    }

    public function restoreKey(int $id): array
    {
        $k = $this->db->query(
            'SELECT privilege, group_id, legacy_privilege, revoked_at FROM bdus_api_keys WHERE id = ?', [$id], 'read'
        )[0] ?? null;
        if ($k === null) {
            return ['ok' => false, 'code' => 'key_not_found'];
        }
        if (!empty($k['revoked_at'])) {
            return ['ok' => false, 'code' => 'api_key_revoked'];
        }
        if ($k['legacy_privilege'] === null) {
            return ['ok' => false, 'code' => 'not_moved'];
        }
        // the level or the group was edited after the move: do not overwrite that choice
        $systemId = null;
        foreach (LevelGroupComparison::LEVEL_GROUP as $level => $name) {
            if ($level === (int) $k['legacy_privilege']) {
                $systemId = $this->systemGroupId($name);
            }
        }
        if ((int) $k['privilege'] !== self::ENTER || $systemId === null || (int) $k['group_id'] !== $systemId) {
            return ['ok' => false, 'code' => 'level_changed'];
        }

        return $this->transaction(function () use ($id, $k) {
            $this->db->query(
                'UPDATE bdus_api_keys SET privilege = ?, group_id = NULL, legacy_privilege = NULL WHERE id = ?',
                [(int) $k['legacy_privilege'], $id], 'boolean'
            );
            return ['ok' => true, 'code' => 'restored', 'id' => $id, 'level' => (int) $k['legacy_privilege']];
        });
    }

    /**
     * Moves every user and every ungrouped key at a data level whose access is
     * identical to its group's; the others are listed with the reason.
     *
     * @param  bool $dryRun  decide and report, write nothing
     * @return array{moved: list<array>, refused: list<array>, dry_run: bool}
     */
    public function moveAll(bool $dryRun = false): array
    {
        $levels = array_keys(LevelGroupComparison::LEVEL_GROUP);
        $marks  = implode(',', array_fill(0, count($levels), '?'));

        $moved = $refused = [];
        $users = $this->db->query("SELECT id, name FROM bdus_users WHERE privilege IN ({$marks}) ORDER BY id", $levels, 'read') ?: [];
        foreach ($users as $u) {
            $r = $dryRun ? $this->wouldMoveUser((int) $u['id']) : $this->moveUser((int) $u['id']);
            $r += ['type' => 'user', 'id' => (int) $u['id'], 'name' => (string) $u['name']];
            $r['ok'] ? $moved[] = $r : $refused[] = $r;
        }
        $keys = $this->db->query(
            "SELECT id, label FROM bdus_api_keys WHERE revoked_at IS NULL AND group_id IS NULL AND privilege IN ({$marks}) ORDER BY id",
            $levels, 'read'
        ) ?: [];
        foreach ($keys as $k) {
            $r = $dryRun ? $this->wouldMoveKey((int) $k['id']) : $this->moveKey((int) $k['id']);
            $r += ['type' => 'key', 'id' => (int) $k['id'], 'name' => (string) $k['label']];
            $r['ok'] ? $moved[] = $r : $refused[] = $r;
        }
        return ['moved' => $moved, 'refused' => $refused, 'dry_run' => $dryRun];
    }

    private function wouldMoveUser(int $id): array
    {
        $row = $this->comparison->compareUser($id);
        return $row !== null && $row['equivalent'] === true
            ? ['ok' => true, 'code' => 'moved', 'level' => $row['level'], 'group' => $row['group']]
            : $this->moveUser($id);   // refuses without writing, with the reason
    }

    private function wouldMoveKey(int $id): array
    {
        $row = $this->comparison->compareKey($id);
        return $row !== null && !$row['revoked'] && !$row['has_group'] && $row['equivalent'] === true
            ? ['ok' => true, 'code' => 'moved', 'level' => $row['level'], 'group' => $row['group']]
            : $this->moveKey($id);
    }

    private function systemGroupId(string $name): ?int
    {
        $row = $this->db->query('SELECT id FROM bdus_groups WHERE name = ? AND is_system = 1', [$name], 'read')[0] ?? null;
        return $row ? (int) $row['id'] : null;
    }

    /** @param callable():array $write */
    private function transaction(callable $write): array
    {
        $this->db->beginTransaction();
        try {
            $out = $write();
            $this->db->commit();
            return $out;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'code' => 'db_error', 'error' => $e->getMessage()];
        }
    }
}
