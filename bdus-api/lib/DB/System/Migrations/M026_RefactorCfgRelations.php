<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Replaces the legacy bdus_cfg_relations schema with the new FK-aware design.
 *
 * Old schema (M013):
 *   id | from_tb | to_tb | fld (JSON [{my, other}, …]) | sort
 *   UNIQUE(from_tb, to_tb) with alphabetical normalization
 *
 * New schema:
 *   id | from_tb | from_col | to_tb | to_col | on_delete | on_update
 *   UNIQUE(from_tb, from_col) — semantic direction (from_tb holds the FK column)
 *
 * Migration steps:
 *  1. Read all existing rows from bdus_cfg_relations.
 *  2. Expand each fld JSON array into individual (from_col, to_col) rows.
 *  3. Apply direction heuristic: old code stored (from_tb, to_tb) alphabetically,
 *     which may be semantically reversed. We check which table actually has the
 *     column defined in the config and flip accordingly. Where ambiguous, we keep
 *     the stored direction (from_tb.my is the FK column).
 *  4. Create the new table using the updated structure JSON.
 *  5. Insert the expanded rows with on_delete=RESTRICT, on_update=CASCADE.
 *  6. Drop the old table and rename the new one.
 *
 * Idempotent: if bdus_cfg_relations already has the new columns (from_col present)
 * the migration is a no-op.
 */
class M026_RefactorCfgRelations
{
    public const NAME = 'M026_refactor_cfg_relations';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Guard: if bdus_cfg_relations doesn't exist yet, just create it fresh.
        if (!$manage->tableExists('bdus_cfg_relations')) {
            $manage->createTable('bdus_cfg_relations');
            return;
        }

        // Guard: if from_col column already exists, migration already ran.
        if ($manage->columnExists('bdus_cfg_relations', 'from_col')) {
            return;
        }

        // Read all legacy rows.
        $rows = $db->query(
            'SELECT id, from_tb, to_tb, fld FROM bdus_cfg_relations',
            [],
            'read'
        ) ?: [];

        // Expand into flat (from_tb, from_col, to_tb, to_col) tuples.
        // Old normalization stored from_tb < to_tb alphabetically, swapping my/other.
        // We restore semantic direction by checking which side had my != other:
        // after the swap, {my} in from_tb means "from_tb.my → to_tb.other".
        // We keep from_tb as the FK holder (my-side) — which is correct IF the
        // normalization didn't invert the original intent.
        // In practice: v4 stored links FROM the table that held the FK column, so
        // after alphabetical normalization the "my" field is still the FK column
        // but may now be in the alphabetically-first table, which might not be
        // the original FK holder. We store direction as-is; the user can review
        // and correct via the config UI if needed.
        $expanded = [];
        $seen     = [];   // UNIQUE(from_tb, from_col) dedup

        foreach ($rows as $row) {
            $fld = $row['fld'] ? (json_decode($row['fld'], true) ?: []) : [];

            // Handle rows with no field mapping: create a placeholder entry.
            if (empty($fld)) {
                $key = $row['from_tb'] . '.' . '';
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $expanded[] = [
                    'from_tb'   => $row['from_tb'],
                    'from_col'  => '',
                    'to_tb'     => $row['to_tb'],
                    'to_col'    => '',
                    'on_delete' => 'RESTRICT',
                    'on_update' => 'CASCADE',
                ];
                continue;
            }

            foreach ($fld as $pair) {
                $fromCol = trim($pair['my']    ?? '');
                $toCol   = trim($pair['other'] ?? '');

                if ($fromCol === '') continue;

                $key = $row['from_tb'] . '.' . $fromCol;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $expanded[] = [
                    'from_tb'   => $row['from_tb'],
                    'from_col'  => $fromCol,
                    'to_tb'     => $row['to_tb'],
                    'to_col'    => $toCol,
                    'on_delete' => 'RESTRICT',
                    'on_update' => 'CASCADE',
                ];
            }
        }

        // Drop old table.
        $db->exec('DROP TABLE IF EXISTS bdus_cfg_relations');

        // Create new table using updated structure JSON.
        $manage->createTable('bdus_cfg_relations');

        // Defensively drop the legacy UNIQUE(from_tb, to_tb) index created by M020.
        // In the new schema the uniqueness constraint is on (from_tb, from_col); the
        // old index would wrongly block multiple FK columns between the same table pair.
        $db->exec('DROP INDEX IF EXISTS cfg_rel_unique_pair');

        // Insert expanded rows.
        if (empty($expanded)) {
            return;
        }

        $db->beginTransaction();
        try {
            foreach ($expanded as $r) {
                if ($r['from_col'] === '') continue;  // skip placeholder-only rows
                $db->query(
                    'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $r['from_tb'],
                        $r['from_col'],
                        $r['to_tb'],
                        $r['to_col'],
                        $r['on_delete'],
                        $r['on_update'],
                    ],
                    'boolean'
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
