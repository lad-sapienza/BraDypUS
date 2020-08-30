<?php
declare(strict_types=1);

namespace SQL;

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
     * @param string $tb table name, default null
     */
    public function __construct(string $tb = null)
    {
        if ($tb) {
            $this->table($tb);
        }
    }

    /**
     * Sets table name
     * @param  string $tb table name
     * @param  string $alias table alias
     * @return object     Main object
     */
    public function setTable(string $tb, string $alias = null) : self
    {
        $this->tb = $tb . ($alias ? "AS $alias" : '');
        return $this;
    }

    public function setJoin(string $tb, string $onStatement, string $joinType = 'JOIN') : self
    {
        array_push($this->joins, "$joinType $tb ON $onStatement");
        return $this;
    }

    /**
     * Sets field
     * @param string $fld field name
     * @param string $alias field alias
     * @return object     Main object
     */
    public function setFields(string $fld, string $alias = null) : self
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
    public function setWhere(string $fld, string $val, $op = '=', string $conn = 'AND', string $pre = null, string $post = null) : self
    {
        if (empty($this->where)) {
            $conn = false;
        }
        $this->where[] = " {$conn} {$pre} {$fld} {$op} ? {$post} ";
        array_push($this->val, $val);

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
        $this->limit = " LIMIT $limit  OFFSET {$offset} ";
        return $this;
    }

    /**
     * Sets order by statement
     * @param  string $column Field name to use for sorting
     * @param  string $sort   Sorting type; can be ASC or DESC, default ASC
     * @return object        Main object
     */
    public function setOrder(string $column, string $sort = 'ASC') : self
    {
        if (!in_array(strtolower($sort), ['asc', 'desc'])) {
            $sort = 'ASC';
        }
        $this->order[] = " {$column} {$sort} ";
        return $this;
    }

    /**
     * Sets group by statement
     * @param  string $column Column to use for grouping
     * @return object        Main object
     */
    public function setGroup(string $column) : self
    {
        $this->group[] .= " {$column} ";
        return $this;
    }

    /**
     * Returns formatted SQL statement
     * @return string Formatted SQL statement
     */
    public function getSql() : array
    {
        if (empty($this->where)) {
            $this->where[] = '1=1';
        }

        if (empty($this->fields)) {
            $this->fields[] = '*';
        }

        $sql = [
          'SELECT',
          implode(', ', $this->fields),
          'FROM ' . $this->tb,
          implode(' ', $this->joins),
          'WHERE ' . implode(' ', $this->where),
          ($this->group ? 'GROUP BY ' . implode(' ', $this->group) : ''),
          ($this->order ? 'ORDER BY ' . implode(' ', $this->order) : ''),
          $this->limit
        ];

        return [
          implode(' ', $sql), 
          $this->val
        ];
    }
}
