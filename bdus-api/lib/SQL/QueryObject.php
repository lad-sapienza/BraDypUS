<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * 
 * 
 * Builds an array with SQL query data
 * And has method for returning SQL
 * This object is filled up by ParseShortSql
 * 
 * tb: [
 *      name: tb-name    required, string
 *      alias: tb-alias  optional, string
 * ]
 * fields: [
 *      [
 *          tb: tb-name     required if subQuery not set, string
 *          fld: fld-name   required if subQuery not set, string
 *          subQuery: QueryObject, required if fld not set, Object
 *          alias:  alias   optional, string
 *          fn:function:    optional, string
 *      ],
 * ]
 * joins: [
 *      [
 *          tb: tb-name:        required, string
 *          alias: tb-alias:    optional, string
 *          on: on-statement:   required, array [
 *              connector       required if not first element, string: and|or
 *              opened_bracket  optional, string: (
 *              fld             required, string
 *              operator        required, string
 *              binded          required: string
 *              closed_bracket  oprional, string: )
 *          ], [...]
 *      ]
 * ]
 * where: [
 *      [
 *          connector,      optional if first, string
 *          open-bracket,   optional, string
 *          fld,            required, string
 *          operator:       required, string,
 *          value,          required if subQuery not set, string
 *          subQuery: QueryObject, required if value not set, Object [Not implemented: passed as string]
 *          close-bracket,  optional, string
 *      ]
 * ]
 * group: [
 *          fld-name:   optional, string,
 *          ...
 * ]
 * order: [
 *      [
 *          fld: fld-name   optional, string
 *          dir: direction:  optional, string
 *      ]
 * ]
 * limit: [
 *      [
 *          tot: n      optional, int
 *          offset: n   optional, int
 *      ]
 * ]
 * values: [
 *      val:    string, optional
 *      ...
 * ]
 * 
 */

namespace SQL;

use \SQL\SqlException;
use \SQL\Validator;
use Config\Config;

class QueryObject
{
    /**
     * Main data container
     *
     * @var array
     */
    private $obj;

    /**
     * Flag to enable (default) or disable auto-joins
     *
     * @var bool
     */
    private $auto_join = true;

    /**
     * Config object
     *
     * @var Config
     */
    private $cfg;

    /**
     * If true validation will be performed
     *
     * @var boolean
     */
    private $validation = false;

    /**
     * Initializes the class and sets default values for query object
     */
    public function __construct(Config $cfg = null)
    {
        $this->cfg = $cfg;

        $this->obj = [
            'tb'        => [
                'name' => null, // required, string
                'alias' => null // optional, string
            ],
            'fields'    => [], // [ [tb, fld, alias, function], [...] ]
            'joins'     => [], // [ [tb, alias, on ], [...] ]
            'where'     => [], // [ [null, field, operator, value], [ connector, field, operator, value], [...] ]
            'group'     => [], // [ fld1, fld2, ... ]
            'order'     => [], // [ [fld,ASC|DESC], [...] ]
            'limit'     => [], // [ tot: n, offset: n ]
            'values'    => [], // [val, val, val]
        ];
    }

    public function enable_validation()
    {
        $this->validation = true;
    }

    public function setAutoJoin(bool $auto_join = false): self
    {
        $this->auto_join = $auto_join;
        return $this;
    }

    /**
     * Returns array of QueryObject data,
     * or part of them, if $index is provided
     * Returns false if provided index does not exist
     *
     * @param string $index
     */
    public function get(string $index = null)
    {
        if (!$index) {
            return $this->obj;
        } else if (array_key_exists($index, $this->obj)) {
            return $this->obj[$index];
        } else {
            return false;
        }
    }

    /**
     * Adds table name and eventually the alias to $this->obj['tb']
     *
     * @param string $tb        table name
     * @param string $alias     table alias
     * @return self
     */
    public function setTb(string $tb, string $alias = null): self
    {
        $this->obj['tb']['name'] = $tb;
        if ($alias) {
            $this->obj['tb']['alias'] = $alias;
        }
        return $this;
    }

