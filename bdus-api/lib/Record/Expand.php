<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use Config\Config;
use DB\DBInterface;

/**
 * Directus-style `fields` for GET|POST /api/records/{tb}: plain columns plus
 * related rows nested in each result row.
 *
 *   fields=id,name,tags.*,cat_ref.name,items_tags:tags.label
 *
 * Token grammar (comma separated, or an array of tokens):
 *   column                  a column of the main table
 *   rel.*                   every configured field of the related table (+ id)
 *   rel.column              one column of the related table (+ id); repeatable
 *   alias:rel.*             the same, under the key `alias` in each row
 *
 * `rel` is one of
 *   - a plugin table of the main table  → list of rows, under the key (alias or name);
 *   - a field with `id_from_tb`          → the referenced row replaces the id;
 *   - a table whose rows point at this one through a declared relation (the
 *     inverse of a lookup, e.g. `finds` for `complexes`) → list of those rows.
 *     When the other table points here through several columns, name one:
 *     `finds~complex.*`.
 *
 * Only one level of nesting is supported (`rel.sub.col` is refused).
 *
 * Permissions (never wider than the direct request to the related table):
 *   - plugin rows follow their record (same rule as GET /api/record/{tb}/{id});
 *     the main rows are already restricted to the readable ones;
 *   - a lookup is expanded only when the principal may read the referenced table
 *     and that very row; otherwise the raw id stays, as in a plain list;
 *   - an inverse relation is omitted when the table is not readable, and lists
 *     only the rows the principal may read.
 *
 * Cost: one query per relation (IN lists of at most CHUNK ids), never one per row.
 */
final class Expand
{
    /** Ids per IN (...) list: below every engine's bound-parameter limit. */
    private const CHUNK = 500;

    /** Cap on the nested rows of one response; above it the request is refused. */
    public const MAX_NESTED_ROWS = 50000;

    /** @var string[] main-table columns asked for (never `id`) */
    private array $columns = [];

    /**
     * key (alias or relation name) =>
     *   ['kind' => 'plugin'|'fk'|'inverse', 'rel' => table,
     *    'via' => id_link | lookup column of the main table | column of `rel` pointing here,
     *    'cols' => string[] requested columns, 'all' => bool]
     * @var array<string, array>
     */
    private array $nodes = [];

    private int $nested = 0;

