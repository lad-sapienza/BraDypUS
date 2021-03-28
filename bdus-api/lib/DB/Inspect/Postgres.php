<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Inspect;

use DB\DBInterface;
/**
 * Interface to interact with database structure
 */
class Postgres implements InspectInterface
{   
    private $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function tableExists(string $tb): bool
    {
        $res = $this->db->query(
            "SELECT COUNT(*) as tot FROM information_schema.tables WHERE table_name = ?",
            [ $tb ]
        );
        
        return $res && !empty($res) && $res[0]['tot'] > 0;
    }

    public function tableColumns(string $tb): array
    {
        $ret = [];

        $res = $this->db->query("SELECT * FROM information_schema.columns WHERE table_name = ?", [ $tb ]);
        if (!$res || empty($res)){
            throw new \Exception("Error on getting column list for table {$tb}");
        }

        foreach ($res as $row) {
            $ret[] = [
                'fld' => $row['column_name'],
                'type' => $row['data_type']
            ];
        }

        return $ret;
    }

    public function getAllTables() : array
    {
        $res = $this->db->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname != 'pg_catalog' AND  schemaname != 'information_schema'");
        
        $ret = [];
        foreach ($res as $row) {
            array_push($ret, $row['tablename']);
        }
        return $ret;
    }
}