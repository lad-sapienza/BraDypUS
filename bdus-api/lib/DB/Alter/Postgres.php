<?php

namespace DB\Alter;

use DB\DBInterface;

class Postgres implements AlterInterface
{

    private $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): bool
    {
        $sql = 'ALTER TABLE ' . $old . ' RENAME TO ' . $new;
        return $this->db->execInTransaction($sql);
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        $sql = "ALTER TABLE {$tb} RENAME COLUMN {$old} TO {$new}";
        return $this->db->execInTransaction($sql);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        $sql = "ALTER TABLE {$tb} ADD COLUMN {$fld_name} {$fld_type}";
        return $this->db->execInTransaction($sql);
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        $sql = "ALTER TABLE {$tb} DROP COLUMN \"{$fld_name}\"";
        return $this->db->execInTransaction($sql);
    }

    public function createMinimalTable( string $tb, bool $is_plugin = false ): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$tb} (id SERIAL PRIMARY KEY, ";
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