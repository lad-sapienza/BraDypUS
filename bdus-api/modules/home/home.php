<?php
/**
 * @uses pref
 * @uses $_SESSION
 * @uses cfg
 * @uses utils
 * 
 */


class home_ctrl extends Controller 
{
    public function showAll()
    {
        $js_libs = [
            'php2js.js',
            'jquery-2.1.1.min.js',
            'jquery-sortable.js',
            'bootstrap.js',
            'bootstrap-datepicker.js',
            'jquery.dataTables.js',
            'datatables-bootstrap.js',
            'jquery.keyboard.js',
            'utils.js',
            'jquery.pnotify.js',
            'jquery.fineuploader-3.4.0.js',
            'core.js',
            'api.js',
            'layout.js',
            'formControls.js',
            'select2.full.js',
            'enhanceForm.js',
            'jquery.checklabel.js',
            'jquery.printElement.js',
            'jquery.jqplot.js',
            'jqplot.barRenderer.min.js',
            'jqplot.categoryAxisRenderer.min.js',
            'jqplot.pointLabels.js',
            'export-jqplot-to-png.js',
            'jquery.insertAtCaret.js',
            'bootstrap-slider.js',
            'hashActions.js'
        ]; 

        $this->render('home', 'main', [
            "version" => version::current(),
            "app_label" => strtoupper($this->cfg ? $this->cfg->get('main.name') : ''),
            "css" => $this->compressCss( ['main.css'], $this->get['mini'] === 1 ),
            "tr_json" => tr::lang2json(),
            "debugMode" => DEBUG_ON ? "true" : "false",
            "prefix" => $this->prefix ?: '',
            "js_libs" => $this->compressJs( $js_libs, $this->get['mini'] === 1, $_SESSION['debug_mode'] === true),
            "can_user_enter" => utils::canUser('enter') ? true : false,
            "address" => $this->request['address'],
            "token" => $this->request['token'],
            "request_app" => $this->request['app'],
            "googleanaytics" => utils::is_online() ? ($this->cfg->get('main.googleanaytics') ?? false) : false
        ]);
    }

    private function compressCss ( array $files, bool $mini = false): string
	{
		if ( $mini && file_exists('./css-less/main.less')) {
			$str = "/*\n * BraDypUS css minified archive includes different sources and licenses" .
			        "\n * For details on external libraries (copyrights and licenses) please consult the Credits information" .
        			"\n*/\n";

	    	try {
				$opts = [ 'compress' => true ];
				
				$parser = new Less_Parser($opts);
				$parser->parseFile( "./css-less/main.less");
				$css = $parser->getCss();

				if (hash_file('sha256', "./css/mini.css") !== hash('sha256', $css)) {
					file_put_contents("./css/mini.css", $str . $css);
				}
			} catch (\Throwable $e) {
				$this->log->error($e);
      		}
		}
		return implode("\n", [
			'<link type="text/css" media="all" rel="stylesheet" href="./css/mini.css?sha256' . hash_file('sha256', './css/mini.css') . '" />',
			'<link rel="shortcut icon" href="./img/favicon.ico">'
		]);
    }
    
    private function compressJs( array $files, bool $mini = false, bool $debug = false ): string
	{
		$str = [];

		if ( ($mini || !file_exists('./js/bdus.mini.js')) && is_dir('./js-sources')) {

			$str_to_write[] = "/*\n * BraDypUS javascripts minified archive includes different sources and licenses";
			$str_to_write[] =  "\n * For details on external libraries (copyrights and licenses) please consult the Credits information";
			$str_to_write[] =  "\n */";

			foreach ($files as $file) {

				$file = ltrim($file);

				if ( file_exists( './js-sources/' . $file ) ) {
					$str_to_write[] = \JShrink\Minifier::minify( file_get_contents ( './js-sources/' . $file ) );
				}
			}

			if ( !file_exists('./js/bdus.mini.js') ||
				(file_exists('./js/bdus.mini.js') && hash_file('sha256', './js/bdus.mini.js') !== hash('sha256', implode("\n", $str_to_write)))
				) {
				@unlink('./js/bdus.mini.js');
  				utils::write_in_file ( './js/bdus.mini.js', implode("\n", $str_to_write));
			}
			  
			return '<script language="JavaScript" type="text/JavaScript" ' .
						'src="./js/bdus.mini.js?sha256=' . hash_file('sha256', './js/bdus.mini.js') . '"></script>';
			
		} else if ( $debug && is_dir('./js-sources')) {
			
			foreach ( $files as $file ) {
				$file = ltrim($file);

				if ( file_exists( './js-sources/' . $file ) ) {
					$str[] = '<script language="JavaScript" type="text/JavaScript" ' .
				              ' src="./js-sources/' . $file .'?sha256=?_' . hash_file('sha256', './js-sources/' . $file) . '"></script>';
				}
			}

			return implode("\n", $str);
		
		} else {
			
			return '<script language="JavaScript" type="text/JavaScript" src="./js/bdus.mini.js?sha256' . hash_file('sha256', './js/bdus.mini.js') . '"></script>' . "\n";
    	}
	}


