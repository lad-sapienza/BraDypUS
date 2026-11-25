<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Mcp\ValidationToken;
use Record\Save;

/**
 * Dry run of a create or update: everything `records_write` would check,
 * without writing. When every record passes it returns a token that
 * `records_write` asks for, so that a write is always preceded by a
 * validation of exactly the same payload.
 */
class RecordsValidate extends Base
{
    public const MAX_RECORDS = 50;

    public function name(): string
    {
        return 'records_validate';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Validate records (dry run)',
            'description' => 'Checks records to create (`id` null or absent) or update (`id` given) without writing: '
                . 'permissions, field names, required fields, types, ranges, uniqueness, lookup ids and, if given, the `bibliography` '
                . '(Zotero links) of a record. Returns, per record, '
                . 'the errors or the values as they would be written and the stored values they replace. When all pass, '
                . 'returns `validation_token`: show the user what will change, and only after they agree call '
                . '`records_write` with the same `table`, the same `records` and that token.',
            'inputSchema' => self::inputSchema(false),
            'annotations' => $this->readOnly(),
        ];
    }

    /** Input of records_validate and, with the token, of records_write. */
    public static function inputSchema(bool $withToken): array
    {
        $properties = [
            'table'   => ['type' => 'string'],
            'records' => [
                'type'     => 'array',
                'minItems' => 1,
                'maxItems' => self::MAX_RECORDS,
                'items'    => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Record to update; null or absent to create.'],
                        'core'    => ['type' => 'object', 'description' => '{ field: value } — only the fields to set. Lookup fields take the id.'],
                        'plugins' => [
                            'type'        => 'object',
                            'description' => '{ plugin_table: [ { "fields": { … } } to add a row, { "id": 7, "fields": { … } } to '
                                . 'change one, { "id": 7, "_delete": true } to remove one ] }',
                        ],
                        'bibliography' => Bibliography::schema(),
                    ],
                    'required' => ['core'],
                ],
            ],
        ];
        $required = ['table', 'records'];
        if ($withToken) {
            $properties['validation_token'] = ['type' => 'string', 'description' => 'From records_validate, for exactly this payload.'];
            $required[] = 'validation_token';
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb      = $this->table($args, $ctx);
        $records = self::records($args);
        $save    = new Save($ctx->db, $ctx->cfg, $ctx->access, $ctx->log);

        $results = [];
        $allOk   = true;
        foreach ($records as $i => $rec) {
            $res = $save->check($tb, $rec['id'], $rec['core'], $rec['plugins']);
            if ($res['status'] === 'success' && ($refused = Bibliography::check($tb, $rec['id'], $rec['bibliography'], $ctx))) {
                $res = ['status' => 'error'] + $refused;
            }
            if ($res['status'] !== 'success') {
                $allOk     = false;
                $results[] = array_filter([
                    'index'  => $i,
                    'ok'     => false,
                    'code'   => $res['code'] ?? 'error',
                    'errors' => $res['errors'] ?? null,
                    'detail' => $res['detail'] ?? null,
                ], fn($v) => $v !== null);
                continue;
            }
            $results[] = [
                'index'   => $i,
                'ok'      => true,
                'action'  => $rec['id'] ? 'update' : 'create',
                'id'      => $rec['id'],
                'core'    => $res['core'],
                'plugins' => $res['plugins'],
                'current' => $res['current'],
            ] + (Bibliography::isEmpty($rec['bibliography']) ? [] : ['bibliography' => $rec['bibliography']]);
        }

        return [
            'table'            => $tb,
            'valid'            => $allOk,
            'results'          => $results,
            'validation_token' => $allOk ? self::token($tb, $records, $ctx) : null,
            'next'             => $allOk
                ? 'Show the user what will be created or changed. After they agree, call records_write with the same table and records and this validation_token.'
                : 'Nothing can be written until every record passes. Correct the errors or ask the user.',
        ];
    }

    /**
     * The `records` argument, normalised: [ [id|null, core, plugins], … ].
     *
     * @return array<int, array{id: ?int, core: mixed, plugins: mixed, bibliography: array}>
     */
    public static function records(array $args): array
    {
        $records = $args['records'] ?? null;
        if (!is_array($records) || !$records || !array_is_list($records) || count($records) > self::MAX_RECORDS) {
            throw new ToolError('invalid_parameters', 'records');
        }
        $out = [];
        foreach ($records as $i => $rec) {
            if (!is_array($rec)) {
                throw new ToolError('invalid_parameters', "records[{$i}]");
            }
            $id = $rec['id'] ?? null;
            // An id is a positive integer. 0 must never fall back to "create":
            // a model that sends it by mistake would add a record, not change one.
            if ($id !== null && (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1)) {
                throw new ToolError('invalid_parameters', "records[{$i}].id");
            }
            $out[] = [
                'id'      => $id === null ? null : (int) $id,
                'core'    => $rec['core'] ?? [],
                'plugins' => self::pluginRows($rec['plugins'] ?? [], $i),
                'bibliography' => Bibliography::parse($rec['bibliography'] ?? null, $i),
            ];
        }
        return $out;
    }

    /**
     * The plugin rows of a record in the form Record\Save reads: a row without an `id`
     * is a new row and says so (`_isNew`), a row with an `id` never does. Save takes the
     * flag, not the missing id, as the sign of a new row when it updates a record; the
     * tool describes `{ "fields": { … } }` as "add a row", so the flag is set here,
     * and one a client supplies is never believed. Anything else is left for Save to judge.
     */
    private static function pluginRows(mixed $plugins, int $index): mixed
    {
        if (!is_array($plugins)) {
            throw new ToolError('invalid_parameters', "records[{$index}].plugins");
        }
        foreach ($plugins as $table => $rows) {
            if (!is_array($rows) || !array_is_list($rows)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                unset($row['_isNew']);
                if (empty($row['id']) && empty($row['_delete'])) {
                    $row['_isNew'] = true;
                }
                $plugins[$table][$i] = $row;
            }
        }
        return $plugins;
    }

    /** Token for these records, for this principal, until $expires (default: 15 minutes). */
    public static function token(string $tb, array $records, Context $ctx, ?int $expires = null): string
    {
        return ValidationToken::issue('records', ['table' => $tb, 'records' => $records], $ctx, $expires);
    }

    /** Is $token the one issued for these records and principal, and still valid? */
    public static function tokenMatches(mixed $token, string $tb, array $records, Context $ctx): bool
    {
        return ValidationToken::valid($token, 'records', ['table' => $tb, 'records' => $records], $ctx);
    }
}
