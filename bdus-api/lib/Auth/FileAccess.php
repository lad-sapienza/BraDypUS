<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

use Config\Config;
use DB\DBInterface;

/**
 * Who may do what with a file. The single place where file permissions are decided:
 * nothing else in the application may judge a file on its own.
 *
 * A file can be reached in two ways, and either one is enough to READ it:
 *  - **directly**: a rule (or tier) that grants the action on the table `bdus_files`, with the
 *    usual row filter, locks and app status (that is `Access`/`WriteGate`, applied to
 *    `bdus_files` like to any table; rules on all tables and the global tiers include it);
 *  - **through the data**: at least one record the file is attached to is readable.
 *
 * To CHANGE or delete a file, the same two ways, but the second is stricter: the file must be
 * attached to at least one record and EVERY record it is attached to must be changeable,
 * because changing the file changes what each of those records shows. For the same reason a
 * lock on any record the file is attached to freezes the file, whatever the direct rights.
 *
 * A file attached to nothing (an orphan) is reachable only directly: there is no data to
 * inherit from, and its creator gets no special right.
 *
 * Attaching, detaching and uploading from a record stay governed by the record's own update
 * right (the controllers check it); this class adds that the file to attach must be readable.
 */
final class FileAccess
{
    public const TABLE = 'bdus_files';

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        private readonly Access $access,
        private readonly WriteGate $gate,
    ) {}

    public static function make(DBInterface $db, Config $cfg, Access $access): self
    {
        return new self($db, $cfg, $access, new WriteGate($db, $cfg, $access));
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * SQL condition selecting the files the principal may read, to AND into a query over
     * `bdus_files AS {$alias}`.
     *
     * @return array{0: string, 1: array}  [sql, bound values]; '1=1' = every file, '1=0' = none
     */
    public function readCondition(string $alias = 'f'): array
    {
        if ($this->access->isAdmin()) {
            return ['1=1', []];
        }

        $parts  = [];
        $values = [];

        // Directly: a rule on bdus_files itself
        [$sql, $vals] = $this->access->rowCondition('read', self::TABLE);
        if ($sql === '1=1') {
            return ['1=1', []];
        }
        if ($sql !== '1=0') {
            $parts[] = "{$alias}.id IN (SELECT " . self::TABLE . '.id FROM ' . self::TABLE . " WHERE {$sql})";
            $values  = array_merge($values, $vals);
        }

        // Through the data: attached to a record that may be read
        $links  = [];
        $lvalues = [];
        foreach ((array) ($this->cfg->get('tables.*.name') ?: []) as $tb) {
            if (!is_string($tb) || $tb === self::TABLE || !$this->access->can('read', $tb)) {
                continue;
            }
            [$rowSql, $rowVals] = $this->access->rowCondition('read', $tb);
            if ($rowSql === '1=0') {
                continue;
            }
            if ($rowSql === '1=1') {
                $links[]   = '(fl.table_name = ?)';
                $lvalues[] = $tb;
            } else {
                // $tb reaches SQL only here, after rowCondition() accepted it as a configured table
                $links[]  = "(fl.table_name = ? AND fl.record_id IN (SELECT {$tb}.id FROM {$tb} WHERE {$rowSql}))";
                $lvalues  = array_merge($lvalues, [$tb], $rowVals);
            }
        }
        if ($links) {
            $parts[] = "EXISTS (SELECT 1 FROM bdus_file_links fl WHERE fl.file_id = {$alias}.id AND ("
                . implode(' OR ', $links) . '))';
            $values  = array_merge($values, $lvalues);
        }

        return $parts ? ['(' . implode(' OR ', $parts) . ')', $values] : ['1=0', []];
    }

    /** May the principal read file $id? */
    public function mayRead(int $id): bool
    {
        [$sql, $values] = $this->readCondition('f');
        if ($sql === '1=0') {
            return false;
        }
        $res = $this->db->query(
            "SELECT COUNT(*) AS n FROM bdus_files f WHERE f.id = ? AND ({$sql})",
            array_merge([$id], $values),
            'read'
        );
        return (int) ($res[0]['n'] ?? 0) > 0;
    }

    /**
     * Keeps, of rows `{table_name, record_id, …}` (links of files), only those to records the
     * principal may read: a file never reveals what it is attached to beyond that.
     *
     * @param  array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    public function readableLinks(array $links): array
    {
        $byTable = [];
        foreach ($links as $l) {
            $byTable[(string) $l['table_name']][] = (int) $l['record_id'];
        }
        $ok = [];
        foreach ($byTable as $tb => $ids) {
            $ok[$tb] = $this->access->unrestricted('read', $tb)
                ? array_flip($ids)
                : array_flip($this->access->readableIds($tb, $ids));
        }
        return array_values(array_filter(
            $links,
            fn($l) => isset($ok[(string) $l['table_name']][(int) $l['record_id']])
        ));
    }

    // ── Change ────────────────────────────────────────────────────────────────

    /**
     * May the principal change (`update`: metadata, binary) or `delete` file $id?
     *
     * @param 'update'|'delete' $action
     */
    public function mayChange(string $action, int $id): bool
    {
        if ($action !== 'update' && $action !== 'delete') {
            return false;
        }
        $exists = $this->db->query('SELECT id FROM bdus_files WHERE id = ?', [$id], 'read');
        if (!$exists) {
            return false;
        }

        $links = $this->db->query(
            'SELECT table_name, record_id FROM bdus_file_links WHERE file_id = ?',
            [$id],
            'read'
        ) ?: [];

        // Directly: a rule on bdus_files (row scope, locks and app status included). Even then, a
        // file attached to a locked record cannot be changed: it is part of what the lock freezes.
        if ($this->mayChangeDirectly($action, $id)) {
            foreach ($links as $l) {
                if ($this->access->isLocked((string) $l['table_name'], (int) $l['record_id'])) {
                    return false;
                }
            }
            return true;
        }

        // Through the data: attached somewhere, and every record it is attached to is changeable
        // (which includes not being locked)
        if (!$links) {
            return false;
        }
        foreach ($links as $l) {
            if (!$this->gate->mayChange('update', (string) $l['table_name'], (int) $l['record_id'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The direct way: the tier or a group rule grants `$action` on the files table (a rule that
     * only deletes is enough to delete, one that only updates is enough to update), the row is in
     * scope and outside every lock, and the application is not frozen or off.
     */
    private function mayChangeDirectly(string $action, int $id): bool
    {
        if (!\Auth\Authorization::can($action === 'delete' ? 'delete' : 'edit', null, self::TABLE)) {
            return false;
        }
        return $this->access->unrestricted($action, self::TABLE)
            || $this->access->rowAllowed($action, self::TABLE, $id);
    }

    /** May the principal add a file that belongs to no record (a standalone upload)? */
    public function mayCreate(): bool
    {
        return \Auth\Authorization::can('add_new', table: self::TABLE)
            && $this->access->can('create', self::TABLE);
    }
}
