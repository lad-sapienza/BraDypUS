<?php
namespace DB\System;

use DB\Engines\AvailableEngines;

/**
 * Main class used to manage (CRUD) system tables
 * 
 * Test / Examples
 * $db = new \DB();
 * $mng = new \DB\System\Manage($db, PREFIX);
 * $mng->createTable('charts');
 * $mng->createTable('files');
 * $mng->createTable('geodata');
 * $mng->createTable('queries');
 * $mng->createTable('rs');
 * $mng->createTable('userlinks');
 * $mng->createTable('vocabularies');
 * $mng->createTable('ciccia');

 * $mng->addRow('geodata', [ 'table_link' => 'ciao', 'id_link'	=> 1, 'geometry' => 'POINT(0, 1)' ]);
 * $mng->addRow('geodata', [ 'id_link'	=> 1, 'geometry' => 'POINT(0, 1)' ]);
		
 * $mng->editRow('geodata', 1, [ 'table_link' => 'ciao', 'id_link'	=> 1, 'geometry' => 'POINT(0, 1)' ]);

 * $mng->deleteRow('geodata', 1);

 * $mng->getById('geodata', 1);

 * $mng->getBySQL('geodata', 'table_link = ?', ['sitarc__siti']);
 *
 */

use DB\DBInterface;

class Manage
{
    private $db;
    private $prefix;
    private $driver;
    private $structure;
    private $spatial;
    public $available_tables = [
        'charts',
        'files',
        'geodata',
        'log',
        'queries',
        'rs',
        'userlinks',
        'users',
        'versions',
        'vocabularies',
    ];

    /**
     * Initializes class
     *
     * @param DBInterface $db           DB class
     * @param string $prefix    Application prefix, if available
     * @param string $driver    DB driver: throws error if it is not a valid driver
     */
    public function __construct(DBInterface $db, string $prefix = null)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->driver = $this->db->getEngine();

