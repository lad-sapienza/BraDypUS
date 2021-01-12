<?php
/**
 * Builds an array with SQL query data
 * And has method for returning SQL
 * This object is filled up by ParseShortSql
 * 
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace SQL;

class QueryObject
{
    private $obj;

    /**
     * Initializes the class and sets default values for query object
     */
    public function __construct()
    {
        $this->obj = [
            'tb'        => [], // [ tb, alias ]
            'fields'    => [], // [ [tb, fld, alias], [...] ]
            'joins'     => [], // [ [tb, alias, on ], [...] ]
            'where'     => [], // [ [null, field, operator, value], [ connector, field, operator, value], [...] ]
            'group'     => [], // [ fld1, fld2, ... ]
            'order'     => [], // [ [fld,ASC|DESC], [...] ]
            'limit'     => null, // [ tot: n, offset: n ]
            'values'    => [], // [val, val, val]
        ];
    }

    /**
     * Returns array of query data 
     * Or part of them, if $index is provided
     * Returns dalse
     *
     * @param string $index
     * @return void
     */
    public function get(string $index = null)
    {
        if (!$index) {
            return $this->obj;
        } else if ( array_key_exists($index, $this->obj) ) {
            return $this->obj[$index];
        } else {
            return false;
        }
    }

    public function importObj(array $obj): self
    {
        $this->obj = $obj;
        return $this;
    }

    /**
     * Adds table name and eventually the alias to $this->obj['tb']
     *
     * @param string $tb
     * @param string $alias
     * @return self
     */
    public function setTb( string $tb, string $alias = null) : self
    {
        $this->obj['tb'] = [$tb, $alias];
        return $this;
    }
    
    /**
     * Adds field name and eventually the alias to $this->obj['fields']
     *
     * @param string $fld
     * @param string $alias
     * @param string $tb
     * @return self
     */
    public function setField(string $fld, string $alias = null, string $tb = null): self
    {
        array_push ( $this->obj['fields'], [$tb, $fld, $alias] );
        return $this;
    }
    
    /**
     * Adds JOIN statement to $this->obj['joins']
     *
     * @param string $tb
     * @param string $tb_alias
     * @param array $on
     * @return self
     */
    public function setJoin( string $tb, string $tb_alias = null, array $on): self
    {
        $found = false;
        foreach ($this->obj['joins'] as $j) {
            if ($j == [
                $tb,
                $tb_alias,
                $on
            ]){
                $found = true;
            }
        }
        if (!$found) {
            array_push($this->obj['joins'], [
                trim($tb),
                $tb_alias,
                $on
            ]);
        }
        return $this;
    }
    
    /**
     * Adds WHERE part to $this->obj['where']
     * The WHERE part is an array of 4 elements: connector, field, operator, value. 
     * Connector might be null
     *
     * @param string $connector
     * @param string $fld
     * @param string $operator
     * @param string $val
     * @return self
     */
    public function setWherePart(string $connector = null, string $open_bracket = null, string $fld, string $operator, string $val, string $closed_bracket = null): self
    {
        if ( count($this->obj['where']) > 0 && !$connector ) {
            throw new \Exception("Connector is required for query parts other than first");
        }

        array_push( $this->obj['where'] , [
            $connector,
            $open_bracket,
            $fld,
            $operator,
            $val,
            $closed_bracket
        ]);
        return $this;
    }

    /**
     * Adds WHERE values to $this->obj['values']
     *
     * @param array $values
     * @return self
     */
    public function setWhereValues( array $values ): self
    {
        $this->obj['values'] = array_merge($this->obj['values'], $values);
        return $this;
    }

    /**
     * Adds order array to $this->obj['order']
     * The array has 2 elements: fieldname and ordering direction
     *
     * @param string $fld
     * @param string $dir
     * @return self
     */
    public function setOrderFld(string $fld, string $dir): self
    {
        array_push( $this->obj['order'], [$fld, $dir]);
        return $this;
    }

    /**
     * Sets $this->obj['limit'] as array.
     * First element is total number of records to retreive
     * Second element is the offsest
     *
     * @param integer $tot
     * @param integer $offset
     * @return self
     */
    public function setLimit(int $tot, int $offset): self
    {
        $this->obj['limit'] = ['tot' => $tot, 'offset' => $offset];
        return $this;
    }

    /**
     * Adds grouping field to $this->obj['group'] array
     *
     * @param string $fld
     * @return self
     */
    public function setGroupFld( string $fld ): self
    {
        array_push( $this->obj['group'], $fld);
        return $this;
    }

    /**
     * TRansforms $where array to SQL string
     * TODO: implement brackets
     *
     * @param array $where
     * @return string
     */
    private function whereToStr(array $where = null): string
    {
        if (!$where || empty($where)) {
            return '1=1';
        }
        $str = '';

        foreach ($where as $i => $w) {
            if ($i !== 0){
                $str .= "{$w[0]} ";
            }
            $str .= "{$w[1]} {$w[2]} {$w[3]} {$w[4]} {$w[5]}";
        }
        return $str;
    }

    /**
     * Returns array containing as
     *  first element the SQL statement, if $onlyWhere is truw, only the WHERE parte will be returned
     *  second element, an array with values
     * @param bool $onlyWhere
     * @return array
     */
    public function getSql(bool $onlyWhere = false) : array
    {
        if ($onlyWhere){
            return [
                $this->whereToStr( $this->obj['where'] ),
                $this->obj['values']
            ];
        }
        $sql = [
            'SELECT'
        ];

        $fld_arr = [];
        foreach ($this->obj['fields'] as $f) {
            if (is_array($f)) {
                
                array_push(
                    $fld_arr,
                    ( $f[0] ? $f[0] . '.' : '') . $f[1] . ( $f[2] ? ' AS "' . $f[2] . '"' : '')
                );
            } else {
                array_push($fld_arr, $this->obj['tb'][0] . '.*');
            }
        }

        array_push($sql, implode(', ', $fld_arr ));

        array_push($sql, 'FROM ' . $this->obj['tb'][0] . ($this->obj['tb'][1] ? ' AS "' . $this->obj['tb'][1] . '"' : ''));

        $joins = $this->obj['joins'];
        foreach ($joins as $j) {
            array_push(
                $sql,
                "JOIN {$j[0]} " . ( isset($j[1]) ? ' AS "' . $j[1]. '"' : '') . " ON " . $this->whereToStr($j[2])
            );
        }

        array_push(
            $sql, 
            'WHERE ' . $this->whereToStr( $this->obj['where'] )
        );

        $group = $this->obj['group'];
        if ($group){
            array_push($sql, 'GROUP BY ' . implode(', ', $group));
        }
        
        $order = $this->obj['order'];
        if ($order){
            array_push($sql, 'ORDER BY ' . implode(', ', array_map( function($el){
                return implode(' ', $el);
            }, $order)));
        }
        
        $limit = $this->obj['limit'];
        if($limit){
            array_push($sql, "LIMIT {$limit['tot']} OFFSET {$limit['offset']}");
        }

        return [
          implode(' ', $sql), 
          $this->obj['values']
        ];
    }

}