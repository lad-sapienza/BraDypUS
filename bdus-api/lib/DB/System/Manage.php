<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;

use DB\Engines\AvailableEngines;

/**
 * Main class used to manage (CRUD) system tables
 *
 * All table names passed to public methods must be full bdus_* names.
 *
 * Test / Examples
 * $db = new \DB();
 * $mng = new \DB\System\Manage($db);
 * $mng->createTable('bdus_charts');
 * $mng->createTable('bdus_files');
 * $mng->createTable('bdus_geodata');
 * $mng->createTable('bdus_queries');
 * $mng->createTable('bdus_rs');
 * $mng->createTable('bdus_userlinks');
 * $mng->createTable('bdus_vocabularies');

 * $mng->addRow('bdus_geodata', [ 'table_link' => 'ciao', 'id_link' => 1, 'geometry' => 'POINT(0, 1)' ]);

 * $mng->editRow('bdus_geodata', 1, [ 'table_link' => 'ciao', 'id_link' => 1, 'geometry' => 'POINT(0, 1)' ]);

 * $mng->deleteRow('bdus_geodata', 1);

 * $mng->getById('bdus_geodata', 1);

 * $mng->getBySQL('bdus_geodata', 'table_link = ?', ['sitarc__siti']);
 *
 */

use DB\DBInterface;

class Manage
{
    private $db;
    private $driver;
    private $spatial;
    /** @var array<string, array{columns: array, indexes: array, relations: array}> */
    private array $descriptor = [];
    public $available_tables = [
        'bdus_api_keys',
        'bdus_cfg_fields',
        'bdus_cfg_relations',
        'bdus_cfg_tables',
        'bdus_cfg_templates',
        'bdus_charts',
        'bdus_file_links',
        'bdus_files',
        'bdus_geodata',
        'bdus_log',
        'bdus_migrations',
        'bdus_queries',
        'bdus_rs',
        'bdus_userlinks',
        'bdus_users',
        'bdus_user_table_privs',
        'bdus_versions',
        'bdus_vocabularies',
    ];

    /**
     * Initializes class
     *
     * @param DBInterface $db  DB class
     */
    public function __construct(DBInterface $db)
    {
        $this->db = $db;
        $this->driver = $this->db->getEngine();

        if (!AvailableEngines::isValidEngine($this->driver)){
            throw new \Exception("Not valid database engine: $this->driver");
        }
        $this->spatial = $this->db->hasSpatialExtension();
    }

    public function getDb(): DBInterface  { return $this->db; }

    // ── Descriptor loading ───────────────────────────────────────────────────

    /**
     * Loads and caches the full table descriptor (columns + indexes + relations).
     * Supports both the current object format and the legacy flat-array format
     * (legacy: columns only, no indexes or relations).
     *
     * @param string $table  Table full name (bdus_*)
     * @return array{columns: array, indexes: array, relations: array}
     */
    private function loadDescriptor(string $table): array
    {
        if (isset($this->descriptor[$table])) {
            return $this->descriptor[$table];
        }

        if (!in_array($table, $this->available_tables)) {
            throw new \Exception("Table $table is not a valid system table");
        }

        // Strip bdus_ prefix to find the JSON structure file (files stay named e.g. users.json)
        $shortName = str_starts_with($table, 'bdus_') ? substr($table, 5) : $table;
        $file_path = __DIR__ . '/Structure/' . $shortName . '.json';

        if (!file_exists($file_path)) {
            throw new \Exception("Cannot find structure configuration file {$file_path}");
        }

        $data = json_decode(file_get_contents($file_path), true);

        if (!$data || !\is_array($data) || empty($data)) {
            throw new \Exception("Configuration file {$file_path} has invalid syntax or is empty");
        }

        // New format: { columns: [...], indexes: [...], relations: [...] }
        // Legacy format: flat array of column objects
        if (isset($data['columns'])) {
            $descriptor = [
                'columns'   => $data['columns'],
                'indexes'   => $data['indexes']   ?? [],
                'relations' => $data['relations'] ?? [],
            ];
        } else {
            $descriptor = [
                'columns'   => $data,
                'indexes'   => [],
                'relations' => [],
            ];
        }

        $this->descriptor[$table] = $descriptor;
        return $descriptor;
    }

    /**
     * Returns column definitions for a system table.
     * Backward-compatible: all existing callers continue to work unchanged.
     *
     * @param string $table  Table full name (bdus_*)
     * @return array         Array of column definition arrays
     */
    public function getStructure(string $table): array
    {
        return $this->loadDescriptor($table)['columns'];
    }

    /**
     * Returns index definitions declared for a system table.
     * Each entry: { name, columns[], unique? }
     */
    public function getIndexDefinitions(string $table): array
    {
        return $this->loadDescriptor($table)['indexes'];
    }

    /**
     * Returns FK relation definitions declared for a system table.
     * Each entry: { name, column, ref_table, ref_column, on_delete }
     */
    public function getRelationDefinitions(string $table): array
    {
        return $this->loadDescriptor($table)['relations'];
    }

    // ── Table creation ───────────────────────────────────────────────────────

