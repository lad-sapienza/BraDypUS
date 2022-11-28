<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Template;


interface TemplateInterface
{
	/**
	 * Returns "col-sm-$nr" for use in templates. Also used by HtmlStartDiv to replace span-* syntax
	 * @param int $nr
	 */
	public function cell ( string $nr) : string;

	/**
	 * Shows record permalink
	 */
	public function permalink() : ?string;

	/**
	 * Shows fieldset with links to record, both system and user
	 */
	public function links() : ?string;

	/**
	 * Shows Stratigraphic relations module
	 */
	public function rs() : ?string;

	/**
	 *
	 * Writes out single field
	 * @param string $fieldname		field name to show
	 * @param string $formatting	formatting information
	 * @example template {{ print.fld( 'id_sito', 'width:200') }}
	 * @example template {{ print.fld( 'id_sito') }}
	 */

	public function fld(string $fieldname, string $formatting = null) : string;

	/**
	 *
	 * Writes out single field
	 * @param string $fieldname		field name to show
	 * @param string $formatting	formatting information
	 * @example template {{ print.fld( 'id_sito', 'width:200') }}
	 * @example template {{ print.fld( 'id_sito') }}
	 */
	public function plg_fld(string $fieldname, string $formatting = null) : string;

	/**
	 *
	 * Returns nude value of a field
	 * @param string $fieldname		field name to show
	 * @param boolean $plg	true if field is from a plugin
	 */

	public function value(string $fieldname, bool $plg = false) : string;

	/**
	 *
	 * Writes out all rows and all fields of $plg plugin
	 * @param string $plg plugin name
	 */

	public function plg($plg) : ?string;

	/**
	 *
	 * Shows all fields of a given table
	 */
	public function showall() : string;

	/**
	 *
	 * Returns html containing linked files info
	 * @param mixed $max maximum no of files to show, default: 2. Any number can be used. 'all' will show up all available images.
	 */
	public function image_thumbs(int $max = 2): ?string;

}
