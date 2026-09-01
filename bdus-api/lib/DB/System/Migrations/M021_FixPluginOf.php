<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\Alter;
use DB\System\Manage;
use DB\System\Migrations\Support\PluginTableSplitter;

/**
 * Back-fills the plugin_of column for plugin tables that were migrated from
 * v4 JSON config files by M011_ConfigToDb.
 *
 * Root cause:
 *   In v4, the table plugin relationship was expressed only on the *parent*
 *   table:
 *       tables.json: { "name": "us",       "plugin": ["attivita"] }
 *       tables.json: { "name": "attivita", "is_plugin": "1" }     ← no plugin_of
 *
 *   M011 stored plugin_of from each row's own key (which was absent for plugin
 *   tables), so all plugin tables were stored with plugin_of = NULL.
 *   LoadFromDB derives the `plugin` array from bdus_cfg_relations (post-2026-07
 *   redesign) / plugin_of (pre-redesign), so every parent table ends up with an
 *   empty plugin list unless plugin_of is set correctly here.
 *
 *   M011 did, however, store non-standard table attributes in the `extra` JSON
 *   column, so the v4 `plugin` array is preserved there, e.g.:
 *       extra = '{"plugin":["attivita","materiali"]}'
 *
 * Fix — two cases, depending on how many *different* parents reference the
 * same plugin table across all of v4's `extra.plugin` declarations:
 *
 *  - Single parent (the common case): set plugin_of = parent_table_name on the
 *    plugin table, as before.
 *
 *  - Multiple parents (v4 allowed one physical plugin table — e.g. "misure" —
 *    to be shared by several parents, discriminated at the row level by
 *    table_link): the v5 model requires one physical table per parent (see
 *    Config\LoadFromDB — a plugin table's relation to its parent is
 *    from_tb=plugin/from_col=id_link, UNIQUE per from_col, so a single
 *    physical table cannot serve two parents). Silently keeping only the
 *    first parent (the old behaviour) drops the other parents' data. Instead,
 *    the plugin table is split into one physical twin per parent —
 *    {plugin}_{parent} — copying the field config and only the rows whose
 *    table_link matches that parent (original row ids preserved), then the
 *    original shared table and its config are dropped.
 *
 *  In both cases the `plugin` key is then removed from the parent's `extra`
 *  to avoid stale data.
 *
 *  The plugin→parent attachment itself is recorded directly in
 *  bdus_cfg_relations (from_tb=plugin/from_col='id_link'/to_tb=parent/to_col='id')
 *  rather than in a plugin_of column — there is no such physical column
 *  (dropped by M036_DropPluginOfColumn; in fact bdus_cfg_tables never has it
 *  from the moment M011 creates the table, since Structure/cfg_tables.json no
 *  longer declares it). Config\LoadFromDB derives tables.{parent}.plugin[]
 *  from this same relation.
 */
class M021_FixPluginOf
{
    public const NAME = 'M021_fix_plugin_of';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if (!$manage->tableExists('bdus_cfg_tables')) {
            return;
        }

        // Load all rows that have a non-null extra column.
        $rows = $db->query(
            'SELECT name, extra FROM bdus_cfg_tables WHERE extra IS NOT NULL',
            [],
            'read'
        ) ?: [];

        // Group by plugin name first, so a plugin referenced by more than one
        // parent can be told apart from the common single-parent case.
        $pluginParents = [];  // pluginName => [parentName, …]
        $parentExtras  = [];  // parentName => decoded extra array

        foreach ($rows as $row) {
            $extra = json_decode($row['extra'] ?? '', true);
            if (!is_array($extra) || empty($extra['plugin'])) {
                continue;
            }

            $parentName = $row['name'];
            $parentExtras[$parentName] = $extra;

            foreach ((array) $extra['plugin'] as $pluginName) {
                if (!$pluginName || !is_string($pluginName)) {
                    continue;
                }
                $pluginParents[$pluginName][] = $parentName;
            }
        }

        foreach ($pluginParents as $pluginName => $parents) {
            $parents = array_values(array_unique($parents));

            if (count($parents) === 1) {
                $db->query('UPDATE bdus_cfg_tables SET is_plugin = 1 WHERE name = ?', [$pluginName], 'boolean');
                // ensurePluginRelation() is itself the "don't overwrite an
                // existing attachment" guard: if a relation row already exists
                // for this plugin table (e.g. it was correctly attached to a
                // different parent already), it is left untouched.
                if ($manage->tableExists($parents[0])) {
                    self::ensurePluginRelation($db, $pluginName, $parents[0]);
                }
            } else {
                self::splitMultiTenantPlugin($manage, $pluginName, $parents);
            }
        }

        // Remove the now-redundant `plugin` key from every parent's extra.
        foreach ($parentExtras as $parentName => $extra) {
            unset($extra['plugin']);
            $newExtra = empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);

            $db->query(
                'UPDATE bdus_cfg_tables SET extra = ? WHERE name = ?',
                [$newExtra, $parentName],
                'boolean'
            );
        }
    }

    /**
     * Splits a v4 plugin table shared by several parents into one physical
     * twin per parent, copying config + the rows that belong to that parent
     * (matched by table_link), then drops the original shared table.
     *
     * Idempotent: no-op if the shared table (or its config row) is already
     * gone — i.e. a previous run already split it.
     *
     * @param string   $pluginTb Name of the shared plugin table (e.g. "misure").
     * @param string[] $parents  Distinct parent table names referencing it.
     */
    private static function splitMultiTenantPlugin(Manage $manage, string $pluginTb, array $parents): void
    {
        $db = $manage->getDb();

        if (!$manage->tableExists($pluginTb)) {
            return;
        }

        $origCfg = $db->query('SELECT * FROM bdus_cfg_tables WHERE name = ?', [$pluginTb], 'read');
        if (empty($origCfg)) {
            return;
        }
        $origCfg = $origCfg[0];

        $fieldRows = $db->query(
            'SELECT name, label, type, db_type, sort, extra FROM bdus_cfg_fields
              WHERE table_name = ? ORDER BY sort ASC, id ASC',
            [$pluginTb],
            'read'
        ) ?: [];
        $dataFields = array_values(array_filter(
            $fieldRows,
            fn($f) => !in_array($f['name'], ['id', 'table_link', 'id_link'], true)
        ));

        foreach ($parents as $parentTb) {
            if (!$manage->tableExists($parentTb)) {
                continue; // stale v4 reference to a table that no longer exists
            }

            PluginTableSplitter::createTwin($manage, $pluginTb, $parentTb, $dataFields, $fieldRows, $origCfg);
        }

        // The original shared table's data has been fully redistributed above.
        (new Alter($db))->dropTable($pluginTb);
        $db->query('DELETE FROM bdus_cfg_fields WHERE table_name = ?', [$pluginTb], 'boolean');
        $db->query('DELETE FROM bdus_cfg_tables WHERE name = ?', [$pluginTb], 'boolean');
    }

    /**
     * Ensures bdus_cfg_relations has a from_tb=$pluginTb/from_col='id_link' row
     * pointing at $parentTb. No-op if a relation row already exists for
     * $pluginTb (whatever its target — never overwrites an existing attachment).
     * Same idempotent insert pattern as M035_PluginRelationsFromPluginOf.
     */
    private static function ensurePluginRelation(\DB\DBInterface $db, string $pluginTb, string $parentTb): void
    {
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
    }
}
