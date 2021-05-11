<?php
/**
* @copyright 2007-2021 Julian Bogdani
* @license AGPL-3.0; see LICENSE
*/

namespace API;

use \DB\DBInterface;
use \SQL\ShortSql\ParseShortSql;
use \Config\Config;
use \Record\Read;

class Search
{	
    private static $db;
    private static $cfg;
    private static $prefix;
    /**
    * Runs a query from a shortSQl string and returns results
    *
    * @param DBInterface $db	Database object
    * @param string $shortSql			ShortSQL string
    * @param array $opts				Options array, all values are optional:
    * 				totale_rows:	Total number of rows, if not provided will be calculated
    * 				page:			page number, if not provided will be assumed as 1
    * 				geojson:		if true, geojson string will be returned, instead of array of results
    * 				records_per_page:	To be used for pagination. default value: 30
    * 				full_records:	if true full records will be returned (with plugins, files, etc), otherwise only core information will be returned
    * @param bool	$debugging:	if true, some debugging information will be returned
    * @return array
    */
    public static function run( string $prefix, string $shortSql, array $opts = [], bool $debugging = false, DBInterface $db, Config $cfg ): array
    {	
        self::$db = $db;
        self::$cfg = $cfg;
        self::$prefix = $prefix;
        
        // Set default opts
        $total_rows 		= $opts['total_rows']   	?: false;
        $page      		 	= $opts['page']         	?: 1;
        $geojson   			= $opts['geojson']      	?: false;
        $records_per_page	= $opts['records_per_page']	?: 30;
        $full_records 		= $opts['full_records'] 	?: false;
        
        $parseShortSql = new ParseShortSql(self::$prefix, self::$cfg);
        $qo = $parseShortSql->parseAll($shortSql)->getQueryObject();
        
        list($sql, $values) = $qo->getSql();
        $tb = $qo->get('tb')['name'];
        $debug['table'] 		= $tb;
        $debug['main_sql'] 		= $sql;
        $debug['main_values']	= $values;			
        
        $header['shortsql'] 		= $shortSql;
        $header['total_rows'] 	= $total_rows ?: (int) self::getTotal($sql, $values);
        $header['total_pages'] 	= ceil($header['total_rows']/$records_per_page);
        $header['table'] 		= $tb;
        $header['stripped_table'] = str_replace(PREFIX, '', $tb);
        $header['table_label'] 	= self::$cfg->get("tables.$tb.label");
        $header['page'] 		= ($page > $header['total_pages']) ? $header['total_pages'] : $page;
        
        if ($header['total_rows'] > $records_per_page ) {
            if (empty($qo->get('limit')) ) {
                $qo->setLimit($records_per_page, ($page-1) * $records_per_page);
            }
            list($sql, $values) = $qo->getSql();
            $tb = $qo->get('tb')['name'];
            
            $debug['paginated_sql'] = $sql;
            $debug['paginated_values'] = $values;
        }
        
        $header['no_records_shown'] = (int) self::getTotal($sql, $values);
        
        $records = $full_records ? self::getFullData($sql, $values, $tb) : self::getData($sql, $values);
        if (!$full_records) {
            $labels = [];
            if (isset($records[0]) && is_array($records[0])) {
                foreach (array_keys($records[0]) as $fld) {
                    $labels[$fld] = self::$cfg->get("tables.$tb.fields.$fld.label");
                }
            }
            $header['fields'] = $labels;
        }
        
        if ($geojson) {
            return \utils::multiArray2GeoJSON ( $tb, $records );
        } else {
            return [
                'head' => $header,
                'debug' => $debugging ? $debug : false,
                'records' => $records
            ];
        }
    }
    
    private static function getTotal(string $sql, array $values = null): int
    {
        $count_sql = 'SELECT count(*) AS tot FROM ( ' . $sql .' ) AS ' . uniqid('a');
        $res = self::$db->query($count_sql, $values);
        return $res[0]['tot'];
    }
    
    private static function getData(string $sql, array $values = null): array
    {   
        return self::$db->query($sql, $values, 'read');
    }
    
    private static function getFullData(string $sql, array $values = null, string $tb): array
    {
        $result = self::getData($sql, $values);
        
        if (!is_array($result)) {
            return [];
        }
        
        $fullResult = [];
        
        foreach ($result as $id => $row) {
            $record = new Read($row['id'], null, $tb, self::$db, self::$cfg);
            $rowResult = $record->getFull();
            array_push($fullResult, $rowResult);
        }
        
        return $fullResult;
    }
}
