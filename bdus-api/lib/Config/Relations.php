<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\Alter;
use DB\DB;

/**
 * Creates and updates a table relation (a row of bdus_cfg_relations) together
 * with its database side: the FOREIGN KEY constraint and the auto-index.
 *
 * Shared by `Config::saveRelation()` (the Relations panel) and `DbmlImporter`
 * (refs of a DBML file), so both apply exactly the same rules.
 */
class Relations
{
    public const POLICIES = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'];

    /**
     * Saves a relation. With $id it updates that relation (from_tb / from_col are
     * immutable and taken from the stored row), otherwise it creates one.
     *
     * Returns the response the API sends: `['status' => 'success'|'warning'|'error',
     * 'code' => …, 'id'? => …, 'orphans'? => …]`. Database errors are thrown.
     *
     * @return array<string, mixed>
     */
    public static function save(
        DB $db,
        Config $cfg,
        ?int $id,
        string $fromTb,
        string $fromCol,
        string $toTb,
        string $toCol,
        string $onDel = 'RESTRICT',
        string $onUpd = 'CASCADE'
    ): array {
        if (!$fromTb || !$fromCol || !$toTb || !$toCol) {
            return ['status' => 'error', 'code' => 'parameter_missing'];
        }

        $onDel = strtoupper(trim($onDel));
        $onUpd = strtoupper(trim($onUpd));
        if (!in_array($onDel, self::POLICIES, true)) {
            return ['status' => 'error', 'code' => 'invalid_on_delete'];
        }
        if (!in_array($onUpd, self::POLICIES, true)) {
            return ['status' => 'error', 'code' => 'invalid_on_update'];
        }

        // Plugin tables are satellites of exactly one parent, reached only via
        // their id_link relation (see Config::syncPluginRelation()) — never
        // independently browsable. Any other relation involving a plugin table
        // (on either side) would produce a "Linked records" entry the frontend
        // can never open, because plugin tables are excluded from GET /api/tables.
        // Lookups that don't need a hard FK (dropdowns, search) should use a
        // field's `id_from_tb` instead, which needs no row here.
        if ((bool) $cfg->get("tables.{$fromTb}.is_plugin") && $fromCol !== 'id_link') {
            return ['status' => 'error', 'code' => 'plugin_relation_not_allowed'];
        }
        if ((bool) $cfg->get("tables.{$toTb}.is_plugin")) {
            return ['status' => 'error', 'code' => 'plugin_relation_not_allowed'];
        }

        // Self-referential: force fixed policy (RESTRICT/CASCADE).
        if ($fromTb === $toTb) {
            $onDel = 'RESTRICT';
            $onUpd = 'CASCADE';
        }

        $alter = new Alter($db);

        // Drop the legacy UNIQUE(from_tb, to_tb) index defensively — it blocks multiple
        // FK columns between the same table pair, which the new schema supports.
        ToDB::dropLegacyPairIndex($db);   // engine-aware: MySQL has no bare `DROP INDEX IF EXISTS`

        if ($id) {
            // UPDATE: from_tb and from_col are immutable (they identify the FK column).
            // Only to_tb, to_col, on_delete, on_update may change.
            $existing = $db->query(
                'SELECT from_tb, from_col FROM bdus_cfg_relations WHERE id=?',
                [$id], 'read'
            );
            if (empty($existing)) {
                return ['status' => 'error', 'code' => 'not_found'];
            }
            $fromTb  = $existing[0]['from_tb'];
            $fromCol = $existing[0]['from_col'];

            if (!self::typesCompatible($db, $fromTb, $fromCol, $toTb, $toCol)) {
                return ['status' => 'error', 'code' => 'relation_type_mismatch'];
            }

            // Drop old FK before re-applying with new params.
            $alter->dropForeignKey($fromTb, $fromCol);

            $db->query(
                'UPDATE bdus_cfg_relations SET to_tb=?, to_col=?, on_delete=?, on_update=? WHERE id=?',
                [$toTb, $toCol, $onDel, $onUpd, $id],
                'boolean'
            );
        } else {
            // CREATE: reject duplicate FK column.
            $dup = $db->query(
                'SELECT id FROM bdus_cfg_relations WHERE from_tb=? AND from_col=?',
                [$fromTb, $fromCol], 'read'
            );
            if (!empty($dup)) {
                return ['status' => 'error', 'code' => 'relation_already_exists'];
            }
            if (!self::typesCompatible($db, $fromTb, $fromCol, $toTb, $toCol)) {
                return ['status' => 'error', 'code' => 'relation_type_mismatch'];
            }
            $id = (int) $db->query(
                'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
                 VALUES (?,?,?,?,?,?)',
                [$fromTb, $fromCol, $toTb, $toCol, $onDel, $onUpd],
                'id'
            );
        }

        // Pre-validate: count orphans before applying FK to DB.
        $orphans = $alter->checkOrphans($fromTb, $fromCol, $toTb, $toCol);
        if ($orphans > 0) {
            // Config row is saved; DB constraint is not applied (would fail).
            return [
                'status'  => 'warning',
                'code'    => 'relation_saved_orphans_found',
                'id'      => $id,
                'orphans' => $orphans,
            ];
        }

        // Apply FK constraint and auto-index.
        $alter->addForeignKey($fromTb, $fromCol, $toTb, $toCol, $onDel, $onUpd);
        self::saveAutoIndex($db, $fromTb, $fromCol, $alter);

        return ['status' => 'success', 'code' => 'relation_saved', 'id' => $id];
    }

