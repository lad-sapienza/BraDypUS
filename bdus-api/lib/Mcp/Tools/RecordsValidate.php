<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
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
    public const TOKEN_TTL   = 900;

    public function name(): string
    {
        return 'records_validate';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Validate records (dry run)',
            'description' => 'Checks records to create (`id` null or absent) or update (`id` given) without writing: '
                . 'permissions, field names, required fields, types, ranges, uniqueness, lookup ids. Returns, per record, '
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
                        'id'      => ['type' => ['integer', 'null'], 'description' => 'Record to update; null or absent to create.'],
                        'core'    => ['type' => 'object', 'description' => '{ field: value } — only the fields to set. Lookup fields take the id.'],
                        'plugins' => [
                            'type'        => 'object',
                            'description' => '{ plugin_table: [ { "fields": { … } } to add a row, { "id": 7, "fields": { … } } to '
                                . 'change one, { "id": 7, "_delete": true } to remove one ] }',
                        ],
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
            ];
        }

        return [
            'table'            => $tb,
            'valid'            => $allOk,
            'results'          => $results,
            'validation_token' => $allOk ? self::token($tb, $records, $ctx, time() + self::TOKEN_TTL) : null,
            'next'             => $allOk
                ? 'Show the user what will be created or changed. After they agree, call records_write with the same table and records and this validation_token.'
                : 'Nothing can be written until every record passes. Correct the errors or ask the user.',
        ];
    }

    /**
     * The `records` argument, normalised: [ [id|null, core, plugins], … ].
     *
     * @return array<int, array{id: ?int, core: mixed, plugins: mixed}>
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
            if ($id !== null && !is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new ToolError('invalid_parameters', "records[{$i}].id");
            }
            $out[] = [
                'id'      => $id === null ? null : ((int) $id ?: null),
                'core'    => $rec['core'] ?? [],
                'plugins' => $rec['plugins'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Token for one payload, one principal, until $expires: `expires.signature`.
     * It proves that this exact payload was validated; it does not prove that
     * the user agreed — that is the client's confirmation.
     */
    public static function token(string $tb, array $records, Context $ctx, int $expires): string
    {
        $who = \Auth\CurrentUser::isApiKey()
            ? 'key:' . (int) \Auth\CurrentUser::get('key_id')
            : 'user:' . (int) \Auth\CurrentUser::id();
        $payload = json_encode(self::canonical(['table' => $tb, 'records' => $records]), JSON_UNESCAPED_UNICODE);
        return $expires . '.' . hash_hmac('sha256', $expires . '|' . $who . '|' . $payload, $ctx->signingKey);
    }

    /** Is $token the one issued for this payload and principal, and still valid? */
    public static function tokenMatches(mixed $token, string $tb, array $records, Context $ctx): bool
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.[a-f0-9]{64}$/', $token, $m) !== 1 || (int) $m[1] < time()) {
            return false;
        }
        return hash_equals(self::token($tb, $records, $ctx, (int) $m[1]), $token);
    }

    /** Same payload, same text: object keys sorted, lists left in order. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
