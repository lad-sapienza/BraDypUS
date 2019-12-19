<?php
/**
 * Utility to easily build sql queries using a OO pattern
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			May 29, 2017

 */
class QueryBuilder
{
    private $tb;
    private $fields = [];
    private $where = [];
    private $limit;
    private $order = [];
    private $val = [];
    private $joins = [];

    /**
     * Initializated object and sets table name
     * @param string $tb table name, default false
     * @param string $fld field name, dafult false
     */
    public function __construct($tb = false, $fld = false)
    {
        if ($tb) {
            $this->table($tb);
        }

        if ($fld) {
            $this->fields($fld);
        }
        return $this;
    }

    /**
     * Sets table name
     * @param  string $tb table name
     * @return object     Main object
     */
    public function setTable($tb)
    {
        $this->tb = $tb;
        return $this;
    }

    public function setJoin($tb, $onStatement, $joinType = 'JOIN')
    {
        array_push($this->join, "$joinType $tb ON $onStatement");
        return $this;
    }

    /**
     * Sets field
     * @param string $fld field name
     * @param string $alias field alias
     * @return object     Main object
     */
    public function setFields($fld, $alias = false)
    {
        $this->fields[] = $fld . ($alias ? ' AS ' . $alias : '');
        return $this;
    }

    /**
     * Sets WHERE statement
     * @param  string  $fld  Field to search in
     * @param  string  $val  Value to set
     * @param  string  $op   Operator, default =
     * @param  string  $conn Connection between statements, default AND
     * @param  string|false $pre  Open one or more brackets
     * @param  string|false $post Close one or more brackets
     * @return object        Main object
     */
    public function setWhere($fld, $val, $op = '=', $conn = 'AND', $pre = false, $post = false)
    {
        if (empty($this->where)) {
            $conn = false;
        }
        $this->where[] = " {$conn} {$pre} `{$fld}` {$op} ? {$post} ";
        array_push($this->val, $val);

        return $this;
    }

    /**
     * Sets limit statement
     * @param  int  $nr     number of rows to return
     * @param  int|false $offset Offset to start at (default false)
     * @return object        Main object
     */
    public function setLimit($nr, $offset = false)
    {
        $this->limit = " LIMIT " . ($offset ? " {$offset}, " : " ") . $nr;
        return $this;
    }

    /**
     * Sets order by statement
     * @param  string $column Field name to use for sorting
     * @param  string $sort   Sorting type; can be ASC or DESC, default ASC
     * @return object        Main object
     */
    public function setOrder($column, $sort = 'ASC')
    {
        if (!in_array(strtolower($sort), ['asc', 'desc'])) {
            $sort = 'ASC';
        }
        $this->order[] = " `{$column}` {$sort} ";
        return $this;
    }

    /**
     * Sets group by statement
     * @param  string $column Column to use for grouping
     * @return object        Main object
     */
    public function setGroup($column)
    {
        $this->group[] .= " `{$column}` ";
        return $this;
    }

    /**
     * Returns formatted SQL statement
     * @return string Formatted SQL statement
     */
    public function getSql()
    {
        if (empty($this->where)) {
            $this->where[] = '1';
        }

        if (empty($this->fields)) {
            $this->fields[] = '*';
        }

        $sql = [
          'SELECT',
          implode(', ', $this->fields),
          'FROM ' . $this->tb,
          implode(' ', $this->join),
          'WHERE ' . implode(' ', $this->where),
          ($this->group ? 'GROUP BY ' . implode(' ', $this->group) : false),
          ($this->order ? 'ORDER BY ' . implode(' ', $this->order) : false),
          $this->limit
        ];

        return [
          implode(' ', $sql), 
          $this->val
        ];
    }
}