    /**
     * Class of a physical column type: 'numeric', 'text' or null (anything else:
     * dates, blobs, booleans, unknown…). Works on the type strings of all three
     * engines (SQLite declared type, MySQL DESCRIBE, PostgreSQL data_type).
     */
    public static function typeClass(string $type): ?string
    {
        $t = strtolower(trim($type));
        if (preg_match('/^((tiny|small|medium|big)?int|integer|(small|big)?serial|real|float|double|decimal|numeric|number)\b/', $t)) {
            return 'numeric';
        }
        if (preg_match('/char|text|clob|string/', $t)) {
            return 'text';
        }
        return null;
    }

    /**
     * False only when one column is clearly numeric and the other clearly text:
     * `Record\Read::getLinks()` would compare them, which returns nothing on
     * SQLite/MySQL and raises an error on PostgreSQL. Columns that cannot be
     * inspected (missing table/column) or are of another class are not judged.
     */
    public static function typesCompatible(DB $db, string $fromTb, string $fromCol, string $toTb, string $toCol): bool
    {
        $a = self::columnClass($db, $fromTb, $fromCol);
        $b = self::columnClass($db, $toTb, $toCol);
        return !($a !== null && $b !== null && $a !== $b);
    }

    private static function columnClass(DB $db, string $tb, string $col): ?string
    {
        $inspect = new \DB\Inspect($db);
        if (!$inspect->tableExists($tb)) {
            return null;
        }
        foreach ($inspect->tableColumns($tb) as $c) {
            if (strcasecmp($c['fld'], $col) === 0) {
                return self::typeClass((string) $c['type']);
            }
        }
        return null;
    }

    /**
     * Creates the auto-index for a FK column (idx_{tb}_{col}) and records it
     * in bdus_cfg_indexes if not already present.
     */
    public static function saveAutoIndex(DB $db, string $tb, string $col, Alter $alter): void
    {
        $name = "idx_{$tb}_{$col}";
        $alter->createIndex($tb, $name, [$col], false);

        $exists = $db->query(
            'SELECT id FROM bdus_cfg_indexes WHERE tb=? AND name=?',
            [$tb, $name], 'read'
        );
        if (empty($exists)) {
            $db->query(
                'INSERT INTO bdus_cfg_indexes (tb, name, columns, is_unique) VALUES (?,?,?,0)',
                [$tb, $name, json_encode([$col], JSON_UNESCAPED_UNICODE)],
                'boolean'
            );
        }
    }
}
