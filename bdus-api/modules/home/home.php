<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 * @uses pref
 * @uses $_SESSION
 * @uses cfg
 * @uses utils
 * 
 */

class home_ctrl extends Controller 
{
    private $assets = [
        // jquery
        "jquery/dist/jquery.min.js",
        "jquery/dist/jquery.min.map",

        // bootstrap
        "bootstrap/dist/js/bootstrap.min.js",
        "bootstrap/dist/css/bootstrap.min.css",
        "bootstrap/dist/css/bootstrap.min.css.map",

        // izitoast
        "izitoast/dist/js/iziToast.min.js",
        "izitoast/dist/css/iziToast.min.css",

        // datatables.net
        "datatables.net/js/jquery.dataTables.min.js",

        // datatables.net-bs
        "datatables.net-bs/js/dataTables.bootstrap.min.js",
        "datatables.net-bs/css/dataTables.bootstrap.min.css",

        // select2
        "select2/dist/js/select2.full.min.js",
        "select2/dist/css/select2.min.css",

        // sortablejs
        "sortablejs/Sortable.min.js",

        // fancybox
        "@fancyapps/fancybox/dist/jquery.fancybox.min.js",
        "@fancyapps/fancybox/dist/jquery.fancybox.min.css",

        // font-awesome
        "font-awesome/css/font-awesome.min.css",
        "font-awesome/css/font-awesome.css.map",

        // bdus
        "bdus/bdus.min.css",
        "bdus/bdus.min.js"
    ];

    public function showAll()
    {
        $this->render('home', 'main', [
            "version" => version::current(),
            "app_label" => strtoupper($this->cfg ? $this->cfg->get('main.name') : ''),
            "assets" => $this->loadAssets(),
            "tr_json" => \tr::lang2json(),
            "debugMode" => DEBUG_ON ? "true" : "false",
            "prefix" => $this->prefix ?: '',
            "can_user_enter" => \utils::canUser('enter') ? true : false,
            "address" => $this->request['address'],
            "token" => $this->request['token'],
            "request_app" => $this->request['app'],
            "googleanaytics" => \utils::is_online() && $this->cfg ? ($this->cfg->get('main.googleanaytics') ?? false) : false
        ]);
    }

