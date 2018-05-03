<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			May 2, 2018
 */

interface iPostProcess
{
	/**
	 * Runs middlevare
	 * @param  array $data array of input data
	 * @param  array $get  array of GET data
	 * @param  array $post  array of POST data
	 * @return array        data, mime
	 */
	public function run($data, $get = false, $post = false);


}
