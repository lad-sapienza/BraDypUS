<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 15, 2012
 */

class test_ctrl extends Controller
{

	public function test()
	{
		// $alter = new \DB\Alter(new \DB\Alter\Sqlite());
		// $alter->renameTable('ghazni__queries', 'ghazni__queries2');
		// $alter->renameFld('ghazni__queries', 'name', 'newname');
		// $alter->addFld('ghazni__queries', 'ciao', 'TEXT');
		// $alter->dropFld('ghazni__queries', 'ciao');
		// $alter->run(new DB());

		// $inspect = new \DB\Inspect( new \DB\Inspect\Mysql( new DB() ) );
		// var_dump($inspect->tableExists('sitarc__geodata'));
		// var_dump($inspect->tableColumns('sitarc__geodata'));

		echo '<hr />end of test_ctrl::test()';
	}
}
?>
