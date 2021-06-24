<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Apr 15, 2012
 */


class test_ctrl extends Controller
{

    public function test()    
	{	

        // $this->testShortSQL();
        // $this->testTmpl();
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
    
    private function testTmpl()
    {
        $read_rec = new \Record\Read( 3, null, 'sitarc__siti', $this->db, $this->cfg);
        
        
        $tmpl = new \Template\Template(
            'edit',
            $read_rec,
            $this->db,
            $this->cfg
        );

        foreach ([
            // '$tmpl->cell("1")' => $tmpl->cell('1'), //OK
            // '$tmpl->simpleSum("wgs84z34_east, wgs84z34_north")' => $tmpl->simpleSum('wgs84z34_east, wgs84z34_north'), // OK
            // '$tmpl->permalink()' => $tmpl->permalink(), // OK
            // '$tmpl->links()' => $tmpl->links(), // OK

            // Link comprende anche userlinks
            // '$tmpl->userlinks()' => $tmpl->userlinks(), // OK
            // '$tmpl->rs()' => $tmpl->rs(), // OK
            
            // '$tmpl->fld("anno")' => $tmpl->fld('anno'), // Select
            // '$tmpl->fld("fase")' => $tmpl->fld('fase'), // Input
            // '$tmpl->fld("descrizione")' => $tmpl->fld('descrizione'), // Textarea
            // '$tmpl->plg_fld()' => $tmpl->plg_fld(),
            // '$tmpl->value("anno")' => $tmpl->value('anno'), // OK
            // '$tmpl->plg("")' => $tmpl->plg('anno'),
            // '$tmpl->showall()' => $tmpl->showall(),
            // '$tmpl->image_thumbs()' => $tmpl->image_thumbs(), // OK
            '$tmpl->plg("m_biblio")' => $tmpl->plg('m_biblio'), // OK

            // Geodata dipende da fld
            // '$tmpl->geodata()' => $tmpl->geodata(),
        ] as $name => $html) {
            $this->print_test($name, $html);
        }
    }
    private function print_test(string $name, $html)
    {
        echo '<div style="margin: 2rem auto; border: 1px solid red; padding: 1rem;">' .
        '<code>' . $name . '</code><hr>' .
        '<div>' . $html . '</div><hr>' .
        '<pre>' . htmlentities($html) . '</pre>' .
        '</div>';
    }

    private function testUAC()
    {
        $uac = new \UAC\UAC( $this->cfg->get('main.status'), $this->is_online(), $this->db );
        $actions = $uac->available_actions;

        // // Pure GLOBAL
        // echo $this->runTest($uac, "global:SUPERADM", [
        //     'global' => \UAC\UAC::SUPERADM
        // ]);
        
        // echo $this->runTest($uac, "global:ADM", [
        //     'global' => \UAC\UAC::ADM
        // ]);

        // echo  $this->runTest($uac, "global:UPDATE", [
        //     'global' => \UAC\UAC::UPDATE
        // ]);

        // echo $this->runTest($uac, "global:CREATE", [
        //     'global' => \UAC\UAC::CREATE
        // ]);
        
        // echo $this->runTest($uac, "global:CREATE", [
        //     'global' => \UAC\UAC::CREATE
        // ]);

        // echo $this->runTest($uac, "global:READ", [
        //     'global' => \UAC\UAC::READ
        // ]);
        
        // echo $this->runTest($uac, "global:ENTER", [
        //     'global' => \UAC\UAC::ENTER
        // ]);

        // // GLOBAL rewriting table
        // echo $this->runTest($uac, "global:SUPERADM , sitarc__siti:READ", [
        //     'global' => \UAC\UAC::SUPERADM,
        //     'sitarc__siti' => \UAC\UAC::READ,
        // ]);

        // echo $this->runTest($uac, "global:ADM , sitarc__siti:READ", [
        //     'global' => \UAC\UAC::ADM,
        //     'sitarc__siti' => \UAC\UAC::READ,
        // ]);

        // echo $this->runTest($uac, "global:UPDATE , sitarc__siti:READ", [
        //     'global' => \UAC\UAC::UPDATE,
        //     'sitarc__siti' => \UAC\UAC::READ,
        // ]);

        // // GLOBAL rewriting table
        // echo $this->runTest($uac, "global:READ , sitarc__siti:UPDATE", [
        //     'global' => \UAC\UAC::READ,
        //     'sitarc__siti' => \UAC\UAC::UPDATE,
        // ]);

        // echo $this->runTest($uac, "global:READ , sitarc__siti:UPDATE", [
        //     'global' => \UAC\UAC::READ,
        //     'sitarc__siti' => \UAC\UAC::UPDATE,
        // ]);

        // GLOBAL, table, on table
        // echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:CREATE", 
        //     [
        //         'global' => \UAC\UAC::ENTER,
        //         'sitarc__siti' => \UAC\UAC::CREATE,
        //     ],
        //     'sitarc__siti'
        // );
        // echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:READ", 
        //     [
        //         'global' => \UAC\UAC::ENTER,
        //         'sitarc__siti' => \UAC\UAC::READ,
        //     ],
        //     'sitarc__siti'
        // );
        // echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:UPDATE", 
        //     [
        //         'global' => \UAC\UAC::ENTER,
        //         'sitarc__siti' => \UAC\UAC::UPDATE,
        //     ],
        //     'sitarc__siti'
        // );
        // echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:DELETE", 
        //     [
        //         'global' => \UAC\UAC::ENTER,
        //         'sitarc__siti' => \UAC\UAC::DELETE,
        //     ],
        //     'sitarc__siti'
        // );


        echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:READ (id < 2)", 
            [
                'global' => \UAC\UAC::ENTER,
                'sitarc__siti' => [\UAC\UAC::READ, 'id < 2'],
            ],
            'sitarc__siti', 1
        );
        echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:UPDATE (id < 2)", 
            [
                'global' => \UAC\UAC::ENTER,
                'sitarc__siti' => [\UAC\UAC::UPDATE, 'id < 2'],
            ],
            'sitarc__siti', 1
        );
        echo $this->runTest( $uac,  "global:ENTER, sitarc__siti:DELETE (id < 2)", 
            [
                'global' => \UAC\UAC::ENTER,
                'sitarc__siti' => [\UAC\UAC::DELETE, 'id < 2'],
            ],
            'sitarc__siti', 1
        );


       

        

