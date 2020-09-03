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
    private $js_libs = [
        'jquery.min.js',
        'iziToast.min.js'
    ];
    
    private $js_compress_libs = [
        'php2js.js',
        'jquery-sortable.js',
        'bootstrap.js',
        'bootstrap-datepicker.js',
        'jquery.dataTables.js',
        'datatables-bootstrap.js',
        'jquery.keyboard.js',
        'utils.js',
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
        'hashActions.js',
    ];

    private $css_libs = [
        'mini.css',
        'iziToast.min.css'
    ];

    public function showAll()
    {
        if ($this->get['mini'] === 1 || !file_exists('./css/mini.css')){
            $this->compressCss();
        }
        if ($this->get['mini'] === 1 || !file_exists('./js/bdus.min.js')){
            $this->compressJs();
        }

        $this->render('home', 'main', [
            "version" => version::current(),
            "app_label" => strtoupper($this->cfg ? $this->cfg->get('main.name') : ''),
            "css_libs" => $this->showCSS(),
            "js_libs" => $this->showJS( $_SESSION['debug_mode'] === true ),
            "tr_json" => tr::lang2json(),
            "debugMode" => DEBUG_ON ? "true" : "false",
            "prefix" => $this->prefix ?: '',
            "can_user_enter" => utils::canUser('enter') ? true : false,
            "address" => $this->request['address'],
            "token" => $this->request['token'],
            "request_app" => $this->request['app'],
            "googleanaytics" => utils::is_online() ? ($this->cfg->get('main.googleanaytics') ?? false) : false
        ]);
    }

    private function showCSS(): string
    {
        $html = [];
        foreach ($this->css_libs as $css_file) {
            $full_path = "./css/$css_file";
            if (file_exists($full_path)){
            
                array_push(
                    $html,
                    '<link type="text/css" media="all" rel="stylesheet" href="' . $full_path . '?sha256' . hash_file('sha256', $full_path) . '" />'
                );
            } else {
                $this->log->warning("CSS file `$full_path` not found");
            }
        }
        return implode("\n  ", $html);
    }

    private function showJS( bool $debug = false ): string
    {
        $files_to_include = $this->js_libs;
        if (!$debug){
            array_push($files_to_include, 'bdus.mini.js');
        } else {
            $files_to_include = array_merge($files_to_include, $this->js_compress_libs);
        }
        $html = [];
        foreach ($files_to_include as $file) {
            if (file_exists("./js/$file")){
                $full_path = "./js/$file";
            } else if (file_exists("./js-sources/$file")){
                $full_path = "./js-sources/$file";
            } else {
                $this->log->warning("Cannot find JS file `$file` not found");
            }
            if ( $full_path){
                array_push(
                    $html,
                    '<script language="javascript" ' .
                            'type="text/javascript" ' .
						    'src="' . $full_path . '?sha256=' . hash_file('sha256', $full_path) . '"></script>'
                );
            }
        }
        return implode("\n", $html);
    }

    private function compressCss () : bool
	{
		if ( !file_exists('./css-less/main.less')) {
            $this->log->error("File `css-less/main.less` not found. Nothing to compress");
            return false;
        }
        $mini_css= "/*\n * BraDypUS css minified archive includes different sources and licenses" .
                "\n * For details on external libraries (copyrights and licenses) please consult the Credits information" .
                "\n*/\n";

        try {
            $parser = new Less_Parser([
                'compress' => true
            ]);
            $parser->parseFile( "./css-less/main.less");
            $mini_css .= $parser->getCss();

            if (file_exists('./css/mini.css') && hash_file('sha256', "./css/mini.css") !== hash('sha256', $mini_css)) {
                $delete = @unlink('./css/mini.css');
                if (!$delete){
                    $this->log->warning("Can not delete old CSS file `./css/mini.css`");
                    return false;
                }
            }
            if ( file_exists('./css/mini.css') ) {
                return true;
            }
            return file_put_contents("./css/mini.css", $mini_css) === false ? false : true;

        } catch (\Throwable $e) {
            $this->log->error($e);
        }
        return false;
    }
    
    private function compressJs(): bool
	{
        $str = [];
        $str_to_write[] = "/*\n * BraDypUS javascripts minified archive includes different sources and licenses";
        $str_to_write[] =  "\n * For details on external libraries (copyrights and licenses) please consult the Credits information";
        $str_to_write[] =  "\n */";

        foreach ($this->js_compress_libs as $file) {

            $file = ltrim($file);

            if ( file_exists( './js-sources/' . $file ) ) {
                $str_to_write[] = \JShrink\Minifier::minify( file_get_contents ( './js-sources/' . $file ) );
            } else {
                $this->log->warning("JS file `$file` not found");
            }
        }
        $mini_js = implode("\n", $str_to_write);
        
        if ( file_exists('./js/bdus.mini.js') && hash_file('sha256', './js/bdus.mini.js') !== hash('sha256', $mini_js ) ) {
            $deleted = @unlink('./js/bdus.mini.js');
            if (!$deleted){
                $this->log->warning("Cannot delete `./js/bdus.mini.js` file");
                return false;
            }
        }

        if (file_exists('./js/bdus.mini.js')) {
            return true;
        }
        return file_put_contents("./js/bdus.mini.js", $mini_js) === false ? false : true;
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