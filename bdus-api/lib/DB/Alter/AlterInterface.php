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
     * @return bool true is success, farlse if error
     */
    public function renameTable(string $old, string $new): bool;

    /**
     * Renames a Column
     *
     * @param string $tb    Table name
     * @param string $old   Old column name
     * @param string $new   New column name
     * @param boolean $fld_type MySQL requires Firld type
     * @return bool true is success, farlse if error
     */
    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool;

    /**
     * Adds a new field to the table
     *
     * @param string $tb        Table name
     * @param string $fld_name  Field name
     * @param string $fld_type  Field type
     * @return bool true is success, farlse if error
     */
    public function addFld(string $tb, string $fld_name, string $fld_type): bool;

    /**
     * Drops a column
     *
     * @param string $tb        Table name
     * @param string $fld_name  Name of column to drop
     * @return bool true is success, farlse if error
    */
    public function dropFld(string $tb, string $fld_name): bool;
    
    /**
     * Creates new table
     *
     * @param string $tb        table name
     * @return boolean
     */
    public function createMinimalTable(string $tb): bool;
    
    /**
     * Drops table from database
     *
     * @param string $tb    Table nome to drop
     * @return boolean
     */
    public function dropTable(string $tb): bool;
}