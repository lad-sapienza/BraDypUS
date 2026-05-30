<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Migrates table/field config and templates from JSON files on disk into the
 * database (bdus_cfg_tables, bdus_cfg_fields, bdus_cfg_templates).
 *
 * Why:
 *   – Atomic backups: a single DB dump captures everything.
 *   – Stateless API: no write permission needed on cfg/ for config changes.
 *   – Consistency: adding a field = one transaction (ALTER TABLE + INSERT into
 *     bdus_cfg_fields) instead of two independent operations.
 *
 * What stays on the filesystem:
 *   – cfg/app_data.json  (DB credentials — needed to bootstrap the connection)
 *   – db/*.sqlite / binary assets (files, geodata)
 *
 * Idempotency:
 *   The migration is tracked in bdus_migrations like all others. If the target
 *   tables already have rows when M011 runs (shouldn't happen in practice),
 *   the import is skipped to avoid duplicates.
 *
 * After this migration the JSON files are still present on disk but are no
 *   longer read by Config or Template\Loader. They can be archived or removed
 *   once the new system is confirmed stable (Phase 8 cleanup).
 */
class M011_ConfigToDb
{
    public const NAME = 'M011_config_to_db';

    /** Table attributes stored as explicit columns; everything else → extra JSON. */
    private const TABLE_COLUMNS = [
        'name', 'label', 'order', 'id_field', 'preview',
        'is_plugin', 'plugin_of', 'sort', 'link', 'backlink', 'fields',
    ];

    /** Field attributes stored as explicit columns; everything else → extra JSON. */
    private const FIELD_COLUMNS = ['name', 'label', 'type', 'db_type', 'sort'];

    /**
     * @param Manage      $manage   System table manager (provides DB + DDL).
     * @param string|null $projDir  Override for the project root directory
     *                              (must end with '/').  Defaults to the
     *                              PROJ_DIR constant when null.  Useful in
     *                              tests where PROJ_DIR is fixed by bootstrap.
     */
    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $db = $manage->getDb();

        // 1 — Create the four new system tables (idempotent).
        foreach (['bdus_cfg_tables', 'bdus_cfg_fields', 'bdus_cfg_templates', 'bdus_cfg_relations'] as $tbl) {
            if (!self::tableExists($db, $tbl)) {
                $manage->createTable($tbl);
            }
        }

        // 2 — Skip import if bdus_cfg_tables already has rows.
        $existing = $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read');
        if (($existing[0]['cnt'] ?? 0) > 0) {
            return;
        }

        // 3 — Locate the cfg/ directory.
        //     Caller may pass an explicit $projDir (useful in tests where the
        //     PROJ_DIR constant is locked in by the test bootstrap).
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return; // Cannot locate files without app context.
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $cfgDir      = $root . 'cfg/';
        $tablesFile  = $cfgDir . 'tables.json';

        if (!file_exists($tablesFile)) {
            return; // Fresh app with no JSON config — nothing to import.
        }

        // 4 — Import tables.json → bdus_cfg_tables.
        $tablesJson = json_decode(file_get_contents($tablesFile), true);
        if (!$tablesJson || empty($tablesJson['tables'])) {
            return;
        }

