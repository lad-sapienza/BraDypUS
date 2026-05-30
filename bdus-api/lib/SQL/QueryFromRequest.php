<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Builds a SQL SELECT from a structured $request array.
 *
 * Required keys: tb (string)
 * Optional key:  type (string, default 'all')
 *
 * Supported types and their extra keys:
 *
 *   'all'       — no filter
 *   'fast'      — 'string': text searched across preview fields via LIKE
 *   'sqlExpert' — 'querytext': raw SQL WHERE clause (sanitised)
 *                 'join': optional SQL JOIN clause (sanitised)
 *   'filter'    — 'filter': Directus-style nested filter array
 *                   e.g. ['field' => ['_eq' => 'value'], '_and' => [[...], [...]]]
 *                   Parsed via SQL\Filter\JsonFilter.
 */


namespace SQL;

use DB\DBInterface;
use Config\Config;

class QueryFromRequest
{
  private $tb;
  private $join;
  private $fields;
  private $where;
  private $values = [];
  private $order;
  private $limit;
  private $db;
  private $cfg;

  /**
   *
   * Initializes class setting table, preview fields and where statement
   * @param array $request	array of request data
   * @param boolean $use_preview	use preview fields or all fields
   * @throws \Exception
   */
  public function __construct(
    DBInterface $db,
    Config $cfg,
    array $request,
    bool $use_preview = false
  ) {
    if (!$request['tb']) {
      throw new \Exception('Missing required parameter: tb');
    }
    $this->tb = $request['tb'];

    $this->db = $db;

    $this->cfg = $cfg;

    $this->setFields($use_preview, $request['fields'] ?? false);

    $this->setWhere($request);
  }

  public function setOrder($fld = false, $type = false): ?QueryFromRequest
  {
    if (!preg_match('/(LIMIT)/', $this->where)) {
      !$type ? $type = 'asc' : '';
      if (!$fld) {
        if (preg_match('/ORDER/', $this->where)) {
          return null;
        }
        if ($this->cfg->get("tables.{$this->tb}.order")) {
          $fld = $this->tb . '.' . $this->cfg->get("tables.{$this->tb}.order");
        } else {
          $fld = $this->tb . '.id';
        }
      } else {
        $fld = $this->tb . '.' . $fld;
      }

      $this->order = $fld ? " ORDER BY $fld $type " : '';

      if (preg_match('/ORDER/', $this->where)) {
        $this->where = preg_replace('/order\sby\s([a-z_\.]+)\s?(?:asc|desc)?/i', '', $this->where);
      }
    }
    return $this;
  }

  public function setLimit($offset = false, $limit = false): QueryFromRequest
  {
    if (!$limit) {
      $this->limit = false;
      return $this;
    }
    if (!preg_match('/LIMIT/', $this->where)) {
      $this->limit = " LIMIT $limit " . ($offset ? " OFFSET $offset " : '');
    }

    return $this;
  }

  /**
   * Returns the resolved WHERE predicate and its bound values as a pair.
   *
   * Use this instead of calling getWhere() + getValues() separately when you
   * only need the filter predicate — e.g. chart aggregations or geo JOINs
   * that build their own SELECT statement.  QueryFromRequest always produces
   * a non-empty WHERE string (defaults to '1=1' for type=all).
   *
   * @return array{0: string, 1: array}  [$whereSql, $boundValues]
   */
  public function getWhereClause(): array
  {
    return [$this->where, $this->values];
  }

  public function getValues(): array
  {
    return $this->values;
  }

  private function getOrder()
  {
    $this->order ?:  $this->setOrder();
    return $this->order;
  }

  private function getLimit()
  {
    $this->limit  ?: $this->setLimit();
    return $this->limit;
  }
  /**
   *
   * Returns ready-to-use query string
   * @throws \Exception
   */
  public function getQuery(bool $with_values = false)
  {
    if (!$this->fields) {
      throw new \Exception('Missing required parameter: fields');
    }

    if (!$this->tb) {
      throw new \Exception('Missing required parameter: tb');
    }

    if (!$this->where) {
      throw new \Exception('Missing required parameter: where');
    }

    $sql = "SELECT " .
      $this->formatFields() .
      " FROM " . $this->tb . " " .
      $this->join .
      " WHERE " . $this->where .
      $this->getOrder() .
      $this->getLimit();

    if ($with_values) {
      return [
        $sql,
        $this->getValues()
      ];
    } else {
      return $sql;
    }
  }


