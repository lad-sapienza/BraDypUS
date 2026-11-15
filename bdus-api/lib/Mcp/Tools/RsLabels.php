<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Record\Rs;

/**
 * Words for a relation, so that the user can confirm what will be written: the readable
 * label (key field) of each record — only for records the principal may read — and the
 * name of the relation.
 */
final class RsLabels
{
    private array $cache = [];

    public function __construct(private readonly Context $ctx, private readonly string $tb) {}

    /** @return array{first: int, relation: int, second: int, name: string, reading: ?string} */
    public function describe(int $first, int $relation, int $second): array
    {
        $name = Rs::RELATIONS[$relation] ?? null;
        $a    = $this->label($first);
        $b    = $this->label($second);
        return [
            'first'    => $first,
            'relation' => $relation,
            'second'   => $second,
            'name'     => $name,
            'reading'  => ($a !== null && $b !== null && $name !== null) ? $a . ' ' . str_replace('_', ' ', $name) . ' ' . $b : null,
        ];
    }

    private function label(int $id): ?string
    {
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }
        $access = $this->ctx->access;
        $label  = null;
        if ($access->unrestricted('read', $this->tb) || $access->rowAllowed('read', $this->tb, $id)) {
            $field = $this->ctx->cfg->get("tables.{$this->tb}.id_field") ?: 'id';
            $row   = $this->ctx->db->query("SELECT {$field} AS label FROM {$this->tb} WHERE id = ?", [$id], 'read')[0] ?? null;
            $label = $row === null ? null : (string) ($row['label'] ?? $id);
        }
        return $this->cache[$id] = $label;
    }
}
