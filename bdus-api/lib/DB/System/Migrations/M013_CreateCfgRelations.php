<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates bdus_cfg_relations and migrates link config out of the JSON blobs
 * stored in bdus_cfg_tables.links.
 *
 * Before this migration each forward-link definition was a serialised JSON
 * array in the `links` column of bdus_cfg_tables:
 *
 *   [{"other_tb":"periodi","fld":[{"my":"periodo","other":"nome"}]}]
 *
 * After this migration each link is a row in bdus_cfg_relations:
 *
 *   from_tb | to_tb   | fld                                  | sort
 *   --------+---------+--------------------------------------+-----
 *   us      | periodi | [{"my":"periodo","other":"nome"}]    | 0
 *
 * The `links` column in bdus_cfg_tables is left in place (no DROP COLUMN —
 * SQLite on this platform does not support it reliably) but is no longer
 * read or written by application code after this migration runs.
 *
 * Backlinks are NOT migrated; they remain as a JSON blob in
 * bdus_cfg_tables.backlinks and are handled separately.
 */
class M013_CreateCfgRelations
{
    public const NAME = 'M013_create_cfg_relations';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // 1 — Create bdus_cfg_relations (idempotent: CREATE TABLE IF NOT EXISTS).
        $manage->createTable('bdus_cfg_relations');

        // 2 — Skip if bdus_cfg_tables doesn't exist yet.
        if (!$manage->tableExists('bdus_cfg_tables')) {
            return;
        }

        // 3 — Skip if bdus_cfg_relations already has rows (idempotency guard).
        $existing = $db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_relations',
            [],
            'read'
        );
        // Only skip if there are rows AND bdus_cfg_tables also has data —
        // an empty relations table on a fresh app is expected.
        $cfgCount = $db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_tables',
            [],
            'read'
        );
        if (
            ($existing[0]['cnt'] ?? 0) > 0 ||
            ($cfgCount[0]['cnt'] ?? 0) === 0
        ) {
            return;
        }

        // 4 — Migrate links JSON blobs → individual rows.
        $rows = $db->query(
            'SELECT name, links FROM bdus_cfg_tables WHERE links IS NOT NULL AND links != \'\' AND links != \'null\'',
            [],
            'read'
        ) ?: [];

        $db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $links = json_decode($row['links'], true);
                if (!is_array($links) || empty($links)) {
                    continue;
                }
                $sort = 0;
                foreach ($links as $link) {
                    $otherTb = $link['other_tb'] ?? null;
                    if (!$otherTb) continue;

                    $fld = isset($link['fld'])
                        ? json_encode($link['fld'], JSON_UNESCAPED_UNICODE)
                        : null;

                    $db->query(
                        'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,?)',
                        [$row['name'], $otherTb, $fld, $sort++],
                        'boolean'
                    );
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
