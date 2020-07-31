<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Sep 10, 2012
 */


class myHistory_ctrl extends Controller
{

	public function sql2json() {
		return Meta::getData($this->get['tb'], $this->post);
	}

	public static function show_all()
	{
		echo Meta::tableTop('version', "./?&obj=myHistory_ctrl&method=sql2json&tb=version");
	}

}
