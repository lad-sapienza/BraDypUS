<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * 
 * Returns list of values to use as select2 ajax source
 * @uses DB
 * @uses  \SQL\QueryObject
 */

use \SQL\QueryObject;

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



    $query = new QueryObject($this->cfg);

    $tot = new QueryObject($this->cfg);

    switch($context)
    {
      case 'vocabulary_set':
        $query->setTb($this->prefix . 'vocabularies')
          ->setField('def', 'id')
          ->setField('def', 'val')
          ->setWherePart(null, null, 'voc', '=', '?', null)
          ->setWhereValues([$att])
          ->setLimit($this->res_x_page, $offset)
          ->setOrderFld('sort', 'ASC');

        $tot->setTb($this->prefix . 'vocabularies')
          ->setField('id', 'tot', $this->prefix . 'vocabularies', 'count')
          ->setWherePart(null, null, 'voc', '=', '?', null)
          ->setWhereValues([$att])
          ->setGroupFld('voc')
          ->setGroupFld('sort')
          ->setOrderFld('sort', 'ASC');

        if ($q && !empty($q)) {
          $query->setWherePart('and', null, 'def', 'LIKE', "?", null);
          $query->setWhereValues(["%{$q}%"]);
          $tot->setWherePart('and', null, 'def', 'LIKE', "?", null);
          $tot->setWhereValues(["%{$q}%"]);
        }

      break;

      case 'get_values_from_tb':
        list($tb, $fld) = \utils::csv_explode ($att, ':');
        $query->setTb($tb)
          ->setField($fld, 'id')
          ->setField($fld, 'val')
          ->setGroupFld($fld)
          ->setLimit($this->res_x_page, $offset)
          ->setOrderFld($fld, 'asc');

        $tot->setTb($tb)
          ->setField('id', 'tot', $tb, 'count')
          ->setGroupFld('id')
          ->setGroupFld($fld)
          ->setOrderFld($fld, 'ASC');

          if ($q && !empty($q)) {
            $query->setWherePart(null, null, $fld, 'LIKE', "?", null);
            $query->setWhereValues(["%{$q}%"]);
            $tot->setWherePart (null, null, $fld, 'LIKE', "?", null);
            $tot->setWhereValues (["%{$q}%"]);
          }
      break;

      case 'id_from_tb':
        $id_field = $this->cfg->get("tables.{$att}.id_field");


        $query->setTb($att)
          ->setField('id', 'id')
          ->setField($id_field, 'val')
          ->setLimit($this->res_x_page, $offset)
          ->setOrderFld($id_field, 'asc');

        $tot->setTb($att)
          ->setField('id', 'tot', $att, 'count')
          ->setGroupFld('id')
          ->setGroupFld($id_field)
          ->setOrderFld($id_field, 'asc');

        if ($q && !empty($q)) {
          $query->setWherePart(null, null, $id_field, 'LIKE', "?", null);
          $query->setWhereValues(["%{$q}%"]);
          $tot->setWherePart(null, null, $id_field, 'LIKE', "?", null);
          $tot->setWhereValues(["%{$q}%"]);
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
