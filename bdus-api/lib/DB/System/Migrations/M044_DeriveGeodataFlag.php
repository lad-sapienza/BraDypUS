<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Backfills bdus_cfg_tables.extra.geodata = "1" for every table that has
 * geometry rows in the shared bdus_geodata table.
 *
 * On a clean v5 app the geo-enabled tables carry extra = {"geodata":"1", …},
 * which Record\Read reads (tables.{tb}.geodata) to decide whether to attach
 * geometry to GET /api/record/{tb}/{id}. A v4→v5 upgrade populates bdus_geodata
 * (through the geoface import) but never sets that flag, so a migrated app
 * always returns `geodata: []` for a record even though the list/map views —
 * which read bdus_geodata directly — work fine.
 *
 * Idempotent: a table that already has the flag is left untouched, and only the
 * `geodata` key inside `extra` is added — every other key is preserved.
 */
class M044_DeriveGeodataFlag
{
    public const NAME = 'M044_derive_geodata_flag';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_geodata')
            || !$manage->tableExists('bdus_cfg_tables')) {
            return;
        }

        $db = $manage->getDb();

        $links = $db->query(
            "SELECT DISTINCT table_link
               FROM bdus_geodata
              WHERE table_link IS NOT NULL AND table_link != ''",
            [],
            'read'
        ) ?: [];

        foreach ($links as $link) {
            $tb = (string) $link['table_link'];

            $rows = $db->query(
                'SELECT extra FROM bdus_cfg_tables WHERE name = ?',
                [$tb],
                'read'
            ) ?: [];
            if (!$rows) {
                // geometry rows for a table with no config entry — nothing to flag
                continue;
            }

            $extra = json_decode((string) ($rows[0]['extra'] ?? ''), true);
            if (!is_array($extra)) {
                $extra = [];
            }
            if (($extra['geodata'] ?? null) === '1') {
                continue; // already flagged
            }

            $extra['geodata'] = '1';

            $db->query(
                'UPDATE bdus_cfg_tables SET extra = ? WHERE name = ?',
                [json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $tb],
                'boolean'
            );
        }
    }
}
