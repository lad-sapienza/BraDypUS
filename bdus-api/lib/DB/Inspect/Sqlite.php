<?php

namespace DB\Inspect;

use DB\DBInterface;

/**
 * Interface to interact with database structure
 */
class Sqlite implements InspectInterface
{   
    private $db;

    public function __construct(DBInterface $db)
    {
        $this->db = $db;
    }

    public function tableExists(string $tb): bool
    {
        $res = $this->db->query(
            "SELECT count(name) as tot FROM sqlite_master WHERE type='table' AND name = ?",
            [ $tb ]
        );
        
        return $res && !empty($res) && $res[0]['tot'] > 0;
    }

    public function tableColumns(string $tb): array
    {
        $ret = [];

        $res = $this->db->query("PRAGMA table_info('{$tb}')");
        if (!$res || empty($res)){
            throw new \Exception("Error on getting column list for table {$tb}");
        }

        foreach ($res as $row) {
            $ret[] = [
                'fld' => $row['name'],
                'type' => $row['type']
            ];
        }

        return $ret;
    }
}