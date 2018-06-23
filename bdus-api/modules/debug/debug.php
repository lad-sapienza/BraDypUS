<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 */

class debug extends Controller
{

	public function sql2json()
	{
		return Meta::getData($this->get['tb'], $this->post);
	}

	public function read()
	{
		echo Meta::tableTop('errorlog', "./controller.php?&obj=debug&method=sql2json&tb=errorlog");
	}
}
