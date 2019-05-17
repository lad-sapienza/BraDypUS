<?php
/**
 * Returns list of values to use as select2 ajax source
 * @uses DB
 * @uses  QueryBuilder
 */

class menuValues_ctrl extends Controller
{
  /**
   * Max number of results per page
   * @var integer
   */
  private static $res_x_page = 30;

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

    $data = self::getValues($context, $att, $q, $p);
    $this->json($data);
  }

  /**
   * Return array of data with total number of data found and list of data
   * @param  string  $context Context of usage, one of: vocabulary_set | get_values_from_tb | id_from_tb
   * @param  string  $att     Second attribute value: name of vocabulay, table name & field name (colon separated) or table name
   * @param  string $q       Filter string to search for (LIKE operator will be used)
   * @param  string $p       Page nuber to return
   * @return array           Array with query results (tot: totale number of results, data: array of data)
   */
  public static function getValues($context, $att, $q = false, $p = 1)
  {
    $offset = self::$res_x_page * ($p - 1);


    $query = new QueryBuilder();

    $tot = new QueryBuilder();

    switch($context)
    {
      case 'vocabulary_set':
        $query->table(PREFIX . 'vocabularies')
          ->fields('def as id')
          ->fields('def as val')
          ->where('voc', $att)
          ->limit(self::$res_x_page, $offset)
          ->order('sort');

        $tot->table(PREFIX . 'vocabularies')
          ->fields('count(id) as tot')
          ->where('voc', $att)
          ->order('sort');

        if ($q && !empty($q))
        {
          $query->where('def', "%{$q}%", 'LIKE');
          $tot->where('def', "%{$q}%", 'LIKE');
        }


      break;

      case 'get_values_from_tb':
        list($tb, $fld) = utils::csv_explode ($att, ':');
        $query->table($tb)
          ->fields($fld . ' as id')
          ->fields($fld . ' as val')
          ->group($fld)
          ->limit(self::$res_x_page, $offset)
          ->order($fld);

        $tot->table($tb)
          ->fields('count(id) as tot')
          ->order($fld);

          if ($q && !empty($q))
          {
            $query->where($fld, "%{$q}%", 'LIKE');
            $tot->where($fld, "%{$q}%", 'LIKE');
          }
      break;

      case 'id_from_tb':
        $id_field = cfg::tbEl($att, 'id_field');

        $query->table($att)
          ->fields('id  as id')
          ->fields($id_field . ' as val')
          ->limit(self::$res_x_page, $offset)
          ->order($id_field);

        $tot->table($att)
          ->fields('count(id) as tot')
          ->order($id_field);

        if ($q && !empty($q))
        {
          $query->where($id_field, "%{$q}%", 'LIKE');
          $tot->where($id_field, "%{$q}%", 'LIKE');
        }
      break;
    }

    list($sql, $val) = $query->getSql();
    list($tot_sql, $tot_val) = $tot->getSql();

    $db = new DB();

    return [
      "tot" => $db->query($tot_sql, $tot_val)[0]['tot'],
      "data" => $db->query($sql, $val),
    ];
  }

}

?>
