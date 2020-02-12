<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			May 2, 2018
 */

interface iPreProcess
{
	/**
	 * Runs middlevare
	 * @param  array $get  array of GET data
	 * @param  array $post  array of POST data
	 * @return array 		[halt: boolean, echo: string, headers: array]
	 */
	public function run($get = false, $post = false);


}
