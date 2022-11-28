<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			May 2, 2018
 */

interface iPostProcess
{
	/**
	 * Runs middlevare
	 * @param  array $data array of input data
	 * @param  array $get  array of GET data
	 * @param  array $post  array of POST data
	 * @return array        [data: array, mime: string]
	 */
	public function run($data, $get = false, $post = false);


}