        return;

    }
    
    private function runTest( \UAC\UAC $uac, 
        string $label, 
        array $user, 
        string $onTable = null, 
        int $onRecId = null, 
        bool $user_owns_record = false
    )
    {
        
        $actions = $uac->available_actions;

        $html = "Testing user " . $label . " on " . ( $onTable ? $onTable : 'system') .
            ($onRecId ? " on record $onRecId" : '') .
            ($user_owns_record ? " where users OWNS record" : '') .
            "<ul>";
        foreach ($actions as $action) {
            $ret = $uac->setUAL( $user )->can( $action, $onTable, $onRecId, $user_owns_record );
            $html .= '<li class="text-' . ( $ret ? 'success' : 'danger') . '">' .
                "Can <strong>$action</strong>: " . 
                ( $ret ? '&#10004; ' : '&#10006;') .
                '</li>';
        }
        $html .= "</ul>";

        return $html;
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
			'?id_sito|=|1||and|id_sito|=|1',
			// '?1',
			// '?sitarc__m_biblio.bib_abbreviazione|like|%sara%',

			// Group
			'*id_sito,toponimo',

			// Limit
			'-30:0',

			'>id_sito,toponimo',
			// "@siti~?sitarc__m_biblio.bib_abbreviazione|like|%sara%",
			
		]);

		try {
            $pss = new \SQL\ShortSql\ParseShortSql($this->prefix, $this->cfg);
            $pss->parseAll($test);

			$sql = $pss->getSql();

			echo "<code>$sql[0]</code>:<br>";
			echo '<pre>' . json_encode($sql[1], JSON_PRETTY_PRINT) . '</pre>';
			echo '<pre>' . json_encode($pss->getQueryObject()->get(), JSON_PRETTY_PRINT) . '</pre>';

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