    public function main_home() {

        if (!utils::canUser('enter')) {
            return '<h2>' . tr::get('not_enough_privilege') . '</h2>';
        }

        $this->render('home', 'home', [
            "app" => strtoupper(APP),
            "app_definition" => $this->cfg->get('main.definition'),
            "is_frozen_text" => $this->cfg->get('main.status') === 'frozen' ? tr::get('app_is_frozen') :  false,
            "all_tb" => $this->cfg->get('tables.*.label', 'is_plugin', null),
            
            "welcome" => file_exists(PROJ_DIR . 'welcome.html') ? file_get_contents(PROJ_DIR . 'welcome.html') : false,

            "tb_options" => [
                [ 
                    "fa-file-o", 
                    'new', 
                    utils::canUser('add_new') ? "api.record.add($('#ref_tb').val())" :  false 
                ],
                [ 
                    "fa-picture-o", 
                    'new_file', 
                    utils::canUser('add_new') ? "api.record.add('" . $this->prefix . "files')" :  false 
                ],
                [ 
                    "fa-table", 
                    'show_all', 
                    utils::canUser('read') ? "core.runMod('search', [ 'all', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search", 
                    'advanced_search', 
                    utils::canUser('read') ? "core.runMod('search', ['advanced', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search-plus", 
                    'sql_expert_search', 
                    utils::canUser('read') ? "core.runMod('search', ['sqlExpert', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    false, 
                    'fast_search', 
                    'javascript:void(0)" style="width:200px',
                    utils::canUser('read') ? '<input type="text" style="width: 90%;" placeholder="' . tr::get('fast_search') . '" class="fast_search" />' : false
                ],
                [ 
                    "fa-external-link", 
                    'export', 
                    utils::canUser('edit') ? "api.query.Export('1', $('#ref_tb').val() );" :  false
                ],
                [ 
                    "fa-map-marker", 
                    'GeoFace', 
                    utils::canUser('read') ? "core.runMod('geoface', $('#ref_tb').val());" :  false 
                ],
            ],

            "option_list" => [
                [ 
                    "fa-bookmark-o", 
                    'saved_queries', 
                    utils::canUser('read') ? "core.runMod('saved_queries');" :  false 
                ],
                [ 
                    "fa-lightbulb-o", 
                    'user_preferences', 
                    utils::canUser('read') ? "core.runMod('preferences');" :  false 
                ],
                [ 
                    "fa-group", 
                    'user_mng', 
                    utils::canUser('read') ? "core.runMod('user');" :  false 
                ],
                [ 
                    "fa-random", 
                    'available_exports', 
                    utils::canUser('read') ? "core.runMod('myExport');" :  false 
                ],
                [ 
                    "fa-bar-chart-o", 
                    'saved_charts', 
                    utils::canUser('read') ? "core.runMod('chart');" :  false 
                ],
                [ 
                    "fa-suitcase", 
                    'backup', 
                    utils::canUser('edit') ? "core.runMod('backup');" :  false 
                ],
                [ 
                    "fa-exchange", 
                    'find_replace', 
                    utils::canUser('edit') ? "core.runMod('search_replace');" :  false 
                ],
                [ 
                    "fa-upload", 
                    'multiupload', 
                    utils::canUser('edit') ? "core.runMod('multiupload');" :  false 
                ],
                [ 
                    "fa-cloud-upload", 
                    'import_geodata', 
                    utils::canUser('edit') ? "core.runMod('import_geodata');" :  false 
                ],
                [ 
                    "fa-quote-left", 
                    'vocs', 
                    utils::canUser('admin') ? "core.runMod('vocabularies');" :  false 
                ],
                [ 
                    "fa-edit", 
                    'front_page_editor', 
                    utils::canUser('admin') ? "core.runMod('frontpage_editor');" :  false 
                ],
                [ 
                    "fa-envelope-o", 
                    'system_email', 
                    utils::canUser('admin') ? "core.runMod('sys_mail');" :  false 
                ],
                [ 
                    "fa-book", 
                    'history', 
                    utils::canUser('super_admin') ? "core.runMod('myHistory');" :  false 
                ],
                [ 
                    "fa-comment", 
                    'system_translate', 
                    utils::canUser('super_admin') ? "core.runMod('translate');" :  false 
                ],
                [ 
                    "fa-cog", 
                    'sys_config', 
                    utils::canUser('super_admin') ? "core.runMod('config');" :  false 
                ],
                [ 
                    "fa-code", 
                    tr::get('ip'), 
                    !utils::is_online() && utils::canUser('read') ? "core.runMod('info', 'getIP');" :  false 
                ],
                [ 
                    "fa-terminal", 
                    'run_free_sql', 
                    utils::canUser('super_admin') ? "core.runMod('free_sql');" :  false 
                ],
                [ 
                    "fa-trash", 
                    'empty_cache', 
                    utils::canUser('super_admin') ? "core.runMod('empty_cache');" :  false 
                ],
                [ 
                    "fa-flask", 
                    'Test', 
                    utils::canUser('super_admin') ? "core.open({obj: 'test_ctrl', method: 'test', title: 'test'});" :  false 
                ],
                [ 
                    "fa-bug", 
                    'debug', 
                    utils::canUser('read') ? "core.runMod('debug_ctrl');" :  false 
                ]
            ]

        ]);
    }
}