<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds to bdus_versions where a change came from: `channel` (web, api, mcp,
 * import, cli), `key_id` (the API key) and `client_id` (the OAuth client).
 * All nullable: entries written before this migration keep them empty.
 *
 * From this version on every INSERT of a record also writes an entry with
 * operation `create` and no content, so the history says who created a record,
 * when and through which channel (see Record\Persist and DB::saveSnapshot()).
 *
 * Purely additive.
 */
class M053_AddProvenanceToVersions
{
    public const NAME = 'M053_add_provenance_to_versions';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_versions')) {
            return;
        }
        $db = $manage->getDb();
        foreach (['channel' => 'TEXT', 'key_id' => 'INTEGER', 'client_id' => 'TEXT'] as $col => $type) {
            if (!$manage->columnExists('bdus_versions', $col)) {
                $db->exec("ALTER TABLE bdus_versions ADD COLUMN {$col} {$type}");
            }
        }
    }
}
