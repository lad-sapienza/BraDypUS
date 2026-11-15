<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

use DB\DBInterface;
use Config\Config;

/**
 * May the current principal write to a given record? One place for the rules
 * every write path shares: tier or group rule on the table, ownership for a
 * self writer, row scope and locks; a plugin row follows its host record.
 *
 * Used by the controllers (through Bdus\Controller::mayChange()) and by
 * Record\Save, so that every entry point judges a write the same way.
 */
class WriteGate
{
  public function __construct(
    private readonly DBInterface $db,
    private readonly Config $cfg,
    private readonly Access $access,
  ) {}

  /**
   * The record whose rules govern a write to record $id of $tb: the record
   * itself, or for a plugin table the host record its row belongs to (plugin
   * rows follow their record). [null, 0] when a plugin row has no resolvable host.
   *
   * @return array{0: ?string, 1: int}
   */
  public function writeTarget(string $tb, int $id): array
  {
    if (!$this->cfg->get("tables.{$tb}.is_plugin")) {
      return [$tb, $id];
    }
    $host = $this->cfg->get("tables.{$tb}.plugin_of");
    if (!$host || !preg_match('/^[A-Za-z0-9_]+$/', $tb)) {
      return [null, 0];
    }
    $row    = $this->db->query("SELECT id_link FROM {$tb} WHERE id = ?", [$id], 'read');
    $idLink = (int) ($row[0]['id_link'] ?? 0);
    return $idLink ? [$host, $idLink] : [null, 0];
  }

  /**
   * May the principal change (`update` or `delete`) record $id of $tb? The
   * single gate for writes to existing records: the tier or a group rule must
   * grant it (table level, $creator lets a self writer act on their own
   * records), and the row must be inside the principal's scope and outside
   * every lock. A plugin row is judged by its host record, always as an update.
   *
   * Without $creator the owner of the record (of the host, for a plugin row) is
   * looked up, so that a self writer can change what hangs off their own
   * records — its plugin rows, relations, links, files, geometries — and nothing
   * that hangs off someone else's.
   */
  public function mayChange(string $action, string $tb, int $id, ?int $creator = null): bool
  {
    if ($this->cfg->get("tables.{$tb}.is_plugin")) {
      $action = 'update';
    }
    [$gateTb, $gateId] = $this->writeTarget($tb, $id);
    if ($gateTb === null) {
      return false;
    }
    $allowed = \Auth\Authorization::can('edit', $creator, $gateTb);
    if (!$allowed && $creator === null) {
      $owner   = $this->ownerOf($gateTb, $gateId);
      $allowed = $owner !== null && \Auth\Authorization::can('edit', $owner, $gateTb);
    }
    if (!$allowed) {
      return false;
    }
    return $this->access->unrestricted($action, $gateTb) || $this->access->rowAllowed($action, $gateTb, $gateId);
  }

  /** User id that created record $id of $tb; null when unknown (no creator column, no such record). */
  private function ownerOf(string $tb, int $id): ?int
  {
    if (preg_match('/^[A-Za-z0-9_]+$/', $tb) !== 1 || empty($this->cfg->get("tables.{$tb}"))) {
      return null;
    }
    try {
      $row = $this->db->query("SELECT creator FROM {$tb} WHERE id = ?", [$id], 'read');
    } catch (\Throwable $e) {
      return null;
    }
    return (int) ($row[0]['creator'] ?? 0) ?: null;
  }

  /**
   * Resolves the "owner" user id used by self_writer ownership checks.
   *
   * Plugin tables (is_plugin=1) never have their own `creator` column by
   * design — ownership is inherited from the host record via `id_link`, not
   * stored locally (see issue #64). For a plugin table this reads `id_link`
   * off the given row, loads the host record (`tables.{tb}.plugin_of`), and
   * returns *its* creator instead. Any failure to resolve (missing
   * plugin_of, dangling id_link) returns null, which denies self_writer but
   * leaves writer/admin unaffected — Authorization::can() only consults the
   * returned value for the self_writer bracket.
   */
  public function ownerCreator(string $tb, \Record\Read $reader, bool $isPlugin): ?int
  {
    if (!$isPlugin) {
      return (int) ($reader->getCore('creator', true) ?? 0) ?: null;
    }

    $hostTb = $this->cfg->get("tables.{$tb}.plugin_of");
    $idLink = (int) ($reader->getCore('id_link', true) ?? 0) ?: null;
    if (!$hostTb || !$idLink) {
      return null;
    }

    $hostReader = new \Record\Read($idLink, null, $hostTb, $this->db, $this->cfg);
    return (int) ($hostReader->getCore('creator', true) ?? 0) ?: null;
  }

  /**
   * May the principal add a row to plugin table $tb? A plugin row belongs to its
   * host record: a principal whose tier already allows adding keeps that, but
   * not on a frozen host; one that relies on group rules needs the right to
   * change the host record.
   */
  public function mayAddPluginRow(string $tb, array $core): bool
  {
    $hostTb = $this->cfg->get("tables.{$tb}.plugin_of");
    $link   = $core['id_link'] ?? 0;
    $hostId = (int) (is_array($link) ? ($link['val'] ?? $link['_val'] ?? 0) : $link);

    if (!$hostTb || !$hostId) {
      return \Auth\Authorization::can('add_new', table: $tb);
    }
    // Adding a row changes the host record: same right as changing it (a self writer: own
    // records only; nobody: a frozen one).
    return $this->mayChange('update', $hostTb, $hostId);
  }
}
