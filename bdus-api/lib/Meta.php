<?php

require_once LIB_DIR . 'vendor/redbean/rb-sqlite.php';

/**
 * Me
 */
class Meta
{

  /**
   * Private method that checks general settings and initializes R
   * throws Exception in case of errors
   */
  private static function check()
  {
    if (!defined('PROJ_DIR')){
      return false;
    }
    
    $metaDbPath = PROJ_DIR . 'db/meta.sqlite';

    if (!file_exists($metaDbPath)){
      @touch($metaDbPath);
      if (!file_exists($metaDbPath)){
        throw new Exception('Can not create databse file ' . $metaDbPath);
      }
    }

    if(!R::testConnection()){
      R::addDatabase( 'meta', 'sqlite:' . $metaDbPath );
      R::selectDatabase( 'meta' );
    }
    return true;
  }


  /**
   * Writes user log
   * @param int  $user User id
   * @param boolean $out  if true a logout is performed, else e login
   */
  public function addUserLog(int $user, bool $out = false)
  {
    self::check();
    $dt = new DateTime();
    $userlog = R::dispense( 'userlog' );
    $userlog->user = $user;
    $userlog->unixtime = $dt->format('U');
    $userlog->datetime = $dt->format('Y-m-d H:i:s');
    $userlog->in = !$out;
    $userlog->out = $out;
    $id = R::store( $userlog );
  }

  /**
   * Writes in database Exception
   * @param Exception|Error $e    Exception object
   * @param int    $user User id who triggered the Exception
   */
  public function logException($e, int $user = null)
  {
    if (!$user) {
      $user = $_SESSION['user']['id'];
    }
    if (!self::check()){
      return false;
    }
    $dt = new DateTime();
    $errorlog = R::dispense( 'errorlog' );
    $errorlog->user = $user;
    $errorlog->unixtime = $dt->format('U');
    $errorlog->datetime = $dt->format('Y-m-d H:i:s');
    $errorlog->message = $e->getMessage();
    $errorlog->trace = json_encode($e->getTrace(), JSON_PRETTY_PRINT);
    $id = R::store( $errorlog );
  }

  /**
   * Writes in database error log
   * @param  int    $errno   Error number
   * @param  string $errstr  Error text
   * @param  string $errfile Error file
   * @param  string $errline Error line
   */
  public function logError(int $errno, string $errstr, string $errfile, string $errline)
  {
    $user = $_SESSION['user']['id'];

    if (!self::check()){
      return false;
    }
    $dt = new DateTime();
    $errorlog = R::dispense( 'errorlog' );
    $errorlog->user = $user;
    $errorlog->unixtime = $dt->format('U');
    $errorlog->datetime = $dt->format('Y-m-d H:i:s');
    $errorlog->message = $errstr;
    $errorlog->trace = '#' . $errno . '. ' . $errstr . '. In ' . $errfile . ', ' . $errline;
    $id = R::store( $errorlog );
  }

  /**
   * Support for versioning.
   * Adds a line to the version table
   * @param int $user            User if who triggered the edit action
   * @param string $table           Edited table
   * @param string $id              Edited id
   * @param string $editQuery       Sql used for editing
   * @param array  $editQueryValues [description]
   */
  public function addVersion(int $user, string $table, int $id, string $editQuery, array $editQueryValues = [])
  {
    if (!self::check()){
      return false;
    }

    $db = new DB();

    try {
      $rows = $db->query( 'SELECT * FROM ' . $table . ' WHERE id = ?', [ $id ] );

      if(!is_array($rows)) {
        $rows = [];
      }

      foreach ($rows as $r) {
        $dt = new DateTime();
        $version = R::dispense( 'version' );
        $version->user = $user;
        $version->unixtime = $dt->format('U');
        $version->datetime = $dt->format('Y-m-d H:i:s');
        $version->table = $table;
        $version->rowid = $r['id'] ?: '';
        $version->content = json_encode($r);
        $version->editsql = $editQuery;
        $version->editvalues = json_encode($editQueryValues);
        $id = R::store( $version );
      }
    } catch (\Throwable $th) {
      // almost silently dies....
      error_log(json_encode($th, JSON_PRETTY_PRINT));
    }

    
  }

  /**
   * Gets data from a table and returns json econded result to be used with datatables
   * @param  string $table table name
   * @param  array  $get   array of get data (datatables)
   * @return string        jsons encoded string with results
   */
  public function getData(string $table, array $get = [])
  {

    if (!self::check()){
      return false;
    }

    $fields = array_keys( R::inspect( $table ) );

    $q = 'SELECT * FROM ' . $table . ' WHERE';

    $v = [];
    $w = [];

    if ($get['sSearch']) {
      foreach ($fields as $f) {
        $w[] = "$f LIKE ?";
        $v[] = "%{$get['sSearch']}%";
      }
      $q .= implode(' OR ', $w);
    } else {
      $q .= ' 1';
    }

    $response['sEcho'] = intval($get['sEcho']);
    $response['query_arrived'] = $q;

    if ( isset($get['iTotalRecords']) ) {
        $response['iTotalRecords'] = $get['iTotalRecords'];
    } else {
        $response['iTotalRecords'] = R::getCell( 'SELECT count(id) as tot FROM ' . $table . ' WHERE 1=1');
    }

    $response['iTotalDisplayRecords'] = $response['iTotalRecords'];

    if (isset($get['iSortCol_0'])) {
        $q .= ' ORDER BY ' . $fields[$get['iSortCol_0']] . ' ' . ($get['sSortDir_0']==='asc' ? 'asc' : 'desc');
    } else {
        $q .= ' ORDER BY id DESC';
    }

    if (isset($get['iDisplayStart']) && $get['iDisplayLength'] != '-1') {
        $q .= ' LIMIT ' . $get['iDisplayLength'] . ' OFFSET ' . $get['iDisplayStart'];
    } else {
      $q .= ' LIMIT 30 OFFSET 0 ';
    }

    $response['query_executed'] = $q;

    $response['aaData'] = R::getAll($q, $v);

    foreach ($response['aaData'] as $id => &$row) {
        $response['aaData'][$id]['DT_RowId'] = $row['id'];
    }

    echo json_encode($response);
  }

  /**
   * Returns well formatted HTML to display results using TabelTop
   * @param  string $table Table name
   * @param  string $ajaxSource Ajax Source where to get data
   * @return string        Well formatted HTML
   */
  public function tableTop(string $table, string $ajaxSource)
  {
    if (!self::check()){
      return false;
    }
    $uid = uniqid();
    $fields = array_keys( R::inspect( $table ) );
    $th_fields = '<th>' . implode('</th><th>', $fields) . '</th>';
    $m_data = '{"mData":"' . implode('"},{"mData":"', $fields) . '"}';

    $html = <<<EOD
    <table id="list_$uid" class="results  table table-striped table-bordered table-condensed" style="width:100%">
    	<thead>
    		<tr>
    		$th_fields
    		</tr>
    	</thead>
    	<tbody></tbody>
    </table>
    <script>

    	var dt$uid = $('#list_$uid').dataTable({
    		"aaSorting": [],
    		"iDisplayLength": 30,
    		"bProcessing": true,
        "bServerSide": true,
        "sAjaxSource": "$ajaxSource",
        "sServerMethod": "POST",
        "aoColumns": [ $m_data ]
    	});
    </script>
EOD;
    echo $html;
  }

}

 ?>
