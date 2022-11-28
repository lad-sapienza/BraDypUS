<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

use DB\DBInterface;
use DB\Export\JSON;
use DB\Export\CSV;
use DB\Export\SQL;
use DB\Export\HTML;
use DB\Export\XML;
use DB\Export\XLS;

class Export
{
    private $data;
    private $metadata;

    public function __construct( DBInterface $db, string $tb, string $where_sql = null, array $values = null )
    {
        $this->data = $db->query('SELECT * FROM ' . $tb . ' WHERE ' . ($where_sql ? $where_sql : '1=1'), $values);
        $this->metadata = [
            'table' => $tb,
            'filter' => $where_sql,
            'filter_values' => $values
        ];

    }

    public function saveToFile(string $format, string $file)
    {
        switch (strtolower($format)) {
            case 'json':
                $exp = new JSON();
                break;
            
            case 'csv':
                $exp = new CSV();
                break;
            
            case 'sql':
                $exp = new SQL();
                break;
            
            case 'html':
                $exp = new HTML();
                break;
            
            case 'xml':
                $exp = new XML();
                break;
            
            case 'xls':
                $exp = new XLS();
                break;
            
            default:
                throw new \Exception("Unknown format `$format`");
                break;
        }

        return $exp->saveToFile($this->data, $this->metadata, $file);
    }
}