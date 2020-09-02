<?php
/**
 *
 * Use this class to do a backup of your database
 * @author Raul Souza Silva (raul.3k@gmail.com)
 * @category Database
 * @copyright No one. You can copy, edit, do anything you want. If you change anything to better, please let me know.
 *
 */

class BackupMySQL
{
	protected $filename, $db, $sql;


	public function __construct(\DB\DB\DBInterface $db, $filename)
	{
		ini_set('memory_limit','1560M');

		$this->filename = $filename;
		$this->db = $db;
	}

	/**
	 * exports wholle database (PREFIX)
	 */
	public function exportAll()
	{
		$this->sql = '';
		$tables = $this->db->query("SHOW TABLES", null, 'read');

		$tables = array_filter(array_map(function ($r){
			$el = array_values($r)[0];
			if (strpos($el, PREFIX) !== false){
				return $el;
			}
			return null;
		}, $tables));

		foreach($tables as $tb) {
			$this->export($tb);
		}
		$this->sql2gzip($this->sql);
	}

	/**
	 * Exports single tb
	 * @param string $tb table to export
	 */
	public function exportTb($tb)
	{
		$this->sql = '';
		$this->export($tb);
		$this->sql2gzip($this->sql);
	}



	/**
	 *
	 * Saves data to file
	 * @param $tb	table name
	 */
	private function export($tb)
	{
		// get table structure
		$sql = $this->getColumns($tb);

		$q = $this->db->query('SELECT * FROM ' . $tb . ' WHERE 1=1');

		foreach($q as $row){
			foreach ($row as $k=>&$f) {
				$f = "'" . htmlentities(addslashes($f)) . "'";
			}

			$sql .= "INSERT INTO {$tb} "
				. '(' . implode(', ', array_keys($row)). ')'
				. " VALUES "
				. "(" . implode(", ", $row). "); \n";
		}
		$this->sql .= $this->formatSQLstring($sql, $tb);
	}

	/**
	 *
	 * Saves SQL test to a gziped file
	 * @param string $sql	sql text
	 * @throws Exception
	 */
	private function sql2gzip($sql)
	{
		$this->filename .= '.gz';

		$gzh = @gzopen($this->filename,'w9');

		if ( !gzh ) {
			throw new \Exception('Can not open file ' . $this->filename);
		}

		if ( !gzwrite($gzh, $sql) ) {
			throw new \Exception('Can not write in file ' . $this->filename);
		}

		gzclose($gzh);
	}

	/**
	 *
	 * Get the list of Columns
	 * @param $tb	table name
	 */
	private function getColumns($tb)
	{
		$q = $this->db->query('SHOW CREATE TABLE ' . $tb, null, 'read');
		$q = array_map(function($el){
			return  preg_replace("/AUTO_INCREMENT=[\w]*./", '', $el['Create Table']);
		}, $q);

		return "\n\n" . $q[0] . "; \n\n";
	}


	/**
	 *
	 * Formats $table_data array in a SQL string
	 * @param array $table_data array with data
	 * @param string $table table name
	 */
	private function formatSQLstring( $sql, $tb )
	{
		$f_sql = 'SET FOREIGN_KEY_CHECKS = 0;' . "\n\n"
			. 'DROP TABLE IF EXISTS '. $tb .';'."\n"
			. $sql . "\n\n"
			. '-- THE END'."\n"
		. 'SET FOREIGN_KEY_CHECKS = 1;' . "\n\n";

		return $f_sql;
	}
}
?>