    private function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        private readonly string $tb
    ) {}

    /**
     * @param string|string[] $fields
     * @throws \InvalidArgumentException on a request that cannot be understood
     */
    public static function parse(string|array $fields, DBInterface $db, Config $cfg, string $tb): self
    {
        $self   = new self($db, $cfg, $tb);
        $tokens = is_array($fields) ? $fields : explode(',', $fields);
        $own    = self::fieldNames($cfg, $tb);

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            $alias = null;
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):(.+)$/', $token, $m)) {
                [, $alias, $token] = $m;
            }

            $path = explode('.', $token);
            if (count($path) > 2) {
                throw new \InvalidArgumentException("Nesting deeper than one level is not supported: '{$token}'");
            }

            if (count($path) === 1) {
                if ($alias !== null) {
                    throw new \InvalidArgumentException("An alias needs a relation: '{$alias}:{$token}'");
                }
                // Unknown names are dropped, as for `columns` (stale client preferences).
                $col = preg_replace('/[^a-zA-Z0-9_]/', '', $path[0]);
                if ($col && $col !== 'id' && in_array($col, $own, true) && !in_array($col, $self->columns, true)) {
                    $self->columns[] = $col;
                }
                continue;
            }

            [$rel, $sel] = $path;
            // The key defaults to the table name, without a `~column` selector.
            $self->addNode($rel, $sel, $alias ?? explode('~', $rel, 2)[0], $own);
        }

        // A lookup is replaced in place: its column must be read from the main table.
        foreach ($self->nodes as $node) {
            if ($node['kind'] === 'fk' && !in_array($node['via'], $self->columns, true)) {
                $self->columns[] = $node['via'];
            }
        }

        return $self;
    }

    /** Main-table columns to select (without `id`, which is always selected). */
    public function mainColumns(): array
    {
        return $this->columns;
    }

    /**
     * Nests the related rows into $rows (a page of getRecords()).
     *
     * @param  array[] $rows
     * @return array[]
     * @throws \LengthException when the nested rows exceed MAX_NESTED_ROWS
     */
    public function apply(array $rows): array
    {
        $this->nested = 0;
        if (!$this->nodes || !$rows) {
            return $rows;
        }

        $access = \Auth\Access::forCurrentUser($this->db, $this->cfg);

        foreach ($this->nodes as $key => $node) {
            $rows = match ($node['kind']) {
                'plugin'  => $this->applyPlugin($rows, $key, $node),
                'fk'      => $this->applyLookup($rows, $key, $node, $access),
                'inverse' => $this->applyInverse($rows, $key, $node, $access),
            };
        }
        return $rows;
    }

    // ── Parsing helpers ──────────────────────────────────────────────────────

    private function addNode(string $rel, string $sel, string $key, array $own): void
    {
        $plugins = (array) ($this->cfg->get("tables.{$this->tb}.plugin") ?? []);

        // `table~column` names the pointing column when there are several.
        $pick = null;
        if (str_contains($rel, '~')) {
            [$rel, $pick] = explode('~', $rel, 2);
        }

        if ($pick === null && in_array($rel, $plugins, true)) {
            $kind = 'plugin';
            $via  = 'id_link';
            $to   = $rel;
        } elseif ($pick === null && in_array($rel, $own, true)
            && $this->cfg->get("tables.{$this->tb}.fields.{$rel}.id_from_tb")) {
            $kind = 'fk';
            $via  = $rel;
            $to   = $this->cfg->get("tables.{$this->tb}.fields.{$rel}.id_from_tb");
        } elseif (($cols = $this->inverseColumns()[$rel] ?? []) !== []) {
            if ($pick === null && count($cols) > 1) {
                throw new \InvalidArgumentException(
                    "'{$rel}' points at '{$this->tb}' through several columns; name one: "
                    . implode(', ', array_map(fn($c) => "{$rel}~{$c}", $cols))
                );
            }
            if ($pick !== null && !in_array($pick, $cols, true)) {
                throw new \InvalidArgumentException("'{$rel}' has no relation to '{$this->tb}' through '{$pick}'");
            }
            $kind = 'inverse';
            $via  = $pick ?? $cols[0];
            $to   = $rel;
        } else {
            throw new \InvalidArgumentException("Unknown relation '{$rel}' for table '{$this->tb}'");
        }

        if (in_array($key, $own, true) && !($kind === 'fk' && $key === $rel)) {
            throw new \InvalidArgumentException("Alias '{$key}' clashes with a column of '{$this->tb}'");
        }
        if (isset($this->nodes[$key]) && ($this->nodes[$key]['rel'] !== $to || $this->nodes[$key]['via'] !== $via)) {
            throw new \InvalidArgumentException("Key '{$key}' is used for two different relations");
        }

        $node = $this->nodes[$key] ?? ['kind' => $kind, 'rel' => $to, 'via' => $via, 'cols' => [], 'all' => false];

        if ($sel === '*') {
            $node['all'] = true;
        } else {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $sel);
            if ($col && $col !== 'id' && in_array($col, self::fieldNames($this->cfg, $to), true)
                && !in_array($col, $node['cols'], true)) {
                $node['cols'][] = $col;
            }
        }
        $this->nodes[$key] = $node;
    }

    /**
     * Tables whose rows point at this one: [table => [column, ...]].
     *
     * Read from the declared relations (`tables.{tb}.link`, built from
     * bdus_cfg_relations): single-column references to `id`, never a plugin
     * table (those are reached as plugins).
     *
     * @return array<string, string[]>
     */
    private function inverseColumns(): array
    {
        $out = [];
        foreach ((array) ($this->cfg->get("tables.{$this->tb}.link") ?? []) as $link) {
            $other = $link['other_tb'] ?? null;
            $pairs = $link['fld'] ?? [];
            if (!$other || $other === $this->tb || !$pairs || $this->cfg->get("tables.{$other}.is_plugin")) {
                continue;
            }
            // LoadFromDB groups every relation between two tables into one entry, so
            // several pairs all ending on `id` are several columns, each its own relation.
            // A pair that does not end on `id` is a composite reference: not handled.
            if (array_filter($pairs, fn($p) => ($p['my'] ?? null) !== 'id')) {
                continue;
            }
            foreach ($pairs as $p) {
                $col = $p['other'] ?? null;
                if ($col && in_array($col, self::fieldNames($this->cfg, $other), true)) {
                    $out[$other][] = $col;
                }
            }
        }
        return $out;
    }

    /** Configured field names of a table, in config order. */
    private static function fieldNames(Config $cfg, string $tb): array
    {
        return array_values(array_map(
            fn($f) => $f['name'],
            array_filter((array) ($cfg->get("tables.{$tb}.fields") ?? []), 'is_array')
        ));
    }

    /** Columns to SELECT for a node, `id` first. */
    private function selection(array $node): array
    {
        $cols = $node['all'] ? self::fieldNames($this->cfg, $node['rel']) : $node['cols'];
        $cols = array_values(array_diff(array_unique($cols), ['id']));
        return array_merge(['id'], $cols);
    }

    // ── Plugin rows ──────────────────────────────────────────────────────────

    private function applyPlugin(array $rows, string $key, array $node): array
    {
        $tb      = $node['rel'];
        $wanted  = $this->selection($node);
        $hasSort = in_array('sort', self::fieldNames($this->cfg, $tb), true);
        // id_link groups the rows; sort orders them (like Record\Read::getPlugin).
        $select  = array_values(array_unique(array_merge($wanted, ['id_link'], $hasSort ? ['sort'] : [])));

        $ids = array_values(array_unique(array_filter(array_map(fn($r) => $this->idOf($r), $rows))));

        $grouped = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $found = $this->db->query(
                'SELECT ' . implode(', ', $select) . " FROM {$tb} WHERE id_link IN ({$marks}) ORDER BY id",
                $chunk,
                'read'
            ) ?: [];
            $this->count(count($found));
            foreach ($found as $f) {
                $grouped[(int) $f['id_link']][] = $f;
            }
        }

        foreach ($grouped as &$list) {
            if ($hasSort) {
                usort($list, fn($a, $b) => $a['sort'] === $b['sort'] ? 0 : ($a['sort'] > $b['sort'] ? 1 : -1));
            }
            $list = array_map(fn($f) => array_intersect_key($f, array_flip($wanted)), $list);
        }
        unset($list);

        foreach ($rows as &$row) {
            $row[$key] = $grouped[$this->idOf($row)] ?? [];
        }
        unset($row);
        return $rows;
    }

    // ── Inverse relation: rows of another table pointing at this one ─────────

    private function applyInverse(array $rows, string $key, array $node, \Auth\Access $access): array
    {
        $tb  = $node['rel'];
        $col = $node['via'];

        // Not readable at all: the key is left out (an empty list would confirm the table exists and is empty).
        if (!$access->can('read', $tb)) {
            return $rows;
        }

        $wanted = $this->selection($node);
        $select = array_values(array_unique(array_merge($wanted, [$col])));
        $ids    = array_values(array_unique(array_filter(array_map(fn($r) => $this->idOf($r), $rows))));

        [$cond, $condValues] = $access->rowCondition('read', $tb);
        $grouped = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $found = $this->db->query(
                'SELECT ' . implode(', ', $select) . " FROM {$tb} WHERE {$tb}.{$col} IN ({$marks}) AND ({$cond}) ORDER BY {$tb}.id",
                array_merge($chunk, $condValues),
                'read'
            ) ?: [];
            $this->count(count($found));
            foreach ($found as $f) {
                $grouped[(int) $f[$col]][] = array_intersect_key($f, array_flip($wanted));
            }
        }

        foreach ($rows as &$row) {
            $row[$key] = $grouped[$this->idOf($row)] ?? [];
        }
        unset($row);
        return $rows;
    }

    // ── Lookup (id_from_tb) ──────────────────────────────────────────────────

    private function applyLookup(array $rows, string $key, array $node, \Auth\Access $access): array
    {
        $tb  = $node['rel'];
        $via = $node['via'];

        // No access to the referenced table: the raw id stays, as in a plain list.
        if (!$access->can('read', $tb)) {
            return $rows;
        }

        $ids = [];
        foreach ($rows as $row) {
            $v = $row[$via] ?? null;
            if (is_array($v)) {
                $v = $v['val'] ?? null;
            }
            if ($v !== null && $v !== '' && ctype_digit((string) $v)) {
                $ids[(int) $v] = (int) $v;
            }
        }

        [$cond, $condValues] = $access->rowCondition('read', $tb);
        $select = $this->selection($node);
        $byId   = [];
        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $found = $this->db->query(
                'SELECT ' . implode(', ', $select) . " FROM {$tb} WHERE {$tb}.id IN ({$marks}) AND ({$cond})",
                array_merge($chunk, $condValues),
                'read'
            ) ?: [];
            $this->count(count($found));
            foreach ($found as $f) {
                $byId[(int) $f['id']] = $f;
            }
        }

        foreach ($rows as &$row) {
            $v = $row[$via] ?? null;
            if (is_array($v)) {
                $v = $v['val'] ?? null;
            }
            // A row the principal may not read, or that no longer exists, keeps its raw id.
            if ($v !== null && isset($byId[(int) $v])) {
                $row[$key] = $byId[(int) $v];
                // Unaliased: the object replaces the id and its label. Aliased: the
                // raw id stays under its own name, next to the object.
                if ($key === $via) {
                    unset($row['@' . $via]);
                }
            }
        }
        unset($row);
        return $rows;
    }

    // ── Misc ─────────────────────────────────────────────────────────────────

    private function idOf(array $row): int
    {
        $id = $row['id'] ?? 0;
        return (int) (is_array($id) ? ($id['val'] ?? 0) : $id);
    }

    private function count(int $n): void
    {
        $this->nested += $n;
        if ($this->nested > self::MAX_NESTED_ROWS) {
            throw new \LengthException('Too many nested rows (max ' . self::MAX_NESTED_ROWS . ')');
        }
    }
}
