<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 9, 2012
 */

class Vocabulary
{
	private $db;

	/**
	 * Starts object and sets $db
	 * @param DB $db
	 */
	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	/**
	 * Updates definition
	 * @param int $id		record id to update
	 * @param string $def	new definition
	 */
	public function update($id, $def)
	{
		return $this->db->query('UPDATE `' . PREFIX . '__vocabularies` SET `def` = :def WHERE `id` = :id', array(':def'=>$def, ':id'=>$id), 'boolean');
	}

	/**
	 * Deletes definition
	 * @param int $id	record id to delete
	 */
	public function erase($id)
	{
		return $this->db->query('DELETE FROM `' . PREFIX . '__vocabularies` WHERE `id` = ' . $id, false, 'boolean');
	}

	/**
	 * Adds new definition
	 * @param string $voc	vocabulary set
	 * @param string $def	definition
	 */
	public function add($voc, $def)
	{
		return $this->db->query('INSERT INTO `' . PREFIX . '__vocabularies` (voc, def) VALUES (:voc, :def)', array(':voc'=>$voc, ':def'=>$def), 'boolean');
	}

	/**
	 * Changes definition sort
	 * @param array $data array with keys containing the sort order and values the record ids
	 */
	public function sort($data)
	{
		foreach($data as $sort=>$id) {

			try {
				$res = $this->db->query('UPDATE `' . PREFIX . '__vocabularies` SET `sort` = ' . $sort . ' WHERE `id` = ' . $id, false, 'boolean');
				if (!$res) {
					$flag_error = true;
				}

			} catch(myException $e) {
				$e->log();
				$flag_error = true;
			}

		}

		return ($flag_error ? false : true);
	}

	/**
	 * Returns structured array with all data, organised by voc
	 * voc1 = array (def.id, def.text)
	 */
	public function getAll()
	{
		$res = $this->db->query('SELECT * FROM `' . PREFIX . '__vocabularies` WHERE 1 ORDER BY voc, sort', false, 'read');

		if(!is_array($res) OR empty($res[0])) {
			return false;
		}

		$ret = array();

		foreach($res as $arr) {
			$ret[$arr['voc']][$arr['id']] = $arr['def'];
		}
		return $ret;
	}

	/**
	 * Returns array of available vocabularies or false if no vocabulary is available
	 */
	public function getAllVoc()
	{
		$query = 'SELECT voc FROM `' . PREFIX . '__vocabularies` WHERE 1 GROUP BY voc';

		$res = $this->db->query($query, false, 'read');

		if (is_array($res)) {

			foreach($res as $arr) {
				$res2[] = $arr['voc'];
			}
			return $res2;
		} else  {
			return false;
		}

	}
}
