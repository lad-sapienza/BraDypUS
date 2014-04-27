<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			All rights reserved
 * @since			Apr 15, 2012
 * @uses			cfg
 * 
 * $request
 * 	tb		string, required
 * type
 * 		'all'
 * 		'recent'
 * 			'limit'		int, required
 * 
 * 		'advanced'
 * 			'adv'		array of arrays:
 * 				[
 * 				'connector'	:	string, optional, AND|OR|XOR,
 * 				'('					:	boolean, optional
 * 				'fld'				:	string, required, table-name-with-prefix:fieldname
 * 				'operator'		:	string, required, LIKE|=|NOT LIKE|starts_with|ends_with|is_empty|is_not_empty|>|<
 * 				'value'			:	string, required if operator is LIKE|=|NOT LIKE|starts_with|ends_with|>|<, and not required if operator is is_empty|is_not_empty
 * 				')'					:	boolean, optional
 * 				],
 * 				[...], [...]
 * 				order: array of fieldnames following syntax: table-name-with-prefix:fieldname
 * 				[
 * 				..., ...
 * 				]
 * 
 * 		'sqlExpert'
 * 			'querytext'		string, required, not encoded query text
 * 
 * 		'fast'
 * 			'string'		string, required, string to search for
 * 
 * 		'id_array'
 * 			'id'			array, required, array of integers (ids)
 * 
 * 		'encoded'
 * 			'q_encoded'		string, required, base64_encoded query string
 */

class Query
{
	private $tb, $fields, $where, $order, $limit, $db;
	
	/**
	 * 
	 * Initializes class setting table, preview fields and where statement
	 * @param array $request	array of request data
	 * @param boolean $use_preview	use preview fields or all fields 
	 * @throws myException
	 */
	public function __construct(DB $db, $request, $use_preview = false)
	{
		if (!$request['tb'])
		{
			throw new myException(tr::sget('parameter_missing', 'tb'));
		}
		$this->tb = $request['tb'];
		
		$this->db = $db;
		
		$this->fields = $this->setFields($use_preview);
		
		$this->where = $this->setWhere($request);
	}
	
	public function setSubQuery($string, $limit2preview = false)
	{
		if ($this->where)
		{
			if (preg_match('/ORDER/', $this->where))
			{
				$this->where = str_replace('ORDER', 'AND (' . $this->fast($string, $limit2preview) . ') ORDER', $this->where);
							
			}
			else if (!preg_match('/LIMIT/', $this->where))
			{
				$this->where .= ' AND (' . $this->fast($string, $limit2preview) . ')';
			}
		}
	}
	
	public function setOrder($fld = false, $type = false)
	{
		if (!preg_match('/(LIMIT)/', $this->where))
		{
			!$type ? $type = 'asc' : '';
			if (!$fld)
			{
				if (preg_match('/ORDER/', $this->where))
				{
					return;
				}
				$fld = cfg::tbEl($this->tb, 'order');
			}
			$this->order = " ORDER BY `$fld` $type ";
			
			if (preg_match('/ORDER/', $this->where))
			{
				$this->where = preg_replace('/order\sby\s([`a-z_]+)\s?(?:asc|desc)?/i', null, $this->where);
			}
		}
		
		return $this;
	}
	
	public function setLimit($start = false, $limit = false)
	{
		if (!preg_match('/(LIMIT)/', $this->where))
		{
			$this->limit = $limit ? " LIMIT $start , $limit " : '';
		}
		
		return $this;
	}
	
	/**
	 * 
	 * Returns where clausole
	 * @param boolean $encoded
	 */
	public function getWhere($encoded = false)
	{
		return $encoded ? base64_encode($this->where) : $this->where;
	}
	
	public function getOrder()
	{
		$this->order ?:  $this->setOrder();
		return $this->order;
	}
	
