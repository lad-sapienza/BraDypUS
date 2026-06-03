<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Migrates bdus_rs.first and bdus_rs.second from TEXT to INTEGER.
 *
 * Old design: stored the value of the configured rs_field (e.g. the text
 * value of a "us_number" column, or the stringified integer "id" column).
 *
 * New design: stores the integer primary key (id) of the referenced record,
 * enabling proper relational integrity.  The display label is resolved at
 * read-time via the table's configured id_field.
 *
 * Migration steps:
 *  1. Skip if bdus_rs already uses INTEGER columns (idempotent guard).
 *  2. Read the old per-table rs_field names from the DB config (bdus_cfg_tables)
 *     or fallback to the JSON file if M011 has not run yet.
 *  3. For each row, resolve first/second (text identifier → integer id):
 *     - If rs_field was "id": values are already stringified integers, cast directly.
 *     - Otherwise: SELECT id FROM {tb} WHERE {rs_field} = ? for each unique value.
 *  4. Recreate bdus_rs with INTEGER columns (rs.json already updated).
 *  5. Re-insert converted rows, dropping any that cannot be resolved.
 *  6. Update config: rs: "fieldname" → rs: 1 (boolean flag).
 */
class M030_RsIdsToInteger
{
    public const NAME = 'M030_rs_ids_to_integer';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_rs')) {
            return;
        }

        $db = $manage->getDb();

        $isSqlite = $db->getEngine() === 'sqlite';

        // ── Guard / recovery from partial previous run ────────────────────────
        // We use a backup table (bdus_rs_v4_backup) as a safety net instead of
        // DROP, so a crash between the table swap and the commit is recoverable.
        if ($isSqlite) {
            $backupExists = (int)($db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='bdus_rs_v4_backup'",
                [], 'read'
            )[0]['cnt'] ?? 0) > 0;

            $cols    = $db->query('PRAGMA table_info(bdus_rs)', [], 'read') ?: [];
            $typeMap = array_column($cols, 'type', 'name');
            $isInt   = isset($typeMap['first'], $typeMap['second'])
                    && strtoupper($typeMap['first'])  === 'INTEGER'
                    && strtoupper($typeMap['second']) === 'INTEGER';

            if ($isInt && !$backupExists) {
                return; // Already fully migrated.
            }

            if ($isInt && $backupExists) {
                // Previous partial run: new table exists but inserts didn't complete.
                // Restore original data from backup and re-run.
                $db->exec('DROP TABLE bdus_rs');
                $db->exec('ALTER TABLE bdus_rs_v4_backup RENAME TO bdus_rs');
            }
        }

        // Read existing rows (TEXT first/second)
        $rows = $db->query('SELECT id, tb, first, second, relation FROM bdus_rs', [], 'read') ?: [];

        // Build per-tb resolution maps: text identifier → integer id
        $resolveMaps = self::buildResolveMaps($db, $rows);

        // Convert each row to integer first/second
        $converted = [];
        foreach ($rows as $row) {
            $tb  = $row['tb'];
            $map = $resolveMaps[$tb] ?? null;

            if ($map !== null) {
                $first  = $map[(string)$row['first']]  ?? (is_numeric($row['first'])  ? (int)$row['first']  : null);
                $second = $map[(string)$row['second']] ?? (is_numeric($row['second']) ? (int)$row['second'] : null);
            } else {
                $first  = is_numeric($row['first'])  ? (int)$row['first']  : null;
                $second = is_numeric($row['second']) ? (int)$row['second'] : null;
            }

            if ($first === null || $second === null) {
                continue; // Unresolvable orphan — drop it
            }

            $converted[] = [
                'tb'       => $tb,
                'first'    => $first,
                'second'   => $second,
                'relation' => (int)$row['relation'],
            ];
        }

        // Rename old table to backup (preserves v4 data until commit succeeds).
        if ($isSqlite) {
            $db->exec('ALTER TABLE bdus_rs RENAME TO bdus_rs_v4_backup');
        } else {
            $db->exec('DROP TABLE IF EXISTS bdus_rs');
        }
        $manage->createTable('bdus_rs');

        if (!empty($converted)) {
            $db->beginTransaction();
            try {
                foreach ($converted as $r) {
                    $db->query(
                        'INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?,?,?,?)',
                        [$r['tb'], $r['first'], $r['second'], $r['relation']],
                        'boolean'
                    );
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                // Restore original table from backup so the migration can be retried.
                if ($isSqlite) {
                    try {
                        $db->exec('DROP TABLE bdus_rs');
                        $db->exec('ALTER TABLE bdus_rs_v4_backup RENAME TO bdus_rs');
                    } catch (\Throwable $inner) {
                        // Best-effort restore; log the inner error if possible.
                    }
                }
                throw $e;
            }
        }

        // Inserts committed — safe to drop the backup.
        if ($isSqlite) {
            try {
                $db->exec('DROP TABLE IF EXISTS bdus_rs_v4_backup');
            } catch (\Throwable $e) {
                // Non-fatal: backup table stays but migration data is correct.
            }
        }

        // Update config: rs: "fieldname" → rs: 1
        self::updateConfigFlag($db);
    }

    /**
     * Builds per-tb maps from old text identifier → integer record id.
     * Returns [ tb => [textValue => intId] ] or [ tb => null ] when a direct
     * numeric cast suffices (rs_field was "id").
     *
     * @param  array $rows  All rows currently in bdus_rs
     */
    private static function buildResolveMaps(\DB\DBInterface $db, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $tbsNeeded = array_unique(array_column($rows, 'tb'));
        $rsConfig  = self::readRsConfig($db);

        $maps = [];
        foreach ($tbsNeeded as $tb) {
            $rsField = $rsConfig[$tb] ?? null;

            // No config, already 'id', or boolean flag → values are numeric strings
            if (
                $rsField === null ||
                $rsField === false ||
                $rsField === true  ||
                $rsField === 1     ||
                $rsField === '1'   ||
                $rsField === 'id'
            ) {
                $maps[$tb] = null;
                continue;
            }

            // Need to lookup text identifiers in the user table
            try {
                $tbRows = $db->query(
                    "SELECT id, {$rsField} AS label FROM {$tb}",
                    [],
                    'read'
                ) ?: [];
                $map = [];
                foreach ($tbRows as $r) {
                    if ($r['label'] !== null) {
                        $map[(string)$r['label']] = (int)$r['id'];
                    }
                }
                $maps[$tb] = $map;
            } catch (\Throwable $e) {
                $maps[$tb] = null; // Table not found — fall back to numeric cast
            }
        }

        return $maps;
    }

    /**
     * Reads the old per-table rs_field names from DB config or JSON fallback.
     * Returns [ tb_name => field_name_string ].
     */
    private static function readRsConfig(\DB\DBInterface $db): array
    {
        $result = [];

        // Prefer DB-backed config (post-M011)
        try {
            $rows = $db->query(
                "SELECT name, extra FROM bdus_cfg_tables WHERE extra IS NOT NULL AND extra != ''",
                [],
                'read'
            ) ?: [];
            foreach ($rows as $row) {
                $extra = json_decode($row['extra'] ?? '', true) ?: [];
                if (!empty($extra['rs']) && $extra['rs'] !== false && $extra['rs'] !== 1 && $extra['rs'] !== true) {
                    $result[$row['name']] = (string)$extra['rs'];
                }
            }
            if (!empty($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            // bdus_cfg_tables not yet available — fall through
        }

        // Fallback: legacy JSON file
        if (!defined('PROJ_DIR')) {
            return [];
        }
        $cfgPath = PROJ_DIR . 'cfg/tables.json';
        if (!file_exists($cfgPath)) {
            return [];
        }
        $data = json_decode(file_get_contents($cfgPath), true);
        if (!$data || !isset($data['tables'])) {
            return [];
        }
        foreach ($data['tables'] as $tb) {
            $name = $tb['name'] ?? null;
            $rs   = $tb['rs']   ?? null;
            if ($name && is_string($rs) && $rs !== '' && $rs !== 'id') {
                $result[$name] = $rs;
            }
        }
        return $result;
    }

    /**
     * Updates the stored config to replace rs: "fieldname" with rs: 1.
     * Handles both DB-backed (bdus_cfg_tables.extra) and JSON file configs.
     */
    private static function updateConfigFlag(\DB\DBInterface $db): void
    {
        // DB-backed config
        try {
            $rows = $db->query(
                "SELECT id, extra FROM bdus_cfg_tables WHERE extra IS NOT NULL AND extra != ''",
                [],
                'read'
            ) ?: [];
            foreach ($rows as $row) {
                $extra = json_decode($row['extra'] ?? '', true) ?: [];
                if (isset($extra['rs']) && is_string($extra['rs']) && $extra['rs'] !== '') {
                    $extra['rs'] = 1;
                    $db->query(
                        'UPDATE bdus_cfg_tables SET extra = ? WHERE id = ?',
                        [json_encode($extra, JSON_UNESCAPED_UNICODE), (int)$row['id']],
                        'boolean'
                    );
                }
            }
        } catch (\Throwable $e) {
            // bdus_cfg_tables not available — skip
        }

        // JSON file fallback
        if (!defined('PROJ_DIR')) {
            return;
        }
        $cfgPath = PROJ_DIR . 'cfg/tables.json';
        if (!file_exists($cfgPath)) {
            return;
        }
        $data = json_decode(file_get_contents($cfgPath), true);
        if (!$data || !isset($data['tables'])) {
            return;
        }
        $changed = false;
        foreach ($data['tables'] as &$tb) {
            if (isset($tb['rs']) && is_string($tb['rs']) && $tb['rs'] !== '') {
                $tb['rs'] = 1;
                $changed  = true;
            }
        }
        unset($tb);
        if ($changed) {
            file_put_contents(
                $cfgPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }
}