  /**
   *
   * Returns no of total rows for the query string
   */
  public function getTotal(): int
  {
    list($query, $values) = $this->getQuery(true);
    $q = 'SELECT count(*) as tot FROM (' . $query . ') as ' . uniqid('a');
    $res = $this->db->query($q, $values);
    return (int)$res[0]['tot'];
  }


  /**
   * Returns array of results for query.
   */
  public function getResults(): array
  {
    return $this->db->query($this->getQuery(), $this->getValues(), 'read') ?: [];
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
   * @throws Exception
   */
  private function setWhere($request)
  {
    switch ($request['type']) {
      case 'all':
        $this->where = ' 1=1 ';
        break;

      case 'sqlExpert':
        $this->join = $this->makeSafeStatement(urldecode($request['join']));
        $safe = trim($this->makeSafeStatement(urldecode($request['querytext'])));
        // An empty querytext would produce WHERE () — a syntax error; fall back to all records
        $this->where = $safe !== '' ? '(' . $safe . ')' : '1=1';
        break;

      case 'fast':
        $this->where = '(' . $this->fast($request['string']) . ')';
        break;

      case 'filter':
        // Directus-style JSON filter: [ "field" => ["_op" => value], "_and" => [...] ]
        // Received either from a JSON POST body or from URL bracket notation
        //   ?filter[status][_eq]=active&filter[name][_icontains]=pompeii
        // which PHP natively parses into the same nested array structure.
        $filterArr = $request['filter'] ?? [];
        if (empty($filterArr)) {
          $this->where = '1=1';
          break;
        }
        $jsonFilter = new \SQL\Filter\JsonFilter($this->cfg, $this->tb);
        [$filterSql, $filterVals] = $jsonFilter->toSql($filterArr);
        $this->where  = $filterSql  ?: '1=1';
        $this->values = $filterVals ?: [];
        break;

      default:
        throw new \Exception('Missing required parameter: type');
        break;
    }
    return;
  }

  /**
   *
   * Formats query from expert user input
   * @param string $string
   * @param boolean $limit2preview
   */
  private function fast($string, $limit2preview = false)
  {
    $fields_to_search_in = $limit2preview ? $this->getPreviewFields($this->tb) : $this->cfg->get("tables.{$this->tb}.fields.*.label");

    foreach ($fields_to_search_in as $field => $label) {
      $array_query_core[] = $this->tb . '.' . $field . " LIKE '%" . str_replace("'", "\'", urldecode($string)) . "%'";
    }
    // join partial statements
    return implode(' OR ', $array_query_core);
  }

  /**
   *
   * Sets requested (preview) fields
   * @param boolean $use_preview	if false all fields will be returned
   * @param array|false custom list of fields
   * @throws \Exception
   */
  public function setFields($use_preview = false, $fields = false)
  {
    if (is_array($fields)) {
      $this->fields = $fields;
      return $this;
    }

    if ($use_preview) {
      $preview_array = $this->getPreviewFields($this->tb);

      if (!in_array('id', $preview_array)) {
        $preview_array = array_merge(array('id'), $preview_array);
      }

      foreach ($preview_array as $fld) {
        $col_names[$fld] = $this->cfg->get("tables.{$this->tb}.fields.$fld.label");
      }
    } else {
      $col_names = $this->cfg->get("tables.{$this->tb}.fields.*.label");
    }

    $this->fields = $col_names;
    return $this;
  }

  /**
   *
   * Returns ready-to-use string of fields for the query statement
   * @throws \Exception
   */
  private function formatFields()
  {
    if (!$this->fields) {
      throw new \Exception('Missing required parameter: fields');
    }
    $ret = [];
    foreach (array_keys($this->fields) as $f) {
      if (!preg_match('/\./', $f) && !preg_match('/count\(/', $f)) {
        $ret[] = $this->tb . '.' . $f;
      } else {
        $ret[] = $f;
      }
    }
    return implode(', ', $ret);
  }

  private function getPreviewFields(string $tb)
  {
    return $this->cfg->get("tables.{$tb}.preview");
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
