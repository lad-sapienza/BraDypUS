<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\ToolError;

/**
 * The `add` and `remove` arguments shared by `rs_validate` and `rs_write`, in the
 * form Record\Rs works with.
 */
final class RsPayload
{
    public const MAX = 50;

    /** Input schema of rs_validate and, with the token, of rs_write. */
    public static function inputSchema(bool $withToken): array
    {
        $int = ['type' => 'integer', 'minimum' => 1];
        $properties = [
            'table'  => ['type' => 'string', 'description' => 'A table with stratigraphic relations (see `schema`: feature `stratigraphic_relations`).'],
            'add'    => [
                'type'        => 'array',
                'maxItems'    => self::MAX,
                'description' => 'Relations to add, each read "first <relation> second" (codes in `schema`): { first, relation, second }, '
                    . 'the two ids being records of `table`. The same fact read from the other side counts as a duplicate.',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => ['first' => $int, 'relation' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'second' => $int],
                    'required'             => ['first', 'relation', 'second'],
                    'additionalProperties' => false,
                ],
            ],
            'remove' => [
                'type'        => 'array',
                'maxItems'    => self::MAX,
                'description' => 'Ids of the relations to remove, as `rs_read` lists them. Removals are done before additions.',
                'items'       => $int,
            ],
        ];
        $required = ['table'];
        if ($withToken) {
            $properties['validation_token'] = ['type' => 'string', 'description' => 'From rs_validate, for exactly this payload.'];
            $required[] = 'validation_token';
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /**
     * @return array{add: array<int, array{first: int, relation: int, second: int}>, remove: int[]}
     * @throws ToolError
     */
    public static function parse(array $args): array
    {
        $add    = $args['add'] ?? [];
        $remove = $args['remove'] ?? [];
        if (!is_array($add) || !array_is_list($add)) {
            throw new ToolError('invalid_parameters', 'add');
        }
        if (!is_array($remove) || !array_is_list($remove)) {
            throw new ToolError('invalid_parameters', 'remove');
        }
        if (!$add && !$remove) {
            throw new ToolError('parameter_missing', 'add or remove');
        }
        if (count($add) + count($remove) > self::MAX) {
            throw new ToolError('invalid_parameters', 'at most ' . self::MAX . ' relations per call');
        }

        $out = ['add' => [], 'remove' => []];
        foreach ($add as $i => $rel) {
            if (!is_array($rel) || array_diff(array_keys($rel), ['first', 'relation', 'second'])) {
                throw new ToolError('invalid_parameters', "add[{$i}]");
            }
            $row = [];
            foreach (['first', 'relation', 'second'] as $key) {
                $row[$key] = self::positiveInt($rel[$key] ?? null, "add[{$i}].{$key}");
            }
            $out['add'][] = $row;
        }
        foreach ($remove as $i => $id) {
            $out['remove'][] = self::positiveInt($id, "remove[{$i}]");
        }
        $out['remove'] = array_values(array_unique($out['remove']));
        return $out;
    }

    private static function positiveInt(mixed $v, string $path): int
    {
        if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || (int) $v < 1) {
            throw new ToolError('invalid_parameters', $path);
        }
        return (int) $v;
    }
}
