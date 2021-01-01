<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Alter;

use DB\DBInterface;

class Mysql implements AlterInterface
{
    private $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): bool
    {
        $sql = 'RENAME TABLE `' . $old . '` TO `' . $new . '`';
        return  $this->db->execInTransaction($sql);
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        $sql = "ALTER TABLE `{$tb}` CHANGE `{$old}` `$new` {$fld_type}";
        return $this->db->execInTransaction($sql);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        $sql = "ALTER TABLE `{$tb}` ADD `{$fld_name}` {$fld_type}";
        return $this->db->execInTransaction($sql);
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        $sql = "ALTER TABLE `{$tb}` DROP COLUMN `{$fld_name}`";
        return  $this->db->execInTransaction($sql);
    }

    public function createMinimalTable( string $tb, bool $is_plugin): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$tb} (id INTEGER PRIMARY KEY AUTO_INCREMENT, ";
        if ($is_plugin) {
            $sql .= "table_link TEXT NOT NULL, id_link INTEGER NOT NULL";
        } else {
            $sql .= "creator INTEGER NOT NULL";
        }

        $sql .= ")";
        return $this->db->execInTransaction($sql);
    }

    public function dropTable( string $tb): bool
    {
        $sql = "DROP TABLE IF EXISTS {$tb}";
        return $this->db->execInTransaction($sql);
    }

}