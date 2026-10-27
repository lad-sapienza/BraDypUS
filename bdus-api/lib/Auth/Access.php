<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

use Config\Config;
use DB\DBInterface;
use SQL\Filter\JsonFilter;

/**
 * Table- and row-level access decisions for one principal (#78).
 *
 * Layered on top of the global tier (see Authorization): the tier is the
 * baseline on every table, group rules can grant more on specific tables and
 * rows, and table locks take update/delete away from non-admins on frozen
 * rows. Admins and super admins bypass everything.
 *
 * For action A on table T and row R:
 *   1. tier <= ADM                         → allowed (locks do not apply)
 *   2. otherwise A needs a grant: the tier grants A on all tables, or a group
 *      rule grants A on T and R matches its filter (rules are OR-ed, default deny)
 *      — a self writer (tier 25) is additionally granted update/delete on the
 *      rows they created
 *   3. and for update/delete, R must match no lock on T.
 *
 * Rule and lock filters are JSON filters (SQL\Filter\JsonFilter); the only
 * variable is `$CURRENT_USER`. A filter that cannot be compiled (unknown field,
 * invalid JSON) fails closed: a bad rule grants nothing, a bad lock locks the
 * whole table. Application status (frozen/off) is not handled here: it stays in
 * Authorization::can().
 *
 * With no groups, rules or locks this class changes nothing: the tier alone
 * decides, as before.
 */
class Access
{
    public const ACTIONS = ['create', 'read', 'update', 'delete'];

    /** Variable replaced, in rule and lock filters only, by the principal's user id. */
    public const VAR_CURRENT_USER = '$CURRENT_USER';

    /** @var array<string, array<string, list<?array>>>|null  table => action => filters (null = all rows) */
    private ?array $rules = null;

    /** @var array<string, list<?array>>|null  table => filters (null = unparsable → lock everything) */
    private ?array $locks = null;

