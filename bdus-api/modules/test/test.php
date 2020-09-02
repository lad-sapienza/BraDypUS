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
		
			
		// $firephp = new Monolog\Handler\FirePHPHandler();
		// $this->log->pushHandler($firephp);
		// $this->log->error('Error PHP', ["hello" => "world"]);
		// $driver = new \DB\Alter\Sqlite($this->db);
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

	private function testShortSQL()
	{
		$test = implode( '~', [
			// tb
			'@siti:Siti',

			// fields
			'[id_sito',

			// Join
			'+sitarc__materiali_us||sitarc__materiali_us.localita|=|id_sito',

			// Where
			// '?id_sito|=|1||and|id_sito|=|1',
			// '?1',
			'?sitarc__m_biblio.bib_abbreviazione|like|%sara%',

			// Group
			'*id_sito,toponimo',

			// Limit
			'-30:0',

			'>id_sito,toponimo',
			// "@siti~?sitarc__m_biblio.bib_abbreviazione|like|%sara%",
			
		]);

		try {
			$qb = new \SQL\QueryBuilder();
			$qb->loadShortSQL($this->prefix, $this->cfg, $test);

			$sql = $qb->getSql();

			echo "<code>$sql[0]</code>:<br>";
			echo '<pre>' . json_encode($sql[1], JSON_PRETTY_PRINT) . '</pre>';
			echo '<pre>' . json_encode($qb->getQueryObject()->get(), JSON_PRETTY_PRINT) . '</pre>';

			echo "<hr>";
			
		} catch (\Throwable $th) {
			echo '<pre>' . 
				$test . "\n\n" . 
				$th->getMessage() . "\n\n" . 
				$th->getTraceAsString() . '</pre>';
			var_dump($th);
		}
	}
}
?>
