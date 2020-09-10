<?php

namespace DB\Inspect;

/**
 * Interface to interact with database structure
 */
interface InspectInterface
{
    /**
     * Checks if table exists
     */
    public function tableExists(string $tb): bool;

    /**
     * Gets list of table columns 
     *
     * @param string $tb    Table name
     * @return array array of arrays with column data
     *                  example: [ [ 'fld' => "fld_name", 'type' => 'column_type' ], [ ...etc... ] ]
     */
    public function tableColumns(string $tb): array;

    public function getAllTables() : array;
}