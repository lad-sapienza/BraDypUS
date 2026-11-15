<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use Auth\Access;
use Auth\WriteGate;
use Config\Config;
use DB\DBInterface;

/**
 * Stratigraphic relations between two records of one table (bdus_rs): the
 * Harris matrix. A relation is read "first <relation> second" and is part of
 * both records it joins, so changing it needs the right to change both.
 *
 * Request-independent: the REST endpoints (Record::addRs / deleteRs) and the MCP
 * tools share it. Refusals are returned as code strings, never thrown; only a
 * database failure throws.
 *
 * The relation is stored once, as it was entered; the inverse reading
 * ("second covers first") is the same fact, which is why a duplicate is looked
 * for in both directions.
 */
class Rs
{
    /** Relation code => name (the i18n key of the interface). */
    public const RELATIONS = [
        1  => 'is_covered_by',
        2  => 'is_cut_by',
        3  => 'carries',
        4  => 'is_filled_by',
        5  => 'covers',
        6  => 'cuts',
        7  => 'leans_against',
        8  => 'fills',
        9  => 'is_the_same_as',   // symmetric
        10 => 'is_bound_to',      // symmetric
    ];

    private readonly WriteGate $gate;

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        Access $access,
    ) {
        $this->gate = new WriteGate($db, $cfg, $access);
    }

    /** The reading from the other record's side: 1↔5, 2↔6, 3↔7, 4↔8; 9 and 10 are their own inverse. */
    public static function inverse(int $relation): int
    {
        if ($relation >= 1 && $relation <= 4) {
            return $relation + 4;
        }
        if ($relation >= 5 && $relation <= 8) {
            return $relation - 4;
        }
        return $relation;
    }

    public static function isRelation(int $relation): bool
    {
        return isset(self::RELATIONS[$relation]);
    }

    /** Does the table keep stratigraphic relations (the `rs` plugin is active on it)? */
    public function enabled(string $tb): bool
    {
        return in_array($tb, $this->cfg->get('tables.*.name') ?: [], true)
            && (bool) $this->cfg->get("tables.{$tb}.rs");
    }

    /**
     * Everything add() checks, without writing.
     *
     * @return string|null  the code of the refusal, or null when the relation could be added
     */
    public function checkAdd(string $tb, int $first, int $relation, int $second): ?string
    {
        if (!$this->enabled($tb)) {
            return 'rs_not_configured';
        }
        if (!self::isRelation($relation)) {
            return 'invalid_relation';
        }
        if ($first === $second) {
            return 'relation_with_itself';
        }
        // A relation is part of both records it joins.
        if (!$this->gate->mayChange('update', $tb, $first) || !$this->gate->mayChange('update', $tb, $second)) {
            return 'not_enough_privilege';
        }
        // After the gate: whether a record exists is not told to someone who may not change it.
        if (!$this->recordExists($tb, $first) || !$this->recordExists($tb, $second)) {
            return 'record_not_found';
        }
        if ($this->exists($tb, $first, $relation, $second)) {
            return 'relation_already_exist';
        }
        return null;
    }

    /**
     * Adds a relation.
     *
     * @return array  { status: 'success', id } or { status: 'error', code }
     * @throws \Throwable when the database refuses the insert
     */
    public function add(string $tb, int $first, int $relation, int $second): array
    {
        $code = $this->checkAdd($tb, $first, $relation, $second);
        if ($code !== null) {
            return ['status' => 'error', 'code' => $code];
        }
        $id = (int) $this->db->query(
            'INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)',
            [$tb, $first, $second, $relation],
            'id'
        );
        return ['status' => 'success', 'id' => $id];
    }

    /** The relation stored under $id, or null. */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT id, tb, first, second, relation FROM bdus_rs WHERE id = ?', [$id], 'read')[0] ?? null;
        return $row === null ? null : [
            'id' => (int) $row['id'], 'tb' => (string) $row['tb'],
            'first' => (int) $row['first'], 'second' => (int) $row['second'], 'relation' => (int) $row['relation'],
        ];
    }

    /**
     * Everything remove() checks, without writing.
     *
     * @return string|null  the code of the refusal, or null when the relation could be removed
     */
    public function checkRemove(int $id): ?string
    {
        $rel = $this->find($id);
        if ($rel === null) {
            return 'not_found';
        }
        if (!$this->gate->mayChange('update', $rel['tb'], $rel['first']) || !$this->gate->mayChange('update', $rel['tb'], $rel['second'])) {
            return 'not_enough_privilege';
        }
        return null;
    }

    /**
     * Removes a relation.
     *
     * @return array  { status: 'success' } or { status: 'error', code }
     * @throws \Throwable when the database refuses the delete
     */
    public function remove(int $id): array
    {
        $code = $this->checkRemove($id);
        if ($code !== null) {
            return ['status' => 'error', 'code' => $code];
        }
        $this->db->query('DELETE FROM bdus_rs WHERE id = ?', [$id], 'boolean');
        return ['status' => 'success'];
    }

    /** The id of the row that already states this fact, in either direction; null when there is none. */
    public function findSame(string $tb, int $first, int $relation, int $second): ?int
    {
        $row = $this->db->query(
            'SELECT id FROM bdus_rs
             WHERE tb = ? AND ((first = ? AND second = ? AND relation = ?) OR (first = ? AND second = ? AND relation = ?))',
            [$tb, $first, $second, $relation, $second, $first, self::inverse($relation)],
            'read'
        )[0] ?? null;
        return $row === null ? null : (int) $row['id'];
    }

    /** Is the same fact already stored, in either direction? */
    public function exists(string $tb, int $first, int $relation, int $second): bool
    {
        return $this->findSame($tb, $first, $relation, $second) !== null;
    }

    private function recordExists(string $tb, int $id): bool
    {
        return (bool) $this->db->query("SELECT 1 FROM {$tb} WHERE id = ?", [$id], 'read');
    }
}
