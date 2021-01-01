<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

/**
 * Utility to easily build sql queries using a OO pattern
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			May 29, 2017
 */

use SQL\QueryObject;
use SQL\ShortSql\Validator;
use SQL\ShortSql\ParseShortSql;
use Config\Config;

class QueryBuilder
{
    private $qo;

    /**
     * Initializated object and sets table name
     *
     * @param QueryObject $qo
     */
    public function __construct(QueryObject $qo = null)
    {
        if ($tb) {
            $this->table($tb);
        }
        $this->qo = $qo ?? new QueryObject();
    }

    public function loadShortSQL( string $prefix, Config $cfg, string $short, int $pagination_limit = null )
    {
        $validator = new Validator($cfg);
        $ss = new ParseShortSql($prefix, $cfg, $validator);
        $this->qo = $ss->parseAll($short, $pagination_limit)->getQueryObject();
    }

    public function getQueryObject()
    {
        return $this->qo;
    }

    /**
     * Shorthand method to search in underlying QueryObject
     *
     * @param string $index
     * @return void
     */
    public function get( string $index = null )
    {
        return $this->qo->get( $index );
    }

    /**
     * Sets table name
     * @param  string $tb table name
     * @param  string $alias table alias
     * @return object     Main object
     */
    public function setTable(string $tb, string $alias = null) : self
    {
        $this->qo->setTb($tb, $alias);
        return $this;
    }

    public function setJoin(string $tb, string $onStatement, string $joinType = 'JOIN') : self
    {
        $this->qo->setJoin( $tb, null, $onStatement);
        return $this;
    }

    /**
     * Sets field
     * @param string $fld field name
     * @param string $alias field alias
     * @return object     Main object
     */
    public function setField(string $fld, string $alias = null, string $tb = null) : self
    {
        $this->qo->setField( $tb, $fld, $alias);

        return $this;
    }

    /**
     * Sets WHERE statement
     * @param  string  $fld  Field to search in
     * @param  string  $val  Value to set
     * @param  string  $op   Operator, default =
     * @param  string  $conn Connection between statements, default AND
     * @return object        Main object
     */
    public function setWhere(string $fld, string $val, string $op = '=', string $conn = 'AND') : self
    {
        $this->qo->setWherePart($conn, $fld, $op, '?');
        $this->qo->setWhereValues( [$val] );
        return $this;
    }

    /**
     * Sets limit statement
     * @param  int  $limit          number of rows to return
     * @param  int|false $offset    Offset to start at (default false)
     * @return object        Main object
     */
    public function setLimit(int $limit, int $offset = 0) : self
    {
        $this->qo->setLimit ( $limit, $offset );
        return $this;
    }

    /**
     * Sets order by statement
     * @param  string $column Field name to use for sorting
     * @param  string $dir   Direction; can be ASC or DESC, default ASC
     * @return object        Main object
     */
    public function setOrder(string $column, string $dir = 'ASC') : self
    {
        $this->qo->setOrderFld($column, $dir);
        return $this;
    }

    /**
     * Sets group by statement
     * @param  string $column Column to use for grouping
     * @return object        Main object
     */
    public function setGroup(string $column) : self
    {
        $this->qo->setGroupFld($column);
        return $this;
    }

    private function whereToStr(array $where = null)
    {
        if (!$where || empty($where)) {
            return '1=1';
        }
        $str = '';

        foreach ($where as $i => $w) {
            if ($i !== 0){
                $str .= "{$w[0]} ";
            }
            $str .= "{$w[1]} {$w[2]} {$w[3]} ";
        }
        return $str;
    }

    /**
     * Returns formatted SQL statement
     * @return string Formatted SQL statement
     */
    public function getSql() : array
    {
        $sql = [
            'SELECT'
        ];

        $fld_arr = [];
        foreach ($this->qo->get('fields') as $f) {
            if (is_array($f)) {
                
                array_push(
                    $fld_arr,
                    ( $f[0] ? $f[0] . '.' : '') . $f[1] . ( $f[2] ? ' AS "' . $f[2] . '"' : '')
                );
            } else {
                array_push($fld_arr, $this->qo->get('tb')[0] . '.*');
            }
        }

        array_push($sql, implode(', ', $fld_arr ));

        array_push($sql, 'FROM ' . $this->qo->get('tb')[0] . ($this->qo->get('tb')[1] ? ' AS "' . $this->qo->get('tb')[1] . '"' : ''));

        $joins = $this->qo->get('joins');
        foreach ($joins as $j) {
            array_push(
                $sql,
                "JOIN {$j[0]} " . ( isset($j[1]) ? ' AS "' . $j[1]. '"' : '') . " ON " . $this->whereToStr($j[2])
            );
        }

        array_push(
            $sql, 
            'WHERE ' . $this->whereToStr( $this->qo->get('where') )
        );

        $group = $this->qo->get('group');
        if ($group){
            array_push($sql, 'GROUP BY ' . implode(', ', $group));
        }
        
        $order = $this->qo->get('order');
        if ($order){
            array_push($sql, 'ORDER BY ' . implode(', ', array_map( function($el){
                return implode(' ', $el);
            }, $order)));
        }
        
        $limit = $this->qo->get('limit');
        if($limit){
            array_push($sql, "LIMIT {$limit['tot']} OFFSET {$limit['offset']}");
        }

        return [
          implode(' ', $sql), 
          $this->qo->get('values')
        ];
    }
}
