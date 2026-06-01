<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Alter;

use DB\DBInterface;

class Mysql implements AlterInterface
{
    private DBInterface $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): bool
    {
        return $this->db->execInTransaction("RENAME TABLE `{$old}` TO `{$new}`");
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        return $this->db->execInTransaction("ALTER TABLE `{$tb}` CHANGE `{$old}` `{$new}` {$fld_type}");
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        return $this->db->execInTransaction("ALTER TABLE `{$tb}` ADD `{$fld_name}` {$fld_type}");
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        return $this->db->execInTransaction("ALTER TABLE `{$tb}` DROP COLUMN `{$fld_name}`");
    }

    public function createMinimalTable(string $tb, bool $is_plugin, string $pluginOf = ''): bool
    {
        if ($is_plugin) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$tb}` ("
                . "id INTEGER PRIMARY KEY AUTO_INCREMENT, "
                . "table_link TEXT NOT NULL, "
                . "id_link INTEGER NOT NULL"
                . ")";
            $ok = $this->db->execInTransaction($sql);
            if ($ok && $pluginOf !== '') {
                $name = "fk_{$tb}_id_link";
                $this->addForeignKey($tb, 'id_link', $pluginOf, 'id', 'RESTRICT', 'CASCADE');
            }
            return $ok;
        }

        return $this->db->execInTransaction(
            "CREATE TABLE IF NOT EXISTS `{$tb}` ("
            . "id INTEGER PRIMARY KEY AUTO_INCREMENT, "
            . "creator INTEGER NOT NULL"
            . ")"
        );
    }

    public function dropTable(string $tb): bool
    {
        return $this->db->execInTransaction("DROP TABLE IF EXISTS `{$tb}`");
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
        $sql  = "ALTER TABLE `{$tb}` ADD CONSTRAINT `{$name}` "
              . "FOREIGN KEY (`{$col}`) REFERENCES `{$refTable}`(`{$refCol}`) "
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
        return $this->db->execInTransaction("ALTER TABLE `{$tb}` DROP FOREIGN KEY `{$name}`");
    }

    public function hasForeignKey(string $tb, string $col): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$tb, $col],
            'read'
        );
        return isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0;
    }

    public function checkOrphans(string $tb, string $col, string $refTable, string $refCol): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM `{$tb}` "
            . "WHERE `{$col}` IS NOT NULL "
            . "AND `{$col}` NOT IN (SELECT `{$refCol}` FROM `{$refTable}`)",
            [],
            'read'
        );
        return (int)($result[0]['cnt'] ?? 0);
    }

    // ── Index management ──────────────────────────────────────────────────────

    public function createIndex(string $tb, string $name, array $columns, bool $unique = false): bool
    {
        if ($this->indexExists($tb, $name)) {
            return true;
        }

        $uniq     = $unique ? 'UNIQUE ' : '';
        $colParts = [];
        foreach ($columns as $col) {
            $typeRow  = $this->db->query(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$tb, $col],
                'read'
            );
            $dataType = strtolower($typeRow[0]['DATA_TYPE'] ?? '');
            // MySQL cannot index TEXT columns without a length prefix
            $colParts[] = in_array($dataType, ['text', 'mediumtext', 'longtext', 'tinytext'])
                ? "`{$col}`(191)"
                : "`{$col}`";
        }

        $cols = implode(', ', $colParts);
        return $this->db->exec("CREATE {$uniq}INDEX `{$name}` ON `{$tb}` ({$cols})");
    }

    public function dropIndex(string $tb, string $name): bool
    {
        if (!$this->indexExists($tb, $name)) {
            return true;
        }
        return $this->db->exec("DROP INDEX `{$name}` ON `{$tb}`");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fkName(string $tb, string $col): string
    {
        return "fk_{$tb}_{$col}";
    }

    private function indexExists(string $tb, string $name): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$tb, $name],
            'read'
        );
        return isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0;
    }
}
