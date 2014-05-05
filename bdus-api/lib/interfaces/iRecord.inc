<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Apr 24, 2012
 */

interface iRecord
{
	/**
	 * 
	 * Returns reference table
	 */
	public function getTb();
	
	/**
	 * 
	 * Returns DB object
	 */
	public function getDB();
	
	/**
	 * 
	 * Writes core data to Record
	 * @param array $core_data array of core data
	 */
	public function setCore($core_data);
	
	/**
	 * 
	 * Returns core data
	 * @param string $fieldname fieldname
	 */
	public function getCore($fieldname = false);
	
	/**
	 * 
	 * Writes $plugin_data to Record object
	 * @param string $plugin	plugin name
	 * @param array $plugin_data	plugin's array data
	 */
	public function setPlugin($plugin, $plugin_data);
	
	/**
	 * 
	 * Returns specifica plugin data
	 * @param string $plugin plugin name
	 * @param int $index	row index
	 * @param string $fieldname fieldname
	 */
	public function getPlugin($plugin, $index = false, $fieldname = false);
	
	/**
	 * 
	 * Returns all plugins data
	 * @param boolean $load if true data will be loaded from DB
	 */
	public function getAllPlugins($load = false);
	
	/**
	 * 
	 * if !$details : Returns link sum data
	 * array(
	 * 	{ref_table} => array (tot: int, query: string, values:array)
	 * )
	 * else
	 * returns array with CoreLinks data
	 * array(
	 * 	{ref_table} => array(
	 * 			array(	id=>value,		{id_fld}=>value	),
	 * 			...
	 * 			)
	 * 		)
	 * 	...
	 * ) 
	 */
	public function getCoreLinks($details = false);
	
	/**
	 * 
	 * Returns (linked) files full data
	 */
	public function getFullFiles();
	
	/**
	 * 
	 * Persists data in DB!
	 */
	public function persist();
	
	/**
	 * 
	 * Deletes record core & plugin & file linking data
	 */
	public function delete();
	
	/**
	 * Returns array of RS data
	 */
	public function getRS();
	
	/**
	 * Writes new relation to obj relation list, checking if relation exists. Returns true if relation does not exists, or false if already exists
	 * @param string $first
	 * @param int $relation
	 * @param string $second
	 */
	public function addRS($first, $relation, $second);
	
}