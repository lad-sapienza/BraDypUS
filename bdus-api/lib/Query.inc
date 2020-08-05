<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 15, 2012
 * @uses			cfg
 *
 * $request
 * 	tb		string, required
 * type
 * 		'all'
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
    private $tb;
    private $join;
    private $fields;
    private $where;
    private $group;
    private $order;
    private $limit;
    private $db;

    /**
     *
     * Initializes class setting table, preview fields and where statement
     * @param array $request	array of request data
     * @param boolean $use_preview	use preview fields or all fields
     * @throws myException
     */
    public function __construct(DB $db, $request, $use_preview = false)
    {
        if (!$request['tb']) {
            throw new myException(tr::get('parameter_missing', ['tb']));
        }
        $this->tb = $request['tb'];

        $this->db = $db;

        $this->setFields($use_preview, $request['fields']);

        $this->setGroup($request['group']);

        $this->setLimit($request['limit_start'], $request['limit_end']);

        $this->where = $this->setWhere($request);
    }

    public function setSubQuery($string, $limit2preview = false)
    {
        if ($this->where) {
            if (preg_match('/ORDER/', $this->where)) {
                $this->where = str_replace('ORDER', 'AND (' . $this->fast($string, $limit2preview) . ') ORDER', $this->where);
            } elseif (!preg_match('/LIMIT/', $this->where)) {
                $this->where .= ' AND (' . $this->fast($string, $limit2preview) . ')';
            }
        }
    }

    public function setGroup($fld = false)
    {
        if ($fld) {
            $this->group = ' GROUP BY ' .
                (is_array($fld) ? implode(', ', $$fld) : $fld);
        }
    }


    public function setOrder($fld = false, $type = false)
    {
        if (!preg_match('/(LIMIT)/', $this->where)) {
            !$type ? $type = 'asc' : '';
            if (!$fld) {
                if (preg_match('/ORDER/', $this->where)) {
                    return;
                }
                if (cfg::tbEl($this->tb, 'order')) {
                    $fld = $this->tb . '`.`' . cfg::tbEl($this->tb, 'order');
                } else {
                    $fld = $this->tb . '`.`id';
                }
            } else {
                $fld = $this->tb . '`.`' . $fld;
            }

            $this->order = $fld ? " ORDER BY `$fld` $type " : '';

            if (preg_match('/ORDER/', $this->where)) {
                $this->where = preg_replace('/order\sby\s([`a-z_\.]+)\s?(?:asc|desc)?/i', null, $this->where);
            }
        }

        return $this;
    }

    public function setLimit($start = false, $limit = false)
    {
        if (!preg_match('/LIMIT/', $this->where)) {
            $this->limit = $limit ? " LIMIT $start , $limit " : false;
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

    private function getGroup()
    {
        return $this->group;
    }

    private function getOrder()
    {
        $this->order ?:  $this->setOrder();
        return $this->order;
    }

    private function getLimit()
    {
        $this->limit	?: $this->setLimit();
        return $this->limit;
    }
    /**
     *
     * Returns ready-to-use query string
     * @throws myException
     */
    public function getQuery()
    {
        if (!$this->fields) {
            throw new myException(tr::get('parameter_missing', ['fields']));
        }

        if (!$this->tb) {
            throw new myException(tr::get('parameter_missing', ['tb']));
        }

        if (!$this->where) {
            throw new myException(tr::get('parameter_missing', ['where']));
        }

        return "SELECT " .
            $this->formatFields() .
            " FROM `" . $this->tb . "` " .
            $this->join .
            " WHERE " . $this->getWhere() .
            $this->getGroup() .
            $this->getOrder() .
            $this->getLimit();
    }


    /**
     *
     * Returns no of total rows for the query string
     */
    public function getTotal()
    {
        $q = "SELECT count(*) as `tot` FROM (" . $this->getQuery() . ") as `a" . rand(0, 999) . "`";
        $res = $this->db->query($q);
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

        if (!$full || !is_array($result)) {
            return $result;
        }

        if (is_array($result)) {
            $fullResult = [];

            foreach ($result as $id => $row) {
                $rec = new Record($this->tb, $row['id'], $this->db);
    			$rowResult = $rec->readFull();
			    array_push($fullResult, $rowResult);
            }

            return $fullResult;
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
     * Formats WHERE statement depending on $request parameters
     * 		type: query type [all |]
     * @param array $request
     * @throws myException
     */
    private function setWhere($request)
    {
        switch ($request['type']) {
            case 'all':
                return ' 1 ';
                break;

            case 'advanced':
                return $this->advSearch($request['adv']);
                break;

            case 'sqlExpert':
                $this->join = $this->makeSafeStatement(urldecode($request['join']));
                return '(' . $this->makeSafeStatement(urldecode($request['querytext'])) . ')';
                break;

            case 'fast':
                return '(' . $this->fast($request['string']) . ')';
                break;

            case 'id_array':
                return " ( `id` IN ('" . implode("', '", $request['id']) . "') ) ";
                break;

            case 'encoded':
                $this->join = $this->makeSafeStatement(urldecode($request['join']));
                // Decode query parameter
                $encoded = base64_decode($request['q_encoded']);
                // Remove new lines
                // https://stackoverflow.com/a/3760830/586449
                $encoded = trim(preg_replace('/\s+/', ' ', $encoded));
                return preg_replace('/^SELECT(.+?)WHERE/i', '', $encoded);
                break;

            default:
                throw new myException(tr::get('parameter_missing', ['type']));
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

        foreach ($fields_to_search_in as $field=>$label) {
            $array_query_core[] = '`' . $this->tb . '`.`' . $field . "` LIKE '%" . str_replace("'", "\'", urldecode($string)) . "%'";
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

        foreach ($dati as $nr=>$row) {

            //Do not consider empty values:
            if (!in_array($row['operator'], ['is_empty', 'is_not_empty']) && $row['value'] === '') {
                continue;
            }
            // if index is order, we are not dealing with where text, continue
            if ($nr == 'order') {
                continue;
            }

            // connector
            $where .= $row['connector'] && $where !== '' ? " {$row['connector']} " : " ";

            // bracket
            $where .= ($row['('] ? ' ( ' : '');

            // fld is a composite string: table-name-with-prefix:fieldname
            $fld_arr = utils::csv_explode($row['fld'], ':');

            // field name
            $tb = $fld_arr[0];
            $fld = $fld_arr[1];


            // case 1: tb and this->tb are the same: checking native fields of current table
            if ($tb === $this->tb) {
                $format = "%s";

            // case 2: queried table is not current table, it s a plugin table
            } else {
                // case 2.1: queried field id populated by id_from_tb
                $third_table = cfg::fldEl($tb, $fld, 'id_from_tb');

                if ($third_table) {
                    $format = " `id` IN (SELECT `id_link` FROM `{$tb}` " .
                        " WHERE " .
                            "`table_link` = '{$this->tb}' " .
                            " AND `{$fld}` IN ( " .
                                "SELECT `id` FROM `{$third_table}` WHERE %s".
                                ") " .
                            ") ";
                    $fld = cfg::tbEl($third_table, 'id_field');

                // case 2.2: queried field is a normal field
                } else {
                    $format = " `id` IN (SELECT `id_link` FROM `{$tb}` WHERE `table_link` = '{$this->tb}' AND %s)";
                }
            }

            // operator
            switch ($row['operator']) {
                case 'LIKE':
                    $where .= sprintf($format, "`{$fld}` LIKE '%{$row['value']}%'");
                    //"`{$fld}` LIKE '%{$row['value']}%'";
                    break;

                case 'NOT LIKE':
                    $where .= sprintf($format, '`' . $fld . "` NOT LIKE '%". $row['value'] ."%'");
                    break;

                case 'starts_with':
                    $where .= sprintf($format, '`' . $fld . "` LIKE '". $row['value'] ."%'");
                    break;

                case 'ends_with':
                    $where .= sprintf($format, '`' . $fld . "` LIKE '%". $row['value'] ."'");
                    break;

                case 'is_empty':
                    $where .= sprintf($format, '(`' . $fld . "` = '' OR `" . $fld . "` IS NULL) ");
                    break;

                case 'is_not_empty':
                    $where .= sprintf($format, '(`' . $fld . "` != '' AND `" . $fld . "` IS NOT NULL) ");
                    break;

                default:
                    $where .= sprintf($format, '`' . $fld . '`' . $row['operator'] . "'" . $row['value'] . "'");
                    break;
            }


            $where .= $row[')'] ? ' ) ' : '';
        }

        $order = [];
        if (is_array($dati['order'])) {
            foreach ($dati['order'] as $o) {
                $order_arr = utils::csv_explode($o, ':');
                array_push($order, $order_arr[1]);
            }
        }


        if (count($order) > 0) {
            $where .= ' ORDER BY ' . implode(', ', $order);
        }

        return $where;
    }

    /**
     *
     * Sets requested (preview) fields
     * @param boolean $use_preview	if false all fields will be returned
     * @param array|false custom list of fields
     * @throws myException
     */
    public function setFields($use_preview = false, $fields = false)
    {
        if (is_array($fields)) {
            $this->fields = $fields;
            return $this;
        }

        if ($use_preview) {
            $preview_array = cfg::getPreviewFlds($this->tb);

            if (!in_array('id', $preview_array)) {
                $preview_array = array_merge(array('id'), $preview_array);
            }

            foreach ($preview_array as $fld) {
                $col_names[$fld] = cfg::fldEl($this->tb, $fld, 'label');
            }
        } else {
            $col_names = cfg::fldEl($this->tb, 'all', 'label');
        }

        $this->fields = $col_names;
        return $this;
    }

    /**
     *
     * Returns ready-to-use string of fields for the query statement
     * @throws myException
     */
    private function formatFields()
    {
        if (!$this->fields) {
            throw new myException(tr::get('parameter_missing', ['fields']));
        }
        $ret = [];
        foreach (array_keys($this->fields) as $f) {
            if (!preg_match('/\./', $f) && !preg_match('/count\(/', $f)) {
                $ret[] = '`' . $this->tb . '`.`' . $f . '`';
            } else {
                $ret[] = $f;
            }
        }
        return implode(', ', $ret);
    }

    /**
     *
     * Cleans sql query statement from dangerouse queries
     * @param string $statement		sql query statement
     */
    public static function makeSafeStatement($statement)
    {
        return preg_replace('/update|delete|truncate|;|insert|insert|update|create|drop|file|index|alter|alter routine|create routine|execute/i', '', $statement);
    }
}
