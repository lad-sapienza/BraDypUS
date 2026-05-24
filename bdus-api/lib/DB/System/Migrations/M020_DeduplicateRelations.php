<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Removes bidirectional duplicate rows from bdus_cfg_relations and adds a
 * UNIQUE index on (from_tb, to_tb).
 *
 * Background
 * ──────────
 * The original v4 YAML config required defining a link in BOTH participating
 * tables: `tombe` had a link entry pointing to `us` AND `us` had a link entry
 * pointing to `tombe`. M013 migrated those blobs verbatim, so the same
 * relationship ended up stored twice — once as (from_tb=tombe, to_tb=us) and
 * once as (from_tb=us, to_tb=tombe).
 *
 * The intent of bdus_cfg_relations was to store each relationship a single
 * time and derive the reverse direction automatically. This migration realises
 * that intent:
 *
 *   1. For every bidirectional pair (A→B) + (B→A), delete the row with the
 *      higher id (keeping the first-inserted canonical row).
 *   2. Add a UNIQUE index on (from_tb, to_tb) to prevent future same-direction
 *      duplicates.
 *
 * After this migration, LoadFromDB queries both from_tb=X and to_tb=X and
 * auto-inverts the fld mapping for the reverse direction.
 */
class M020_DeduplicateRelations
{
    public const NAME = 'M020_deduplicate_relations';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Guard: bdus_cfg_relations may not exist on very old apps that skipped M013.
        try {
            $exists = $db->query(
                "SELECT COUNT(*) AS cnt FROM bdus_cfg_relations",
                [],
                'read'
            );
        } catch (\Throwable) {
            return; // Table does not exist — nothing to do.
        }

        // ── 1. Remove bidirectional duplicates ────────────────────────────────
        // Find all pairs (A→B, B→A): for each such pair keep the lower id,
        // delete the higher id. The self-join with r1.id < r2.id ensures each
        // pair is processed exactly once.
        $pairs = $db->query(
            'SELECT r1.id AS keep_id, r2.id AS drop_id
               FROM bdus_cfg_relations r1
               INNER JOIN bdus_cfg_relations r2
                       ON r1.from_tb = r2.to_tb
                      AND r1.to_tb   = r2.from_tb
              WHERE r1.id < r2.id',
            [],
            'read'
        ) ?: [];

        foreach ($pairs as $pair) {
            $db->query(
                'DELETE FROM bdus_cfg_relations WHERE id = ?',
                [$pair['drop_id']],
                'boolean'
            );
        }

        // ── 2. Remove same-direction duplicates ───────────────────────────────
        // If (A→B) appears more than once, keep the one with the lowest id.
        $samePairs = $db->query(
            'SELECT MIN(id) AS keep_id, from_tb, to_tb
               FROM bdus_cfg_relations
           GROUP BY from_tb, to_tb
             HAVING COUNT(*) > 1',
            [],
            'read'
        ) ?: [];

        foreach ($samePairs as $row) {
            $db->query(
                'DELETE FROM bdus_cfg_relations
                  WHERE from_tb = ? AND to_tb = ? AND id != ?',
                [$row['from_tb'], $row['to_tb'], $row['keep_id']],
                'boolean'
            );
        }

        // ── 3. Add UNIQUE index on (from_tb, to_tb) ───────────────────────────
        // Prevents future same-direction duplicates at the DB level.
        // Silently skip if the index already exists.
        try {
            $db->query(
                'CREATE UNIQUE INDEX IF NOT EXISTS cfg_rel_unique_pair
                        ON bdus_cfg_relations (from_tb, to_tb)',
                [],
                'boolean'
            );
        } catch (\Throwable) {
            // Index creation failed (e.g. still-duplicate data on non-SQLite).
            // Not fatal — the application-level guard in upsertRelations suffices.
        }
    }
}
