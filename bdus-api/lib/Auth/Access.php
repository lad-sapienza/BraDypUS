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
 * Rule and lock filters are JSON filters (SQL\Filter\JsonFilter). Variables,
 * replaced before the filter is compiled: `$CURRENT_USER` (user id),
 * `$CURRENT_GROUPS` (ids of the principal's groups) and `$GROUP.<key>` (the
 * values of the group attribute <key>, united over the principal's groups).
 * A list-valued variable turns `_eq`/`_neq` into `_in`/`_nin`. A filter that
 * cannot be compiled (unknown field, invalid JSON, unknown variable, attribute
 * not set) fails closed: a bad rule grants nothing, a bad lock locks the
 * whole table.
 *
 * Create and update rules can also carry an `enforce` map {field: value|variable|list}
 * (#51): the server accepts only those values for the field, and on create fills
 * in the field when the client leaves it empty and a single value is possible.
 * See enforceValues(). Application status (frozen/off) is not handled here: it stays in
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

    /**
     * `table_name` of a rule that applies to every data table (a configured
     * table, never a `bdus_*` one). Lets a group stand for a whole privilege
     * level ("read everything") without a rule per table, new tables included.
     */
    public const ALL_TABLES = '*';

    /** Variable replaced by the list of the principal's group ids. */
    public const VAR_CURRENT_GROUPS = '$CURRENT_GROUPS';

    /** Prefix of the variables replaced by the values of a group attribute: `$GROUP.<key>`. */
    public const VAR_GROUP_PREFIX = '$GROUP.';

    private const VARIABLE_RE = '/^\$(CURRENT_USER|CURRENT_GROUPS|GROUP\.([A-Za-z0-9_]+))$/';

    /**
     * Values the server stamps on a new record; only in the `enforce` map of a
     * create rule, never in filters: `$NOW` (Y-m-d H:i:s), `$TODAY` (Y-m-d) and
     * `$KEY_ID` (id of the API key making the request; a person has none, so a
     * rule using it grants nothing to people).
     */
    public const STAMPS = ['$NOW', '$TODAY', '$KEY_ID'];

    /** @var array<string, array<string, list<array{filter: ?array, enforce: ?array}>>>|null  table => action => rules */
    private ?array $enforceRules = null;

    /** @var array<string, list<string|int|float>>|null  group attribute => values, united over the principal's groups */
    private ?array $groupAttrs = null;

    /** @var array<string, array<string, list<?array>>>|null  table => action => filters (null = all rows) */
    private ?array $rules = null;

    /** @var array<string, list<?array>>|null  table => filters (null = unparsable → lock everything) */
    private ?array $locks = null;

    /**
     * @param int            $tier     Global privilege tier of the principal
     * @param int            $userId   User id (0 for API keys)
     * @param int[]|\Closure $groupIds Groups the principal belongs to, or a closure
     *                                 returning them (called at most once, and only
     *                                 when rules are actually needed — admins never load them)
     */
    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        private readonly int $tier,
        private readonly int $userId,
        private array|\Closure $groupIds = [],
        private readonly ?int $keyId = null
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
            $groupIds = fn(): array => self::loadUserGroups($db, $userId);
        }

        $keyId = CurrentUser::isApiKey() && CurrentUser::get('key_id') ? (int) CurrentUser::get('key_id') : null;

        return new self($db, $cfg, $tier, $userId, $groupIds, $keyId);
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
     *
     * $includeOwn = false leaves out the self writer's "own records" grant, for
     * callers that already evaluated the tier (and the record's creator) themselves.
     */
    public function can(string $action, string $table, bool $includeOwn = true): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if (!$this->mayEnter()) {
            return false;
        }
        if ($this->tierGrantsAll($action) || ($includeOwn && $this->tierGrantsOwn($action))) {
            return true;
        }

        return $this->isUsable($table) && !empty($this->rulesFor($table, $action));
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
        if (!in_array($action, self::ACTIONS, true)) {
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
        // Nothing withheld and nothing interpolated: no need for a configured table.
        if ($action === 'read' && $this->tierGrantsAll('read')) {
            return ['1=1', []];
        }
        // From here the table name ends up in SQL.
        if (!$this->isUsable($table)) {
            return ['1=0', []];
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
            foreach ($this->rulesFor($table, $action) as $filter) {
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
        if ($action === 'update' || $action === 'delete') {
            $locks = $this->lockParts($table);
            if ($locks === null) {
                return ['1=0', []]; // bad lock: the whole table is locked
            }
            $parts  = array_merge($parts, $locks[0]);
            $values = array_merge($values, $locks[1]);
        }

        return [$parts ? implode(' AND ', $parts) : '1=1', $values];
    }

    /**
     * The conditions a row must meet to be outside every lock of $table, as
     * [sql parts to AND, values]; null when a lock cannot be compiled (the whole
     * table counts as locked).
     *
     * `id NOT IN (SELECT id … WHERE lock)` is NULL-safe: a row whose filtered
     * column is NULL does not match the lock and is not locked, whereas a bare
     * `NOT (col = x)` would evaluate to NULL and silently hide the row.
     *
     * @return array{0: string[], 1: array}|null
     */
    private function lockParts(string $table): ?array
    {
        $parts  = [];
        $values = [];
        foreach ($this->locks()[$table] ?? [] as $filter) {
            $compiled = $filter === null ? null : $this->compile($table, $filter);
            if ($compiled === null) {
                return null;
            }
            $parts[]  = "{$table}.id NOT IN (SELECT id FROM {$table} WHERE {$compiled[0]})";
            $values   = array_merge($values, $compiled[1]);
        }
        return [$parts, $values];
    }

    /**
     * Is record $id of $table frozen by a lock for this principal? Admins are
     * never locked out (unless $includeAdmins asks whether the record is frozen
     * at all, e.g. to show a badge). Says nothing about grants: it is the lock
     * alone, for callers that keep the tier's own check (e.g. adding a plugin row).
     */
    public function isLocked(string $table, int $id, bool $includeAdmins = false): bool
    {
        if (($this->isAdmin() && !$includeAdmins) || !$this->isUsable($table)) {
            return false;
        }
        $locks = $this->lockParts($table);
        if ($locks === null) {
            return true;
        }
        if (empty($locks[0])) {
            return false;
        }

        $res = $this->db->query(
            "SELECT COUNT(*) AS n FROM {$table} WHERE {$table}.id = ? AND NOT (" . implode(' AND ', $locks[0]) . ')',
            array_merge([$id], $locks[1]),
            'read'
        );
        return (int) ($res[0]['n'] ?? 0) > 0;
    }

    /**
     * Does the principal have a grant for $action on at least one table, through
     * its tier or a group rule? For endpoints that span several tables and
     * filter them one by one.
     */
    public function canAnyTable(string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if (!$this->mayEnter()) {
            return false;
        }
        if ($this->tierGrantsAll($action) || $this->tierGrantsOwn($action)) {
            return true;
        }
        foreach ($this->rules() as $byAction) {
            if (!empty($byAction[$action])) {
                return true;
            }
        }
        return false;
    }

    /** True when no row of $table is withheld from the principal for $action (no query needed). */
    public function unrestricted(string $action, string $table): bool
    {
        return $this->rowCondition($action, $table) === ['1=1', []];
    }

    /**
     * The subset of $ids (records of $table) on which the principal may read.
     *
     * @param  int[] $ids
     * @return int[]
     */
    public function readableIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        [$sql, $values] = $this->rowCondition('read', $table);
        if ($sql === '1=0') {
            return [];
        }
        if ($sql === '1=1') {
            return $ids;
        }

        $readable = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows  = $this->db->query(
                "SELECT {$table}.id AS id FROM {$table} WHERE {$table}.id IN ({$marks}) AND ({$sql})",
                array_merge($chunk, $values),
                'read'
            ) ?: [];
            foreach ($rows as $r) {
                $readable[] = (int) $r['id'];
            }
        }
        return $readable;
    }

    /** Row-level check: may the principal perform $action on record $id of $table? */
    public function rowAllowed(string $action, string $table, int $id): bool
    {
        if (!$this->isUsable($table)) {
            return false;
        }

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

    /**
     * Applies the `enforce` maps (#51) to the values about to be written.
     *
     * Only where a group rule is the source of the grant: admins and a tier
     * that grants $action on every table are never restricted. Among the rules
     * that grant $action on $table (for update, only those whose filter matches
     * record $recordId) one is enough, so a rule without `enforce` lifts the
     * restriction, like a self writer's own record does.
     *
     * For each enforced field the value must be one of the allowed values. A
     * field left out is filled in on create when exactly one value is allowed,
     * and refused when several are (or none); on update it stays as it is.
     * An empty value counts as left out on create. With $coerce (a copy of an
     * existing record, whose values did not come from the client) a value that is
     * not allowed is replaced when exactly one value is, and refused otherwise.
     *
     * @param  'create'|'update'    $action
     * @param  array<string,mixed>  $values    field => value being written
     * @param  int|null             $recordId  the record being updated; null on create
     * @return array<string,mixed>|null  $values, completed; null when a value is not allowed
     */
    public function enforceValues(string $action, string $table, array $values, ?int $recordId = null, bool $coerce = false): ?array
    {
        if (($action !== 'create' && $action !== 'update') || $this->isAdmin() || !$this->mayEnter()) {
            return $values;
        }
        if ($this->tierGrantsAll($action)) {
            return $values;
        }
        if ($action === 'update' && $this->tierGrantsOwn($action) && $recordId && $this->isCreator($table, $recordId)) {
            return $values;
        }

        $this->rules();
        $candidates = array_merge(
            $this->enforceRules[$table][$action] ?? [],
            $this->enforceRules[self::ALL_TABLES][$action] ?? []
        );
        if (empty($candidates)) {
            return $values; // not granted by a rule: the caller's gate decides
        }

        $ctx = ['user' => $this->userId, 'groups' => $this->groupIds(), 'attrs' => $this->groupAttrs()];
        if ($action === 'create') {
            $ctx['stamps'] = ['now' => time(), 'key' => $this->keyId];
        }

        foreach ($candidates as $rule) {
            if ($action === 'update' && !$this->ruleMatchesRecord($table, $rule['filter'], (int) $recordId)) {
                continue;
            }
            if (empty($rule['enforce'])) {
                return $values; // this rule restricts nothing
            }
            $done = $this->satisfy($action, $rule['enforce'], $values, $ctx, $coerce);
            if ($done !== null) {
                return $done;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function satisfy(string $action, array $enforce, array $values, array $ctx, bool $coerce): ?array
    {
        foreach ($enforce as $field => $spec) {
            try {
                $allowed = self::expand($spec, $ctx);
            } catch (\Throwable $e) {
                return null; // unknown variable / attribute not set: this rule grants nothing
            }
            $sent = array_key_exists($field, $values)
                && !($action === 'create' && ($values[$field] === null || $values[$field] === ''));

            if (!$sent) {
                if ($action === 'update') {
                    continue;
                }
                if (count($allowed) !== 1) {
                    return null;
                }
                $values[$field] = $allowed[0];
                continue;
            }
            if (!is_scalar($values[$field]) || is_bool($values[$field])) {
                return null;
            }
            if (!in_array((string) $values[$field], array_map('strval', $allowed), true)) {
                if (!$coerce || count($allowed) !== 1) {
                    return null;
                }
                $values[$field] = $allowed[0];
            }
        }
        return $values;
    }

    /** Does record $id of $table match the filter of a rule (null = every row)? */
    private function ruleMatchesRecord(string $table, ?array $filter, int $id): bool
    {
        if ($filter === null) {
            return true;
        }
        if (!$this->isUsable($table) || $id <= 0) {
            return false;
        }
        $compiled = $this->compile($table, $filter);
        if ($compiled === null) {
            return false;
        }
        $res = $this->db->query(
            "SELECT COUNT(*) AS n FROM {$table} WHERE {$table}.id = ? AND ({$compiled[0]})",
            array_merge([$id], $compiled[1]),
            'read'
        );
        return (int) ($res[0]['n'] ?? 0) > 0;
    }

    private function isCreator(string $table, int $id): bool
    {
        if (!$this->isUsable($table) || $this->userId <= 0) {
            return false;
        }
        try {
            $res = $this->db->query("SELECT creator FROM {$table} WHERE id = ?", [$id], 'read');
        } catch (\Throwable $e) {
            return false; // no creator column (plugin table)
        }
        return (int) ($res[0]['creator'] ?? 0) === $this->userId;
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
        return self::substitute($node, [
            'user'   => $this->userId,
            'groups' => $this->groupIds(),
            'attrs'  => $this->groupAttrs(),
        ]);
    }

    /**
     * Checks that a rule or lock filter can be compiled for $table (known
     * fields and operators, well-formed structure, known variables). Used when
     * filters are saved: a filter that fails here would fail closed at run time.
     * Group attributes are not known at that point: `$GROUP.<key>` is accepted
     * for any key.
     *
     * @return string|null  null when valid, else why it is not
     */
    public static function checkFilter(DBInterface $db, Config $cfg, string $table, array $filter): ?string
    {
        try {
            $filter = self::substitute($filter, ['user' => 0, 'groups' => [0], 'attrs' => null]);
            (new JsonFilter($cfg, $table, $db->getEngine()))->toSql($filter);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Checks the `enforce` map of a create/update rule: every key is a field of
     * $table, every value a literal, a known variable or a list of those.
     *
     * @return string|null  null when valid, else why it is not
     */
    public static function checkEnforce(Config $cfg, string $table, array $enforce, string $action = 'create'): ?string
    {
        $fields = $cfg->get("tables.{$table}.fields") ?? [];
        $names  = array_column(is_array($fields) ? $fields : [], 'name');
        foreach ($enforce as $field => $value) {
            if (!is_string($field) || !in_array($field, $names, true)) {
                return "unknown field '{$field}'";
            }
            if (in_array($field, ['id', 'creator'], true)) {
                return "field '{$field}' cannot be enforced";
            }
            try {
                self::expand($value, [
                    'user' => 0, 'groups' => [0], 'attrs' => null,
                    // server stamps make sense on a new record only: on update the form sends the stored value back
                    'stamps' => $action === 'create' ? ['now' => 0, 'key' => 0] : null,
                ]);
            } catch (\Throwable $e) {
                return "{$field}: " . $e->getMessage();
            }
        }
        return null;
    }

    /**
     * Value of a variable string, a list for list-valued ones; null when the
     * string is not a variable. Anything that looks like a variable but is not
     * a known one, or a group attribute nobody sets, throws (fails closed).
     *
     * @param  array{user: int, groups: int[], attrs: ?array} $ctx  attrs null = validation only
     * @return int|string|array|null
     * @throws \InvalidArgumentException
     */
    private static function variableValue(string $s, array $ctx): int|string|array|null
    {
        if (in_array($s, self::STAMPS, true)) {
            $st = $ctx['stamps'] ?? null;
            if ($st === null) {
                throw new \InvalidArgumentException("{$s} is only available when creating a record");
            }
            if ($s === '$KEY_ID') {
                if (empty($st['key']) && $ctx['attrs'] !== null) {
                    throw new \InvalidArgumentException('$KEY_ID needs an API key');
                }
                return $st['key'] ?: 0;
            }
            return date($s === '$NOW' ? 'Y-m-d H:i:s' : 'Y-m-d', $st['now']);
        }
        if (!str_starts_with($s, '$CURRENT_') && !str_starts_with($s, '$GROUP')) {
            return null;
        }
        if (!preg_match(self::VARIABLE_RE, $s, $m)) {
            throw new \InvalidArgumentException("unknown variable {$s}");
        }
        if ($m[1] === 'CURRENT_USER') {
            return $ctx['user'];
        }
        if ($m[1] === 'CURRENT_GROUPS') {
            return $ctx['groups'];
        }
        if ($ctx['attrs'] === null) {
            return ['x'];
        }
        if (empty($ctx['attrs'][$m[2]])) {
            throw new \InvalidArgumentException("group attribute {$m[2]} is not set");
        }
        return $ctx['attrs'][$m[2]];
    }

    /**
     * Replaces the variables of a filter. A list-valued variable under `_eq` /
     * `_neq` turns the operator into `_in` / `_nin`, inside a `_in` / `_nin`
     * list it is spliced in, and under any other operator it throws.
     *
     * @param array{user: int, groups: int[], attrs: ?array} $ctx
     */
    private static function substitute(array $node, array $ctx): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::substitute($v, $ctx);
                continue;
            }
            $val = is_string($v) ? self::variableValue($v, $ctx) : null;
            if ($val === null) {
                $out[$k] = $v;
            } elseif (!is_array($val)) {
                $out[$k] = $val;
            } elseif (is_int($k)) {
                foreach ($val as $item) {
                    $out[] = $item; // list element of _in / _nin
                }
            } else {
                $newKey = match ($k) {
                    '_eq'        => '_in',
                    '_neq'       => '_nin',
                    '_in', '_nin' => $k,
                    default      => throw new \InvalidArgumentException("list variable {$v} needs _eq, _neq, _in or _nin"),
                };
                $out[$newKey] = $val;
            }
        }
        return $out;
    }

    /**
     * Allowed values of an `enforce` entry: a literal, a variable or a list of
     * those, flattened.
     *
     * @param array{user: int, groups: int[], attrs: ?array} $ctx
     * @return list<string|int|float>
     * @throws \InvalidArgumentException
     */
    private static function expand(mixed $value, array $ctx): array
    {
        $items = is_array($value) ? $value : [$value];
        $out   = [];
        foreach ($items as $item) {
            if (is_array($item) || is_bool($item) || $item === null) {
                throw new \InvalidArgumentException('values must be text, numbers or variables');
            }
            $val = is_string($item) ? self::variableValue($item, $ctx) : null;
            if ($val === null) {
                $out[] = $item;
            } elseif (is_array($val)) {
                array_push($out, ...$val);
            } else {
                $out[] = $val;
            }
        }
        return $out;
    }

    /**
     * Attributes of the principal's groups: key => values, united over the
     * groups (a scalar attribute counts as a one-item list). Loaded once.
     *
     * @return array<string, list<string|int|float>>
     */
    private function groupAttrs(): array
    {
        if ($this->groupAttrs !== null) {
            return $this->groupAttrs;
        }
        $this->groupAttrs = [];

        $groupIds = $this->groupIds();
        if (empty($groupIds)) {
            return $this->groupAttrs;
        }
        $marks = implode(',', array_fill(0, count($groupIds), '?'));
        try {
            $rows = $this->db->query(
                "SELECT attrs FROM bdus_groups WHERE id IN ({$marks})",
                $groupIds,
                'read'
            ) ?: [];
        } catch (\Throwable $e) {
            return $this->groupAttrs; // M049 pending: no attributes
        }

        foreach ($rows as $row) {
            $attrs = json_decode((string) ($row['attrs'] ?? ''), true);
            if (!is_array($attrs)) {
                continue; // none or unreadable: no attributes for this group
            }
            foreach ($attrs as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                foreach ((is_array($value) ? $value : [$value]) as $item) {
                    if (is_scalar($item) && !is_bool($item) && $item !== '' &&
                        !in_array($item, $this->groupAttrs[$key] ?? [], true)) {
                        $this->groupAttrs[$key][] = $item;
                    }
                }
            }
        }
        return $this->groupAttrs;
    }

    /** A usable table is a plain identifier configured in the app (it is interpolated into SQL). */
    private function isUsable(string $table): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
            && !empty($this->cfg->get("tables.{$table}"));
    }

    // ── Loading ───────────────────────────────────────────────────────────────

    /**
     * Filters of the rules that grant $action on $table: the ones written for the
     * table itself and the ones written for every table (`*`). null = all rows.
     *
     * @return list<?array>
     */
    private function rulesFor(string $table, string $action): array
    {
        $rules = $this->rules();
        return array_merge($rules[$table][$action] ?? [], $rules[self::ALL_TABLES][$action] ?? []);
    }

    /**
     * Templates (record layouts) the principal's groups oblige them to use on
     * $table, or null when they are free to choose (no group assigns one, or the
     * principal is an admin). Interface convenience, not a permission: see Template\Assign.
     *
     * @return string[]|null
     */
    public function layouts(string $table, string $appName): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        return \Template\Assign::allowed($this->db, $appName, $table, $this->groupIds());
    }

    /** @return int[] */
    private function groupIds(): array
    {
        if ($this->groupIds instanceof \Closure) {
            $this->groupIds = ($this->groupIds)();
        }
        return array_map('intval', $this->groupIds);
    }

    /** @return array<string, array<string, list<?array>>> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        $this->rules        = [];
        $this->enforceRules = [];

        $groupIds = $this->groupIds();
        if (empty($groupIds)) {
            return $this->rules;
        }

        $marks = implode(',', array_fill(0, count($groupIds), '?'));
        try {
            try {
                $rows = $this->db->query(
                    "SELECT table_name, action, filter, enforce FROM bdus_group_rules WHERE group_id IN ({$marks})",
                    $groupIds,
                    'read'
                ) ?: [];
            } catch (\Throwable $e) {
                // M049 pending (no `enforce` column yet): rules keep working, nothing is enforced.
                $rows = $this->db->query(
                    "SELECT table_name, action, filter FROM bdus_group_rules WHERE group_id IN ({$marks})",
                    $groupIds,
                    'read'
                ) ?: [];
            }
        } catch (\Throwable $e) {
            return $this->rules; // table missing (migration pending): no rules
        }

        foreach ($rows as $row) {
            if (!in_array($row['action'], self::ACTIONS, true)) {
                continue;
            }

            $enforce    = null;
            $enforceRaw = trim((string) ($row['enforce'] ?? ''));
            if ($enforceRaw !== '') {
                $enforce = json_decode($enforceRaw, true);
                if (!is_array($enforce)) {
                    continue; // unreadable enforce map: the rule is dropped (grants nothing)
                }
            }

            $filter = trim((string) ($row['filter'] ?? ''));
            if ($filter === '') {
                $this->rules[$row['table_name']][$row['action']][] = null; // all rows
                $this->enforceRules[$row['table_name']][$row['action']][] = ['filter' => null, 'enforce' => $enforce ?: null];
                continue;
            }
            $decoded = json_decode($filter, true);
            if (is_array($decoded)) {
                $this->rules[$row['table_name']][$row['action']][] = $decoded;
                $this->enforceRules[$row['table_name']][$row['action']][] = ['filter' => $decoded, 'enforce' => $enforce ?: null];
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
