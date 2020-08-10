<?php

namespace DB\Alter;

/**
 * Interface to interact with database structure
 */
interface AlterInterface
{
    /**
     * Renames a table
     *
     * @param string $old   Old name
     * @param string $new   New name
     * @return array array of arrays SQL statements and possible values
     *                  example: [ [ 'sql' => "statement", 'values' => ['val1', 'valn'] ], [ ...etc... ]]
     */
    public function renameTable(string $old, string $new): array;

    /**
     * Renames a Column
     *
     * @param string $tb    Table name
     * @param string $old   Old column name
     * @param string $new   New column name
     * @param boolean $fld_type MySQL requires Firld type
     * @return array array of arrays SQL statements and possible values
     *                  example: [ [ 'sql' => "statement", 'values' => ['val1', 'valn'] ], [ ...etc... ]]
     */
    public function renameFld(string $tb, string $old, string $new, $fld_type = false): array;

    /**
     * Adds a new field to the table
     *
     * @param string $tb        Table name
     * @param string $fld_name  Field name
     * @param string $fld_type  Field type
     * @return array array of arrays SQL statements and possible values
     *                  example: [ [ 'sql' => "statement", 'values' => ['val1', 'valn'] ], [ ...etc... ]]
     */
    public function addFld(string $tb, string $fld_name, string $fld_type): array;

    /**
     * Drops a column
     *
     * @param string $tb        Table name
     * @param string $fld_name  Name of column to drop
     * @return array array of arrays SQL statements and possible values
     *                  example: [ [ 'sql' => "statement", 'values' => ['val1', 'valn'] ], [ ...etc... ]]
     */
    public function dropFld(string $tb, string $fld_name): array;
}