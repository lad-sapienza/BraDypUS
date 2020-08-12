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
            "app_label" => defined('APP') ?  strtoupper(cfg::main('name')) : false,
            "css" => Compress::css( ['main.css'], $this->get['mini']),
            "tr_json" => tr::lang2json(),
            "debugMode" => DEBUG_ON ? "true" : "false",
            "prefix" => defined('PREFIX') ? PREFIX : '',
            "js_libs" => Compress::js( $js_libs, $this->get['mini'], $_SESSION['debug_mode']),
            "can_user_enter" => utils::canUser('enter') ? true : false,
            "address" => $this->request['address'],
            "token" => $this->request['token'],
            "request_app" => $this->request['app'],
            "googleanaytics" => utils::is_online() ? cfg::main('googleanaytics') ?: false : false
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

            "tb_options" => [
                [ 
                    "fa-file-o", 
                    'new', 
                    utils::canUser('add_new') ? "api.record.add($('#ref_tb').val())" :  false 
                ],
                [ 
                    "fa-picture-o", 
                    'new_file', 
                    utils::canUser('add_new') ? "api.record.add('" . PREFIX . "files')" :  false 
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
                    "fa-cloud-upload", 
                    'vocabularies', 
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
                    utils::canUser('read') ? "core.runMod('debug');" :  false 
                ]
            ]

        ]);
    }
}