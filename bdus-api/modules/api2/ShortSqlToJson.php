<?php

/**
 * Gets Unique values from database
 * @requires ShortSql
 * @requires DB
 */
class ShortSqlToJson
{	
	/**
	 * Runs a query from a shortSQl string and returns results
	 *
	 * @param String $app		Application name
	 * @param String $shortSql	ShortSQL string
	 * @param Array $opts		Options array, all values are optional:
	 * 				totale_rows:	Total number of rows, if not provided will be calculated
	 * 				page:			page number, if not provided will be assumed as 1
	 * 				geojson:		if true, geojson string will be returned, instead of array of results
	 * 				records_per_page:	To be used for pagination. default value: 30
	 * 				full_records:	if true full records will be returned (with plugins, files, etc), otherwise only core information will be returned
	 * @param boolean $debugging:	if true, some debugging information will be returned
	 * @return Array|String
	 */
    public static function run($app, $shortSql, $opts = [], $debugging = false)
    {	
		// Set default opts
        $total_rows 		= $opts['total_rows']   	?: false;
        $page      		 	= $opts['page']         	?: 1;
        $geojson   			= $opts['geojson']      	?: false;
        $records_per_page	= $opts['records_per_page']	?: 30;
		$full_records 		= $opts['full_records'] 	?: false;
		
        try {
			list($sql, $values, $tb) = ShortSql::getSQLAndValues($shortSql);
			$debug['shortSql'] 		= $shortSql;
			$debug['urlencodedShortSql'] = urlencode($shortSql);
			$debug['table'] 		= $tb;
			$debug['main_sql'] 		= $sql;
			$debug['main_values']	= $values;			

			$header['total_rows'] 	= $total_rows ?: (int) self::getTotal($sql, $values);
			$header['total_pages'] 	= ceil($header['total_rows']/$records_per_page);
			$header['stripped_table'] = str_replace($app . PREFIX_DELIMITER, null, $tb);
			$header['table_label'] 	= cfg::tbEl($tb, 'label');
			$header['page'] 		= ($page > $header['total_pages']) ? $header['total_pages'] : $page;

			if ($header['total_rows'] > $records_per_page ) {
				$paginated_limit = ($page-1) * $records_per_page . ":" . $records_per_page;
				list($sql, $values, $tb) = ShortSql::getSQLAndValues($shortSql, $paginated_limit);

				$debug['paginated_sql'] = $sql;
				$debug['paginated_values'] = $values;
			}

			$header['no_records_shown'] = (int) self::getTotal($sql, $values);

			$records = $full_records ? self::getFullData($sql, $values, $tb) : self::getData($sql, $values);

			if ($geojson) {
				return toGeoJson::fromMultiArray( $records, true, $tb );
			} else {
				return [
					'head' => $header,
					'debug' => $debugging ? $debug : false,
					'records' => $records
				];
			}
    
        } catch (Exception $e) {
            return [
                'type' => 'error',
				'text' => $e->getMessage(),
                'trace' => json_encode($e->getTrace(), JSON_PRETTY_PRINT)
            ];
        }

	}
	
	private static function getTotal($sql, $values = [])
    {
        $count_sql = 'SELECT count(*) AS `tot` FROM ( ' . $sql .' ) AS `' . uniqid('a') . '`';
        $res = DB::start()->query($count_sql, $values);
        return $res[0]['tot'];
	}
	
	private static function getData($sql, $values = [])
    {   
        return DB::start()->query($sql, $values, 'read');
	}
	
	private static function getFullData($sql, $values = [], $tb)
    {
        $result = self::getData($sql, $values);

        if (!is_array($result)) {
            return false;
        }

		$fullResult = [];

		$db = DB::start();

        foreach ($result as $id => $row) {
            $rec = new Record($tb, $row['id'], $db);
			$rowResult = $rec->readFull();
			array_push($fullResult, $rowResult);
        }

        return $fullResult;
    }
}