    /**
     * @param int   $tier     Global privilege tier of the principal
     * @param int   $userId   User id (0 for API keys)
     * @param int[] $groupIds Groups the principal belongs to
     */
    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        private readonly int $tier,
        private readonly int $userId,
        private readonly array $groupIds = []
    ) {}

    /**
     * Builds the Access of the current request's principal (user or API key).
     * An unauthenticated request gets an Access that allows nothing.
     */
    public static function forCurrentUser(DBInterface $db, Config $cfg): self
    {
        if (!CurrentUser::isAuthenticated()) {
            return new self($db, $cfg, PHP_INT_MAX, 0);
        }

        $tier   = CurrentUser::privilege() ?? PHP_INT_MAX;
        $userId = (int) CurrentUser::id();

        if (CurrentUser::isApiKey()) {
            $groupId  = CurrentUser::get('group_id');
            $groupIds = $groupId ? [(int) $groupId] : [];
        } else {
            $groupIds = self::loadUserGroups($db, $userId);
        }

        return new self($db, $cfg, $tier, $userId, $groupIds);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** True for admins and super admins, who bypass rules and locks. */
    public function isAdmin(): bool
    {
        return $this->tier >= Authorization::SUPERADM && $this->tier <= Authorization::ADM;
    }

    /**
     * Table-level gate: may the principal perform $action on at least some rows
     * of $table? Row-level restrictions (filters, locks) are not considered.
     */
    public function can(string $action, string $table): bool
    {
        if (!in_array($action, self::ACTIONS, true) || !$this->isUsable($table)) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if (!$this->mayEnter()) {
            return false;
        }

        return $this->tierGrantsAll($action)
            || $this->tierGrantsOwn($action)
            || !empty($this->rules()[$table][$action]);
    }

    /**
     * SQL condition selecting the rows of $table on which $action is allowed,
     * ready to be ANDed into a WHERE clause of a query over $table.
     *
     * 'create' has no row dimension: the condition is '1=1' or '1=0'.
     *
     * @return array{0: string, 1: array}  [sql, bound values]; '1=1' = every row, '1=0' = none
     */
    public function rowCondition(string $action, string $table): array
    {
        if (!in_array($action, self::ACTIONS, true) || !$this->isUsable($table)) {
            return ['1=0', []];
        }
        if ($this->isAdmin()) {
            return ['1=1', []];
        }
        if (!$this->mayEnter()) {
            return ['1=0', []];
        }
        if ($action === 'create') {
            return $this->can('create', $table) ? ['1=1', []] : ['1=0', []];
        }

        // ── Grants (OR) ──────────────────────────────────────────────────────
        $grantParts  = [];
        $grantValues = [];
        $grantsAll   = $this->tierGrantsAll($action);

        if (!$grantsAll) {
            if ($this->tierGrantsOwn($action)) {
                $grantParts[]  = "({$table}.creator = ?)";
                $grantValues[] = $this->userId;
            }
            foreach ($this->rules()[$table][$action] ?? [] as $filter) {
                if ($filter === null) {
                    $grantsAll = true;
                    break;
                }
                $compiled = $this->compile($table, $filter);
                if ($compiled === null) {
                    continue; // bad rule: grants nothing
                }
                $grantParts[]  = '(' . $compiled[0] . ')';
                $grantValues   = array_merge($grantValues, $compiled[1]);
            }
        }

        if (!$grantsAll && empty($grantParts)) {
            return ['1=0', []];
        }

        $parts  = $grantsAll ? [] : ['(' . implode(' OR ', $grantParts) . ')'];
        $values = $grantsAll ? [] : $grantValues;

        // ── Locks (AND NOT) ──────────────────────────────────────────────────
        // `id NOT IN (SELECT id … WHERE lock)` is NULL-safe: a row whose filtered
        // column is NULL does not match the lock and is not locked, whereas a bare
        // `NOT (col = x)` would evaluate to NULL and silently hide the row.
        if ($action === 'update' || $action === 'delete') {
            foreach ($this->locks()[$table] ?? [] as $filter) {
                $compiled = $filter === null ? null : $this->compile($table, $filter);
                if ($compiled === null) {
                    return ['1=0', []]; // bad lock: the whole table is locked
                }
                $parts[]  = "{$table}.id NOT IN (SELECT id FROM {$table} WHERE {$compiled[0]})";
                $values   = array_merge($values, $compiled[1]);
            }
        }

        return [$parts ? implode(' AND ', $parts) : '1=1', $values];
    }

    /** Row-level check: may the principal perform $action on record $id of $table? */
    public function rowAllowed(string $action, string $table, int $id): bool
    {
        [$sql, $values] = $this->rowCondition($action, $table);

        if ($sql === '1=0') {
            return false;
        }

        $res = $this->db->query(
            "SELECT COUNT(*) AS n FROM {$table} WHERE {$table}.id = ? AND ({$sql})",
            array_merge([$id], $values),
            'read'
        );

        return (int) ($res[0]['n'] ?? 0) > 0;
    }

    // ── Tier helpers ──────────────────────────────────────────────────────────

    /** Waiting users (tier > ENTER) and unauthenticated requests get nothing, groups or not. */
    private function mayEnter(): bool
    {
        return $this->tier >= Authorization::SUPERADM && $this->tier <= Authorization::ENTER;
    }

    /** Does the tier alone grant $action on every row of every table? */
    private function tierGrantsAll(string $action): bool
    {
        return match ($action) {
            'read'             => $this->tier <= Authorization::READ,
            'create'           => $this->tier <= Authorization::CREATE,
            'update', 'delete' => $this->tier <= Authorization::UPDATE,
            default            => false,
        };
    }

    /** Self writers may update/delete only the records they created. */
    private function tierGrantsOwn(string $action): bool
    {
        return ($action === 'update' || $action === 'delete')
            && $this->tier > Authorization::UPDATE
            && $this->tier <= Authorization::CREATE
            && $this->userId > 0;
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: array}|null  null when the filter cannot be compiled */
    private function compile(string $table, array $filter): ?array
    {
        try {
            return (new JsonFilter($this->cfg, $table, $this->db->getEngine()))
                ->toSql($this->resolveVariables($filter));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveVariables(array $node): array
    {
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = $this->resolveVariables($v);
            } elseif ($v === self::VAR_CURRENT_USER) {
                $node[$k] = $this->userId;
            }
        }
        return $node;
    }

    /** A usable table is a plain identifier configured in the app (it is interpolated into SQL). */
    private function isUsable(string $table): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
            && !empty($this->cfg->get("tables.{$table}"));
    }

    // ── Loading ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, list<?array>>> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        $this->rules = [];

        if (empty($this->groupIds)) {
            return $this->rules;
        }

        $marks = implode(',', array_fill(0, count($this->groupIds), '?'));
        try {
            $rows = $this->db->query(
                "SELECT table_name, action, filter FROM bdus_group_rules WHERE group_id IN ({$marks})",
                array_map('intval', $this->groupIds),
                'read'
            ) ?: [];
        } catch (\Throwable $e) {
            return $this->rules; // table missing (migration pending): no rules
        }

        foreach ($rows as $row) {
            if (!in_array($row['action'], self::ACTIONS, true)) {
                continue;
            }
            $filter = trim((string) ($row['filter'] ?? ''));
            if ($filter === '') {
                $this->rules[$row['table_name']][$row['action']][] = null; // all rows
                continue;
            }
            $decoded = json_decode($filter, true);
            if (is_array($decoded)) {
                $this->rules[$row['table_name']][$row['action']][] = $decoded;
            }
            // Invalid JSON: the rule is dropped (grants nothing).
        }

        return $this->rules;
    }

    /** @return array<string, list<?array>> */
    private function locks(): array
    {
        if ($this->locks !== null) {
            return $this->locks;
        }
        $this->locks = [];

        try {
            $rows = $this->db->query('SELECT table_name, filter FROM bdus_table_locks', [], 'read') ?: [];
        } catch (\Throwable $e) {
            return $this->locks; // table missing (migration pending): no locks
        }

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['filter'] ?? ''), true);
            // Invalid JSON is stored as null, which locks the whole table.
            $this->locks[$row['table_name']][] = is_array($decoded) ? $decoded : null;
        }

        return $this->locks;
    }

    /** @return int[] */
    private static function loadUserGroups(DBInterface $db, int $userId): array
    {
        try {
            $rows = $db->query(
                'SELECT group_id FROM bdus_user_groups WHERE user_id = ?',
                [$userId],
                'read'
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        return array_map('intval', array_column($rows, 'group_id'));
    }
}
