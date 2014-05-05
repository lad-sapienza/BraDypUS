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
		return $this->db->query('SELECT * FROM `' . PREFIX . '__queries` WHERE `id` = ' . $id, false, 'read');
	}
	
	public function getAll()
	{
		$query = 'SELECT * FROM `' . PREFIX . '__queries` WHERE `user_id` = ' . $_SESSION['user']['id'] . ' OR `is_global` = 1';
		
		return $this->db->query($query, false, 'read');
	}
	
	/**
	 * Erases record from queries table. Sets $this->message
	 * @param int $id	record id to erase
	 * @throws myException
	 */
	public function erase($id)
	{
		return $this->db->query('DELETE FROM `' . PREFIX . '__queries`  WHERE `id`=' . $id, false, 'boolean');
	}
	
	/**
	 * Makes record shared. Sets $this->message
	 * @param int $id	record id to share
	 * @throws myException
	 */
	public function share($id)
	{
		return $this->db->query('UPDATE `' . PREFIX . '__queries` SET `is_global` = 1  WHERE `id`=' . $id, false, 'boolean');
		
	}
	
	/**
	 * Makes record private. Sets $this->message
	 * @param int $id record id to unshare
	 * @throws myException
	 */
	public function unshare($id)
	{
		return $this->db->query('UPDATE `' . PREFIX . '__queries` SET `is_global` = 0 WHERE `id`=' . $id, false, 'boolean');
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
		$query = 'INSERT INTO `' . PREFIX . '__queries`'
		. '(`user_id`, `date`, `name`, `text`, `table`, `is_global`)'
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