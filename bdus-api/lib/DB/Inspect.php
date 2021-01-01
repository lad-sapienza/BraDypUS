<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

 
namespace DB;

use DB\DB;
use DB\Inspect\Sqlite;
use DB\Inspect\Mysql;
use DB\Inspect\Postgres;

class Inspect implements Inspect\InspectInterface
{
    private $driver;

    public function __construct ( DB $db )
    {
        $engine = $db->getEngine();
        switch ($engine) {
            case 'sqlite':
                $this->driver = new Sqlite($db);
                break;
            case 'mysql':
                $this->driver = new Mysql($db);
                break;
            case 'pgsql':
                $this->driver = new Postgres($db);
                break;
            
            default:
                throw new \Exception("Unknown driver $engine");
                break;
        }
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

    public function getAllTables() : array
    {
        return $this->driver->getAllTables();
    }
}