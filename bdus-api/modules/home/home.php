<?php
/**
 * @uses pref
 * @uses $_SESSION
 * @uses cfg
 * @uses utils
 * 
 */

 use Bdus\CompressAssets;

class home_ctrl extends Controller 
{
    private $js_libs = [
        'jquery.min.js',
        'bootstrap.min.js',
        'iziToast.min.js',
    ];
    
    private $css_libs = [
        'bootstrap.min.css',
        'iziToast.min.css',
        'bdus.min.css'
    ];

    public function showAll()
    {
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
            "googleanaytics" => utils::is_online() && $this->cfg ? ($this->cfg->get('main.googleanaytics') ?? false) : false
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
            array_push($files_to_include, 'bdus.min.js');
        } else {
            $files_to_include = array_merge($files_to_include, \Bdus\CompressAssets::$js_compress_libs);
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
        return implode("\n  ", $html);
    }

    public function main_home() {

        if (!utils::canUser('enter')) {
            return '<h2>' . tr::get('not_enough_privilege') . '</h2>';
        }

        $all_tb = $this->cfg->get('tables.*.label', 'is_plugin', null);

        $not_fresh = count($all_tb) > 1;

        $this->render('home', 'home', [
            "app" => strtoupper(APP),
            "app_definition" => $this->cfg->get('main.definition'),
            "is_frozen_text" => $this->cfg->get('main.status') === 'frozen' ? tr::get('app_is_frozen') :  false,
            "all_tb" => $all_tb,
            
            "welcome" => file_exists(PROJ_DIR . 'welcome.html') ? file_get_contents(PROJ_DIR . 'welcome.html') : false,

            "tb_options" => [
                [ 
                    "fa-file-o", 
                    'new', 
                    $not_fresh && utils::canUser('add_new') ? "api.record.add($('#ref_tb').val())" :  false 
                ],
                [ 
                    "fa-picture-o", 
                    'new_file', 
                    $not_fresh && utils::canUser('add_new') ? "api.record.add('" . $this->prefix . "files')" :  false 
                ],
                [ 
                    "fa-table", 
                    'show_all', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('search', [ 'all', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search", 
                    'advanced_search', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('search', ['advanced', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search-plus", 
                    'sql_expert_search', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('search', ['sqlExpert', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    false, 
                    'fast_search', 
                    'javascript:void(0)" style="width:200px',
                    $not_fresh && utils::canUser('read') ? '<input type="text" style="width: 90%;" placeholder="' . tr::get('fast_search') . '" class="form-control fast_search" />' : false
                ],
                [ 
                    "fa-external-link", 
                    'export', 
                    $not_fresh && utils::canUser('edit') ? "api.query.Export('1', $('#ref_tb').val() );" :  false
                ],
                [ 
                    "fa-map-marker", 
                    'GeoFace', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('geoface', $('#ref_tb').val());" :  false 
                ],
            ],

            "option_list" => [
                [ 
                    "fa-bookmark-o", 
                    'saved_queries', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('saved_queries');" :  false 
                ],
                [ 
                    "fa-lightbulb-o", 
                    'user_preferences', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('preferences');" :  false 
                ],
                [ 
                    "fa-group", 
                    'user_mng', 
                    utils::canUser('read') ? "core.runMod('user');" :  false 
                ],
                [ 
                    "fa-random", 
                    'available_exports', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('myExport');" :  false 
                ],
                [ 
                    "fa-bar-chart-o", 
                    'saved_charts', 
                    $not_fresh && utils::canUser('read') ? "core.runMod('chart');" :  false 
                ],
                [ 
                    "fa-suitcase", 
                    'backup', 
                    $not_fresh && utils::canUser('edit') ? "core.runMod('backup');" :  false 
                ],
                [ 
                    "fa-exchange", 
                    'find_replace', 
                    $not_fresh && utils::canUser('edit') ? "core.runMod('search_replace');" :  false 
                ],
                [ 
                    "fa-upload", 
                    'multiupload', 
                    $not_fresh && utils::canUser('edit') ? "core.runMod('multiupload');" :  false 
                ],
                [ 
                    "fa-cloud-upload", 
                    'import_geodata', 
                    $not_fresh && utils::canUser('edit') ? "core.runMod('import_geodata');" :  false 
                ],
                [ 
                    "fa-quote-left", 
                    'vocs', 
                    $not_fresh && utils::canUser('admin') ? "core.runMod('vocabularies');" :  false 
                ],
                [ 
                    "fa-edit", 
                    'front_page_editor', 
                    utils::canUser('admin') ? "core.runMod('frontpage_editor');" :  false 
                ],
                [ 
                    "fa-envelope-o", 
                    'system_email', 
                    $not_fresh && utils::canUser('admin') ? "core.runMod('sys_mail');" :  false 
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
                    $not_fresh && utils::canUser('super_admin') ? "core.runMod('free_sql');" :  false 
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