    private function loadAssets(): string
    {
        $html = [];
        foreach ($this->assets as $asset) {

            if (!file_exists("assets/$asset")){
                $this->log->warning("Asset `$asset` not found!");
            }

            if (substr( $asset, -4 ) === '.css'){
                array_push(
                    $html, 
                    '<link type="text/css" media="all" rel="stylesheet" href="assets/' . $asset . '?sha256' . hash_file('sha256', "assets/$asset") . '" />'
                );

            } elseif (substr( $asset, -3 ) === '.js') {
                array_push(
                    $html,
                    '<script language="javascript" ' .
                            'type="text/javascript" ' .
						    'src="assets/' . $asset . '?sha256=' . hash_file('sha256', "assets/$asset") . '"></script>'
                );
            } elseif (substr( $asset, -4 ) === '.map') {
                // ignore map files...
            } else {
                $this->log->warning("Unknown asset type: `$asset`");
            }
        }

        return implode("\n  ", $html);
    }

    
    public function main_home() {

        if (!\utils::canUser('enter')) {
            return '<h2>' . \tr::get('not_enough_privilege') . '</h2>';
        }

        $all_tb = $this->cfg->get('tables.*.label', 'is_plugin', null);

        $not_fresh = count($all_tb) > 1;

        $this->render('home', 'home', [
            "app" => strtoupper(APP),
            "app_definition" => $this->cfg->get('main.definition'),
            "is_frozen_text" => $this->cfg->get('main.status') === 'frozen' ? \tr::get('app_is_frozen') :  false,
            "all_tb" => $all_tb,
            
            "welcome" => file_exists(PROJ_DIR . 'welcome.html') ? file_get_contents(PROJ_DIR . 'welcome.html') : false,

            "tb_options" => [
                [ 
                    "fa-file-o", 
                    'new', 
                    $not_fresh && \utils::canUser('add_new') ? "api.record.add($('#ref_tb').val())" :  false 
                ],
                [ 
                    "fa-picture-o", 
                    'new_file', 
                    $not_fresh && \utils::canUser('add_new') ? "api.record.add('" . $this->prefix . "files')" :  false 
                ],
                [ 
                    "fa-table", 
                    'show_all', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('search', [ 'all', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search", 
                    'advanced_search', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('search', ['advanced', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    "fa-search-plus", 
                    'sql_expert_search', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('search', ['sqlExpert', $('#ref_tb').val() ] );" :  false 
                ],
                [ 
                    false, 
                    'fast_search', 
                    'javascript:void(0)" style="width:200px',
                    $not_fresh && \utils::canUser('read') ? '<input type="text" style="width: 90%;" placeholder="' . \tr::get('fast_search') . '" class="form-control fast_search" />' : false
                ],
                [ 
                    "fa-random", 
                    'export', 
                    $not_fresh && \utils::canUser('edit') ? "api.query.Export('WyIxPTEiLFtdXQ', $('#ref_tb').val() );" :  false
                ],
                [ 
                    "fa-map-marker", 
                    'GeoFace', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('geoface', $('#ref_tb').val());" :  false 
                ],
            ],

            "option_list" => [
                [ 
                    "fa-bookmark-o", 
                    'saved_queries', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('saved_queries');" :  false 
                ],
                [ 
                    "fa-lightbulb-o", 
                    'user_preferences', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('preferences');" :  false 
                ],
                [ 
                    "fa-group", 
                    'user_mng', 
                    \utils::canUser('read') ? "core.runMod('user');" :  false 
                ],
                [ 
                    "fa-random", 
                    'available_exports', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('myExport');" :  false 
                ],
                [ 
                    "fa-bar-chart-o", 
                    'saved_charts', 
                    $not_fresh && \utils::canUser('read') ? "core.runMod('chart');" :  false 
                ],
                [ 
                    "fa-suitcase", 
                    'backup', 
                    $not_fresh && \utils::canUser('edit') ? "core.runMod('backup');" :  false 
                ],
                [ 
                    "fa-exchange", 
                    'find_replace', 
                    $not_fresh && \utils::canUser('edit') ? "core.runMod('search_replace');" :  false 
                ],
                [ 
                    "fa-cloud-upload", 
                    'import_geodata', 
                    $not_fresh && \utils::canUser('edit') ? "core.runMod('import_geodata');" :  false 
                ],
                [ 
                    "fa-quote-left", 
                    'vocs', 
                    $not_fresh && \utils::canUser('admin') ? "core.runMod('vocabularies');" :  false 
                ],
                [ 
                    "fa-edit", 
                    'front_page_editor', 
                    \utils::canUser('admin') ? "core.runMod('frontpage_editor');" :  false 
                ],
                [ 
                    "fa-envelope-o", 
                    'system_email', 
                    $not_fresh && \utils::canUser('admin') ? "core.runMod('sys_mail');" :  false 
                ],
                [ 
                    "fa-book", 
                    'history', 
                    \utils::canUser('super_admin') ? "core.runMod('myHistory');" :  false 
                ],
                [ 
                    "fa-comment", 
                    'system_translate', 
                    \utils::canUser('super_admin') ? "core.runMod('translate');" :  false 
                ],
                [ 
                    "fa-cog", 
                    'sys_config', 
                    \utils::canUser('super_admin') ? "core.runMod('config');" :  false 
                ],
                [ 
                    "fa-code", 
                    'ip', 
                    !\utils::is_online() && \utils::canUser('read') ? "core.runMod('info', 'getIP');" :  false 
                ],
                [ 
                    "fa-terminal", 
                    'run_free_sql', 
                    $not_fresh && \utils::canUser('super_admin') ? "core.runMod('free_sql');" :  false 
                ],
                [ 
                    "fa-trash", 
                    'empty_cache', 
                    \utils::canUser('super_admin') ? "core.runMod('empty_cache');" :  false 
                ],
                [ 
                    "fa-flask", 
                    'Test', 
                    \utils::canUser('super_admin') ? "core.open({obj: 'test_ctrl', method: 'test', title: 'test'});" :  false 
                ],
                [ 
                    "fa-bug", 
                    'debug', 
                    \utils::canUser('read') ? "core.runMod('debug_ctrl');" :  false 
                ]
            ]

        ]);
    }
}