<?php

namespace DB\Inspect;

/**
 * Interface to interact with database structure
 */
class Postgres implements InspectInterface
{   
    private $db;

    public function __construct(\DB $db)
    {
        $this->db = $db;
    }

    public function tableExists(string $tb): bool
    {
        $res = $this->db->query(
            "SELECT COUNT(*) as tot FROM information_schema.tables WHERE table_name = ?"
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
}