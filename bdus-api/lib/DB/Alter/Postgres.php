<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Alter;

use DB\DBInterface;

class Postgres implements AlterInterface
{
    private DBInterface $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$old}\" RENAME TO \"{$new}\"");
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" RENAME COLUMN \"{$old}\" TO \"{$new}\"");
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" ADD COLUMN \"{$fld_name}\" {$fld_type}");
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" DROP COLUMN \"{$fld_name}\"");
    }

    public function createMinimalTable(string $tb, bool $is_plugin, string $pluginOf = ''): bool
    {
        if ($is_plugin) {
            $sql = "CREATE TABLE IF NOT EXISTS \"{$tb}\" ("
                . "id SERIAL PRIMARY KEY, "
                . "table_link TEXT NOT NULL, "
                . "id_link INTEGER NOT NULL"
                . ")";
            $ok = $this->db->execInTransaction($sql);
            if ($ok && $pluginOf !== '') {
                $this->addForeignKey($tb, 'id_link', $pluginOf, 'id', 'RESTRICT', 'CASCADE');
            }
            return $ok;
        }

        $ok = $this->db->execInTransaction(
            "CREATE TABLE IF NOT EXISTS \"{$tb}\" ("
            . "id SERIAL PRIMARY KEY, "
            . "creator INTEGER"
            . ")"
        );
        if ($ok) {
            $this->addForeignKey($tb, 'creator', 'bdus_users', 'id', 'SET NULL', 'NO ACTION');
        }
        return $ok;
    }

    public function dropTable(string $tb): bool
    {
        return $this->db->execInTransaction("DROP TABLE IF EXISTS \"{$tb}\"");
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
        if ($this->hasForeignKey($tb, $col)) {
            return true;
        }

        $name = $this->fkName($tb, $col);
        $sql  = "ALTER TABLE \"{$tb}\" ADD CONSTRAINT \"{$name}\" "
              . "FOREIGN KEY (\"{$col}\") REFERENCES \"{$refTable}\"(\"{$refCol}\") "
              . "ON DELETE " . strtoupper($onDelete) . " "
              . "ON UPDATE " . strtoupper($onUpdate);

        return $this->db->execInTransaction($sql);
    }

    public function dropForeignKey(string $tb, string $col): bool
    {
        if (!$this->hasForeignKey($tb, $col)) {
            return true;
        }
        $name = $this->fkName($tb, $col);
        return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" DROP CONSTRAINT IF EXISTS \"{$name}\"");
    }

    public function hasForeignKey(string $tb, string $col): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM pg_constraint c
               JOIN pg_class t  ON t.oid = c.conrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
              WHERE c.contype = 'f'
                AND t.relname = ?
                AND a.attname = ?",
            [$tb, $col],
            'read'
        );
        return isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0;
    }

    public function checkOrphans(string $tb, string $col, string $refTable, string $refCol): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM \"{$tb}\" "
            . "WHERE \"{$col}\" IS NOT NULL "
            . "AND \"{$col}\" NOT IN (SELECT \"{$refCol}\" FROM \"{$refTable}\")",
            [],
            'read'
        );
        return (int)($result[0]['cnt'] ?? 0);
    }

    // ── Index management ──────────────────────────────────────────────────────

    public function createIndex(string $tb, string $name, array $columns, bool $unique = false): bool
    {
        $uniq = $unique ? 'UNIQUE ' : '';
        $cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        return $this->db->exec("CREATE {$uniq}INDEX IF NOT EXISTS \"{$name}\" ON \"{$tb}\" ({$cols})");
    }

    public function dropIndex(string $tb, string $name): bool
    {
        return $this->db->exec("DROP INDEX IF EXISTS \"{$name}\"");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fkName(string $tb, string $col): string
    {
        return "fk_{$tb}_{$col}";
    }
}
