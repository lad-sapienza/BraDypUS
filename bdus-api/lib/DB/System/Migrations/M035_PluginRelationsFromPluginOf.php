<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\Alter;
use DB\System\Manage;

/**
 * Backfills bdus_cfg_relations with an explicit relation row for every
 * existing plugin table, so Config\LoadFromDB can derive tables.{parent}.plugin[]
 * from relations instead of scanning every table for plugin_of === $parent.
 *
 * For each bdus_cfg_tables row with is_plugin=1 and a non-empty plugin_of:
 *   - insert from_tb={plugin}, from_col='id_link', to_tb={plugin_of}, to_col='id'
 *     (skipped if a row already exists for that from_tb/from_col — idempotent,
 *     and also the natural outcome of a plugin table created after this
 *     migration via the relation-based activation flow)
 *   - on MySQL/PostgreSQL only, also apply the live FK constraint via \DB\Alter
 *     (idempotent no-op if already present — most plugin tables already got it
 *     at creation time from Alter::createMinimalTable()). SQLite is skipped for
 *     this step, same caution as M032_CreatorFkNullable: adding a FK to an
 *     existing SQLite table requires a full table recreation, too risky to run
 *     unconditionally on every login for tables that may hold real project data.
 *     The config-level relation row (which is all Config\LoadFromDB needs) is
 *     applied on every engine regardless.
 *
 * Tables whose plugin_of points at a table that no longer exists (a pre-existing
 * orphan — Config::delete_tb() does not clean up children's plugin_of) are
 * skipped: there is nothing safe to backfill for them.
 *
 * plugin_of itself is left untouched — later migrations/cleanup drop it once no
 * code reads it anymore.
 */
class M035_PluginRelationsFromPluginOf
{
    public const NAME = 'M035_plugin_relations_from_plugin_of';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if (!$manage->tableExists('bdus_cfg_tables') || !$manage->tableExists('bdus_cfg_relations')) {
            return;
        }

        // A fresh install creates bdus_cfg_tables from the current Structure JSON,
        // which no longer declares plugin_of (see M036_DropPluginOfColumn) — without
        // this guard the SELECT below would fail with "no such column" and abort the
        // entire migration chain for every brand-new app.
        if (!$manage->columnExists('bdus_cfg_tables', 'plugin_of')) {
            return;
        }

        $rows = $db->query(
            "SELECT name, plugin_of FROM bdus_cfg_tables
              WHERE is_plugin = 1 AND plugin_of IS NOT NULL AND plugin_of != ''",
            [],
            'read'
        ) ?: [];

        if (empty($rows)) {
            return;
        }

        $engine = $db->getEngine();
        $alter  = $engine !== 'sqlite' ? new Alter($db) : null;

        foreach ($rows as $row) {
            $pluginTb = $row['name'];
            $parentTb = $row['plugin_of'];

            if (!$manage->tableExists($parentTb)) {
                continue; // stale plugin_of pointing at a deleted parent — nothing safe to do
            }

            $existing = $db->query(
                "SELECT id FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
                [$pluginTb],
                'read'
            );
            if (empty($existing)) {
                $db->query(
                    'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$pluginTb, 'id_link', $parentTb, 'id', 'RESTRICT', 'CASCADE'],
                    'boolean'
                );
            }

            if ($alter === null) {
                continue; // SQLite — config-level relation row is enough, see class docblock
            }

            try {
                $alter->addForeignKey($pluginTb, 'id_link', $parentTb, 'id', 'RESTRICT', 'CASCADE');
            } catch (\Throwable $e) {
                // Best-effort: a pre-existing data issue (e.g. orphaned id_link
                // values) shouldn't block the rest of the backfill. The config-level
                // relation row above is what LoadFromDB needs; the live constraint
                // is a safety net that can be reapplied later once data is clean.
            }
        }
    }
}
