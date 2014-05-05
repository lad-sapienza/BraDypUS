<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 21, 2012
 */

interface iTemplate
{
	/**
	 * Returns "col-sm-$nr" for use in templates. Also used by HtmlStartDiv to replace span-* syntax
	 * @param unknown $nr
	 */
	public function cell($nr);
	
	/**
	 * Retuns sums of fields from other tables using filter
	 * @param string $tb	other table name, without prefix
	 * @param string $fld	other table field whose values will be summed
	 * @param string $where filtering options following this pattern: {name of other table's field to search in}={name of this table's field to get search value}; etc
	 * @example <sqlSum table="test"  field="fldTest" where="campoAltro1=thisTableField1; campoAltro2=thisTableField2" />
	 */
	public function sqlSum($tb, $fld, $filter = false);
	
	/**
	 * Returns sum of values of the given fields for the current record in the current table
	 * @param string $fields comma separated list of fields to sum up.
	 * @example <simpleSum fields="field1, field2, etc" />
	 */
	public function simpleSum($fields);
	
	/**
	 * Shows record permalink
	 */
	public function permalink();
	
	/**
	 * Shows fieldset with links to record, both system and user
	 */
	public function links();
	
	/**
	 * Shows list of available geodata + form to enter some more (WKT)
	 */
	public function geodata();
	
	/**
	 * Shows Stratigraphic relations module
	 */
	public function rs();
	
	/**
	 *
	 * Writes out single field
	 * @param string $fieldname		field name to show
	 * @param string $formatting	formatting information
	 * @param string $plg_name		plugin name, if false I'dealing with core!
	 * @param string $plg_index		plugin index
	 * @example template <fld name="id_sito" style="width:200" />
	 * @example template <fld name="id_sito" />
	 */
	
	public function fld($fieldname, $formatting = false);
	
	/**
	 * 
	 * Writes out single field
	 * @param string $fieldname		field name to show
	 * @param string $formatting	formatting information
	 * @example template {{ print.plg_fld('abbreviazione', 'width:200px') }}
	 * @example template {{ print.plg_fld('abbreviazione') }}
	 */
	public function plg_fld($fieldname, $formatting = false);
	
	/**
	 *
	 * Writes out all rows and all fields of $plg plugin
	 * @param string $plg plugin name
	 */
	
	public function plg($plg);
		
	/**
	 * 
	 * Shows all fields of a given table
	 */
	public function showall();
	
	/**
	 * 
	 * Returns html containing linked files info
	 * @param mixed $max maximum no of files to show, default: 2. Any number can be used. 'all' will show up all available images. 
	 */
	public function image_thumbs($max = false);
	
}