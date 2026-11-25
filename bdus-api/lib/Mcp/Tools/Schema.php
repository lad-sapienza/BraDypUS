<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Config\TableSchema;

/**
 * The schema of the application as the principal may see it: the readable
 * tables, or one table in detail.
 */
class Schema extends Base
{
    public function name(): string
    {
        return 'schema';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Schema',
            'description' => 'Without arguments: the tables you may read, with what you may do on each. '
                . 'With `table`: its fields (type, help, checks, where the accepted values come from), '
                . 'its plugin tables and its key field.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table' => ['type' => 'string', 'description' => 'Table name, as listed by this tool without arguments.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        if (!isset($args['table'])) {
            return ['tables' => $this->tables($ctx)];
        }

        $tb     = $this->table($args, $ctx);
        $schema = (new TableSchema($ctx->cfg))->describe($tb);
        $isView = (bool) $ctx->cfg->get("tables.{$tb}.is_view");

        $plugins = [];
        foreach ($schema['plugins'] as $name => $plg) {
            $plugins[] = ['table' => $name, 'label' => $plg['label'], 'fields' => $this->compact($plg['fields'])];
        }

        return array_filter([
            'table'       => $tb,
            'label'       => $ctx->cfg->get("tables.{$tb}.label") ?: $tb,
            'description' => $ctx->cfg->get("tables.{$tb}.description") ?: null,
            'key_field'   => $schema['id_field'],
            'can'         => \Auth\Authorization::tableAbilities($tb),
            'is_view'     => $isView ?: null,
            'writable'    => $isView ? (($ctx->cfg->get("tables.{$tb}.view_writable") ?: 'none') !== 'none') : null,
            'features'    => $this->features($schema),
            'bibliography' => !empty($schema['has_zotero']) ? self::bibliography() : null,
            'links'        => $isView ? null : self::links(),
            'stratigraphy' => !empty($schema['rs']) ? self::stratigraphy() : null,
            'fields'      => $this->compact($schema['fields']),
            'plugins'     => $plugins,
        ], fn($v) => $v !== null);
    }

    /** Non-plugin tables the principal may read, in configuration order. */
    private function tables(Context $ctx): array
    {
        $out = [];
        foreach ($ctx->cfg->get('tables.*.label', 'is_plugin', null) ?: [] as $name => $label) {
            if (str_starts_with($name, 'bdus_') || !\Auth\Authorization::can('read', table: $name)) {
                continue;
            }
            $out[] = array_filter([
                'table'       => $name,
                'label'       => $label ?: $name,
                'description' => $ctx->cfg->get("tables.{$name}.description") ?: null,
                'can'         => \Auth\Authorization::tableAbilities($name),
                'plugins'     => array_values($ctx->cfg->get("tables.{$name}.plugin") ?: []) ?: null,
            ], fn($v) => $v !== null);
        }
        return $out;
    }

    /** How to read and write the Zotero links of a table that has bibliography. */
    private static function bibliography(): array
    {
        return [
            'reading' => 'records_read with bibliography: true returns the links of each record.',
            'finding' => 'bibliography_search lists the Zotero libraries and finds the item (its key) to link.',
            'writing' => 'The `bibliography` section of a record in records_validate / records_write: '
                . '{ add: [{ lib_id, zotero_key, pages?, notes? }], edit: [{ id, pages?, notes?, sort? }], remove: [link id] }.',
        ];
    }

    /** How to read and write the manual links between records (any table). */
    private static function links(): array
    {
        return [
            'reading' => 'records_read with links: true returns the manual links of each record (id of the link, table and id of the other record).',
            'writing' => 'The `links` section of a record in records_validate / records_write: '
                . '{ add: [{ table, id, label? }], remove: [link id] }. A link shows on both records: you must be allowed to change both.',
        ];
    }

    /** How to read and write the relations of a table that has them (tools rs_read, rs_validate, rs_write). */
    private static function stratigraphy(): array
    {
        $relations = [];
        foreach (\Record\Rs::RELATIONS as $code => $name) {
            $relations[] = ['code' => $code, 'name' => str_replace('_', ' ', $name), 'inverse' => \Record\Rs::inverse($code)];
        }
        return [
            'reading'   => 'A relation is read "first <name> second", e.g. first 5 = covers: first covers second. '
                . 'The same fact read from the other side uses the inverse code and counts as a duplicate. 9 and 10 are symmetric.',
            'relations' => $relations,
        ];
    }

    /** Features switched on for the table, by name. */
    private function features(array $schema): array
    {
        $map = [
            'rs'             => 'stratigraphic_relations',
            'has_geodata'    => 'geodata',
            'has_zotero'     => 'bibliography',
            'has_fuzzy_date' => 'fuzzy_date',
            'has_osteology'  => 'osteology',
            'has_pleiades'   => 'pleiades',
        ];
        return array_values(array_filter(array_map(
            fn($key, $name) => !empty($schema[$key]) ? $name : null,
            array_keys($map),
            $map
        )));
    }

    /** Field list without what a model has no use for (interface options, empty values). */
    private function compact(array $fields): array
    {
        return array_map(function (array $f): array {
            unset($f['widget'], $f['direction']);
            return array_filter($f, fn($v) => $v !== null && $v !== false && $v !== [] && $v !== '');
        }, $fields);
    }
}
