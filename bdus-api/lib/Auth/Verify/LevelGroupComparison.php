<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth\Verify;

use Auth\Access;
use Config\Config;
use DB\DBInterface;

/**
 * Read-only comparison of the built-in privilege levels with the system groups
 * that stand for their data side (M050): Reader (30), Self writer (25) and
 * Writer (20).
 *
 * For every user at one of those levels it builds two principals: the user as
 * they are (the level alone) and a user at level Enter in the equivalent
 * group, and compares, on every configured table, the table gate and the rows
 * of each action. A user with no difference could be moved from the level to
 * the group without anyone noticing; a difference is listed with its table,
 * action and how many rows each side has alone.
 *
 * Levels with no group (super admin 1, admin 10, enter 39, waiting 40) are only
 * counted: admin and super admin also gate configuration, users and backups,
 * which are not data rules. API keys without a group at 20/25/30 are listed.
 *
 * Nothing is written.
 */
class LevelGroupComparison
{
    /** level => system group that stands for its data side */
    public const LEVEL_GROUP = [30 => 'Reader', 25 => 'Self writer', 20 => 'Writer'];

    private const ENTER = 39;

    /** @var array<string, array<string, array{can: bool, ids: ?array}>> */
    private array $levelCache = [];

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg
    ) {}

    /**
     * @return array{
     *   groups: array<string, ?int>,
     *   levels: array<int, array{users: int, group: ?string, equivalent: ?bool}>,
     *   users: list<array{id: int, name: string, email: string, level: int, group: ?string, equivalent: ?bool, differences: list<array>}>,
     *   keys: list<array{id: int, label: string, level: int}>,
     *   notes: list<string>,
     *   summary: array{users: int, comparable: int, equivalent: int, different: int, missing_groups: list<string>, ok: bool}
     * }
     */
    public function run(): array
    {
        $groups = $this->groupIds();
        $tables = array_values(array_filter(
            (array) ($this->cfg->get('tables.*.name') ?: []),
            fn($t) => is_string($t) && preg_match('/^[A-Za-z0-9_]+$/', $t) === 1
        ));
        $notes = [];

        $users = $this->db->query('SELECT id, name, email, privilege FROM bdus_users ORDER BY id', [], 'read') ?: [];

        $levels = [];
        $out    = [];
        foreach ($users as $u) {
            $level = (int) $u['privilege'];
            $name  = self::LEVEL_GROUP[$level] ?? null;
            $levels[$level] ??= ['users' => 0, 'group' => $name, 'equivalent' => $name === null ? null : true];
            $levels[$level]['users']++;

            $row = [
                'id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'],
                'level' => $level, 'group' => $name, 'equivalent' => null, 'differences' => [],
            ];
            if ($name !== null && ($groups[$name] ?? null) !== null) {
                $row['differences'] = $this->differences($level, (int) $u['id'], (int) $groups[$name], $tables, $notes);
                $row['equivalent']  = $row['differences'] === [];
                if (!$row['equivalent']) {
                    $levels[$level]['equivalent'] = false;
                }
            } elseif ($name !== null) {
                $levels[$level]['equivalent'] = false;   // the group is missing: nothing to compare against
            }
            $out[] = $row;
        }
        ksort($levels);

        $comparable = array_filter($out, fn($r) => $r['equivalent'] !== null);
        $equivalent = array_filter($comparable, fn($r) => $r['equivalent'] === true);
        $missing    = array_keys(array_filter($groups, fn($id) => $id === null));
        $used       = array_unique(array_filter(array_column($out, 'group')));

        return [
            'groups' => $groups,
            'levels' => $levels,
            'users'  => $out,
            'keys'   => $this->keysWithoutGroup(),
            'notes'  => array_values(array_unique($notes)),
            'summary' => [
                'users'          => count($out),
                'comparable'     => count($comparable),
                'equivalent'     => count($equivalent),
                'different'      => count($comparable) - count($equivalent),
                'missing_groups' => array_values(array_intersect($missing, $used)),
                'ok'             => count($comparable) === count($equivalent)
                                    && array_intersect($missing, $used) === [],
            ],
        ];
    }

    /** @return array<string, ?int>  system group name => id (null when absent) */
    private function groupIds(): array
    {
        $ids = array_fill_keys(array_values(self::LEVEL_GROUP), null);
        try {
            $rows = $this->db->query('SELECT id, name FROM bdus_groups WHERE is_system = 1', [], 'read') ?: [];
        } catch (\Throwable $e) {
            return $ids;   // M049/M050 not applied yet
        }
        foreach ($rows as $r) {
            if (array_key_exists($r['name'], $ids)) {
                $ids[$r['name']] = (int) $r['id'];
            }
        }
        return $ids;
    }

    /** @return list<array{id: int, label: string, level: int}> */
    private function keysWithoutGroup(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id, label, privilege FROM bdus_api_keys WHERE revoked_at IS NULL AND group_id IS NULL ORDER BY id',
                [], 'read'
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset(self::LEVEL_GROUP[(int) $r['privilege']])) {
                $out[] = ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'level' => (int) $r['privilege']];
            }
        }
        return $out;
    }

    /**
     * Differences between the user as they are and the same user at level Enter
     * in the equivalent group.
     *
     * @param  string[] $tables
     * @param  string[] $notes  filled with cells that cannot be compared
     * @return list<array{table: string, action: string, kind: string, level_only: int, group_only: int}>
     */
    private function differences(int $level, int $userId, int $groupId, array $tables, array &$notes): array
    {
        $byLevel = new Access($this->db, $this->cfg, $level, $userId, []);
        $byGroup = new Access($this->db, $this->cfg, self::ENTER, $userId, [$groupId]);

        $diff = [];
        foreach ($tables as $table) {
            foreach (Access::ACTIONS as $action) {
                $a = $this->cell($byLevel, $table, $action);
                $b = $this->cell($byGroup, $table, $action);

                if ($a['can'] !== $b['can']) {
                    $diff[] = ['table' => $table, 'action' => $action, 'kind' => 'gate', 'level_only' => (int) $a['can'], 'group_only' => (int) $b['can']];
                    continue;
                }
                if ($a['ids'] === null || $b['ids'] === null) {
                    if ($a['ids'] !== $b['ids']) {
                        $notes[] = "{$table}: {$action} by owner cannot be compared (no creator column, e.g. a plugin table)";
                    }
                    continue;
                }
                $onlyLevel = array_diff($a['ids'], $b['ids']);
                $onlyGroup = array_diff($b['ids'], $a['ids']);
                if ($onlyLevel || $onlyGroup) {
                    $diff[] = ['table' => $table, 'action' => $action, 'kind' => 'rows', 'level_only' => count($onlyLevel), 'group_only' => count($onlyGroup)];
                }
            }
        }
        return $diff;
    }

    /**
     * What a principal gets on one table and action: the gate, and the ids of
     * the rows (null when there is no row dimension, or the rows cannot be
     * listed, e.g. a creator test on a table without a creator column).
     *
     * @return array{can: bool, ids: ?array}
     */
    private function cell(Access $access, string $table, string $action): array
    {
        $can = $access->can($action, $table);
        if ($action === 'create' || !$can) {
            return ['can' => $can, 'ids' => $can ? null : []];
        }
        try {
            [$sql, $values] = $access->rowCondition($action, $table);
            if ($sql === '1=0') {
                return ['can' => $can, 'ids' => []];
            }
            $rows = $this->db->query(
                "SELECT {$table}.id AS id FROM {$table} WHERE {$sql} ORDER BY {$table}.id",
                $values, 'read'
            ) ?: [];
            return ['can' => $can, 'ids' => array_map('intval', array_column($rows, 'id'))];
        } catch (\Throwable $e) {
            return ['can' => $can, 'ids' => null];
        }
    }
}
