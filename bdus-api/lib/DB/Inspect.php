<?php

namespace DB;

class Inspect implements Inspect\InspectInterface
{
    private $driver;

    public function __construct (Inspect\InspectInterface $driver)
    {
        $this->driver = $driver;
    }

    
    /**
     * Checks if table exists
     */
    public function tableExists(string $tb): bool
    {
        return $this->driver->tableExists($tb);
    }

    /**
     * Gets list of table columns 
     *
     * @param string $tb    Table name
     * @return array array of arrays SQL statements and possible values
     *                  example: [ [ 'sql' => "statement", 'values' => ['val1', 'valn'] ], [ ...etc... ]]
     */
    public function tableColumns(string $tb): array
    {
        return $this->driver->tableColumns($tb);
    }
}