<?php
/**
 * @copyright 2007-2022 Julian Bogdani
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
     * True when $tb is a SQL view
     */
    public function isView(string $tb): bool
    {
        return $this->driver->isView($tb);
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

    /** Single-column FKs declared on $tb, see InspectInterface::foreignKeys(). */
    public function foreignKeys(string $tb): array
    {
        return $this->driver->foreignKeys($tb);
    }

    /**
     * Tables and views of the database, see InspectInterface::listObjects().
     * Adds `has_id` / `has_creator`, computed the same way for every engine.
     */
    public function listObjects(): array
    {
        $objects = $this->driver->listObjects();
        foreach ($objects as &$o) {
            $names = array_column($o['columns'], 'fld');
            $o['has_id']      = in_array('id', $names, true);
            $o['has_creator'] = in_array('creator', $names, true);
        }
        unset($o);
        usort($objects, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $objects;
    }

    public function getAllTables() : array
    {
        return $this->driver->getAllTables();
    }
}