	public function getLimit()
	{
		$this->limit	?: $this->setOrder();
		return $this->limit;
	}
	/**
	 * 
	 * Returns ready-to-use query string
	 * @throws myException
	 */
	public function getQuery()
	{
		if (!$this->fields || !$this->tb || !$this->where)
		{
			throw new myException(tr::sget('parameter_missing', array('tb', 'fields', 'where')));
		}
		return "SELECT " . $this->formatFields() . " FROM `" . $this->tb . "` WHERE " . $this->getWhere() . $this->getOrder() . $this->getLimit();
	}
	
	
	/**
	 * 
	 * Returns no of total rows for the query string
	 */
	public function getTotal()
	{
		$q = "SELECT count(*) as `tot` FROM (" . $this->getQuery() . ") as `a" . rand(0, 999) . "`";
		$res = $this->db->query( $q );
		return $res[0]['tot'];
	}
	
	
	/**
	 * 
	 * Returns array of results for query
	 * @param boolean $full if true also links  and images will be returned (usefyll for API)
	 */
	public function getResults($full = false)
	{
		$result = $this->db->query($this->getQuery(), false, 'read');
		
		if (!$full)
		{
			return $result;
		}
		else
		{
			if (is_array($result))
			{
        $fullResult = array();
        
				foreach ($result as $id => $row)
				{
          $rec = new Record($this->tb, $row['id'], $this->db);
          
          $rowResult = array();
          
          $rowResult['core'] = $rec->getCore();
          
          $rowResult['coreLinks'] = $rec->getCoreLinks();

          $rowResult['allPlugins'] = $rec->getAllPlugins(true);

          $rowResult['fullFiles'] = $rec->getFullFiles();

          $rowResult['geodata'] = $rec->getGeodata();

          if (cfg::tbEl($tb, 'rs'))
          {
            $rowResult['rs'] = $rec->getRS();
          }

          $rowResult['userLinks'] = $rec->getUserLinks();
          
          $fullResult[] = $rowResult;
    
				}
				return $fullResult;
			}
			else 
			{
				return $result;
			}
		}
	}
	
	/**
	 * 
	 * Returns array of requested (preview) fields
	 */
	public function getFields()
	{
		return $this->fields;
	}
	
	
	/**
	 * 
	 * Formats WHERE statement depending on $_REQUEST parameters
	 * 		type: query type [all |] 
	 * @param array $request
	 * @throws myException
	 */
	private function setWhere($request)
	{
		switch($request['type'])
		{
			case 'all':
				return ' 1 ';
				break;
				
			case 'recent':
				return ' 1  ORDER BY `id` DESC LIMIT 0, ' . $request['limit'];
				break;
				
			case 'advanced':
				return $this->advSearch($request['adv']);
				break;
				
			case 'sqlExpert':
				return '(' . $this->makeSafeStatement( urldecode ($request['querytext']) ) . ')'; 
				break;
				
			case 'fast':
				return '(' . $this->fast($request['string']) . ')';
				break;
				
			case 'id_array':
				return " ( `id` IN ('" . implode("', '", $request['id']) . "') ) ";
				break;
				
			case 'encoded':
				return preg_replace('/^SELECT(.+?)WHERE/i', '', base64_decode($request['q_encoded']));
				break;
				
			default:
				throw new myException(tr::sget('parameter_missing', 'type'));
				break;
		}
		return false;
	}
	
	/**
	 * 
	 * Formats query from expert user input
	 * @param string $string
	 * @param boolean $limit2preview
	 */
	private function fast($string, $limit2preview = false)
	{
		$fields_to_search_in = $limit2preview ? cfg::getPreviewFlds($this->tb) : cfg::fldEl($this->tb, 'all', 'label');
		
		foreach ($fields_to_search_in as $field=>$label)
		{
			$array_query_core[] = '`' . $this->tb . '`.`' . $field . "` LIKE '%" . str_replace("'", "\'", urldecode($string) ) . "%'";
		}
		// join partial statements
		return implode(' OR ', $array_query_core);
			
	}
	
