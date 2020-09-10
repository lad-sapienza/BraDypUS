<?php
/**
 * Returns list of values to use as select2 ajax source
 * @uses DB
 * @uses  \SQL\QueryBuilder
 */

use \SQL\QueryBuilder;

class menuValues_ctrl extends Controller
{
  /**
   * Max number of results per page
   * @var integer
   */
  private $res_x_page = 30;

  /**
   * Wrapper function for getValues to be used with query parameters:
   *    context:  Context of usage, one of: vocabulary_set | get_values_from_tb | id_from_tb
   *    att:      Second attribute value: name of vocabulay, table name & field name (colon separated) or table name
   *    q:        Filter string to search for (LIKE operator will be used)
   *    p:        Page nuber to return
   * @return string Formatted json data (tot: totale number of results, data: array of data)
   */
  public function getValuesUrl()
  {
    $context = $this->get['context'];
    $att = $this->get['att'];
    $q = $this->get['q'];
    $p = $this->get['p'];

    $data = $this->getValues($context, $att, $q, $p);
    echo $this->returnJson($data);
  }

  /**
   * Return array of data with total number of data found and list of data
   * @param  string  $context Context of usage, one of: vocabulary_set | get_values_from_tb | id_from_tb
   * @param  string  $att     Second attribute value: name of vocabulay, table name & field name (colon separated) or table name
   * @param  string $q       Filter string to search for (LIKE operator will be used)
   * @param  string $p       Page number to return
   * @return array           Array with query results (tot: totale number of results, data: array of data)
   */
  private function getValues(string $context, string $att, string $q = null, int $p = null)
  {
    if (!$p){
      $p = 1;
    }
    $offset = $this->res_x_page * ($p - 1);



    $query = new QueryBuilder();

    $tot = new QueryBuilder();

    switch($context)
    {
      case 'vocabulary_set':
        $query->setTable($this->prefix . 'vocabularies')
          ->setField('def', 'id')
          ->setField('def', 'val')
          ->setWhere('voc', $att)
          ->setLimit($this->res_x_page, $offset)
          ->setOrder('sort');

        $tot->setTable($this->prefix . 'vocabularies')
          ->setField('count(id)', 'tot')
          ->setWhere('voc', $att)
          ->setOrder('sort');

        if ($q && !empty($q)) {
          $query->setWhere('def', "%{$q}%", 'LIKE');
          $tot->setWhere('def', "%{$q}%", 'LIKE');
        }

      break;

      case 'get_values_from_tb':
        list($tb, $fld) = \utils::csv_explode ($att, ':');
        $query->setTable($tb)
          ->setField($fld, 'id')
          ->setField($fld, 'val')
          ->setGroup($fld)
          ->setLimit($this->res_x_page, $offset)
          ->setOrder($fld);

        $tot->setTable($tb)
          ->setField('count(id)', 'tot')
          ->setOrder($fld);

          if ($q && !empty($q)) {
            $query->setWhere($fld, "%{$q}%", 'LIKE');
            $tot->setWhere($fld, "%{$q}%", 'LIKE');
          }
      break;

      case 'id_from_tb':
        $id_field = $this->cfg->get("tables.{$att}.id_field");

        $query->setTable($att)
          ->setField('id', 'id')
          ->setField($id_field, 'val')
          ->setLimit($this->res_x_page, $offset)
          ->setOrder($id_field);

        $tot->setTable($att)
          ->setField('count(id)', 'tot')
          ->setOrder($id_field);

        if ($q && !empty($q)) {
          $query->setWhere($id_field, "%{$q}%", 'LIKE');
          $tot->setWhere($id_field, "%{$q}%", 'LIKE');
        }
      break;
    }
    list($sql, $val) = $query->getSql();
    list($tot_sql, $tot_val) = $tot->getSql();

    return [
      "tot" => $this->db->query($tot_sql, $tot_val)[0]['tot'],
      "data" => $this->db->query($sql, $val),
    ];
  }

}

?>
