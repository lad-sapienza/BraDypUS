<?php
/**
 * Manages sharing/unsharing/insertion and deletion to queries table
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 9, 2012
 * @uses			tr
 */

class SavedQueries
{

	private $db;
  private $msg;

	/**
	 * Stars class and sets db object
	 * @param DB $db
	 */
	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	public function getById($id)
	{
		return $this->db->query('SELECT * FROM ' . PREFIX . 'queries WHERE id = ?' , [ $id ], 'read');
	}

	public function getAll()
	{
		return $this->db->query(
			'SELECT * FROM ' . PREFIX . 'queries WHERE user_id = ? OR is_global = ?', 
			[ $_SESSION['user']['id'], 1 ], 
			'read'
		);
	}

	/**
	 * Erases record from queries table. Sets $this->message
	 * @param int $id	record id to erase
	 * @throws myException
	 */
	public function erase($id)
	{
		return $this->db->query(
			'DELETE FROM ' . PREFIX . 'queries  WHERE id = ?', 
			[ $id ], 
			'boolean'
		);
	}

	/**
	 * Makes record shared. Sets $this->message
	 * @param int $id	record id to share
	 * @throws myException
	 */
	public function share($id)
	{
		return $this->db->query(
			'UPDATE ' . PREFIX . 'queries SET is_global = ?  WHERE id = ?',
			['1', $id ], 
			'boolean'
		);

	}

	/**
	 * Makes record private. Sets $this->message
	 * @param int $id record id to unshare
	 * @throws myException
	 */
	public function unshare($id)
	{
		return $this->db->query(
			'UPDATE ' . PREFIX . 'queries SET is_global = ? WHERE id = ?',
			['0', $id ], 
			'boolean'
		);
	}

	/**
	 * Saves new query. Sets $this->message
	 * @param string $name	query name
	 * @param string $text	query base64 encoded text
	 * @param string $tb	reference tb
	 * @throws myException
	 */
	public function save($name, $text, $tb)
	{
		$query = 'INSERT INTO ' . PREFIX . 'queries'
		. '(user_id, date, name, text, "table", is_global)'
		. ' VALUES '
		. '(:user_id, :date, :name, :text, :table, 0)';

		$values = array(
				':user_id'=> $_SESSION['user']['id'],
				':date'=> date('Y-m-d G:i:s'),
				':name'=> $name,
				':text'=> base64_decode($text),
				':table'=> $tb
		);

		return $this->db->query($query, $values, 'boolean');

	}
}