	/**
	 * 
	 * Formats query from advanced search user input
	 * @param array $dati	POST data from user input GUI
	 * @return string query to execute
	 */
	private function advSearch($dati)
	{
		$where = '';
		
		foreach($dati as $nr=>$row)
		{
			// if index is order, we-re not dealing with where text, continue
			if ($nr == 'order')
			{
				continue;
			}
			// connector
			$where .= $row['connector'] ? ' ' . $row['connector'] . ' ' : ' ';
			
			// bracket
			$where .= ($row['('] ? ' ( ' : '');
			
			// fld is a composite string: table-name-with-prefix:fieldname
			$fld_arr = utils::csv_explode($row['fld'], ':');
			
			// field name
			//$where .= '`' . $fld_arr[1] . '`';
			$tb = $fld_arr[0];
			
			$format = ($tb == $this->tb ) ? "%s" : " `id` IN (SELECT `id_link` FROM `{$tb}` WHERE `table_link` = '{$this->tb}' AND %s)";
			
			// operator
			switch($row['operator'])
			{
				case 'LIKE':
					$where .= sprintf($format, "`{$fld_arr[1]}` LIKE '%{$row['value']}%'"); 
					//"`{$fld_arr[1]}` LIKE '%{$row['value']}%'";
					break;
					
				case 'NOT LIKE':
					$where .= sprintf($format, '`' . $fld_arr[1] . "` NOT LIKE '%". $row['value'] ."%'");
					break;
					
				case 'starts_with':
					$where .= sprintf($format, '`' . $fld_arr[1] . "` LIKE '". $row['value'] ."%'");
					break;
					
				case 'ends_with':
					$where .= sprintf($format, '`' . $fld_arr[1] . "` LIKE '%". $row['value'] ."'");
					break;
					
				case 'is_empty':
					$where .= sprintf($format, '(`' . $fld_arr[1] . "` = '' OR `" . $fld_arr[1] . "` IS NULL) ");
					break;
					
				case 'is_not_empty':
					$where .= sprintf($format, '(`' . $fld_arr[1] . "` != '' AND `" . $fld_arr[1] . "` IS NOT NULL) ");
					break;
					
				default:
					$where .= sprintf($format, '`' . $fld_arr[1] . '`' . $row['operator'] . "'" . $row['value'] . "'");
					break;
			}
			
			
			$where .= $row[')'] ? ' ) ' : '';
		}
		
		if (is_array($dati['order']))
		{
			foreach ($dati['order'] as $o)
			{
				$order_arr = utils::csv_explode($o, ':');
				$order[] = $order_arr[1];
			}
		}
		
		
		if (count($order) > 0)
		{
			$where .= ' ORDER BY ' . implode(', ', $order);
		}
		
		return $where; 
	}
	
	/**
	 * 
	 * Sets requested (preview) fields
	 * @param boolean $use_preview	if false all fields will be returned
	 * @throws myException
	 */
	private function setFields($use_preview = false)
	{
		if ($use_preview)
		{
			$preview_array = cfg::getPreviewFlds($this->tb);
			
			if(!in_array('id', $preview_array))
			{
				$preview_array = array_merge(array('id'), $preview_array);
			}
			
			foreach ($preview_array as $fld)
			{
				$col_names[$fld] = cfg::fldEl($this->tb, $fld, 'label'); 
			}
		}
		else
		{
			$col_names = cfg::fldEl($this->tb, 'all', 'label');
		}
		return $col_names;
	}
	
	/**
	 * 
	 * Returns ready-to-use string of fields for the query statement
	 * @throws myException
	 */
	private function formatFields()
	{
		if (!$this->fields)
		{
			throw new myException(tr::sget('parameter_missing', 'fields'));
		}
		
		return '`' . implode('`, `', array_keys($this->fields) ). '` ';
	}
	
	/**
	 * 
	 * Cleans sql query statement from dangerouse queries
	 * @param string $statement		sql query statement
	 */
	public static function makeSafeStatement( $statement )
	{
		return preg_replace('/update|delete|truncate|;|insert|insert|update|create|drop|file|index|alter|alter routine|create routine|execute/i', '', $statement );
	}
	
}