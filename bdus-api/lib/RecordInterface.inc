<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Apr 24, 2012
 */
use DB\DBInterface;

interface RecordInterface
{
	/**
	 *
	 * Returns reference table
	 */
	public function getTb() : string;

	/**
	 *
	 * Returns DB object
	 */
	public function getDB() : DBInterface;

	/**
	 *
	 * Writes core data to Record
	 * @param array $core_data array of core data
	 */
	public function setCore( array $core_data) : void;

	/**
	 *
	 * Returns core data
	 * @param string $fieldname fieldname
	 */
	public function getCore(string $fieldname = null);

	/**
	 *
	 * Writes $plugin_data to Record object
	 * @param string $plugin	plugin name
	 * @param array $plugin_data	plugin's array data
	 */
	public function setPlugin( string $plugin, array $plugin_data) : void;

	/**
	 *
	 * Returns specifica plugin data
	 * @param string $plugin plugin name
	 * @param int $index	row index
	 * @param string $fieldname fieldname
	 */
	public function getPlugin(string $plugin, int $index = null, string $fieldname = null);

	/**
	 *
	 * Returns all plugins data
	 * @param boolean $load if true data will be loaded from DB
	 */
	public function getAllPlugins( bool $load = false) : array;

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
	public function getCoreLinks( bool $details = false) : array;

	/**
	 * Returns array with back-links data.
	 * Back-links are readonly references to records connected to the current one
	 * via a 1-n (m_) plugin.
	 * The connection map is controlled via a table configuration setting (backlink)
	 * where destination table name, plugin table name and plugin field name are separated by colons (:)
	 * @return (array) indexed array with referenced table name used as key and array of data (id & label) for each found record
	 */
	public function getBackLinks();

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
