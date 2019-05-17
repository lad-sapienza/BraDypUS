<?php
/**
 * Different types of integrity check between Database structure and configuration files
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				Jan 3, 2015
 */

class StructureCheck
{
  /**
   * Checks differences in field names between Database and Configuration files
   * Returns an array of data, where foreach table are listed the fields that are
   * present in cfg files and missing in the database (missing_in_db) and files that are
   * presend in db but missing in cfg (missing_in_cfg)
   * @return array
   */
  public static function fieldsDbCfg()
  {
    $all_tables = cfg::tbEl('all', 'name');
    $db = new DB();

    $result = [];

    foreach ($all_tables as $tb)
    {
      $dbFullFlds = $db->query("PRAGMA TABLE_INFO('" . $tb . "')");

      $dbFlds = [];

      foreach ($dbFullFlds as $f)
      {
        array_push($dbFlds, $f['name']);
      }

      $cfgFlds = cfg::fldEl($tb, 'all', 'name');

      $result[$tb]['missing_in_cfg'] = array_diff($dbFlds, $cfgFlds);
      $result[$tb]['missing_in_db'] = array_diff($cfgFlds, $dbFlds);
    }

    return $result;
  }

  /**
   * Returns list (array) of vocabularies:
   * a) present in the database but never used in cfg files (missing) and
   * b) prsent in db and in cfg files (present)
   * @return array
   */
  public static function vocabularies()
  {
    $res = ['missing' => [], 'present' =>[]];
    $voc = new Vocabulary(new DB());
    $vocs = $voc->getAllVoc();

    $flag = false;

    foreach ($vocs as $v)
    {
      foreach($_SESSION['cfg_data']['tables'] as $tbdata)
      {
        foreach($tbdata as $f)
        {
          if($f['vocabulary_set'] == $v)
          {
            $flag = true;
            array_push($res['present'], $v);
          }
        }
      }

      if ($flag === false)
      {
        array_push($res['missing'], $v);
      }
      $flag = false;
    }

    return $res;
  }

  /**
   * Returns multi dimensional array with list of tables:
   * a) present in db but missing in cfg (missing_in_cfg) and tables
   * b) presend in cfg but missing in db (missing_in_db)
   * System tables will be ignored
   * @return array
   */
  public static function tableList()
  {
    $db = new DB();
    $dbList = [];

    $sys_tables = [
      PREFIX . 'charts',
      PREFIX . 'geodata',
      PREFIX . 'vocabularies',
      PREFIX . 'users',
      PREFIX . 'userlinks',
      PREFIX . 'rs',
      PREFIX . 'queries',
      PREFIX . 'm_attachlinks',
      'sqlite_sequence'
    ];

    foreach($db->query("SELECT name FROM sqlite_master WHERE type='table'") as $a)
    {
      array_push($dbList, $a['name']);
    }

    $cfgList = array_merge(array_values(cfg::tbEl('all', 'name')), $sys_tables);

    return array(
      'missing_in_cfg' => array_diff($cfgList, $dbList),
      'missing_in_db' => array_diff($dbList, $cfgList)
    );
  }
}

?>
