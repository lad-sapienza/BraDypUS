<?php

namespace DB\Alter;


class Postgres implements AlterInterface
{

    public function __construct()
    {
    }

    public function renameTable(string $old, string $new): array
    {
        return [
            [
                "sql" => 'ALTER TABLE ' . $old . ' RENAME TO ' . $new,
                "values" => false
            ]
        ];
    }


    public function renameFld(string $tb, string $old, string $new, $fld_type = false): array
    {
        return [
            [
                "sql" => "ALTER TABLE {$tb} RENAME COLUMN {$old} TO {$new}",
                "values" => false
            ]
        ];
    }

    public function addFld(string $tb, string $fld_name, string $fld_type)
    {
        return [
            [
                'sql' => "ALTER TABLE `{$tb}` ADD COLUMN {$fld_name} {$fld_type}",
                'values' => false
            ]
        ];
    }

    public function dropFld(string $tb, string $fld_name)
    {
        return [
            [
                'sql' => "ALTER TABLE {$tb} DROP COLUMN {$fld_name}",
                'values' => false
            ]
        ];
    }
}