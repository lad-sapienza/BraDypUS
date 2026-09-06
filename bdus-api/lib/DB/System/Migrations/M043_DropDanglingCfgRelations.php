<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Removes dangling rows from bdus_cfg_relations whose from_tb or to_tb does not
 * name a real, non-system table.
 *
 * A v4→v5 upgrade of an app that had a per-app `geodata` table (before geodata
 * became the shared, multi-tenant system table bdus_geodata in M037/M038) leaves
 * behind a relation row shaped `geodata.id_link → places.id`, imported verbatim
 * by M011 from the v4 data.json. `geodata` is no longer a real table, so:
 *
 *   - Config\LoadFromDB turns the orphan row into tables.places.link[] = geodata
 *   - Record\Read::getLinks() then runs `SELECT count(id) FROM geodata WHERE …`
 *   - → "no such table: geodata" → every single `places` record read fails with
 *     {status:error, code:db_error}.
 *
 * A configurable relation must always connect two real user tables; it must
 * never point at a bdus_* system table either. This migration deletes any row
 * that violates that. Idempotent — a clean v5 app has nothing to remove.
 */
class M043_DropDanglingCfgRelations
{
    public const NAME = 'M043_drop_dangling_cfg_relations';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_relations')) {
            return;
        }

        $db = $manage->getDb();

        $rows = $db->query(
            'SELECT id, from_tb, to_tb FROM bdus_cfg_relations',
            [],
            'read'
        ) ?: [];

        foreach ($rows as $row) {
            foreach ([$row['from_tb'], $row['to_tb']] as $tb) {
                $name      = (string) $tb;
                $isSystem  = str_starts_with($name, 'bdus_');
                $isMissing = $name === '' || !$manage->tableExists($name);

                if ($isSystem || $isMissing) {
                    $db->query(
                        'DELETE FROM bdus_cfg_relations WHERE id = ?',
                        [$row['id']],
                        'boolean'
                    );
                    break;
                }
            }
        }
    }
}
