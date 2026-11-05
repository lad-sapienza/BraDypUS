<?php

declare(strict_types=1);

namespace Bdus;

use Config\Activate;
use Config\Config;
use Config\FuzzyDate;
use Config\LoadFromDB;
use Config\Relations;
use DB\Alter;
use DB\DB;

/**
 * Converts a parsed DBML structure into BraDypUS configuration and DB schema.
 *
 * Workflow:
 *   1. preview()  — validates and describes what would be created (no side-effects)
 *   2. apply()    — executes the creation (cfg + DB schema + vocabularies)
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class DbmlImporter
{
    /**
     * Maps DBML column types to BraDypUS field types and DB column types.
     * The first matching key wins.
     */
    private const TYPE_MAP = [
        'varchar'   => ['type' => 'text',      'db_type' => 'TEXT'],
        'char'      => ['type' => 'text',      'db_type' => 'TEXT'],
        'text'      => ['type' => 'long_text', 'db_type' => 'TEXT'],
        'integer'   => ['type' => 'text',      'db_type' => 'INTEGER'],
        'int'       => ['type' => 'text',      'db_type' => 'INTEGER'],
        'bigint'    => ['type' => 'text',      'db_type' => 'INTEGER'],
        'smallint'  => ['type' => 'text',      'db_type' => 'INTEGER'],
        'float'     => ['type' => 'text',      'db_type' => 'TEXT'],
        'double'    => ['type' => 'text',      'db_type' => 'TEXT'],
        'decimal'   => ['type' => 'text',      'db_type' => 'TEXT'],
        'numeric'   => ['type' => 'text',      'db_type' => 'TEXT'],
        'real'      => ['type' => 'text',      'db_type' => 'TEXT'],
        'date'      => ['type' => 'date',      'db_type' => 'TEXT'],
        'datetime'  => ['type' => 'date',      'db_type' => 'TIMESTAMP'],
        'timestamp' => ['type' => 'date',      'db_type' => 'TIMESTAMP'],
        'boolean'   => ['type' => 'boolean',   'db_type' => 'INTEGER'],
        'bool'      => ['type' => 'boolean',   'db_type' => 'INTEGER'],
    ];

    /**
     * Validates the parsed DBML and returns a preview of what would be created.
     * No side-effects — nothing is written to DB or cfg.
     *
     * @param array $parsed   Output of DbmlParser::parse()
     * @param Config $cfg     Current app config (read-only)
     * @return array{
     *   tables: list<array>,
     *   vocabularies: list<array{name:string,values:list<string>}>,
     *   has_errors: bool
     * }
     */
    public function preview(array $parsed, Config $cfg, ?DB $db = null): array
    {
        $existingTables = array_keys($cfg->get('tables') ?? []);
        // A deactivated table keeps its name (and its configuration) too.
        if ($db !== null) {
            $existingTables = array_merge($existingTables, array_keys(LoadFromDB::inactiveTables($db)));
        }
        $tables         = [];
        $hasErrors      = false;

        foreach ($parsed['tables'] as $tbName => $tbData) {
            $entry = $this->previewTable($tbName, $tbData, $existingTables, $cfg, $db);
            if (!empty($entry['errors'])) {
                $hasErrors = true;
            }
            $tables[] = $entry;
        }

        $this->previewRelations($tables, $parsed, $cfg);

        $vocabularies = $this->previewVocabularies($parsed['enums']);

        return [
            'tables'       => $tables,
            'vocabularies' => $vocabularies,
            'has_errors'   => $hasErrors,
        ];
    }

    /**
     * Applies the parsed DBML: creates DB tables, writes cfg, inserts vocabulary rows.
     * Call only after preview() confirms no hard errors.
     *
     * @return array{created:list<string>,skipped:list<string>,warnings:list<string>}
     */
    public function apply(array $parsed, Config $cfg, DB $db): array
    {
        $preview = $this->preview($parsed, $cfg, $db);
        $existingTables = array_keys($cfg->get('tables') ?? []);

        $created  = [];
        $skipped  = [];
        $warnings = [];

        foreach ($preview['tables'] as $tbEntry) {
            $tbName = $tbEntry['name'];

            if (!empty($tbEntry['errors'])) {
                $skipped[] = $tbName;
                continue;
            }

            try {
                $this->applyTable($tbEntry, $cfg, $db, $warnings);
                $created[] = $tbName;
            } catch (\Throwable $e) {
                $skipped[] = $tbName;
                $warnings[] = "Error creating table $tbName: " . $e->getMessage();
            }
        }

        // Relations (FK constraints) come after ALL tables exist: a ref may point to
        // a table defined later in the same DBML.
        $this->applyRelations($preview['tables'], $created, $existingTables, $cfg, $db, $warnings);

        // Insert vocabulary rows
        foreach ($preview['vocabularies'] as $voc) {
            $this->applyVocabulary($voc, $db, $warnings);
        }

        return ['created' => $created, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function previewTable(string $tbName, array $tbData, array $existingTables, ?Config $cfg = null, ?DB $db = null): array
    {
        $entry = [
            'name'      => $tbName,
            'label'     => ucfirst($tbName),
            'is_plugin' => false,
            'plugin_of' => '',
            'preview'   => ['id'],
            'order'     => 'id',
            'id_field'  => 'id',
            'rs'        => false,
            'geodata'   => false,
            'fuzzy_date'=> false,
            'is_view'   => false,
            'annotations' => [],
            'fields'    => [],
            'warnings'  => [],
            'errors'    => [],
        ];

        // Hard stop: table already exists
        if (in_array($tbName, $existingTables, true)) {
            $entry['errors'][] = ['code' => 'table_already_exists', 'table' => $tbName];
            return $entry;
        }

        // bdus:view=1 — a SQL view that must already exist in the database.
        $viewAnn = $this->parseAnnotations($tbData['note']);
        if (($viewAnn['view'] ?? '0') === '1') {
            return $this->previewView($entry, $viewAnn, $cfg, $db);
        }

        // Hard stop: PK field is not named 'id'
        foreach ($tbData['columns'] as $colName => $col) {
            if ($col['pk'] && $colName !== 'id') {
                $entry['errors'][] = ['code' => 'pk_must_be_id', 'field' => $colName];
                return $entry;
            }
        }

        // Parse table-level bdus: annotations
        $ann = $this->parseAnnotations($tbData['note']);
        if (isset($ann['label'])) {
            $entry['label'] = $ann['label'];
        }
        if (isset($ann['is_plugin'])) {
            $entry['is_plugin'] = true;
            $entry['plugin_of'] = $ann['is_plugin'];
        }
        if (isset($ann['preview'])) {
            $entry['preview'] = array_map('trim', explode(',', $ann['preview']));
        }
        if (isset($ann['order'])) {
            $entry['order'] = $ann['order'];
        }
        if (isset($ann['id_field'])) {
            $entry['id_field'] = $ann['id_field'];
        }
        $entry['rs']         = ($ann['rs']         ?? '0') === '1';
        $entry['geodata']    = ($ann['geodata']    ?? '0') === '1';
        $entry['fuzzy_date'] = ($ann['fuzzy_date'] ?? '0') === '1';

        // Warnings for missing system fields
        if (!isset($tbData['columns']['id'])) {
            $entry['warnings'][] = ['code' => 'auto_add_id'];
        }
        if (!$entry['is_plugin'] && !isset($tbData['columns']['creator'])) {
            $entry['warnings'][] = ['code' => 'auto_add_creator'];
        }

        // Build field preview
        foreach ($tbData['column_order'] as $colName) {
            $entry['fields'][] = $this->previewField($colName, $tbData['columns'][$colName]);
        }

        return $entry;
    }

    /**
     * A table marked `bdus:view=1`: it is not created, it is activated. It must exist in
     * the database as a view and satisfy the activation rules (id column, and creator when
     * it can be written); its fields come from the database, not from the DBML.
     */
    private function previewView(array $entry, array $ann, ?Config $cfg, ?DB $db): array
    {
        $entry['is_view']     = true;
        $entry['annotations'] = $ann;
        foreach (['label', 'order', 'id_field'] as $k) {
            if (isset($ann[$k])) {
                $entry[$k] = $ann[$k];
            }
        }
        if (isset($ann['preview'])) {
            $entry['preview'] = array_map('trim', explode(',', $ann['preview']));
        }
        if (($ann['fuzzy_date'] ?? '0') === '1') {
            $entry['warnings'][] = ['code' => 'view_flag_ignored', 'flag' => 'fuzzy_date'];
        }
        if (isset($ann['is_plugin'])) {
            $entry['errors'][] = ['code' => 'view_cannot_be_plugin'];
            return $entry;
        }
        if ($db === null || $cfg === null) {
            $entry['errors'][] = ['code' => 'view_check_unavailable'];
            return $entry;
        }

        $plan = Activate::analyse($db, $cfg, $entry['name']);
        if (($plan['status'] ?? '') !== 'success') {
            $entry['errors'][] = ['code' => 'view_not_found'];
        } elseif ($plan['kind'] !== 'view') {
            $entry['errors'][] = ['code' => 'not_a_view'];
        } elseif (!$plan['activatable']) {
            $entry['errors'][] = ['code' => $plan['reason']];
        } else {
            $entry['fields'] = array_map(
                static fn(array $f) => ['name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'db_type' => $f['db_type']],
                $plan['fields']
            );
        }
        return $entry;
    }

    private function previewField(string $name, array $col): array
    {
        $ann = $this->parseAnnotations($col['note']);

        // Determine BraDypUS type and db_type from DBML type + annotations
        $mapped  = self::TYPE_MAP[$col['type']] ?? ['type' => 'text', 'db_type' => 'TEXT'];
        $bdusType = $ann['type'] ?? $mapped['type'];
        $dbType   = $mapped['db_type'];

        // Vocabulary reference
        $vocSet    = $ann['voc'] ?? null;
        $idFromTb  = null;

        if ($vocSet !== null) {
            $bdusType = 'select';
            $dbType   = 'TEXT';
        } elseif (isset($ann['id_from_tb']) && $col['ref'] !== null) {
            $bdusType = 'select';
            $dbType   = 'INTEGER';
            $idFromTb = $col['ref']['table'];
        }

        // boolean forces INTEGER
        if ($bdusType === 'boolean') {
            $dbType = 'INTEGER';
        }

        $field = [
            'name'     => $name,
            'label'    => ucfirst(str_replace('_', ' ', $name)),
            'type'     => $bdusType,
            'db_type'  => $dbType,
            'not_null' => $col['not_null'],
        ];

        if ($vocSet !== null) {
            $field['vocabulary_set'] = $vocSet;
        }
        if ($idFromTb !== null) {
            $field['id_from_tb'] = $idFromTb;
        }

        return $field;
    }

    /** @return list<array{name:string,values:list<string>}> */
    private function previewVocabularies(array $enums): array
    {
        $vocs = [];
        foreach ($enums as $enumName => $enumData) {
            $ann = $this->parseAnnotations($enumData['note']);
            // Only import enums explicitly marked as bdus vocabularies
            if (isset($ann['vocabulary'])) {
                $vocs[] = ['name' => $enumName, 'values' => $enumData['values']];
            }
        }
        return $vocs;
    }

    /**
     * Decides which `ref:` of the NEW tables become relations (Config → Relations rows
     * with their FOREIGN KEY), and says why the others are skipped. Never touches a
     * table that already exists: a ref goes from a new table to any table.
     *
     * Adds to each preview entry `relations` (to create) and appends `warnings`.
     * Annotations on the column note: bdus:on_delete / bdus:on_update
     * (CASCADE | RESTRICT | SET NULL | NO ACTION; default RESTRICT / CASCADE).
     */
    private function previewRelations(array &$tables, array $parsed, Config $cfg): void
    {
        $existing = $cfg->get('tables') ?? [];
        $batch    = [];
        foreach ($tables as $entry) {
            if (empty($entry['errors'])) {
                $batch[$entry['name']] = $entry;
            }
        }

        foreach ($tables as &$entry) {
            $entry['relations'] = [];
            if (empty($entry['errors'])) {
                $tbData = $parsed['tables'][$entry['name']];
                foreach ($tbData['columns'] as $colName => $col) {
                    if ($col['ref'] === null || in_array($colName, ['id', 'creator', 'table_link', 'id_link'], true)) {
                        continue;
                    }
                    $warn = static function (string $code, array $extra = []) use (&$entry, $colName): void {
                        $entry['warnings'][] = ['code' => $code, 'field' => $colName] + $extra;
                    };

                    // A FOREIGN KEY can neither start from nor point to a SQL view.
                    if ($entry['is_view']) {
                        $warn('ref_view_not_allowed', ['to' => $col['ref']['table'] . '.' . $col['ref']['col']]);
                        continue;
                    }

                    $toTb  = $col['ref']['table'];
                    $toCol = $col['ref']['col'];

                    if ($col['ref']['type'] === '<') {
                        // "this column is the one-side": the FK would live on the OTHER table.
                        $warn('ref_direction_unsupported', ['to' => "$toTb.$toCol"]);
                        continue;
                    }
                    if ($entry['is_plugin']) {
                        $warn('ref_plugin_not_allowed', ['to' => "$toTb.$toCol"]);
                        continue;
                    }

                    $targetIsView = false;
                    if (isset($batch[$toTb])) {
                        $targetIsPlugin = $batch[$toTb]['is_plugin'];
                        $targetIsView   = $batch[$toTb]['is_view'] ?? false;
                        $targetHasCol   = $toCol === 'id' || isset($parsed['tables'][$toTb]['columns'][$toCol]);
                    } elseif (isset($existing[$toTb])) {
                        $targetIsPlugin = (bool) ($existing[$toTb]['is_plugin'] ?? false);
                        $targetIsView   = (bool) ($existing[$toTb]['is_view'] ?? false);
                        $targetHasCol   = isset($existing[$toTb]['fields'][$toCol]);
                    } else {
                        $warn('ref_target_missing', ['to' => "$toTb.$toCol"]);
                        continue;
                    }
                    if ($targetIsPlugin) {
                        $warn('ref_plugin_not_allowed', ['to' => "$toTb.$toCol"]);
                        continue;
                    }
                    if ($targetIsView) {
                        $warn('ref_view_not_allowed', ['to' => "$toTb.$toCol"]);
                        continue;
                    }
                    if (!$targetHasCol) {
                        $warn('ref_target_missing', ['to' => "$toTb.$toCol"]);
                        continue;
                    }

                    $ann      = $this->parseAnnotations($col['note']);
                    $policies = ['on_delete' => 'RESTRICT', 'on_update' => 'CASCADE'];
                    foreach ($policies as $key => $default) {
                        $value = $ann[$key] ?? null;
                        if (!is_string($value)) {
                            continue;
                        }
                        if (in_array(strtoupper($value), Relations::POLICIES, true)) {
                            $policies[$key] = strtoupper($value);
                        } else {
                            $warn('invalid_' . $key, ['value' => $value]);
                        }
                    }

                    $entry['relations'][] = [
                        'from_col'  => $colName,
                        'to_tb'     => $toTb,
                        'to_col'    => $toCol,
                        'on_delete' => $policies['on_delete'],
                        'on_update' => $policies['on_update'],
                    ];
                }
            }
        }
        unset($entry);
    }

    /**
     * Creates the relations decided by previewRelations(), through the same code as
     * Config → Relations (config row + FOREIGN KEY + index). A relation that cannot be
     * applied is reported in $warnings as "relation_skipped:table.column:code" and does
     * not stop the import: the tables and fields are already there.
     *
     * @param list<array> $tables         preview()['tables']
     * @param list<string> $created       tables created by this apply()
     * @param list<string> $existingTables tables that existed before apply()
     */
    private function applyRelations(array $tables, array $created, array $existingTables, Config $cfg, DB $db, array &$warnings): void
    {
        foreach ($tables as $entry) {
            if (!in_array($entry['name'], $created, true)) {
                continue;
            }
            foreach ($entry['relations'] ?? [] as $rel) {
                $label = "{$entry['name']}.{$rel['from_col']}";
                if (!in_array($rel['to_tb'], $created, true) && !in_array($rel['to_tb'], $existingTables, true)) {
                    $warnings[] = "relation_skipped:$label:ref_target_missing";
                    continue;
                }
                try {
                    $res = Relations::save(
                        $db, $cfg, null,
                        $entry['name'], $rel['from_col'], $rel['to_tb'], $rel['to_col'],
                        $rel['on_delete'], $rel['on_update']
                    );
                    if (($res['status'] ?? '') !== 'success') {
                        $warnings[] = "relation_skipped:$label:" . ($res['code'] ?? 'error');
                    }
                } catch (\Throwable $e) {
                    // The config row is written before the constraint: do not leave a
                    // relation behind that has no FK in the database.
                    $db->query(
                        'DELETE FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = ?',
                        [$entry['name'], $rel['from_col']],
                        'boolean'
                    );
                    $warnings[] = "relation_skipped:$label:db_error";
                }
            }
        }
    }

    /**
     * A `bdus:view=1` table: activate the existing view (fields from the database),
     * then apply the table-level settings the DBML states explicitly.
     */
    private function applyView(array $tbEntry, Config $cfg, DB $db): void
    {
        $name = $tbEntry['name'];
        $res  = Activate::activate($db, $cfg, $name);
        if (($res['status'] ?? '') !== 'success') {
            throw new \RuntimeException((string) ($res['code'] ?? 'activation_failed'));
        }

        $ann = $tbEntry['annotations'];
        $set = array_intersect_key($tbEntry, array_flip(array_filter(['label', 'order', 'id_field', 'preview'], fn($k) => isset($ann[$k]))));
        if ($set) {
            // Only what the DBML states, on top of what the activation wrote.
            $tbData = [
                'name'          => $name,
                'label'         => $cfg->get("tables.$name.label"),
                'order'         => $cfg->get("tables.$name.order"),
                'id_field'      => $cfg->get("tables.$name.id_field"),
                'preview'       => $cfg->get("tables.$name.preview"),
                'is_plugin'     => 0,
                'is_view'       => true,
                'view_writable' => $cfg->get("tables.$name.view_writable"),
            ];
            $cfg->setTable($set + $tbData);
        }
    }

    private function applyTable(array $tbEntry, Config $cfg, DB $db, array &$warnings): void
    {
        if (!empty($tbEntry['is_view'])) {
            $this->applyView($tbEntry, $cfg, $db);
            return;
        }

        $tbName   = $tbEntry['name'];
        $isPlugin = $tbEntry['is_plugin'];
        $pluginOf = $tbEntry['plugin_of'];

        // 1. Write table metadata to cfg
        $tbData = [
            'name'      => $tbName,
            'label'     => $tbEntry['label'],
            'order'     => $tbEntry['order'],
            'id_field'  => $tbEntry['id_field'],
            'preview'   => $tbEntry['preview'],
            'is_plugin' => $isPlugin ? 1 : 0,
            'plugin_of' => $pluginOf,
        ];
        if ($tbEntry['rs']) {
            $tbData['rs'] = true;
        }
        // bdus:geodata=1 — a flag in the table config, as set from Config → Tables
        // (the geometries live in bdus_geodata, which exists in every app).
        if ($tbEntry['geodata']) {
            $tbData['geodata'] = true;
        }
        $cfg->setTable($tbData);

        // 2. Create minimal DB table
        $alter = new Alter($db);
        $alter->createMinimalTable($tbName, $isPlugin, $pluginOf);

        // Register the plugin→parent attachment as a relation — this is what
        // Config\LoadFromDB actually reads to derive tables.{parent}.plugin[]
        // (the FK constraint itself was already applied above, inline, by
        // createMinimalTable).
        if ($isPlugin && $pluginOf !== '') {
            $db->query(
                'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$tbName, 'id_link', $pluginOf, 'id', 'RESTRICT', 'CASCADE'],
                'boolean'
            );
        }

        // 3. Add 'id' if not in DBML (createMinimalTable already adds it, but cfg needs it)
        $fieldNames = array_column($tbEntry['fields'], 'name');
        if (!in_array('id', $fieldNames, true)) {
            $warnings[] = "auto_add_id:$tbName";
        }
        // Always write id to cfg
        $cfg->setFld($tbName, 'id', [
            'name'     => 'id',
            'label'    => 'Id',
            'type'     => 'text',
            'db_type'  => 'INTEGER',
            'readonly' => true,
        ]);

        // 4. Add 'creator' if not in DBML and not a plugin
        if (!$isPlugin && !in_array('creator', $fieldNames, true)) {
            $warnings[] = "auto_add_creator:$tbName";
        }
        if (!$isPlugin) {
            $cfg->setFld($tbName, 'creator', [
                'name'     => 'creator',
                'label'    => 'Creator',
                'type'     => 'text',
                'db_type'  => 'INTEGER',
                'readonly' => true,
            ]);
        }

        // 5. Write user-defined fields (skip id and creator — already handled above)
        // With bdus:fuzzy_date=1 the five chrono_* columns are created by the plugin
        // activation below (hidden fields, as in Config → Tables); an export of such a
        // table lists them as plain columns, which must not be added a second time.
        $reserved = ['id', 'creator', 'table_link', 'id_link'];
        if ($tbEntry['fuzzy_date']) {
            $reserved = array_merge($reserved, array_keys(FuzzyDate::COLUMNS));
        }
        foreach ($tbEntry['fields'] as $fldData) {
            if (in_array($fldData['name'], $reserved, true)) {
                continue;
            }
            $fld = [
                'name'    => $fldData['name'],
                'label'   => $fldData['label'],
                'type'    => $fldData['type'],
                'db_type' => $fldData['db_type'],
            ];
            if (isset($fldData['vocabulary_set'])) {
                $fld['vocabulary_set'] = $fldData['vocabulary_set'];
            }
            if (isset($fldData['id_from_tb'])) {
                $fld['id_from_tb'] = $fldData['id_from_tb'];
            }
            $cfg->setFld($tbName, $fldData['name'], $fld);
            $alter->addFld($tbName, $fldData['name'], $fldData['db_type']);
        }

        // bdus:fuzzy_date=1 — same activation as Config → Tables (columns + hidden fields + flag)
        if ($tbEntry['fuzzy_date']) {
            FuzzyDate::activate($db, $cfg, $tbName);
        }
    }

    private function applyVocabulary(array $voc, DB $db, array &$warnings): void
    {
        foreach ($voc['values'] as $sort => $def) {
            $db->query(
                'INSERT INTO bdus_vocabularies (voc, def, sort) VALUES (?, ?, ?)',
                [$voc['name'], $def, $sort + 1],
                'boolean'
            );
        }
    }

    /**
     * Parses a bdus:* annotation string.
     * e.g. "bdus:label=My Label bdus:rs=1 bdus:preview=id,name"
     *
     * Values run until the next "bdus:" keyword or end of string, allowing
     * spaces in values (e.g. bdus:label=My Long Label).
     *
     * @return array<string, string|true>
     */
    private function parseAnnotations(string $note): array
    {
        $result = [];
        preg_match_all('/bdus:(\w+)(?:=(.+?))?(?=\s+bdus:|\s*$)/', $note, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = isset($m[2]) && $m[2] !== '' ? trim($m[2]) : true;
            $result[$m[1]] = $value;
        }
        return $result;
    }
}
