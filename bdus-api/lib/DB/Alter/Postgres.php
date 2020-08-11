<?php

namespace DB\Alter;


class Postgres implements AlterInterface
{

    private $db;

    public function __construct(\DB $db)
    {
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): array
    {
        $sql = 'ALTER TABLE ' . $old . ' RENAME TO ' . $new;
        return $this->db->execInTransaction($sql);
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): array
    {
        $sql = "ALTER TABLE {$tb} RENAME COLUMN {$old} TO {$new}";
        return $this->db->execInTransaction($sql);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        $sql = "ALTER TABLE `{$tb}` ADD COLUMN {$fld_name} {$fld_type}";
        return $this->db->execInTransaction($sql);
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        $sql = "ALTER TABLE {$tb} DROP COLUMN {$fld_name}";
        return $this->db->execInTransaction($sql);
    }
}