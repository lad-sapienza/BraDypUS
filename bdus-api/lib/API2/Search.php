<?php
namespace API2;
/**
 * Gets Unique values from database
 * @requires ShortSql
 * @requires DB
 * @requires \Read\Record
 * @requires \toGeoJson
 */
use DB\DBInterface;
use \SQL\QueryBuilder;
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
		
        try {
			$qb = new QueryBuilder();
			$qb->loadShortSQL(self::$prefix, self::$cfg, $shortSql);
			list($sql, $values) = $qb->getSql();
			$tb = $qb->get('tb')[0];
			$debug['shortSql'] 		= $shortSql;
			$debug['urlencodedShortSql'] = urlencode($shortSql);
			$debug['table'] 		= $tb;
			$debug['main_sql'] 		= $sql;
			$debug['main_values']	= $values;			

			$header['total_rows'] 	= $total_rows ?: (int) self::getTotal($sql, $values);
			$header['total_pages'] 	= ceil($header['total_rows']/$records_per_page);
			$header['stripped_table'] = str_replace(PREFIX, null, $tb);
			$header['table_label'] 	= self::$cfg->get("tables.$tb.label");
			$header['page'] 		= ($page > $header['total_pages']) ? $header['total_pages'] : $page;

			if ($header['total_rows'] > $records_per_page ) {
				if (!$qb->get('limit') ) {
					$qb->setLimit($records_per_page, ($page-1) * $records_per_page);
				}
				list($sql, $values) = $qb->getSql();
				$tb = $qb->get('tb')[0];

				$debug['paginated_sql'] = $sql;
				$debug['paginated_values'] = $values;
			}

			$header['no_records_shown'] = (int) self::getTotal($sql, $values);

			$records = $full_records ? self::getFullData($sql, $values, $tb) : self::getData($sql, $values);

			if ($geojson) {
				return \utils::mutliArray2GeoJSON ( $tb, $records );
			} else {
				return [
					'head' => $header,
					'debug' => $debugging ? $debug : false,
					'records' => $records
				];
			}
    
        } catch (\Throwable $e) {
            return [
                'type' => 'error',
				'text' => $e->getMessage(),
                'trace' => $e->getTrace()
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
			$record = new Read(self::$db, self::$cfg, $tb, $row['id']);
			$rowResult = $record->getFull();
			array_push($fullResult, $rowResult);
		}
		
        return $fullResult;
    }
}
