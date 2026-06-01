<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Alter;

/**
 * Interface to interact with database structure.
 * Covers both DDL changes (columns, tables) and constraint/index management
 * for user-defined tables.
 */
interface AlterInterface
{
    public function renameTable(string $old, string $new): bool;

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool;

    public function addFld(string $tb, string $fld_name, string $fld_type): bool;

    public function dropFld(string $tb, string $fld_name): bool;

    /**
     * Creates a minimal table for either a regular table (with `creator`) or a
     * plugin table (with `table_link` + `id_link`). When $pluginOf is provided
     * the plugin table gets a FK constraint: id_link → $pluginOf.id ON DELETE RESTRICT.
     */
    public function createMinimalTable(string $tb, bool $is_plugin, string $pluginOf = ''): bool;

    public function dropTable(string $tb): bool;

    // ── FK constraint management ──────────────────────────────────────────────

    /**
     * Adds a FK constraint on $tb.$col → $refTable.$refCol.
     * Idempotent: no-op if the constraint already exists.
     * On SQLite this requires a full table recreation.
     *
     * @param string $onDelete  CASCADE | RESTRICT | SET NULL | NO ACTION
     * @param string $onUpdate  CASCADE | RESTRICT | SET NULL | NO ACTION
     */
    public function addForeignKey(
        string $tb,
        string $col,
        string $refTable,
        string $refCol,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'CASCADE'
    ): bool;

    /**
     * Drops the FK constraint on $tb.$col.
     * Idempotent: no-op if no such constraint exists.
     * On SQLite this requires a full table recreation.
     */
    public function dropForeignKey(string $tb, string $col): bool;

    /**
     * Returns true if a FK constraint exists on $tb.$col.
     */
    public function hasForeignKey(string $tb, string $col): bool;

    /**
     * Returns the number of rows in $tb where $col has a value not present
     * in $refTable.$refCol (i.e. potential FK violations).
     * Used as pre-validation before applying addForeignKey().
     */
    public function checkOrphans(string $tb, string $col, string $refTable, string $refCol): int;

    // ── Index management ──────────────────────────────────────────────────────

    /**
     * Creates a (optionally unique) index on $tb over the given columns.
     * Idempotent: no-op if the index already exists.
     */
    public function createIndex(string $tb, string $name, array $columns, bool $unique = false): bool;

    /**
     * Drops an index by name. Idempotent: no-op if not found.
     */
    public function dropIndex(string $tb, string $name): bool;
}