    /**
     * Adds field name and eventually the alias and function to apply to $this->obj['fields']
     * Optional auto-join is performed for plugin tables
     *
     * @param string $fld       column name, without table name
     * @param string $alias     column alias, optional
     * @param string $tb        table name, if missing main table will be used
     * @param string $function  aggregative function name to apply to data
     * @return self
     */
    public function setField(
        string $fld,
        string $alias = null,
        string $tb = null,
        string $function = null
    ): self {
        // Table name could also be provided as dot-separated prefix:
        if (strpos($fld, '.') !== false) {
            list($tb, $fld) = explode('.', $fld);
        }
        if (!$tb) {
            if (!$this->obj['tb']['name']) {
                throw new SqlException("Cannot guess table name. Provide table name or add setTb before setField");
            }
            $tb = $this->obj['tb']['name'];
        }
        array_push(
            $this->obj['fields'],
            [
                "tb"    => $tb,
                "fld"   => $fld,
                "alias" => $alias,
                "fn"    => $function
            ]
        );

        $is_plugin_fld = ($this->cfg
            && \is_array($this->cfg->get("tables.{$this->obj['tb']['name']}.plugin"))
            && \in_array($tb, $this->cfg->get("tables.{$this->obj['tb']['name']}.plugin")));

        $is_geodata_fld = (substr_compare($tb, 'geodata', -strlen('geodata')) === 0); // https://stackoverflow.com/a/10473026
        /*
        Auto-join if:
                auto_join flag is true
                and configuration object is provided
                and field table is different from main table
                and field table geodata or another plugin table
        */
        if (
            $this->auto_join                    // autojoin ON
            && $this->obj['tb']['name']            // Main table is set
            && $this->obj['tb']['name'] !== $tb    // Field table is different from main table
            && ($is_geodata_fld || $is_plugin_fld)
        ) {
            // The main results is grouped by id, not to have multiple time the same record

            $this->setGroupFld($this->obj['tb']['name'] . ".id");

            $this->setJoin($tb, null, [
                [
                    "connector"         => null,
                    "opened_bracket"    => null,
                    "fld"               => "{$tb}.table_link",
                    "operator"          => "=",
                    "binded"            => "'{$this->obj['tb']['name']}'",
                    "closed_bracket"    => null
                ],
                [
                    "connector"         => "AND",
                    "opened_bracket"    => null,
                    "fld"               => "{$tb}.id_link",
                    "operator"          => "=",
                    "binded"            => "{$this->obj['tb']['name']}.id",
                    "closed_bracket"    => null
                ]
            ]);
        }

        return $this;
    }

    public function setFieldSubQuery(
        string $subQuery,
        string $alias = null,
        array $values = null
    ): self {
        array_push(
            $this->obj['fields'],
            [
                "subQuery"   => $subQuery,
                "alias" => $alias,
                "fn"    => null
            ]
        );
        if ($values && is_array($values)) {
            $this->setWhereValues($values);
        }

        return $this;
    }