    /**
     * Creates a system table from its JSON descriptor, then applies indexes
     * and (for MySQL/PG) FK constraints. Idempotent: uses CREATE TABLE IF NOT EXISTS.
     * On SQLite, FK constraints are included inline in the DDL.
     *
     * @param string $table  Table full name (bdus_*)
     */
    public function createTable(string $table): bool
    {
        $tb      = $table;
        $columns = [];

        foreach ($this->getStructure($table) as $clm) {
            $columns[] = $this->getCreateColumnStatement($clm, $this->driver, $this->spatial);
        }

        // SQLite: FK constraints must be declared inside CREATE TABLE
        if ($this->driver === 'sqlite') {
            foreach ($this->getRelationDefinitions($table) as $rel) {
                $columns[] = $this->buildInlineFkClause($rel);
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$tb} (\n\t" . implode(",\n\t", $columns) . "\n)";
        $ok  = $this->run($sql);

        if (!$ok) {
            return false;
        }

        // Apply indexes on all engines
        $this->applyIndexes($table);

        // MySQL/PG: apply FK constraints via ALTER TABLE
        if ($this->driver !== 'sqlite') {
            $this->applyRelations($table);
        }

        return true;
    }

    /**
     * Builds a FOREIGN KEY inline clause for use inside CREATE TABLE (SQLite only).
     * ref_table in JSON is already a full bdus_* name.
     */
    private function buildInlineFkClause(array $rel): string
    {
        $refTable = $rel['ref_table'];
        $refCol   = $rel['ref_column'] ?? 'id';
        $onDelete = strtoupper($rel['on_delete'] ?? 'RESTRICT');
        return "FOREIGN KEY ({$rel['column']}) REFERENCES {$refTable}({$refCol}) ON DELETE {$onDelete}";
    }

    /**
     * Applies all index definitions for a table. Called by createTable().
     */
    private function applyIndexes(string $table): void
    {
        foreach ($this->getIndexDefinitions($table) as $idx) {
            $this->createIndex($table, $idx['name'], $idx['columns'], $idx['unique'] ?? false);
        }
    }

    /**
     * Applies all FK relation definitions for a table via ALTER TABLE.
     * Called by createTable() on MySQL/PG only.
     */
    private function applyRelations(string $table): void
    {
        foreach ($this->getRelationDefinitions($table) as $rel) {
            $this->addForeignKey(
                $table,
                $rel['name'],
                $rel['column'],
                $rel['ref_table'],
                $rel['ref_column'] ?? 'id',
                $rel['on_delete']  ?? 'RESTRICT'
            );
        }
    }

    /**
     * Returns string with create information for single column
     * depending on database driver
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
                // TODO Geometry fields are set to text in non spatial databases
                // And to geometry in spatial databases. Not yet supported!
                // $type = $spatial ? 'GEOMETRY' : 'TEXT';
                $type = 'TEXT';
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
     * @param string $table     Table full name (bdus_*)
     * @param array $data       Data indexed array
     * @return integer
     */
    public function addRow(string $table, array $data): int
    {
        $tb = $table;

        $columns_str = $this->getStructure($table);

        $columns = [];
        $values = [];
        $question_marks = [];

        foreach ($columns_str as $column) {
            if ($column['name'] === 'id'){
                continue;
            }
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
     * and returns number of affected records
     *
     * @param string $table     Table full name (bdus_*)
     * @param integer $id       Id of record to delete
     * @return integer
     */
    public function deleteRow(string $table, int $id): int
    {
        $tb = $table;

        $sql = "DELETE FROM {$tb} WHERE id = ?";
        $values = [$id];

        return $this->run($sql, $values, 'affected');
    }

    /**
     * Edits cells in the table
     * Returns true on success or false on error
     *
     * @param string $table     Table full name (bdus_*)
     * @param integer $id       Id of the record to edit
     * @param array $data       Array of data to write
     * @return boolean
     */
    public function editRow(string $table, int $id, array $data): bool
    {
        $tb = $table;

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
     * returns array of data
     *
     * @param string $table     Table full name (bdus_*)
     * @param integer $id       Id of the record to return
     * @return array
     */
    public function getById(string $table, int $id): array
    {
        $tb = $table;

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
        if (\is_array($res) && \is_array($res[0])) {
            return $res[0];
        } else {
            return [];
        }
    }

    /**
     * Gets one or more records from table
     * using a where statement
     * Returns array of data
     *
     * @param string $table             Table full name (bdus_*)
     * @param string $where             Where statement
     * @param array $values             Array with binding values
     * @param array $custom_columns     Manually set the columns
     * @return array
     */
    public function getBySQL(string $table, string $where, array $values = [], array $custom_columns = null): array
    {
        $tb = $table;

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

        // DB::query() may return null (no result / error); normalize to array
        // so the return type declaration is always satisfied.
        return $this->run($sql, $values, 'read') ?: [];
    }

    // ── Public trans-engine index & FK API ───────────────────────────────────

    /**
     * Creates an index on a system table. Idempotent: no-op if already exists.
     *
     * @param string   $table    Table full name (bdus_*)
     * @param string   $name     Index name
     * @param string[] $columns  Columns to include in the index
     * @param bool     $unique   Whether to create a UNIQUE index (default false)
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool
    {
        $tb      = $table;
        $idxName = $name;
        $cols    = implode(', ', $columns);
        $uniq    = $unique ? 'UNIQUE ' : '';

        if ($this->driver === 'mysql') {
            // MySQL < 8.0.29 has no IF NOT EXISTS for regular indexes
            if ($this->indexExists($tb, $idxName)) {
                return true;
            }
            $sql = "CREATE {$uniq}INDEX {$idxName} ON {$tb} ({$cols})";
        } else {
            // SQLite and PostgreSQL both support IF NOT EXISTS
            $sql = "CREATE {$uniq}INDEX IF NOT EXISTS {$idxName} ON {$tb} ({$cols})";
        }

        return (bool) $this->run($sql);
    }

    /**
     * Drops an index. No-op if the index does not exist.
     *
     * @param string $table  Table full name (bdus_*) (needed by MySQL syntax)
     * @param string $name   Index name
     */
    public function dropIndex(string $table, string $name): bool
    {
        $tb      = $table;
        $idxName = $name;

        $sql = match ($this->driver) {
            'mysql'  => "DROP INDEX {$idxName} ON {$tb}",
            default  => "DROP INDEX IF EXISTS {$idxName}",  // SQLite, PostgreSQL
        };

        return (bool) $this->run($sql);
    }

    /**
     * Adds a FK constraint to an existing table.
     *
     * SQLite does NOT support adding FK constraints to existing tables.
     * On SQLite this method throws \BadMethodCallException.
     * For new tables, declare FK constraints in the structure JSON 'relations' section —
     * createTable() will include them in the CREATE TABLE DDL automatically.
     *
     * @param string $table      Table full name (bdus_*) (the table that holds the FK column)
     * @param string $name       Constraint name
     * @param string $column     FK column name on $table
     * @param string $refTable   Referenced table full name (bdus_*)
     * @param string $refColumn  Referenced column (default: id)
     * @param string $onDelete   CASCADE | RESTRICT | SET NULL | NO ACTION
     *
     * @throws \BadMethodCallException on SQLite
     */
    public function addForeignKey(
        string $table,
        string $name,
        string $column,
        string $refTable,
        string $refColumn = 'id',
        string $onDelete  = 'RESTRICT'
    ): bool {
        if ($this->driver === 'sqlite') {
            throw new \BadMethodCallException(
                "SQLite does not support adding FK constraints to existing tables. " .
                "Declare FK constraints in the structure JSON 'relations' section; " .
                "createTable() will include them in the CREATE TABLE DDL."
            );
        }

        $tb         = $table;
        $constraint = $name;
        $ref        = $refTable;
        $onDelete   = strtoupper($onDelete);

        $sql = "ALTER TABLE {$tb} ADD CONSTRAINT {$constraint} " .
               "FOREIGN KEY ({$column}) REFERENCES {$ref}({$refColumn}) ON DELETE {$onDelete}";

        return (bool) $this->run($sql);
    }

    /**
     * Drops a FK constraint from an existing table.
     *
     * @throws \BadMethodCallException on SQLite
     */
    public function dropForeignKey(string $table, string $name): bool
    {
        if ($this->driver === 'sqlite') {
            throw new \BadMethodCallException(
                "SQLite does not support dropping FK constraints from existing tables."
            );
        }

        $tb         = $table;
        $constraint = $name;

        $sql = match ($this->driver) {
            'mysql'  => "ALTER TABLE {$tb} DROP FOREIGN KEY {$constraint}",
            'pgsql'  => "ALTER TABLE {$tb} DROP CONSTRAINT IF EXISTS {$constraint}",
            default  => throw new \BadMethodCallException("Driver {$this->driver} not supported for dropForeignKey"),
        };

        return (bool) $this->run($sql);
    }

    /**
     * Checks whether a named index exists on a table.
     * Used internally to guard against duplicate creation on MySQL.
     */
    private function indexExists(string $tb, string $idxName): bool
    {
        $result = match ($this->driver) {
            'mysql'  => $this->db->query(
                "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
                  WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
                [$tb, $idxName],
                'read'
            ),
            'pgsql'  => $this->db->query(
                "SELECT COUNT(*) AS cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$tb, $idxName],
                'read'
            ),
            'sqlite' => $this->db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='index' AND tbl_name = ? AND name = ?",
                [$tb, $idxName],
                'read'
            ),
            default  => throw new \Exception("Driver {$this->driver} not supported"),
        };

        return isset($result[0]['cnt']) && (int)$result[0]['cnt'] > 0;
    }

    // ── Internal query runner ─────────────────────────────────────────────────

    private function run (string $sql, array $values = [], string $return = null)
    {
        if (is_null($return)){
            return $this->db->exec($sql);
        } else if (\in_array($return, ['id', 'read', 'boolean', 'affected'])){
            return $this->db->query($sql, $values, $return);
        }
    }
}
