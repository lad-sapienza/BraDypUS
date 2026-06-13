<?php

declare(strict_types=1);

namespace Bdus;

/**
 * Serializes a BraDypUS app configuration to an annotated DBML string.
 *
 * The output is valid DBML (readable by dbdiagram.io) enriched with
 * bdus:* annotations in column/table Note fields that allow faithful
 * re-import via DbmlImporter.
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class DbmlExporter
{
    /**
     * Maps BraDypUS field types to DBML column types.
     * Used when no db_type is available.
     */
    private const BDUS_TO_DBML = [
        'text'         => 'varchar',
        'long_text'    => 'text',
        'date'         => 'date',
        'boolean'      => 'boolean',
        'slider'       => 'integer',
        'select'       => 'varchar',
        'combo_select' => 'varchar',
        'multi_select' => 'varchar',
    ];

    /**
     * Maps BraDypUS db_type to DBML column type (used as primary source).
     */
    private const DBTYPE_TO_DBML = [
        'TEXT'      => 'varchar',
        'INTEGER'   => 'integer',
        'TIMESTAMP' => 'timestamp',
    ];

    /**
     * BraDypUS field types whose bdus:type annotation must always be emitted
     * so re-import can reconstruct the exact type (they differ from the default
     * mapping of their db_type).
     */
    private const NEEDS_TYPE_ANNOTATION = [
        'long_text', 'date', 'boolean', 'slider', 'combo_select', 'multi_select',
    ];

    /**
     * Exports the full app configuration as an annotated DBML string.
     *
     * @param array $tables    Value of cfg->get('tables') — keyed by table name
     * @param array $vocItems  Rows from bdus_vocabularies: list of {voc, def, sort}
     * @param string $appName  Human-readable app name (used in header comment)
     */
    public function export(array $tables, array $vocItems, string $appName = ''): string
    {
        $parts = [];

        // Header
        $parts[] = "// BraDypUS DBML export" . ($appName ? " — $appName" : '');
        $parts[] = "// Generated: " . date('Y-m-d H:i:s');
        $parts[] = '';

        // Vocabulary enums
        $vocsByName = $this->groupVocabularies($vocItems);
        foreach ($vocsByName as $vocName => $values) {
            $parts[] = $this->renderEnum($vocName, $values);
        }

        // Tables
        foreach ($tables as $tbName => $tbData) {
            $parts[] = $this->renderTable($tbName, $tbData, $vocsByName);
        }

        return implode("\n", $parts);
    }

    // ── Render helpers ───────────────────────────────────────────────────────

    private function renderEnum(string $name, array $values): string
    {
        $lines   = [];
        $lines[] = "Enum $name {";
        foreach ($values as $val) {
            $lines[] = '  "' . addslashes($val) . '"';
        }
        $lines[] = "  // bdus:vocabulary";
        $lines[] = '}';
        $lines[] = '';
        return implode("\n", $lines);
    }

    private function renderTable(string $tbName, array $tbData, array $vocsByName): string
    {
        $lines = [];
        $lines[] = "Table $tbName {";

        $fields = $tbData['fields'] ?? [];
        foreach ($fields as $fldName => $fld) {
            $lines[] = '  ' . $this->renderColumn($fldName, $fld);
        }

        $tableNote = $this->buildTableNote($tbName, $tbData, $vocsByName);
        if ($tableNote !== '') {
            $lines[] = "  Note: '$tableNote'";
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderColumn(string $name, array $fld): string
    {
        $dbmlType    = $this->resolveDbmlType($fld);
        $constraints = $this->buildColumnConstraints($name, $fld);

        $line = "$name $dbmlType";
        if ($constraints !== '') {
            $line .= " [$constraints]";
        }
        return $line;
    }

    /** Returns the DBML column type for a field definition. */
    private function resolveDbmlType(array $fld): string
    {
        // id_from_tb fields store an integer FK
        if (!empty($fld['id_from_tb'])) {
            return 'integer';
        }
        // Use db_type as primary source
        $dbType = strtoupper($fld['db_type'] ?? 'TEXT');
        if (isset(self::DBTYPE_TO_DBML[$dbType])) {
            return self::DBTYPE_TO_DBML[$dbType];
        }
        // Fall back to bdus type
        return self::BDUS_TO_DBML[$fld['type'] ?? 'text'] ?? 'varchar';
    }

    /** Builds the inline constraint string for a column (without surrounding []). */
    private function buildColumnConstraints(string $name, array $fld): string
    {
        $parts = [];

        // Primary key
        if ($name === 'id') {
            $parts[] = 'pk';
            $parts[] = 'increment';
        }

        // FK ref for id_from_tb
        if (!empty($fld['id_from_tb'])) {
            $parts[] = "ref: > {$fld['id_from_tb']}.id";
        }

        // Build note string
        $noteParts = [];

        // bdus:type annotation for types that don't round-trip from db_type alone
        $bdusType = $fld['type'] ?? 'text';
        if (in_array($bdusType, self::NEEDS_TYPE_ANNOTATION, true)) {
            $noteParts[] = "bdus:type=$bdusType";
        }

        // Vocabulary
        if (!empty($fld['vocabulary_set'])) {
            $noteParts[] = "bdus:voc={$fld['vocabulary_set']}";
        }

        // id_from_tb marker
        if (!empty($fld['id_from_tb'])) {
            $noteParts[] = 'bdus:id_from_tb';
        }

        // get_values_from_tb
        if (!empty($fld['get_values_from_tb'])) {
            $noteParts[] = "bdus:get_values_from_tb={$fld['get_values_from_tb']}";
        }

        // multi_select
        if ($bdusType === 'multi_select') {
            $noteParts[] = 'bdus:multi_select';
        }

        if (!empty($noteParts)) {
            $parts[] = "note: '" . implode(' ', $noteParts) . "'";
        }

        return implode(', ', $parts);
    }

    /** Builds the table-level Note annotation string. */
    private function buildTableNote(string $tbName, array $tbData, array $vocsByName): string
    {
        $parts = [];

        $label = $tbData['label'] ?? '';
        if ($label && $label !== $tbName) {
            $parts[] = "bdus:label=$label";
        }

        if (!empty($tbData['is_plugin'])) {
            $pluginOf = $tbData['plugin_of'] ?? '';
            $parts[] = "bdus:is_plugin=$pluginOf";
        }

        $preview = $tbData['preview'] ?? [];
        if (!empty($preview)) {
            $parts[] = 'bdus:preview=' . implode(',', (array) $preview);
        }

        $order = $tbData['order'] ?? '';
        if ($order && $order !== 'id') {
            $parts[] = "bdus:order=$order";
        }

        $idField = $tbData['id_field'] ?? '';
        if ($idField && $idField !== 'id') {
            $parts[] = "bdus:id_field=$idField";
        }

        if (!empty($tbData['rs'])) {
            $parts[] = 'bdus:rs=1';
        }
        if (!empty($tbData['geodata'])) {
            $parts[] = 'bdus:geodata=1';
        }
        if (!empty($tbData['fuzzy_date'])) {
            $parts[] = 'bdus:fuzzy_date=1';
        }

        return implode(' ', $parts);
    }

    /**
     * Groups vocabulary rows (from bdus_vocabularies) by voc name.
     *
     * @param array $rows  List of {voc, def, sort}
     * @return array<string, list<string>>
     */
    private function groupVocabularies(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['voc']][] = $row['def'];
        }
        return $groups;
    }
}
