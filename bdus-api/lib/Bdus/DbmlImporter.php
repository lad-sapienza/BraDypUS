<?php

declare(strict_types=1);

namespace Bdus;

use Config\Config;
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
    public function preview(array $parsed, Config $cfg): array
    {
        $existingTables = array_keys($cfg->get('tables') ?? []);
        $tables         = [];
        $hasErrors      = false;

        foreach ($parsed['tables'] as $tbName => $tbData) {
            $entry = $this->previewTable($tbName, $tbData, $existingTables);
            if (!empty($entry['errors'])) {
                $hasErrors = true;
            }
            $tables[] = $entry;
        }

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
        $preview = $this->preview($parsed, $cfg);

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

        // Insert vocabulary rows
        foreach ($preview['vocabularies'] as $voc) {
            $this->applyVocabulary($voc, $db, $warnings);
        }

        return ['created' => $created, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function previewTable(string $tbName, array $tbData, array $existingTables): array
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
            'fields'    => [],
            'warnings'  => [],
            'errors'    => [],
        ];

        // Hard stop: table already exists
        if (in_array($tbName, $existingTables, true)) {
            $entry['errors'][] = ['code' => 'table_already_exists', 'table' => $tbName];
            return $entry;
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

    private function applyTable(array $tbEntry, Config $cfg, DB $db, array &$warnings): void
    {
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
        $cfg->setTable($tbData);

        // 2. Create minimal DB table
        $alter = new Alter($db);
        $alter->createMinimalTable($tbName, $isPlugin, $pluginOf);

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
        foreach ($tbEntry['fields'] as $fldData) {
            if (in_array($fldData['name'], ['id', 'creator', 'table_link', 'id_link'], true)) {
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
