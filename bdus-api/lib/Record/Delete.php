<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;
use Auth\Access;
use Auth\WriteGate;

/**
 * Deletes one record with everything that hangs off it (plugin rows, links,
 * stratigraphic relations — see Record\Persist), after checking that the
 * principal may: tier or group rule, ownership for a self writer, row scope,
 * locks. The record is snapshotted first, so it can be restored.
 *
 * Request-independent: the delete endpoint and the MCP tool share it.
 */
class Delete
{
    public const DELETED       = 'deleted';
    public const NOT_FOUND     = 'not_found';
    public const NOT_PERMITTED = 'not_enough_privilege';

    private readonly WriteGate $gate;

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        Access $access,
    ) {
        $this->gate = new WriteGate($db, $cfg, $access);
    }

    /**
     * May the principal delete anything at all in $tb? (tier or group rule; a
     * self writer may, on their own records)
     */
    public static function mayOnTable(string $tb): bool
    {
        return \Auth\Authorization::can('edit', table: $tb)
            || \Auth\Authorization::can('edit', \Auth\CurrentUser::id(), $tb);
    }

    /**
     * @return string  DELETED, NOT_FOUND or NOT_PERMITTED
     * @throws \Throwable when the database refuses the delete
     */
    public function delete(string $tb, int $id): string
    {
        if (!$this->db->query("SELECT 1 FROM {$tb} WHERE id = ?", [$id], 'read')) {
            return self::NOT_FOUND;
        }

        $reader = new Read($id, null, $tb, $this->db, $this->cfg);

        // Ownership check — only needed when the caller doesn't already have a
        // blanket edit privilege (i.e. they are self_writer). Plugin tables have
        // no `creator` of their own: the gate resolves it through the host record.
        $isPlugin = (bool) $this->cfg->get("tables.{$tb}.is_plugin");
        $creator  = \Auth\Authorization::can('edit', table: $tb)
            ? null
            : $this->gate->ownerCreator($tb, $reader, $isPlugin);
        // Row scope and locks (a plugin row follows its host record).
        if (!$this->gate->mayChange('delete', $tb, $id, $creator)) {
            return self::NOT_PERMITTED;
        }

        // The record's Pleiades link (if the plugin is active on this table) owns a
        // bdus_geodata row; remove it alongside the record instead of leaving it orphaned.
        if ($this->cfg->get("tables.{$tb}.pleiades")) {
            $geoId = (int) ($reader->getCore('pleiades_geo_id', true) ?? 0);
            if ($geoId) {
                $this->db->query('DELETE FROM bdus_geodata WHERE id = ?', [$geoId], 'boolean');
            }
        }

        $editor = new Edit($reader);
        $editor->delete();
        $editor->persist($this->db, $this->cfg);

        return self::DELETED;
    }
}
