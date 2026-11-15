<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Mcp\ValidationToken;
use Record\Rs;

/**
 * Dry run of a change to stratigraphic relations: every check `rs_write` makes, without
 * writing. When everything passes it returns the token `rs_write` asks for.
 */
class RsValidate extends Base
{
    public function name(): string
    {
        return 'rs_validate';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Validate stratigraphic relations (dry run)',
            'description' => 'Checks relations to add and to remove without writing: the table has stratigraphic relations, the '
                . 'code is valid, the two records exist and differ, the principal may change both, the fact is not already '
                . 'stored (in either direction), the ids to remove exist. When all pass, returns `validation_token`: show the '
                . 'user what will be added and removed, and only after they agree call `rs_write` with the same payload and that token.',
            'inputSchema' => RsPayload::inputSchema(false),
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        [$tb, $payload] = self::prepare($args, $ctx, $this);
        $rs     = new Rs($ctx->db, $ctx->cfg, $ctx->access);
        $labels = new RsLabels($ctx, $tb);

        $results = [];
        $allOk   = true;
        $gone    = [];   // ids of the relations this call removes: an addition may restate one

        foreach ($payload['remove'] as $i => $id) {
            $rel  = $rs->find($id);
            $code = ($rel === null || $rel['tb'] !== $tb) ? 'not_found' : $rs->checkRemove($id);
            if ($code === null) {
                $gone[$id] = true;
            }
            $results[] = self::row('remove', $i, $code, $rel !== null && $rel['tb'] === $tb ? $labels->describe($rel['first'], $rel['relation'], $rel['second']) + ['id' => $id] : ['id' => $id]);
            $allOk = $allOk && $code === null;
        }

        $seen = [];
        foreach ($payload['add'] as $i => $rel) {
            ['first' => $f, 'relation' => $r, 'second' => $s] = $rel;
            $code = $rs->checkAdd($tb, $f, $r, $s);
            if ($code === 'relation_already_exist' && isset($gone[$rs->findSame($tb, $f, $r, $s)])) {
                $code = null;   // it is removed by this very call
            }
            // the same fact twice in one call: the second would be refused when written
            $keys = [$f . ':' . $r . ':' . $s, $s . ':' . Rs::inverse($r) . ':' . $f];
            if ($code === null && (isset($seen[$keys[0]]) || isset($seen[$keys[1]]))) {
                $code = 'relation_already_exist';
            }
            if ($code === null) {
                $seen[$keys[0]] = true;
            }
            $results[] = self::row('add', $i, $code, $labels->describe($f, $r, $s));
            $allOk = $allOk && $code === null;
        }

        return [
            'table'            => $tb,
            'valid'            => $allOk,
            'results'          => $results,
            'validation_token' => $allOk ? ValidationToken::issue('rs', ['table' => $tb] + $payload, $ctx) : null,
            'next'             => $allOk
                ? 'Show the user which relations will be added and removed. After they agree, call rs_write with the same table, add, remove and this validation_token.'
                : 'Nothing can be written until every relation passes. Correct the errors or ask the user.',
        ];
    }

    /**
     * The table (it must have stratigraphic relations) and the payload, shared with rs_write.
     *
     * @return array{0: string, 1: array{add: array, remove: int[]}}
     */
    public static function prepare(array $args, Context $ctx, Base $tool): array
    {
        $tb = $tool->tableOf($args, $ctx);
        if (!(new Rs($ctx->db, $ctx->cfg, $ctx->access))->enabled($tb)) {
            throw new ToolError('rs_not_configured', $tb);
        }
        return [$tb, RsPayload::parse($args)];
    }

    private static function row(string $kind, int $index, ?string $code, array $what): array
    {
        return array_filter(['kind' => $kind, 'index' => $index, 'ok' => $code === null, 'code' => $code] + $what, fn($v) => $v !== null);
    }
}