        if (!AvailableEngines::isValidEngine($this->driver)){
            throw new \Exception("Not valid database engine: $driver");
        }
        $this->spatial = $this->db->hasSpatialExtension();
    }

    /**
     * Loads table structure in the object,if not loaded yet
     * and returns it
     *
     * @param string $table     Table name (without prefix)
     * @return array            Table stricture
     */
    public function getStructure(string $table): array
    {
        if ( isset($this->structure[$table] ) ){
            return $this->structure[$table];
        }

        if ( !in_array($table, $this->available_tables) ){
            throw new \Exception("Table $table is not a valid system table");
        }

        $file_path = __DIR__ . '/Structure/' . $table . '.json';
        
        if (!file_exists($file_path)){
            throw new Exception("Cannot find structure configuration file {$file_path}");
        }
        
        $array = json_decode( file_get_contents($file_path), true);

        if (!$array || !\is_array($array) || empty($array)) {
            throw new Exception("Configuration file {$file_path} has invalid syntax or is empty");
        }

        $this->structure[$table] = $array;

        return $array;
    }

    /**
     * Composes SQL for table creation, depending on driver
     * And executes it in the database
     * Returns true if successfull or false if not
     *
     * @param string $table     Table name (without prefix)
     * @return boolean
     */
    public function createTable( string $table ): bool
    {
        $tb = $this->prefix . $table;

        $columns_str = $this->getStructure($table);

        $columns = [];

        foreach ($columns_str as $clm) {
            array_push($columns, $this->getCreateColumnStatement($clm, $this->driver, $this->spatial));
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$tb} (\n" .
            "\t". implode(",\n\t", $columns) . 
            "\n)";

        return $this->run($sql);
    }

    /**
     * Returns string with create information for single column
     * depending on databade driver
     *
     * @param array $clm        Array data for column: name, type, pk, notnull
     * @param string $driver    Database driver
     * @param bool $spatial     If true it is a spatially enabled database
     * @return string
     */
    private function getCreateColumnStatement(array $clm, string $driver, bool $spatial): string
    {
        $name = $clm['name'];

        if ($clm['pk']){
            if ($driver === 'pgsql') {
                $type = 'SERIAL PRIMARY KEY';
            } else if ($driver === 'mysql') {
                $type = 'INTEGER PRIMARY KEY AUTO_INCREMENT';
            } else if ($driver === 'sqlite') {
                $type = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            } else {
                throw new \Exception("Driver $driver not implemented");
            }
        } else {
            if (strtolower($clm['type']) === 'timestamp') {
                // TIMESTAMP fields are set to DATETIME on MySQL and SQLite
                $type = $driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
            } else if(strtolower($clm['type']) === 'geometry'){
                // Geometry fields are set to text in non spatial databases
                $type = $spatial ? 'GEOMETRY' : 'TEXT';
            } else {
                $type = $clm['type'];
            }
        }

        $nn = $clm['notnull'] ? 'NOT NULL' : '';

        return implode(' ', [
            $name,
            $type,
            $nn
        ]);
    }

    /**
     * Adds a new row on the table, 
     * checking that not null columns are available.
     * Returns the id of the inserted record
     *
     * @param string $table     Table name (without prefix)
     * @param array $data       Data indexed array
     * @return integer
     */
    public function addRow(string $table, array $data): int
    {
        $tb = $this->prefix . $table;

        $columns_str = $this->getStructure($table);

        $columns = [];
        $values = [];
        $question_marks = [];

        foreach ($columns_str as $column) {
            // Columns set as not null in structure must not be empty on record add
            if($column['notnull'] && !isset($data[$column['name']])){
                throw new \Exception("Missing required key `{$column['name']}` from input data");
            }
            if (isset($data[$column['name']])) {
                array_push($columns, $column['name']);
                if ( strtolower($column['type']) === 'geometry' && $this->spatial ) {
                    array_push($question_marks, "ST_GeomFromText(?)");
                } else {
                    array_push($question_marks, '?');
                }
                array_push($values, $data[$column['name']]);
            }
        }
        

        $sql = "INSERT INTO {$tb} (" .
            implode(", ", $columns ) . ") VALUES (" .
            implode(', ', $question_marks) . ")";

        return $this->run($sql, $values, 'id');
    }

    /**
     * Deletes row from table
     * and returns numer of affected records
     *
     * @param string $table     Table name (without prefix)
     * @param integer $id       Id of record to delete
     * @return integer
     */
    public function deleteRow(string $table, int $id): int
    {
        $tb = $this->prefix . $table;

        $sql = "DELETE FROM {$tb} WHERE id = ?";
        $values = [$id];

        return $this->run($sql, $values, 'affected');
    }

    /**
     * Edits cells in the table
     * Returns true on success or false on error
     *
     * @param string $table     Table name (without prefix)
     * @param integer $id       Id if the recprd to edit
     * @param array $data       Array of data to write
     * @return boolean
     */
    public function editRow(string $table, int $id, array $data): bool
    {
        $tb = $this->prefix . $table;

        $columns_str = $this->getStructure($table);

        $columns = [];
        $values = [];

        foreach ($columns_str as $column) {
            if (isset($data[$column['name']])) {
                if ( strtolower($column['type']) === 'geometry' && $this->spatial ) {
                    array_push($columns, $column['name']. " = ST_GeomFromText(?)");
                } else {
                    array_push($columns, $column['name']. ' = ?');

                }

                array_push( $values, $data[$column['name']]) ;
            }
        }
        array_push($values, $id);

        $sql = "UPDATE {$tb} SET " .
            implode(", ", $columns ) . " WHERE id = ?";
        
        return $this->run($sql, $values, 'boolean');
    }

    /**
     * Gets a row from table by id
     * returns arrau of data
     *
     * @param string $table     Table name (without prefix)
     * @param integer $id       Id of the record to return
     * @return array
     */
    public function getById(string $table, int $id): array
    {
        $tb = $this->prefix . $table;

        $columns_str = $this->getStructure($table);

        $columns = [];
        foreach ($columns_str as $column) {
            if ( strtolower($column['type']) === 'geometry' && $this->spatial) {
                array_push($columns, 'ST_AsText(' . $column['name'] . ')');
            } else {
                array_push($columns, $column['name']);
            }
        }

        $sql = "SELECT " . implode(", ", $columns). " FROM {$tb} WHERE id = ?";
        $values = [$id];

        $res = $this->run($sql, $values, 'read');
        if (\is_array($res)) {
            return $res[0];
        } else {
            return [];
        }
    }

    /**
     * Gets one or more recprds form table
     * using a where statement
     * Returns array of data
     *
     * @param string $table     Table name (without prefix)
     * @param string $where     Where statement
     * @param array $values     Array with binding values
     * @param array $custom_columns     Manually set the columns
     * @return array
     */
    public function getBySQL(string $table, string $where, array $values = [], array $custom_columns = null): array
    {
        $tb = $this->prefix . $table;

        if ($custom_columns){
            $columns = $custom_columns;
        } else {
            $columns_str = $this->getStructure($table);

            $columns = [];
            
            foreach ($columns_str as $column) {
                if ( strtolower($column['type']) === 'geometry' && $this->spatial) {
                    array_push($columns, 'ST_AsText(' . $column['name'] . ')');
                } else {
                    array_push($columns, $column['name']);
                }
            }
        }

        $sql = "SELECT " . implode(", ", $columns). " FROM {$tb} WHERE {$where}";
        
        return $this->run($sql, $values, 'read');
    }

    private function run (string $sql, array $values = [], string $return = null)
    {

        if (is_null($return)){
            return $this->db->exec($sql);
        } else if (\in_array($return, ['id', 'read', 'boolean', 'affected'])){    
            return $this->db->query($sql, $values, $return);
        }
    }
}