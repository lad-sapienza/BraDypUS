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
 * Manual links between two records of any tables (bdus_userlinks). A link is
 * stored once, as entered (tb_one/id_one → tb_two/id_two), and shows on both
 * records, which is why a duplicate is looked for in both directions and why
 * changing a link needs the right to change both records.
 *
 * Request-independent: the REST endpoints (Record::addManualLink /
 * deleteManualLink) and the MCP tools share it. Refusals are returned as code
 * strings, never thrown; only a database failure throws. Whether the principal
 * may edit at all (the route level) is the caller's business.
 */
class ManualLinks
{
    private readonly WriteGate $gate;

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        Access $access,
    ) {
        $this->gate = new WriteGate($db, $cfg, $access);
    }

    /**
     * Everything add() checks, without writing.
     *
     * @return string|null  the code of the refusal, or null when the link could be added
     */
    public function checkAdd(string $tbOne, int $idOne, string $tbTwo, int $idTwo): ?string
    {
        // A link shows on both records: both must be changeable.
        if (!$this->gate->mayChange('update', $tbOne, $idOne) || !$this->gate->mayChange('update', $tbTwo, $idTwo)) {
            return 'not_enough_privilege';
        }
        if ($this->exists($tbOne, $idOne, $tbTwo, $idTwo)) {
            return 'link_already_exists';
        }
        return null;
    }

    /**
     * Adds a link.
     *
     * @return array  { status: 'success', id } or { status: 'error', code }
     * @throws \Throwable when the database refuses the insert
     */
    public function add(string $tbOne, int $idOne, string $tbTwo, int $idTwo, ?string $label = null, ?int $sort = null): array
    {
        $code = $this->checkAdd($tbOne, $idOne, $tbTwo, $idTwo);
        if ($code !== null) {
            return ['status' => 'error', 'code' => $code];
        }
        $id = (int) $this->db->query(
            'INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort, label) VALUES (?, ?, ?, ?, ?, ?)',
            [$tbOne, $idOne, $tbTwo, $idTwo, $sort, $label],
            'id'
        );
        return ['status' => 'success', 'id' => $id];
    }

    /** The link stored under $id (files excluded), or null. */
    public function find(int $id): ?array
    {
        $row = $this->db->query(
            'SELECT id, tb_one, id_one, tb_two, id_two, sort, label FROM bdus_userlinks WHERE id = ?',
            [$id],
            'read'
        )[0] ?? null;
        return $row === null ? null : [
            'id' => (int) $row['id'], 'tb_one' => (string) $row['tb_one'], 'id_one' => (int) $row['id_one'],
            'tb_two' => (string) $row['tb_two'], 'id_two' => (int) $row['id_two'],
            'sort' => $row['sort'] === null ? null : (int) $row['sort'], 'label' => $row['label'],
        ];
    }

    /**
     * Everything remove() checks, without writing. A link that does not exist is
     * 'not_found' (the gate has nothing to protect).
     *
     * @return string|null  the code of the refusal, or null when the link could be removed
     */
    public function checkRemove(int $id): ?string
    {
        $link = $this->find($id);
        if ($link === null) {
            return 'not_found';
        }
        if (!$this->gate->mayChange('update', $link['tb_one'], $link['id_one'])
            || !$this->gate->mayChange('update', $link['tb_two'], $link['id_two'])) {
            return 'not_enough_privilege';
        }
        return null;
    }

    /**
     * Removes a link.
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
        $this->db->query('DELETE FROM bdus_userlinks WHERE id = ?', [$id], 'boolean');
        return ['status' => 'success'];
    }

    /** Is the pair already linked, in either direction? */
    public function exists(string $tbOne, int $idOne, string $tbTwo, int $idTwo): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS c FROM bdus_userlinks
             WHERE (tb_one = ? AND id_one = ? AND tb_two = ? AND id_two = ?)
                OR (tb_one = ? AND id_one = ? AND tb_two = ? AND id_two = ?)',
            [$tbOne, $idOne, $tbTwo, $idTwo, $tbTwo, $idTwo, $tbOne, $idOne],
            'read'
        )[0] ?? [];
        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * The links of records $ids of $tb, seen from each record (the other end is
     * `tb_id`/`ref_id`), in display order. Files are not manual links.
     *
     * @param int[] $ids
     * @return array<int, array[]>  record id => [ { key, tb_id, tb_label, ref_id, ref_label, sort, label } ]
     */
    public function forRecords(string $tb, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 200) as $chunk) {
            $in   = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->query(
                "SELECT * FROM bdus_userlinks
                  WHERE (tb_one = ? AND id_one IN ({$in}) AND tb_two != 'bdus_files')
                     OR (tb_two = ? AND id_two IN ({$in}) AND tb_one != 'bdus_files')
                  ORDER BY sort, id",
                array_merge([$tb], $chunk, [$tb], $chunk),
                'read'
            ) ?: [];
            foreach ($rows as $r) {
                foreach ([[$r['tb_one'], (int) $r['id_one'], $r['tb_two'], (int) $r['id_two']],
                          [$r['tb_two'], (int) $r['id_two'], $r['tb_one'], (int) $r['id_one']]] as [$tbHere, $idHere, $tbThere, $idThere]) {
                    if ($tbHere === $tb && in_array($idHere, $chunk, true)) {
                        $out[$idHere][] = [
                            'key' => (int) $r['id'], 'tb_id' => $tbThere, 'ref_id' => $idThere,
                            'sort' => $r['sort'] === null ? null : (int) $r['sort'], 'label' => $r['label'] ?? null,
                        ];
                    }
                }
            }
        }
        // Labels of the other ends, one query per table.
        $wanted = [];
        foreach ($out as $links) {
            foreach ($links as $l) {
                $wanted[$l['tb_id']][$l['ref_id']] = true;
            }
        }
        $labels = [];
        foreach ($wanted as $other => $refs) {
            $idFld = $this->cfg->get("tables.{$other}.id_field") ?: 'id';
            if ($idFld === 'id' || !preg_match('/^[A-Za-z0-9_]+$/', $other) || !preg_match('/^[A-Za-z0-9_]+$/', (string) $idFld)) {
                continue;
            }
            $refIds = array_map('intval', array_keys($refs));
            $in     = implode(',', array_fill(0, count($refIds), '?'));
            foreach ($this->db->query("SELECT id, {$idFld} AS label FROM {$other} WHERE id IN ({$in})", $refIds, 'read') ?: [] as $r) {
                $labels[$other][(int) $r['id']] = $r['label'];
            }
        }
        foreach ($out as &$links) {
            foreach ($links as &$l) {
                $l['tb_label']  = $this->cfg->get("tables.{$l['tb_id']}.label");
                $l['ref_label'] = $labels[$l['tb_id']][$l['ref_id']] ?? $l['ref_id'];
            }
            unset($l);
        }
        unset($links);
        return $out;
    }

    /** The label shown for record $id of $tb: the value of its id field, or the id. */
    public function refLabel(string $tb, int $id): string|int
    {
        $idFld = $this->cfg->get("tables.{$tb}.id_field") ?: 'id';
        if ($idFld === 'id') {
            return $id;
        }
        return $this->db->query("SELECT {$idFld} AS label FROM {$tb} WHERE id = ?", [$id], 'read')[0]['label'] ?? $id;
    }
}