    /**
     * Adds WHERE part to $this->obj['where']
     * The WHERE part is an array of 4 elements: connector, field, operator, value. 
     * Connector might be null
     * Optional auto-join is performed if field is set to if_from_tb
     * Optional auto-join is performed if field belongs to a plugin or geodata table
     *
     * @param string $connector
     * @param string $opened_bracket
     * @param string $fld
     * @param string $operator
     * @param string $val
     * @param string $closed_bracket
     * @return self
     */
    public function setWherePart(
        string $connector = null,
        string $opened_bracket = null,
        string $fld,
        string $operator,
        string $val,
        string $closed_bracket = null
    ): self {
        if (count($this->obj['where']) > 0 && !$connector) {
            throw new \Exception("Connector is required for query parts other than first");
        }

        /*
        Auto-join if 
                auto_join flag is true
                and configuration object is provided
                and field is set to id_from_table
        */
        list($tb, $strip_fld) = explode('.', $fld);
        // Literal fieldname support since v4.0.7
        if ($strip_fld[0] === '^') {
            $strip_fld = substr($strip_fld, 1);
            $dont_id_from_tb = true;
            $fld = $tb . '.' . $strip_fld;
        }
        if ($this->auto_join && $this->cfg) {
            $id_from_tb = $this->cfg->get("tables.$tb.fields.$strip_fld.id_from_tb");
            if ($id_from_tb && !$dont_id_from_tb) {
                // Set unique alias for joined table (might be same as referenced table)
                $alias = uniqid($id_from_tb);
                // Get the id_field of to-join table
                $id_from_tb_id_fld = $this->cfg->get("tables.$id_from_tb.id_field");
                // Set ON statement
                $on = [
                    [
                        "connector"         => null,
                        "opened_bracket"    => null,
                        "fld"               => "{$tb}.{$strip_fld}",
                        "operator"          => "=",
                        "binded"            => "{$alias}.id",
                        "closed_bracket"    => null
                    ]
                ];
                // Set JOIN
                $this->setJoin($id_from_tb, $alias, $on);
                $fld = $alias . '.' . $id_from_tb_id_fld;
            }
        }

        array_push($this->obj['where'], [
            "connector"     => $connector,
            "opened_bracket" => $opened_bracket,
            "fld"           => $fld,
            "operator"      => $operator,
            "binded"        => $val,
            "closed_bracket" => $closed_bracket
        ]);

        /*
        Auto-join if:
                auto_join flag is true
                and configuration object is provided
                and field table is different from main table
                and field table geodata or another plugin table
        */
        $is_plugin_fld = ($this->cfg
            && \is_array($this->cfg->get("tables.{$this->obj['tb']['name']}.plugin"))
            && \in_array($tb, $this->cfg->get("tables.{$this->obj['tb']['name']}.plugin")));

        $is_geodata_fld = (substr_compare($tb, 'geodata', -strlen('geodata')) === 0); // Field table is geodata

        if (
            $this->auto_join
            && $this->obj['tb']['name']
            && $this->obj['tb']['name'] !== $tb
            && ($is_geodata_fld || $is_plugin_fld)
        ) {
            $this->setGroupFld($this->obj['tb']['name'] . ".id");

            $this->setJoin($tb, null, [
                [
                    "connector"         => null,
                    "opened_bracket"    => null,
                    "fld"               => "{$tb}.table_link",
                    "operator"          => "=",
                    "binded"            => "'{$this->obj['tb']['name']}'",
                    "closed_bracket"    => null
                ],
                [
                    "connector"         => "AND",
                    "opened_bracket"    => null,
                    "fld"               => "{$tb}.id_link",
                    "operator"          => "=",
                    "binded"            => "{$this->obj['tb']['name']}.id",
                    "closed_bracket"    => null
                ]
            ]);
        }

        return $this;
    }

    /**
     * Adds WHERE values to $this->obj['values']
     *
     * @param array $values
     * @return self
     */
    public function setWhereValues(array $values): self
    {
        $this->obj['values'] = array_merge($this->obj['values'], $values);
        return $this;
    }

    /**
     * Adds JOIN statement to $this->obj['joins']
     *
     * @param string $tb
     * @param string $tb_alias
     * @param array $on: each on element is structured as follows:
     *                      connector         => null or AND|OR, 
     *                      opened_bracket    => null or (, 
     *                      fld               => {$fld_tb}.table_link, 
     *                      operator          => =|LIKE|etc..., 
     *                      binded            => "value",
     *                      closed_bracket    => null or )
     * @return self
     */
    public function setJoin(string $tb, string $tb_alias = null, array $on): self
    {
        $data_arr = [
            'tb'    => trim($tb),
            'alias' => $tb_alias,
            'on'    => $on
        ];
        // Add JOIN only if not already available
        $found = false;
        foreach ($this->obj['joins'] as $j) {

            if ($j === $data_arr) {
                $found = true;
            }
        }
        if (!$found) {
            array_push($this->obj['joins'], $data_arr);
        }
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
        array_push(
            $this->obj['order'],
            [
                'fld'   => $fld,
                'dir'   => $dir
            ]
        );
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
        $this->obj['limit'] = [
            'tot' => $tot,
            'offset' => $offset
        ];
        return $this;
    }

