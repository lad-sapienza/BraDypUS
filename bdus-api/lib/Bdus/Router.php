<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Central FastRoute-based dispatcher for all API endpoints.
 *
 * Usage in index.php:
 *   \Bdus\Router::dispatch();
 *   // $this merges URL vars + request body into $_GET / $_POST / $_REQUEST
 *   // and sets $_GET['obj'] / $_GET['method'] so App::route() works unchanged.
 *
 * Web-server configuration (all that is needed):
 *
 *   Apache .htaccess:
 *     RewriteEngine On
 *     RewriteCond %{REQUEST_FILENAME} !-f
 *     RewriteRule ^ index.php [L]
 *
 *   Nginx:
 *     location / { try_files $uri $uri/ /index.php$is_args$args; }
 *
 *   Caddy:
 *     try_files {path} /index.php
 */

namespace Bdus;

use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router
{
    /**
     * Minimum privilege required to access each route.
     *
     * Values: 'none' | 'read' | 'edit' | 'admin'
     *
     *   none  — public; no authentication required (login, public info)
     *   read  — any authenticated principal (JWT user or API key with privilege ≤ 30)
     *   edit  — write operations (API key privilege ≤ 25)
     *   admin — administrative operations (API key privilege ≤ 10)
     *
     * The key format is 'ctrl::method' matching the pairs defined in the route
     * collector below.  Any route NOT listed here defaults to 'admin' (safest).
     *
     * This map is the single source of truth for route access control and will
     * also serve as the foundation for OpenAPI spec generation.
     */
    public const ROUTE_PRIVILEGE = [
        // ── Public / no-auth ────────────────────────────────────────────────
        'login_ctrl::listApps'   => 'none',
        'login_ctrl::auth'       => 'none',
        'login_ctrl::refresh'    => 'none',
        'login_ctrl::out'        => 'none',
        'info_ctrl::getInfo'     => 'none',
        'new_app_ctrl::getStatus'=> 'none',
        'new_app_ctrl::create'   => 'none',

        // ── Read — any authenticated principal ───────────────────────────────
        'home_ctrl::listTables'                      => 'read',
        'info_ctrl::getAppInfo'                      => 'read',
        'record_ctrl::getRecord'                     => 'read',
        'record_ctrl::getRecords'                    => 'read',
        'record_ctrl::exportRecords'                 => 'read',
        'record_ctrl::getTemplates'                  => 'read',
        'record_ctrl::getFieldOptions'               => 'read',
        'record_ctrl::searchLinkCandidates'          => 'read',
        'record_ctrl::getRsMatrix'                   => 'read',
        'record_ctrl::getDeletedRecords'             => 'read',
        'record_ctrl::getVersions'                   => 'read',
        'record_ctrl::getVersionDiff'                => 'read',
        'search_ctrl::getAdvancedConfig'             => 'read',
        'search_ctrl::getUsedValues'                 => 'read',
        'chart_ctrl::listCharts'                     => 'read',
        'chart_ctrl::getData'                        => 'read',
        'saved_queries_ctrl::listQueries'            => 'read',
        'myHistory_ctrl::getHistory'                 => 'read',
        'frontpage_editor_ctrl::getWelcome'          => 'read',
        'geoface_ctrl::getGeoJson'                   => 'read',
        'vocabularies_ctrl::list'                    => 'read',

        // ── Edit — write operations ──────────────────────────────────────────
        'record_ctrl::saveRecord'                    => 'edit',
        'record_ctrl::erase'                         => 'edit',
        'record_ctrl::restoreVersion'                => 'admin',
        'record_ctrl::uploadFile'                    => 'edit',
        'record_ctrl::deleteFile'                    => 'edit',
        'file_ctrl::sortFiles'                       => 'edit',
        'record_ctrl::addRs'                         => 'edit',
        'record_ctrl::deleteRs'                      => 'edit',
        'record_ctrl::addManualLink'                 => 'edit',
        'record_ctrl::deleteManualLink'              => 'edit',
        'chart_ctrl::saveChart'                      => 'edit',
        'chart_ctrl::shareChart'                     => 'edit',
        'chart_ctrl::unshareChart'                   => 'edit',
        'chart_ctrl::deleteChart'                    => 'edit',
        'saved_queries_ctrl::saveQuery'              => 'edit',
        'saved_queries_ctrl::shareQuery'             => 'edit',
        'saved_queries_ctrl::unshareQuery'           => 'edit',
        'saved_queries_ctrl::deleteQuery'            => 'edit',
        'geoface_ctrl::saveNew'                      => 'edit',
        'geoface_ctrl::updateGeometry'               => 'edit',
        'geoface_ctrl::eraseGeometry'                => 'edit',
        'vocabularies_ctrl::add'                     => 'edit',
        'vocabularies_ctrl::sort'                    => 'edit',
        'vocabularies_ctrl::edit'                    => 'edit',
        'vocabularies_ctrl::erase'                   => 'edit',
        'import_ctrl::getTableFields'                => 'edit',
        'import_ctrl::previewFile'                   => 'edit',
        'import_ctrl::previewPhotos'                 => 'edit',
        'import_ctrl::importData'                    => 'edit',
        'import_ctrl::importGeoJson'                 => 'edit',
        'import_ctrl::importPhotos'                  => 'edit',

        // ── Admin (privilege ≤ 10) — user management and operational tasks ───
        'home_ctrl::getMigrations'                   => 'admin',
        'user_ctrl::showList'                        => 'admin',
        'user_ctrl::showUserForm'                    => 'admin',
        'user_ctrl::saveUserData'                    => 'admin',
        'user_ctrl::deleteOne'                       => 'admin',
        'user_ctrl::getTablePrivileges'              => 'admin',
        'user_ctrl::saveTablePrivilege'              => 'admin',
        'user_ctrl::deleteTablePrivilege'            => 'admin',
        'confirm_super_adm_pwd_ctrl::check_pwd'      => 'admin',
        'backup_ctrl::listBackups'                   => 'admin',
        'backup_ctrl::doBackup'                      => 'admin',
        'backup_ctrl::deleteBackup'                  => 'admin',
        'backup_ctrl::restoreBackup'                 => 'admin',
        'backup_ctrl::downloadBackup'                => 'admin',
        'debug_ctrl::getLogs'                        => 'admin',
        'debug_ctrl::purgeLogs'                      => 'admin',
        'api_ctrl::listKeys'                         => 'admin',
        'api_ctrl::createKey'                        => 'admin',
        'api_ctrl::revokeKey'                        => 'admin',
        'api_ctrl::deleteKey'                        => 'admin',

        // ── Super-admin (privilege = 1) — schema config and raw SQL ──────────
        'config_ctrl::getAppProperties'              => 'super_admin',
        'config_ctrl::save_app_properties'           => 'super_admin',
        'config_ctrl::getTableList'                  => 'super_admin',
        'config_ctrl::add_new_tb'                    => 'super_admin',
        'config_ctrl::sortTables'                    => 'super_admin',
        'config_ctrl::getTableConfig'                => 'super_admin',
        'config_ctrl::save_tb_data'                  => 'super_admin',
        'config_ctrl::delete_tb'                     => 'super_admin',
        'config_ctrl::rename_tb'                     => 'super_admin',
        'config_ctrl::getFldStructure'               => 'super_admin',
        'config_ctrl::getFldList'                    => 'super_admin',
        'config_ctrl::add_new_fld'                   => 'super_admin',
        'config_ctrl::save_fld_properties'           => 'super_admin',
        'config_ctrl::delete_column'                 => 'super_admin',
        'config_ctrl::rename_column'                 => 'super_admin',
        'config_ctrl::getGeoFaceConfig'              => 'super_admin',
        'config_ctrl::save_geoface_properties'       => 'super_admin',
        'config_ctrl::uploadGeoFile'                 => 'super_admin',
        'config_ctrl::delete_local_geofile'          => 'super_admin',
        'config_ctrl::getValidationReport'           => 'super_admin',
        'config_ctrl::fix'                           => 'super_admin',
        'config_ctrl::getRelations'                  => 'super_admin',
        'config_ctrl::saveRelation'                  => 'super_admin',
        'config_ctrl::deleteRelation'                => 'super_admin',
        'frontpage_editor_ctrl::saveWelcome'         => 'super_admin',
        'templates_ctrl::getTableList'               => 'super_admin',
        'templates_ctrl::getTemplateList'            => 'super_admin',
        'templates_ctrl::getTemplate'                => 'super_admin',
        'templates_ctrl::saveTemplate'               => 'super_admin',
        'templates_ctrl::deleteTemplate'             => 'super_admin',
        'templates_ctrl::renameTemplate'             => 'super_admin',
        'search_replace_ctrl::getTableList'          => 'super_admin',
        'search_replace_ctrl::getFieldList'          => 'super_admin',
        'search_replace_ctrl::doReplace'             => 'super_admin',
        'free_sql_ctrl::verifyPassword'              => 'super_admin',
        'free_sql_ctrl::runSql'                      => 'super_admin',
    ];

    /**
     * Look up the minimum privilege required for a given controller::method.
     *
     * Returns 'super_admin' (safest) for any pair not explicitly listed in ROUTE_PRIVILEGE.
     */
    public static function requiredPrivilege(string $ctrl, string $method): string
    {
        return self::ROUTE_PRIVILEGE[$ctrl . '::' . $method] ?? 'super_admin';
    }

    /**
     * Dispatch the current HTTP request.
     *
     * Modifies $_GET, $_POST, $_REQUEST in place so that Bdus\App and all
     * controllers work without any changes:
     *   - URL path vars  → merged into $_GET and $_REQUEST
     *   - JSON body      → merged into $_POST and $_REQUEST
     *   - $_GET['obj']   → controller class name
     *   - $_GET['method']→ method name
     *
     * Returns [ctrl, method, vars] when a route is found, [null, null, []] for
     * NOT_FOUND (let the caller fall through to legacy routing).
     *
     * Terminates with a 405 JSON response on METHOD_NOT_ALLOWED.
     */
    public static function dispatch(): array
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r) {

            // ── Auth ──────────────────────────────────────────────────────────
            $r->addRoute('GET',  '/api/auth/apps',    ['login_ctrl', 'listApps']);
            $r->addRoute('POST', '/api/auth/login',   ['login_ctrl', 'auth']);
            $r->addRoute('GET',  '/api/auth/refresh', ['login_ctrl', 'refresh']);
            $r->addRoute('GET',  '/api/auth/logout',  ['login_ctrl', 'out']);

            // ── Tables / home ─────────────────────────────────────────────────
            $r->addRoute('GET', '/api/tables',     ['home_ctrl', 'listTables']);
            $r->addRoute('GET', '/api/migrations', ['home_ctrl', 'getMigrations']);

            // ── Info ──────────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/info',     ['info_ctrl', 'getInfo']);
            $r->addRoute('GET', '/api/info/app', ['info_ctrl', 'getAppInfo']);

            // ── Records ───────────────────────────────────────────────────────
            $r->addRoute('GET',             '/api/record/{tb}/new',           ['record_ctrl', 'getRecord']);
            $r->addRoute('GET',             '/api/record/{tb}/{id:\d+}',      ['record_ctrl', 'getRecord']);
            $r->addRoute(['GET', 'POST'],   '/api/records/{tb}',              ['record_ctrl', 'getRecords']);
            $r->addRoute('GET',             '/api/records/{tb}/export',       ['record_ctrl', 'exportRecords']);
            $r->addRoute('POST',            '/api/record/{tb}',               ['record_ctrl', 'saveRecord']);
            $r->addRoute('DELETE',          '/api/record/{tb}/{id:\d+}',      ['record_ctrl', 'erase']);
            $r->addRoute('GET',             '/api/record/{tb}/templates',     ['record_ctrl', 'getTemplates']);
            $r->addRoute('GET',             '/api/record/{tb}/field-options', ['record_ctrl', 'getFieldOptions']);
            $r->addRoute('GET',             '/api/record/{tb}/link-candidates', ['record_ctrl', 'searchLinkCandidates']);
            $r->addRoute('GET',             '/api/record/{tb}/deleted',           ['record_ctrl', 'getDeletedRecords']);
            $r->addRoute('GET',             '/api/record/{tb}/{id:\d+}/versions', ['record_ctrl', 'getVersions']);
            $r->addRoute('GET',             '/api/version/{id:\d+}',              ['record_ctrl', 'getVersionDiff']);
            $r->addRoute('POST',            '/api/version/{id:\d+}/restore',      ['record_ctrl', 'restoreVersion']);

            // ── Files ─────────────────────────────────────────────────────────
            $r->addRoute('POST',   '/api/record/{tb}/{id:\d+}/file', ['record_ctrl', 'uploadFile']);
            $r->addRoute('DELETE', '/api/file/{fileId:\d+}',         ['record_ctrl', 'deleteFile']);
            $r->addRoute('POST',   '/api/files/sort',                ['file_ctrl',   'sortFiles']);

            // ── Stratigraphic Relations (RS) ──────────────────────────────────
            $r->addRoute('POST',   '/api/record/{tb}/rs', ['record_ctrl', 'addRs']);
            $r->addRoute('DELETE', '/api/rs/{id:\d+}',    ['record_ctrl', 'deleteRs']);
            $r->addRoute('GET',    '/api/rs/matrix',      ['record_ctrl', 'getRsMatrix']);

            // ── Manual links ──────────────────────────────────────────────────
            $r->addRoute('POST',   '/api/manual-link',          ['record_ctrl', 'addManualLink']);
            $r->addRoute('DELETE', '/api/manual-link/{id:\d+}', ['record_ctrl', 'deleteManualLink']);

            // ── Search ────────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/search/{tb}/config', ['search_ctrl', 'getAdvancedConfig']);
            $r->addRoute('GET', '/api/search/{tb}/values', ['search_ctrl', 'getUsedValues']);

            // ── Users ─────────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/users',                       ['user_ctrl', 'showList']);
            $r->addRoute('GET',    '/api/user',                        ['user_ctrl', 'showUserForm']);
            $r->addRoute('GET',    '/api/user/{id:\d+}',               ['user_ctrl', 'showUserForm']);
            $r->addRoute('POST',   '/api/user',                        ['user_ctrl', 'saveUserData']);
            $r->addRoute('DELETE', '/api/user/{id:\d+}',               ['user_ctrl', 'deleteOne']);
            $r->addRoute('GET',    '/api/user/{user_id:\d+}/privileges', ['user_ctrl', 'getTablePrivileges']);
            $r->addRoute('POST',   '/api/user/{user_id:\d+}/privileges', ['user_ctrl', 'saveTablePrivilege']);
            $r->addRoute('DELETE', '/api/privilege/{id:\d+}',           ['user_ctrl', 'deleteTablePrivilege']);

            // ── Configuration ─────────────────────────────────────────────────
            $r->addRoute('GET', '/api/config/app',            ['config_ctrl', 'getAppProperties']);
            $r->addRoute('PUT', '/api/config/app',            ['config_ctrl', 'save_app_properties']);
            $r->addRoute('GET', '/api/config/tables',         ['config_ctrl', 'getTableList']);
            $r->addRoute('POST',   '/api/config/tables',      ['config_ctrl', 'add_new_tb']);
            $r->addRoute('POST',   '/api/config/tables/sort', ['config_ctrl', 'sortTables']);
            $r->addRoute('GET',    '/api/config/table/{tb}',  ['config_ctrl', 'getTableConfig']);
            $r->addRoute('PUT',    '/api/config/table/{tb}',  ['config_ctrl', 'save_tb_data']);
            $r->addRoute('DELETE', '/api/config/table/{tb}',  ['config_ctrl', 'delete_tb']);
            $r->addRoute('PATCH',  '/api/config/table/{tb}',  ['config_ctrl', 'rename_tb']);
            $r->addRoute('GET',    '/api/config/field-structure', ['config_ctrl', 'getFldStructure']);
            $r->addRoute('GET',    '/api/config/table/{tb}/fields',              ['config_ctrl', 'getFldList']);
            $r->addRoute('POST',   '/api/config/table/{tb}/field',               ['config_ctrl', 'add_new_fld']);
            $r->addRoute('PUT',    '/api/config/table/{tb}/field/{fld}',         ['config_ctrl', 'save_fld_properties']);
            $r->addRoute('DELETE', '/api/config/table/{tb}/field/{fld}',         ['config_ctrl', 'delete_column']);
            $r->addRoute('PATCH',  '/api/config/table/{tb}/field/{fld}',         ['config_ctrl', 'rename_column']);
            $r->addRoute('GET',    '/api/config/geoface',     ['config_ctrl', 'getGeoFaceConfig']);
            $r->addRoute('PUT',    '/api/config/geoface',     ['config_ctrl', 'save_geoface_properties']);
            $r->addRoute('POST',   '/api/config/geofile',     ['config_ctrl', 'uploadGeoFile']);
            $r->addRoute('DELETE', '/api/config/geofile',     ['config_ctrl', 'delete_local_geofile']);
            $r->addRoute('GET',    '/api/config/validation',      ['config_ctrl', 'getValidationReport']);
            $r->addRoute('POST',   '/api/config/validation/fix',  ['config_ctrl', 'fix']);
            $r->addRoute('GET',    '/api/config/relations',        ['config_ctrl', 'getRelations']);
            $r->addRoute('POST',   '/api/config/relations',        ['config_ctrl', 'saveRelation']);
            $r->addRoute('PUT',    '/api/config/relations/{id}',   ['config_ctrl', 'saveRelation']);
            $r->addRoute('DELETE', '/api/config/relations/{id}',   ['config_ctrl', 'deleteRelation']);

            // ── Admin ─────────────────────────────────────────────────────────
            $r->addRoute('POST', '/api/admin/check-password', ['confirm_super_adm_pwd_ctrl', 'check_pwd']);

            // ── Backups ───────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/backups',                   ['backup_ctrl', 'listBackups']);
            $r->addRoute('POST',   '/api/backups',                   ['backup_ctrl', 'doBackup']);
            $r->addRoute('DELETE', '/api/backup/{file:.+}',          ['backup_ctrl', 'deleteBackup']);
            $r->addRoute('POST',   '/api/backup/{file:.+}/restore',  ['backup_ctrl', 'restoreBackup']);
            $r->addRoute('GET',    '/api/backup/{file:.+}/download', ['backup_ctrl', 'downloadBackup']);

            // ── Logs ──────────────────────────────────────────────────────────
            $r->addRoute('GET',  '/api/logs',       ['debug_ctrl', 'getLogs']);
            $r->addRoute('POST', '/api/logs/purge', ['debug_ctrl', 'purgeLogs']);

            // ── Charts ────────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/charts',                  ['chart_ctrl', 'listCharts']);
            $r->addRoute('POST',   '/api/charts',                  ['chart_ctrl', 'saveChart']);
            $r->addRoute('POST',   '/api/chart/data',              ['chart_ctrl', 'getData']);
            $r->addRoute('POST',   '/api/chart/{id:\d+}/share',    ['chart_ctrl', 'shareChart']);
            $r->addRoute('POST',   '/api/chart/{id:\d+}/unshare',  ['chart_ctrl', 'unshareChart']);
            $r->addRoute('DELETE', '/api/chart/{id:\d+}',          ['chart_ctrl', 'deleteChart']);

            // ── Saved queries ─────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/saved-queries',                    ['saved_queries_ctrl', 'listQueries']);
            $r->addRoute('POST',   '/api/saved-queries',                    ['saved_queries_ctrl', 'saveQuery']);
            $r->addRoute('POST',   '/api/saved-query/{id:\d+}/share',      ['saved_queries_ctrl', 'shareQuery']);
            $r->addRoute('POST',   '/api/saved-query/{id:\d+}/unshare',    ['saved_queries_ctrl', 'unshareQuery']);
            $r->addRoute('DELETE', '/api/saved-query/{id:\d+}',            ['saved_queries_ctrl', 'deleteQuery']);

            // ── API keys ──────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/api-keys',                 ['api_ctrl', 'listKeys']);
            $r->addRoute('POST',   '/api/api-keys',                 ['api_ctrl', 'createKey']);
            $r->addRoute('POST',   '/api/api-key/{id:\d+}/revoke',  ['api_ctrl', 'revokeKey']);
            $r->addRoute('DELETE', '/api/api-key/{id:\d+}',         ['api_ctrl', 'deleteKey']);

            // ── History ───────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/history', ['myHistory_ctrl', 'getHistory']);

            // ── Welcome / frontpage ───────────────────────────────────────────
            $r->addRoute('GET', '/api/welcome', ['frontpage_editor_ctrl', 'getWelcome']);
            $r->addRoute('PUT', '/api/welcome', ['frontpage_editor_ctrl', 'saveWelcome']);

            // ── Print templates ───────────────────────────────────────────────
            $r->addRoute('GET',    '/api/templates',                   ['templates_ctrl', 'getTableList']);
            $r->addRoute('GET',    '/api/templates/{tb}',              ['templates_ctrl', 'getTemplateList']);
            $r->addRoute('GET',    '/api/template/{tb}/{name}',        ['templates_ctrl', 'getTemplate']);
            $r->addRoute('POST',   '/api/template/{tb}/{name}',        ['templates_ctrl', 'saveTemplate']);
            $r->addRoute('DELETE', '/api/template/{tb}/{name}',        ['templates_ctrl', 'deleteTemplate']);
            $r->addRoute('POST',   '/api/template/{tb}/{name}/rename', ['templates_ctrl', 'renameTemplate']);

            // ── Geoface ───────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/geoface',         ['geoface_ctrl', 'getGeoJson']);
            $r->addRoute('POST',   '/api/geoface/feature', ['geoface_ctrl', 'saveNew']);
            $r->addRoute('PUT',    '/api/geoface/feature', ['geoface_ctrl', 'updateGeometry']);
            $r->addRoute('DELETE', '/api/geoface/feature', ['geoface_ctrl', 'eraseGeometry']);

            // ── Vocabularies ──────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/vocabularies',        ['vocabularies_ctrl', 'list']);
            $r->addRoute('POST',   '/api/vocabularies',        ['vocabularies_ctrl', 'add']);
            $r->addRoute('POST',   '/api/vocabularies/sort',   ['vocabularies_ctrl', 'sort']);
            $r->addRoute('PATCH',  '/api/vocabulary/{id:\d+}', ['vocabularies_ctrl', 'edit']);
            $r->addRoute('DELETE', '/api/vocabulary/{id:\d+}', ['vocabularies_ctrl', 'erase']);

            // ── Search & replace ──────────────────────────────────────────────
            $r->addRoute('GET',  '/api/search-replace/tables',          ['search_replace_ctrl', 'getTableList']);
            $r->addRoute('GET',  '/api/search-replace/{tb}/fields',     ['search_replace_ctrl', 'getFieldList']);
            $r->addRoute('POST', '/api/search-replace',                 ['search_replace_ctrl', 'doReplace']);

            // ── Free SQL ──────────────────────────────────────────────────────
            $r->addRoute('POST', '/api/free-sql/verify', ['free_sql_ctrl', 'verifyPassword']);
            $r->addRoute('POST', '/api/free-sql/run',    ['free_sql_ctrl', 'runSql']);

            // ── Data import ───────────────────────────────────────────────────
            $r->addRoute('GET',  '/api/import/{tb}/fields',  ['import_ctrl', 'getTableFields']);
            $r->addRoute('POST', '/api/import/preview-file', ['import_ctrl', 'previewFile']);
            $r->addRoute('POST', '/api/import/preview-photos', ['import_ctrl', 'previewPhotos']);
            $r->addRoute('POST', '/api/import/data',         ['import_ctrl', 'importData']);
            $r->addRoute('POST', '/api/import/geojson',      ['import_ctrl', 'importGeoJson']);
            $r->addRoute('POST', '/api/import/photos',       ['import_ctrl', 'importPhotos']);

            // ── New application wizard ────────────────────────────────────────
            $r->addRoute('GET',  '/api/new-app/status', ['new_app_ctrl', 'getStatus']);
            $r->addRoute('POST', '/api/new-app',         ['new_app_ctrl', 'create']);
        });

        // ── Resolve URI ───────────────────────────────────────────────────────
        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri        = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip script directory prefix so the app works in a subdirectory.
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($base && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if ($uri === '' || $uri === false) {
            $uri = '/';
        }

        $routeInfo = $dispatcher->dispatch($httpMethod, $uri);

        switch ($routeInfo[0]) {

            case \FastRoute\Dispatcher::NOT_FOUND:
                return [null, null, []];

            case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'method_not_allowed',
                    'allowed' => $routeInfo[1],
                ], JSON_UNESCAPED_UNICODE);
                exit;

            case \FastRoute\Dispatcher::FOUND:
                [$ctrl, $method] = $routeInfo[1];
                $vars            = $routeInfo[2];

                // Make URL path vars available to controllers via $_GET / $_REQUEST.
                $_GET     = array_merge($_GET,     $vars);
                $_REQUEST = array_merge($_REQUEST, $vars);

                // Parse JSON / form-encoded body for non-GET requests so that
                // controllers can read data from $this->post / $this->request.
                self::mergeRequestBody();

                // Set obj/method so Bdus\App::route() dispatches correctly.
                $_GET['obj']    = $ctrl;
                $_GET['method'] = $method;
                $_REQUEST       = array_merge($_REQUEST, [
                    'obj'    => $ctrl,
                    'method' => $method,
                ]);

                return [$ctrl, $method, $vars];
        }

        // Unreachable — satisfies static analysis.
        return [null, null, []];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parse the request body and merge it into $_POST / $_REQUEST.
     *
     * PHP auto-populates $_POST only for application/x-www-form-urlencoded and
     * multipart/form-data.  JSON bodies (used for PUT / PATCH / DELETE and some
     * POST requests) must be read manually.
     */
    private static function mergeRequestBody(): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
            return;
        }

        $contentType = trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);

        if ($contentType === 'application/json') {
            $raw  = file_get_contents('php://input');
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $_POST    = array_merge($_POST,    $data);
            $_REQUEST = array_merge($_REQUEST, $data);
        }
        // application/x-www-form-urlencoded and multipart/form-data are
        // already parsed by PHP into $_POST automatically.
    }
}
