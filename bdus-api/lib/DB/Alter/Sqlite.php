<?php

namespace DB\Alter;


class Sqlite implements AlterInterface
{
    private $sqlite_version;

    public function __construct()
    {
        $sqlite_version_arr = \SQLite3::version();
        $this->sqlite_version = $sqlite_version_arr['versionString'];
    }

    public function renameTable(string $old, string $new): array
    {
        $sql = 'ALTER TABLE ' . $old . ' RENAME TO ' . $new;
        return [
            [
                "sql" => $sql,
                "values" => false
            ]
        ];
    }


    public function renameFld(string $tb, string $old, string $new, $fld_type = false): array
    {
        if( \version_compare($this->sqlite_version, '3.25.0')  === -1 ) {
            // TODO: implement the old way
            // https://www.sqlitetutorial.net/sqlite-rename-column/#:~:text=ALTER%20TABLE%20table_name%20RENAME%20COLUMN,name%20after%20the%20TO%20keyword.
            throw new \Exception("Your sqlite version ({$this->sqlite_version}) does not support field rename");
            

        } else {
            return [
                [
                    "sql" => "ALTER TABLE {$tb} RENAME COLUMN {$old} TO {$new}",
                    "values" => false
                ]
            ];
        }
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): array
    {
        return [
            [
                'sql' => "ALTER TABLE {$tb} ADD COLUMN {$fld_name} {$fld_type}",
                'values' => false
            ]
        ];
    }

    public function dropFld(string $tb, string $fld_name): array
    {
        // TODO: implement the old way
        // https://www.sqlitetutorial.net/sqlite-rename-column/#:~:text=ALTER%20TABLE%20table_name%20RENAME%20COLUMN,name%20after%20the%20TO%20keyword.
        throw new \Exception("Sqlite does not support column drop");
    }
}