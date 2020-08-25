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
		return $this->db->query(
			'UPDATE ' . PREFIX . 'vocabularies SET def = ? WHERE id = ?', 
			[ $def, $id ], 
			'boolean'
		);
	}

	/**
	 * Deletes definition
	 * @param int $id	record id to delete
	 */
	public function erase($id)
	{
		return $this->db->query(
			'DELETE FROM ' . PREFIX . 'vocabularies WHERE id = ?',
			[ $id ], 
			'boolean'
		);
	}

	/**
	 * Adds new definition
	 * @param string $voc	vocabulary set
	 * @param string $def	definition
	 */
	public function add($voc, $def)
	{
		return $this->db->query(
			'INSERT INTO ' . PREFIX . 'vocabularies (voc, def) VALUES (?, ?)', 
			[ $voc, $def ], 
			'boolean'
		);
	}

	/**
	 * Changes definition sort
	 * @param array $data array with keys containing the sort order and values the record ids
	 */
	public function sort(array $data): bool
	{
		$error = [];
		foreach($data as $sort => $id) {

			try {
				$res = $this->db->query(
					'UPDATE ' . PREFIX . 'vocabularies SET sort = ? WHERE id = ?', 
					[ $sort, $id], 
					'boolean'
				);
				if (!$res) {
					$flag_error = true;
				}
			} catch(myException $e) {
				array_push($error, $e);
			}
		}
		return count($error) === 0;
	}

	/**
	 * Returns structured array with all data, organised by voc
	 * voc1 = array (def.id, def.text)
	 */
	public function getAll()
	{
		$res = $this->db->query(
			'SELECT * FROM ' . PREFIX . 'vocabularies WHERE 1=1 ORDER BY voc, sort', 
			false, 
			'read'
		);

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
		$res = $this->db->query(
			'SELECT voc FROM ' . PREFIX . 'vocabularies WHERE 1=1 GROUP BY voc', 
			false, 
			'read'
		);

        if (!is_array($res)) {
            return false;
        }

		$res2 = [];
		foreach($res as $arr) {
			array_push($res2, $arr['voc']);
		}
		return $res2;

	}

	/**
	 * Return array of definitions for vocabulary value
	 *
	 * @param [type] $voc
	 * @param boolean $where
	 * @param array $values
	 * @return void
	 */
	public function getValues ($voc)
	{
		$res = $this->db->query(
			'SELECT def FROM ' . PREFIX . 'vocabularies WHERE  voc = ?  ORDER BY sort ASC LIMIT 500 OFFSET 0',
			[ $voc ]
		);
        $resp = [];
        if (is_array($res)) {
            foreach ($res as $r) {
                array_push($resp, $r['def']);
            }
        }
        return $resp;
	}
	
}
