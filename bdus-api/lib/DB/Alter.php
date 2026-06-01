<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB;

use DB\DB;

/**
 * Engine-agnostic façade for database structural operations on user-defined tables.
 * Delegates to the engine-specific driver (Sqlite, Mysql, Postgres).
 */
class Alter implements Alter\AlterInterface
{
    private Alter\AlterInterface $driver;

    public function __construct(DB $db)
    {
        $engine = $db->getEngine();

        $this->driver = match ($engine) {
            'sqlite' => new \DB\Alter\Sqlite($db),
            'mysql'  => new \DB\Alter\Mysql($db),
            'pgsql'  => new \DB\Alter\Postgres($db),
            default  => throw new \Exception("Unknown database engine: `$engine`"),
        };
    }

    public function renameTable(string $old, string $new): bool
    {
        return $this->driver->renameTable($old, $new);
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        if ($old === 'id')      throw new \Exception("Field `id` cannot be renamed");
        if ($old === 'creator') throw new \Exception("Field `creator` cannot be renamed");
        return $this->driver->renameFld($tb, $old, $new, $fld_type);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        return $this->driver->addFld($tb, $fld_name, $fld_type);
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        if ($fld_name === 'id')      throw new \Exception("Field `id` cannot be dropped");
        if ($fld_name === 'creator') throw new \Exception("Field `creator` cannot be dropped");
        return $this->driver->dropFld($tb, $fld_name);
    }

    public function createMinimalTable(string $tb, bool $is_plugin, string $pluginOf = ''): bool
    {
        return $this->driver->createMinimalTable($tb, $is_plugin, $pluginOf);
    }

    public function dropTable(string $tb): bool
    {
        return $this->driver->dropTable($tb);
    }

    // ── FK management ─────────────────────────────────────────────────────────

    public function addForeignKey(
        string $tb,
        string $col,
        string $refTable,
        string $refCol,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'CASCADE'
    ): bool {
        return $this->driver->addForeignKey($tb, $col, $refTable, $refCol, $onDelete, $onUpdate);
    }

    public function dropForeignKey(string $tb, string $col): bool
    {
        return $this->driver->dropForeignKey($tb, $col);
    }

    public function hasForeignKey(string $tb, string $col): bool
    {
        return $this->driver->hasForeignKey($tb, $col);
    }

    public function checkOrphans(string $tb, string $col, string $refTable, string $refCol): int
    {
        return $this->driver->checkOrphans($tb, $col, $refTable, $refCol);
    }

    // ── Index management ──────────────────────────────────────────────────────

    public function createIndex(string $tb, string $name, array $columns, bool $unique = false): bool
    {
        return $this->driver->createIndex($tb, $name, $columns, $unique);
    }

    public function dropIndex(string $tb, string $name): bool
    {
        return $this->driver->dropIndex($tb, $name);
    }
}