    /**
     * Adds grouping field to $this->obj['group'] array
     *
     * @param string $fld
     * @return self
     */
    public function setGroupFld(string $fld): self
    {
        array_push($this->obj['group'], $fld);
        return $this;
    }

    private function whereToStr(array $where = null): string
    {
        if (!$where || empty($where)) {
            return '1=1';
        }
        $str = '';

        foreach ($where as $i => $w) {
            if ($i !== 0) {
                $str .= "{$w['connector']} ";
            }
            $str .= "{$w['opened_bracket']} {$w['fld']} {$w['operator']} {$w['binded']} {$w['closed_bracket']}";
        }
        return $str;
    }

    /**
     * Returns array containing as
     *  first element the SQL statement, if $onlyWhere is true, only the WHERE parte will be returned
     *  second element, an array with values
     * @param bool $onlyWhere
     * @return array
     */
    public function getSql(bool $onlyWhere = false): array
    {
        $this->validateObject();

        if ($onlyWhere) {
            return [
                $this->whereToStr($this->obj['where']),
                $this->obj['values']
            ];
        }

        $sql = ['SELECT'];

        $fld_arr = [];
        foreach ($this->obj['fields'] as $f) {
            if (is_array($f)) {
                if ($f['subQuery']) {
                    $f_str = " ( {$f['subQuery']} ) ";
                    if ($f['values'] && is_array($f['values'])) {
                        $this->obj['values'] = array_merge($this->obj['values'], $f['values']);
                    }
                } else {
                    $f_str = ($f['tb'] ? $f['tb'] . '.' : '') . $f['fld']; // Add table
                    if ($f['fn']) { // Add function
                        if ($f['fn'] === 'count_distinct') {
                            $f_str = "COUNT( DISTINCT {$f_str})";
                        } elseif ($f['fn'] === 'distinct') {
                            $f_str = "DISTINCT {$f_str}";
                        } else {
                            $f_str = "{$f['fn']}({$f_str})";
                        }
                    }
                }
                if ($f['alias']) { // Add alias
                    $f_str = "{$f_str} AS \"{$f['alias']}\"";
                }
                array_push($fld_arr, $f_str);
            } else {
                array_push($fld_arr, $this->obj['tb']['name'] . '.*');
            }
        }

        array_push($sql, implode(', ', $fld_arr));

        array_push($sql, 'FROM ' . $this->obj['tb']['name'] . ($this->obj['tb']['alias'] ? ' AS "' . $this->obj['tb']['alias'] . '"' : ''));

        foreach ($this->obj['joins'] as $j) {
            array_push(
                $sql,
                "JOIN {$j['tb']} " . (isset($j['alias']) ? ' AS "' . $j['alias'] . '"' : '') . " ON " . $this->whereToStr($j['on'])
            );
        }

        array_push(
            $sql,
            'WHERE ' . $this->whereToStr($this->obj['where'])
        );

        if (!empty($this->obj['group'])) {
            array_push($sql, 'GROUP BY ' . implode(', ', $this->obj['group']));
        }

        if (!empty($this->obj['order'])) {
            array_push($sql, 'ORDER BY ' . implode(', ', array_map(function ($el) {
                return $el['fld'] . ' ' . $el['dir'];
            }, $this->obj['order'])));
        }

        if (!empty($this->obj['limit'])) {
            array_push($sql, "LIMIT {$this->obj['limit']['tot']} OFFSET {$this->obj['limit']['offset']}");
        }

        return [
            implode(' ', $sql),
            $this->obj['values']
        ];
    }

    private function validateObject()
    {
        // TODO: Validator is turned off: handle vocabularies validation
        if ($this->cfg && true === false) {
            $validator = new Validator($this->cfg);
            $validator->validateQueryObject($this);
        }
    }
}
