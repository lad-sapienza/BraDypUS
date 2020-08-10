<?php

namespace DB\Inspect;

/**
 * Interface to interact with database structure
 */
class Sqlite implements InspectInterface
{   
    private $db;

    public function __construct(\DB $db)
    {
        $this->db = $db;
    }

    public function tableExists(string $tb): bool
    {
        $this->db->dont_version();
        $res = $this->db->query(
            "SELECT count(name) as tot FROM sqlite_master WHERE type='table' AND name = ?",
            [ $tb ]
        );
        $this->db->do_version();
        
        return $res && !empty($res) && $res[0]['tot'] > 0;
    }

    public function tableColumns(string $tb): array
    {
        $ret = [];

        $this->db->dont_version();
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
        $this->db->do_version();

        return $ret;
    }
}