        $db->beginTransaction();
        try {
            $sort = 0;
            foreach ($tablesJson['tables'] as $tbRow) {
                $name = $tbRow['name'] ?? null;
                if (!$name) continue;

                // Skip legacy system-table entries that crept into tables.json.
                if (self::isSystemTable($name)) continue;

                // Collect non-standard table attributes into extra JSON.
                $tbExtra = [];
                foreach ($tbRow as $k => $v) {
                    if (!in_array($k, self::TABLE_COLUMNS, true)) {
                        $tbExtra[$k] = $v;
                    }
                }

                $db->query(
                    'INSERT INTO bdus_cfg_tables
                        (name, label, order_field, id_field, preview,
                         is_plugin, plugin_of, sort, links, backlinks, extra)
                     VALUES (?,?,?,?,?,?,?,?,NULL,?,?)',
                    [
                        $name,
                        $tbRow['label']     ?? null,
                        $tbRow['order']     ?? null,
                        $tbRow['id_field']  ?? 'id',
                        isset($tbRow['preview'])
                            ? json_encode($tbRow['preview'], JSON_UNESCAPED_UNICODE)
                            : null,
                        isset($tbRow['is_plugin']) ? (int)$tbRow['is_plugin'] : 0,
                        $tbRow['plugin_of'] ?? null,
                        $sort++,
                        isset($tbRow['backlink'])
                            ? json_encode($tbRow['backlink'], JSON_UNESCAPED_UNICODE)
                            : null,
                        empty($tbExtra)
                            ? null
                            : json_encode($tbExtra, JSON_UNESCAPED_UNICODE),
                    ],
                    'boolean'
                );

                // 4b — Import link[] → bdus_cfg_relations.
                $linkSort = 0;
                foreach ($tbRow['link'] ?? [] as $link) {
                    $toTb = $link['other_tb'] ?? null;
                    if (!$toTb) continue;
                    $fld = isset($link['fld'])
                        ? json_encode($link['fld'], JSON_UNESCAPED_UNICODE)
                        : null;
                    $db->query(
                        'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,?)',
                        [$name, $toTb, $fld, $linkSort++],
                        'boolean'
                    );
                }

                // 5 — Import {tb}.json → bdus_cfg_fields.
                $fieldFile = $cfgDir . $name . '.json';
                if (!file_exists($fieldFile)) continue;

                $fields = json_decode(file_get_contents($fieldFile), true) ?: [];
                foreach ($fields as $fieldSort => $fld) {
                    $fldName = $fld['name'] ?? null;
                    if (!$fldName) continue;

                    // Split known columns from extra attributes.
                    $extra = [];
                    foreach ($fld as $k => $v) {
                        if (!in_array($k, self::FIELD_COLUMNS, true)) {
                            $extra[$k] = $v;
                        }
                    }

                    $db->query(
                        'INSERT INTO bdus_cfg_fields
                            (table_name, name, label, type, db_type, sort, extra)
                         VALUES (?,?,?,?,?,?,?)',
                        [
                            $name,
                            $fldName,
                            $fld['label']   ?? null,
                            $fld['type']    ?? 'text',
                            $fld['db_type'] ?? null,
                            $fld['sort']    ?? $fieldSort,
                            empty($extra)
                                ? null
                                : json_encode($extra, JSON_UNESCAPED_UNICODE),
                        ],
                        'boolean'
                    );
                }
            }

            // 6 — Import template/*.json → bdus_cfg_templates.
            $tmplDir = $root . 'template/';
            if (is_dir($tmplDir)) {
                foreach (scandir($tmplDir) ?: [] as $entry) {
                    if (!str_ends_with($entry, '.json')) continue;

                    // Filename format: {tb}.{name}.json
                    $parts = explode('.', $entry, 3);
                    if (count($parts) !== 3) continue;
                    [$tbName, $tmplName] = $parts;

                    $content = file_get_contents($tmplDir . $entry);
                    if ($content === false) continue;

                    $db->query(
                        'INSERT INTO bdus_cfg_templates (table_name, name, content, updated_at)
                         VALUES (?,?,?,?)',
                        [
                            $tbName,
                            $tmplName,
                            $content,
                            date('Y-m-d H:i:s', filemtime($tmplDir . $entry)),
                        ],
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function tableExists($db, string $table): bool
    {
        $engine = $db->getEngine();
        if ($engine === 'sqlite') {
            $rows = $db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$table], 'read'
            );
        } elseif ($engine === 'pgsql') {
            $rows = $db->query(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_name = ? AND table_schema = 'public'",
                [$table], 'read'
            );
        } else {
            $rows = $db->query(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_name = ? AND table_schema = DATABASE()",
                [$table], 'read'
            );
        }
        return !empty($rows);
    }

    /** Returns true for known bdus_* system table names (bare or prefixed). */
    private static function isSystemTable(string $name): bool
    {
        $bare = str_starts_with($name, 'bdus_') ? substr($name, 5) : $name;
        return in_array($bare, [
            'files', 'file_links', 'userlinks', 'users', 'user_table_privs',
            'geodata', 'rs', 'versions', 'log', 'vocabularies',
            'queries', 'charts', 'api_keys', 'migrations',
            'cfg_tables', 'cfg_fields', 'cfg_templates',
        ], true);
    }
}
