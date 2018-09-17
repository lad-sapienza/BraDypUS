<?php

require_once LIB_DIR . 'vendor/redbean/rb-sqlite.php';

/**
 * Me
 */
class Meta
{

  private static $db = PROJ_DB . 'meta.sqlite';


  /**
   * Private method that checks general settings and initializes R
   * throws Exception in case of errors
   */
  private static function check()
  {
    if (!file_exists(self::$db)){
      @touch(self::$db);
      if (!file_exists(self::$db)){
        throw new Exception('Can not create databse file ' . self::$db);
      }
    }

    if(!R::testConnection()){
      R::addDatabase( 'meta', 'sqlite:' . self::$db );
      R::selectDatabase( 'meta' );
    }
  }

  /**
   * Guesses and returns table name from SQL statement.
   * Returns false if in case of failure
   * @param  string $editQuery Query text
   * @return string            table name
   */
  private static function guessTable(string $editQuery) {
		if (preg_match("/^INSERT\s+INTO\s+`?([a-zA-Z_]+)`?/i", $editQuery, $m)) {
			$table = $m[1];
		} elseif (preg_match("/^UPDATE\s+`?([a-zA-Z_]+)`?/i", $editQuery, $m)) {
      // 2. Update query
  		$table = $m[1];
		} elseif (preg_match("/^DELETE\s+FROM\s+`?([a-zA-Z_]+)`?/i", $editQuery, $m)) {
      // 3. Delete query
			$table = $m[1];
		}

		return $table;
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
    self::check();
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

    self::check();
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
   * @param string $editQuery       Sql used for editing
   * @param array  $editQueryValues [description]
   */
  public function addVersion(int $user, string $table = null, string $editQuery = null, array $editQueryValues = [])
  {
    // Insert queries are ignored
    if (preg_match('/^INSERT INTO(.+)/', $editQuery) ) {
      return false;
    }
    self::check();

    $db = new DB();

    // preg_match("/WHERE\s+`*id`*\s*=\s*'?([0-9]){1,4}'?/", $editQuery, $matches);
    preg_match_all("/WHERE(.+)/i", $editQuery, $matches, PREG_SET_ORDER);
    $where = end($matches)[1];

    // if $table is false, try to guess
    if (!$table) {
      $table = self::guessTable($editQuery);

      if (!$table) {
        throw new \Exception("Cannot guess table from query");
      }

    }

    $rows = $db->query('SELECT * FROM ' . $table . ' WHERE ' . $where);

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
  }

  /**
   * Gets data from a table and returns json econded result to be used with datatables
   * @param  string $table table name
   * @param  array  $get   array of get data (datatables)
   * @return string        jsons encoded string with results
   */
  public function getData(string $table, array $get = [])
  {

    self::check();

    $fields = array_keys( R::inspect( $table ) );

    $q = 'SELECT * FROM ' . $table . ' WHERE';

    $v = [];
    $w = [];

    if ($get['sSearch']) {
      foreach ($fields as $f) {
        $w[] = "`$f` LIKE ?";
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
        $response['iTotalRecords'] = R::getCell( 'SELECT count(id) as tot FROM ' . $table . ' WHERE 1');
    }

    $response['iTotalDisplayRecords'] = $response['iTotalRecords'];

    if (isset($get['iSortCol_0'])) {
        $q .= ' ORDER BY ' . $fields[$get['iSortCol_0']] . ' ' . ($get['sSortDir_0']==='asc' ? 'asc' : 'desc');
    } else {
        $q .= ' ORDER BY id DESC';
    }

    if (isset($get['iDisplayStart']) && $get['iDisplayLength'] != '-1') {
        $q .= ' LIMIT ' . $get['iDisplayStart'] . ', ' . $get['iDisplayLength'];
    } else {
      $q .= ' LIMIT 0, 30 ';
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
    self::check();
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
            "aoColumns": [
                          $m_data
                      ]
    	});
    </script>
EOD;
    echo $html;
  }

}

 ?>
