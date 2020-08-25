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

		// $db = new \DB();
		// $driver = new \DB\Alter\Sqlite($db);
		// $alter = new \DB\Alter($driver);

		// $alter->renameTable('sitarc__queries', 'sitarc__queries2');
		// $alter->renameTable('sitarc__queries2', 'sitarc__queries');

		// $alter->renameFld('sitarc__charts', 'date', 'datea');
		// $alter->renameFld('sitarc__charts', 'datea', 'date');

		// $alter->addFld('sitarc__charts', 'ciao', 'TEXT');
		// $alter->dropFld('sitarc__charts', 'ciao');


		// $inspect = new \DB\Inspect( new \DB\Inspect\Mysql( $this->db ) );
		// var_dump($inspect->tableExists('sitarc__geodata'));
		// var_dump($inspect->tableColumns('sitarc__geodata'));

		echo '<hr />end of test_ctrl::test()';
	}
}
?>
