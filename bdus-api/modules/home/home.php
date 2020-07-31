<?php
/**
 * @uses PREFIX
 * @uses pref
 * @uses Compress
 * @uses $_SESSION
 * @uses cfg
 * @uses utils
 * 
 */


class home extends Controller 
{
    public function showAll()
    {
        $this->render('home', 'main', [
            "version" => version::current(),
            "app_label" => $_SESSION['app'] ?  strtoupper(cfg::main('name')) : false,
            "css" => Compress::css( ['main.css'], $this->get['mini']),
            "debugMode" => $this->get['debug'] ? "true" : "false",
            "prefix" => defined('PREFIX') ? PREFIX : '',
            "js_libs" => Compress::js( [
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
                'bootstrap-slider.js'
            ], $this->get['mini'], $_SESSION['debug_mode']),
            "can_user_enter" => utils::canUser('enter') ? true : false,
            "address" => $this->request['address'],
            "token" => $this->request['token'],
            "request_app" => $this->request['app'],
            "is_online" => utils::is_online(),
            "ga_id" => "UA-10461068-18"
        ]);
    }

    public function table_menu ()
    {
        $tb = $this->get['tb'];

        $this->render('home', 'tb_menu', [
            "items" => [
                [ 
                    "fa-file-o", 
                    'new', 
                    utils::canUser('add_new') ? "api.record.add('{$tb}')" :  false 
                ],
                [ 
                    "fa-picture-o", 
                    'new_file', 
                    utils::canUser('add_new') ? "api.record.add('" . PREFIX . "files')" :  false 
                ],
                [ 
                    "fa-list-alt", 
                    'most_recent_records', 
                    utils::canUser('read') ? "core.runMod('search', ['mostRecent', '{$tb}', " . ( pref::get('most_recent_no') ? pref::get('most_recent_no') : 10) . "] );" :  false 
                ],
                [ 
                    "fa-table", 
                    'show_all', 
                    utils::canUser('read') ? "core.runMod('search', ['all', '{$tb}'] );" :  false 
                ],
                [ 
                    "fa-search", 
                    'advanced_search', 
                    utils::canUser('read') ? "core.runMod('search', ['advanced', '{$tb}'] );" :  false 
                ],
                [ 
                    "fa-search-plus", 
                    'sql_expert_search', 
                    utils::canUser('read') ? "core.runMod('search', ['sqlExpert', '{$tb}'] );" :  false 
                ],
                [ 
                    false, 
                    'fast_search', 
                    'javascript:void(0)" style="width:200px',
                    utils::canUser('read') ? '<input type="text" style="width: 90%;" placeholder="' . tr::get('fast_search') . '" class="fast_search" data-table="' . $tb. '" />' : false
                ],
                [ 
                    "fa-external-link", 
                    'fast_search', 
                    utils::canUser('edit') ? "api.query.Export('1', '{$tb}' );" :  false
                ],
                [ 
                    "fa-map-marker", 
                    'GeoFace', 
                    utils::canUser('read') ? "core.runMod('geoface', '{$tb}');" :  false 
                ],
            ]
        ]);
    }


    public function main_home() {

        if (!utils::canUser('enter')) {
            return '<h2>' . tr::get('not_enough_privilege') . '</h2>';
        }

        $this->render('home', 'home', [
            "app" => strtoupper(APP),
            "app_definition" => cfg::main('definition'),
            "is_frozen_text" => cfg::main('status') === 'frozen' ? tr::get('app_is_frozen') :  false,
            "all_tb" => cfg::getNonPlg(),
            
            "welcome" => file_exists(PROJ_DIR . 'welcome.html') ? file_get_contents(PROJ_DIR . 'welcome.html') : false,

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
                    "fa-cloud-upload", 
                    'vocabularies', 
                    utils::canUser('admin') ? "core.runMod('vocabulary_mng');" :  false 
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
                    "fa-cloud-upload", 
                    'import', 
                    utils::canUser('super_admin') ? "core.runMod('myImport');" :  false 
                ],
                [ 
                    "fa-comment", 
                    'system_translate', 
                    utils::canUser('super_admin') ? "core.runMod('translate');" :  false 
                ],
                [ 
                    "fa-wrench", 
                    'app_data_editor', 
                    utils::canUser('super_admin') ? "core.runMod('app_data_editor');" :  false 
                ],
                [ 
                    "fa-cog", 
                    'edit_flds_data', 
                    utils::canUser('super_admin') ? "core.runMod('flds_editor');" :  false 
                ],
                [ 
                    "fa-code", 
                    tr::get('ip'), 
                    !utils::is_online() && utils::canUser('read') ? "core.runMod('flds_editor');" :  false 
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
                    utils::canUser('read') ? "core.runMod('debug');" :  false 
                ]
            ]

        ]);
    }
}