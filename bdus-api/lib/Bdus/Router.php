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
        'Bdus\\Controllers\\Login::listApps'    => 'none',
        'Bdus\\Controllers\\Login::auth'        => 'none',
        'Bdus\\Controllers\\Login::refresh'     => 'none',
        'Bdus\\Controllers\\Login::out'         => 'none',
        'Bdus\\Controllers\\Info::getInfo'      => 'none',
        'Bdus\\Controllers\\NewApp::getStatus' => 'none',
        'Bdus\\Controllers\\NewApp::create'    => 'none',
        'Bdus\\Controllers\\OAuth::redirect'    => 'none',
        'Bdus\\Controllers\\OAuth::callback'    => 'none',

        // ── Read — any authenticated principal ───────────────────────────────
        'Bdus\\Controllers\\Home::listTables'                      => 'read',
        'Bdus\\Controllers\\Info::getAppInfo'                      => 'read',
        'Bdus\\Controllers\\Record::getRecord'                     => 'read',
        'Bdus\\Controllers\\Record::getRecords'                    => 'read',
        'Bdus\\Controllers\\Record::exportRecords'                 => 'read',
        'Bdus\\Controllers\\Record::getTemplates'                  => 'read',
        'Bdus\\Controllers\\Record::getFieldOptions'               => 'read',
        'Bdus\\Controllers\\Record::checkUnique'                   => 'read',
        'Bdus\\Controllers\\Record::searchLinkCandidates'          => 'read',
        'Bdus\\Controllers\\Record::getRsMatrix'                   => 'read',
        'Bdus\\Controllers\\Record::getDeletedRecords'             => 'read',
        'Bdus\\Controllers\\Record::getVersions'                   => 'read',
        'Bdus\\Controllers\\Record::getVersionDiff'                => 'read',
        'Bdus\\Controllers\\Search::getAdvancedConfig'             => 'read',
        'Bdus\\Controllers\\Search::getUsedValues'                 => 'read',
        'Bdus\\Controllers\\Chart::listCharts'                     => 'read',
        'Bdus\\Controllers\\Chart::getData'                        => 'read',
        'Bdus\\Controllers\\SavedQueries::listQueries'            => 'read',
        'Bdus\\Controllers\\MyHistory::getHistory'                 => 'read',
        'Bdus\\Controllers\\FrontpageEditor::getWelcome'          => 'read',
        'Bdus\\Controllers\\Widget::listWidgets'                   => 'read',
        'Bdus\\Controllers\\Widget::serveWidget'                   => 'read',
        'Bdus\\Controllers\\Geoface::getGeoJson'                   => 'read',
        'Bdus\\Controllers\\Vocabularies::list'                    => 'read',

        // ── Edit — write operations ──────────────────────────────────────────
        'Bdus\\Controllers\\Record::saveRecord'                    => 'edit',
        'Bdus\\Controllers\\Record::erase'                         => 'edit',
        'Bdus\\Controllers\\Record::restoreVersion'                => 'admin',
        'Bdus\\Controllers\\Record::uploadFile'                    => 'edit',
        'Bdus\\Controllers\\Record::deleteFile'                    => 'edit',
        'Bdus\\Controllers\\File::sortFiles'                       => 'edit',
        'Bdus\\Controllers\\Record::addRs'                         => 'edit',
        'Bdus\\Controllers\\Record::deleteRs'                      => 'edit',
        'Bdus\\Controllers\\Record::addManualLink'                 => 'edit',
        'Bdus\\Controllers\\Record::deleteManualLink'              => 'edit',
        'Bdus\\Controllers\\Chart::saveChart'                      => 'edit',
        'Bdus\\Controllers\\Chart::shareChart'                     => 'edit',
        'Bdus\\Controllers\\Chart::unshareChart'                   => 'edit',
        'Bdus\\Controllers\\Chart::deleteChart'                    => 'edit',
        'Bdus\\Controllers\\SavedQueries::saveQuery'              => 'edit',
        'Bdus\\Controllers\\SavedQueries::shareQuery'             => 'edit',
        'Bdus\\Controllers\\SavedQueries::unshareQuery'           => 'edit',
        'Bdus\\Controllers\\SavedQueries::deleteQuery'            => 'edit',
        'Bdus\\Controllers\\Geoface::saveNew'                      => 'edit',
        'Bdus\\Controllers\\Geoface::updateGeometry'               => 'edit',
        'Bdus\\Controllers\\Geoface::eraseGeometry'                => 'edit',
        'Bdus\\Controllers\\Vocabularies::add'                     => 'edit',
        'Bdus\\Controllers\\Vocabularies::sort'                    => 'edit',
        'Bdus\\Controllers\\Vocabularies::edit'                    => 'edit',
        'Bdus\\Controllers\\Vocabularies::erase'                   => 'edit',
        'Bdus\\Controllers\\Import::getTableFields'                => 'edit',
        'Bdus\\Controllers\\Import::previewFile'                   => 'edit',
        'Bdus\\Controllers\\Import::previewPhotos'                 => 'edit',
        'Bdus\\Controllers\\Import::importData'                    => 'edit',
        'Bdus\\Controllers\\Import::importGeoJson'                 => 'edit',
        'Bdus\\Controllers\\Import::importPhotos'                  => 'edit',

        // ── Admin (privilege ≤ 10) — user management and operational tasks ───
        'Bdus\\Controllers\\Home::getMigrations'                   => 'admin',
        'Bdus\\Controllers\\User::showList'                        => 'admin',
        'Bdus\\Controllers\\User::showUserForm'                    => 'admin',
        'Bdus\\Controllers\\User::saveUserData'                    => 'admin',
        'Bdus\\Controllers\\User::deleteOne'                       => 'admin',
        'Bdus\\Controllers\\User::getTablePrivileges'              => 'admin',
        'Bdus\\Controllers\\User::saveTablePrivilege'              => 'admin',
        'Bdus\\Controllers\\User::deleteTablePrivilege'            => 'admin',
        'Bdus\\Controllers\\ConfirmAdminPwd::check_pwd'      => 'admin',
        'Bdus\\Controllers\\Backup::listBackups'                   => 'admin',
        'Bdus\\Controllers\\Backup::doBackup'                      => 'admin',
        'Bdus\\Controllers\\Backup::deleteBackup'                  => 'admin',
        'Bdus\\Controllers\\Backup::restoreBackup'                 => 'admin',
        'Bdus\\Controllers\\Backup::downloadBackup'                => 'admin',
        'Bdus\\Controllers\\Debug::getLogs'                        => 'admin',
        'Bdus\\Controllers\\Debug::purgeLogs'                      => 'admin',
        'Bdus\\Controllers\\Api::listKeys'                         => 'admin',
        'Bdus\\Controllers\\Api::createKey'                        => 'admin',
        'Bdus\\Controllers\\Api::revokeKey'                        => 'admin',
        'Bdus\\Controllers\\Api::deleteKey'                        => 'admin',

        // ── Super-admin (privilege = 1) — schema config and raw SQL ──────────
        'Bdus\\Controllers\\Config::getAppProperties'              => 'super_admin',
        'Bdus\\Controllers\\Config::save_app_properties'           => 'super_admin',
        'Bdus\\Controllers\\Config::getTableList'                  => 'super_admin',
        'Bdus\\Controllers\\Config::add_new_tb'                    => 'super_admin',
        'Bdus\\Controllers\\Config::sortTables'                    => 'super_admin',
        'Bdus\\Controllers\\Config::getTableConfig'                => 'super_admin',
        'Bdus\\Controllers\\Config::save_tb_data'                  => 'super_admin',
        'Bdus\\Controllers\\Config::delete_tb'                     => 'super_admin',
        'Bdus\\Controllers\\Config::rename_tb'                     => 'super_admin',
        'Bdus\\Controllers\\Config::getFldStructure'               => 'super_admin',
        'Bdus\\Controllers\\Config::getFldList'                    => 'super_admin',
        'Bdus\\Controllers\\Config::add_new_fld'                   => 'super_admin',
        'Bdus\\Controllers\\Config::save_fld_properties'           => 'super_admin',
        'Bdus\\Controllers\\Config::delete_column'                 => 'super_admin',
        'Bdus\\Controllers\\Config::rename_column'                 => 'super_admin',
        'Bdus\\Controllers\\Config::getGeoFaceConfig'              => 'super_admin',
        'Bdus\\Controllers\\Config::save_geoface_properties'       => 'super_admin',
        'Bdus\\Controllers\\Config::uploadGeoFile'                 => 'super_admin',
        'Bdus\\Controllers\\Config::delete_local_geofile'          => 'super_admin',
        'Bdus\\Controllers\\Config::getValidationReport'           => 'super_admin',
        'Bdus\\Controllers\\Config::fix'                           => 'super_admin',
        'Bdus\\Controllers\\Config::getRelations'                  => 'super_admin',
        'Bdus\\Controllers\\Config::saveRelation'                  => 'super_admin',
        'Bdus\\Controllers\\Config::deleteRelation'                => 'super_admin',
        'Bdus\\Controllers\\FrontpageEditor::saveWelcome'         => 'super_admin',
        'Bdus\\Controllers\\Templates::getTableList'               => 'super_admin',
        'Bdus\\Controllers\\Templates::getTemplateList'            => 'super_admin',
        'Bdus\\Controllers\\Templates::getTemplate'                => 'super_admin',
        'Bdus\\Controllers\\Templates::saveTemplate'               => 'super_admin',
        'Bdus\\Controllers\\Templates::deleteTemplate'             => 'super_admin',
        'Bdus\\Controllers\\Templates::renameTemplate'             => 'super_admin',
        'Bdus\\Controllers\\SearchReplace::getTableList'          => 'super_admin',
        'Bdus\\Controllers\\SearchReplace::getFieldList'          => 'super_admin',
        'Bdus\\Controllers\\SearchReplace::doReplace'             => 'super_admin',
        'Bdus\\Controllers\\FreeSql::verifyPassword'              => 'super_admin',
        'Bdus\\Controllers\\FreeSql::runSql'                      => 'super_admin',

        // ── Zotero integration ────────────────────────────────────────────────
        'Bdus\\Controllers\\Zotero::getLibs'                       => 'admin',
        'Bdus\\Controllers\\Zotero::addLib'                        => 'admin',
        'Bdus\\Controllers\\Zotero::deleteLib'                     => 'admin',
        'Bdus\\Controllers\\Zotero::search'                        => 'edit',
        'Bdus\\Controllers\\Zotero::getLinks'                      => 'read',
        'Bdus\\Controllers\\Zotero::addLink'                       => 'edit',
        'Bdus\\Controllers\\Zotero::editLink'                      => 'edit',
        'Bdus\\Controllers\\Zotero::deleteLink'                    => 'edit',
        'Bdus\\Controllers\\Zotero::syncRecord'                    => 'edit',
        'Bdus\\Controllers\\Zotero::syncAll'                       => 'admin',
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
            $r->addRoute('GET',  '/api/auth/apps',    ['Bdus\\Controllers\\Login', 'listApps']);
            $r->addRoute('POST', '/api/auth/login',   ['Bdus\\Controllers\\Login', 'auth']);
            $r->addRoute('GET',  '/api/auth/refresh', ['Bdus\\Controllers\\Login', 'refresh']);
            $r->addRoute('GET',  '/api/auth/logout',  ['Bdus\\Controllers\\Login', 'out']);

            // ── OAuth2 ────────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/auth/oauth/{provider}/redirect', ['Bdus\\Controllers\\OAuth', 'redirect']);
            $r->addRoute('GET', '/api/auth/oauth/{provider}/callback', ['Bdus\\Controllers\\OAuth', 'callback']);

            // ── Tables / home ─────────────────────────────────────────────────
            $r->addRoute('GET', '/api/tables',     ['Bdus\\Controllers\\Home', 'listTables']);
            $r->addRoute('GET', '/api/migrations', ['Bdus\\Controllers\\Home', 'getMigrations']);

            // ── Info ──────────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/info',     ['Bdus\\Controllers\\Info', 'getInfo']);
            $r->addRoute('GET', '/api/info/app', ['Bdus\\Controllers\\Info', 'getAppInfo']);

            // ── Records ───────────────────────────────────────────────────────
            $r->addRoute('GET',             '/api/record/{tb}/new',           ['Bdus\\Controllers\\Record', 'getRecord']);
            $r->addRoute('GET',             '/api/record/{tb}/{id:\d+}',      ['Bdus\\Controllers\\Record', 'getRecord']);
            $r->addRoute(['GET', 'POST'],   '/api/records/{tb}',              ['Bdus\\Controllers\\Record', 'getRecords']);
            $r->addRoute('GET',             '/api/records/{tb}/export',       ['Bdus\\Controllers\\Record', 'exportRecords']);
            $r->addRoute('POST',            '/api/record/{tb}',               ['Bdus\\Controllers\\Record', 'saveRecord']);
            $r->addRoute('DELETE',          '/api/record/{tb}/{id:\d+}',      ['Bdus\\Controllers\\Record', 'erase']);
            $r->addRoute('GET',             '/api/record/{tb}/templates',     ['Bdus\\Controllers\\Record', 'getTemplates']);
            $r->addRoute('GET',             '/api/record/{tb}/field-options',  ['Bdus\\Controllers\\Record', 'getFieldOptions']);
            $r->addRoute('GET',             '/api/record/{tb}/check-unique',   ['Bdus\\Controllers\\Record', 'checkUnique']);
            $r->addRoute('GET',             '/api/record/{tb}/link-candidates', ['Bdus\\Controllers\\Record', 'searchLinkCandidates']);
            $r->addRoute('GET',             '/api/record/{tb}/deleted',           ['Bdus\\Controllers\\Record', 'getDeletedRecords']);
            $r->addRoute('GET',             '/api/record/{tb}/{id:\d+}/versions', ['Bdus\\Controllers\\Record', 'getVersions']);
            $r->addRoute('GET',             '/api/version/{id:\d+}',              ['Bdus\\Controllers\\Record', 'getVersionDiff']);
            $r->addRoute('POST',            '/api/version/{id:\d+}/restore',      ['Bdus\\Controllers\\Record', 'restoreVersion']);

            // ── Files ─────────────────────────────────────────────────────────
            $r->addRoute('POST',   '/api/record/{tb}/{id:\d+}/file', ['Bdus\\Controllers\\Record', 'uploadFile']);
            $r->addRoute('DELETE', '/api/file/{fileId:\d+}',         ['Bdus\\Controllers\\Record', 'deleteFile']);
            $r->addRoute('POST',   '/api/files/sort',                ['Bdus\\Controllers\\File',   'sortFiles']);

            // ── Stratigraphic Relations (RS) ──────────────────────────────────
            $r->addRoute('POST',   '/api/record/{tb}/rs', ['Bdus\\Controllers\\Record', 'addRs']);
            $r->addRoute('DELETE', '/api/rs/{id:\d+}',    ['Bdus\\Controllers\\Record', 'deleteRs']);
            $r->addRoute('GET',    '/api/rs/matrix',      ['Bdus\\Controllers\\Record', 'getRsMatrix']);

            // ── Manual links ──────────────────────────────────────────────────
            $r->addRoute('POST',   '/api/manual-link',          ['Bdus\\Controllers\\Record', 'addManualLink']);
            $r->addRoute('DELETE', '/api/manual-link/{id:\d+}', ['Bdus\\Controllers\\Record', 'deleteManualLink']);

            // ── Search ────────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/search/{tb}/config', ['Bdus\\Controllers\\Search', 'getAdvancedConfig']);
            $r->addRoute('GET', '/api/search/{tb}/values', ['Bdus\\Controllers\\Search', 'getUsedValues']);

            // ── Users ─────────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/users',                       ['Bdus\\Controllers\\User', 'showList']);
            $r->addRoute('GET',    '/api/user',                        ['Bdus\\Controllers\\User', 'showUserForm']);
            $r->addRoute('GET',    '/api/user/{id:\d+}',               ['Bdus\\Controllers\\User', 'showUserForm']);
            $r->addRoute('POST',   '/api/user',                        ['Bdus\\Controllers\\User', 'saveUserData']);
            $r->addRoute('DELETE', '/api/user/{id:\d+}',               ['Bdus\\Controllers\\User', 'deleteOne']);
            $r->addRoute('GET',    '/api/user/{user_id:\d+}/privileges', ['Bdus\\Controllers\\User', 'getTablePrivileges']);
            $r->addRoute('POST',   '/api/user/{user_id:\d+}/privileges', ['Bdus\\Controllers\\User', 'saveTablePrivilege']);
            $r->addRoute('DELETE', '/api/privilege/{id:\d+}',           ['Bdus\\Controllers\\User', 'deleteTablePrivilege']);

            // ── Configuration ─────────────────────────────────────────────────
            $r->addRoute('GET', '/api/config/app',            ['Bdus\\Controllers\\Config', 'getAppProperties']);
            $r->addRoute('PUT', '/api/config/app',            ['Bdus\\Controllers\\Config', 'save_app_properties']);
            $r->addRoute('GET', '/api/config/tables',         ['Bdus\\Controllers\\Config', 'getTableList']);
            $r->addRoute('POST',   '/api/config/tables',      ['Bdus\\Controllers\\Config', 'add_new_tb']);
            $r->addRoute('POST',   '/api/config/tables/sort', ['Bdus\\Controllers\\Config', 'sortTables']);
            $r->addRoute('GET',    '/api/config/table/{tb}',  ['Bdus\\Controllers\\Config', 'getTableConfig']);
            $r->addRoute('PUT',    '/api/config/table/{tb}',  ['Bdus\\Controllers\\Config', 'save_tb_data']);
            $r->addRoute('DELETE', '/api/config/table/{tb}',  ['Bdus\\Controllers\\Config', 'delete_tb']);
            $r->addRoute('PATCH',  '/api/config/table/{tb}',  ['Bdus\\Controllers\\Config', 'rename_tb']);
            $r->addRoute('GET',    '/api/config/field-structure', ['Bdus\\Controllers\\Config', 'getFldStructure']);
            $r->addRoute('GET',    '/api/config/table/{tb}/fields',              ['Bdus\\Controllers\\Config', 'getFldList']);
            $r->addRoute('POST',   '/api/config/table/{tb}/field',               ['Bdus\\Controllers\\Config', 'add_new_fld']);
            $r->addRoute('PUT',    '/api/config/table/{tb}/field/{fld}',         ['Bdus\\Controllers\\Config', 'save_fld_properties']);
            $r->addRoute('DELETE', '/api/config/table/{tb}/field/{fld}',         ['Bdus\\Controllers\\Config', 'delete_column']);
            $r->addRoute('PATCH',  '/api/config/table/{tb}/field/{fld}',         ['Bdus\\Controllers\\Config', 'rename_column']);
            $r->addRoute('GET',    '/api/config/geoface',     ['Bdus\\Controllers\\Config', 'getGeoFaceConfig']);
            $r->addRoute('PUT',    '/api/config/geoface',     ['Bdus\\Controllers\\Config', 'save_geoface_properties']);
            $r->addRoute('POST',   '/api/config/geofile',     ['Bdus\\Controllers\\Config', 'uploadGeoFile']);
            $r->addRoute('DELETE', '/api/config/geofile',     ['Bdus\\Controllers\\Config', 'delete_local_geofile']);
            $r->addRoute('GET',    '/api/config/validation',      ['Bdus\\Controllers\\Config', 'getValidationReport']);
            $r->addRoute('POST',   '/api/config/validation/fix',  ['Bdus\\Controllers\\Config', 'fix']);
            $r->addRoute('GET',    '/api/config/relations',        ['Bdus\\Controllers\\Config', 'getRelations']);
            $r->addRoute('POST',   '/api/config/relations',        ['Bdus\\Controllers\\Config', 'saveRelation']);
            $r->addRoute('PUT',    '/api/config/relations/{id}',   ['Bdus\\Controllers\\Config', 'saveRelation']);
            $r->addRoute('DELETE', '/api/config/relations/{id}',   ['Bdus\\Controllers\\Config', 'deleteRelation']);

            // ── Admin ─────────────────────────────────────────────────────────
            $r->addRoute('POST', '/api/admin/check-password', ['Bdus\\Controllers\\ConfirmAdminPwd', 'check_pwd']);

            // ── Backups ───────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/backups',                   ['Bdus\\Controllers\\Backup', 'listBackups']);
            $r->addRoute('POST',   '/api/backups',                   ['Bdus\\Controllers\\Backup', 'doBackup']);
            $r->addRoute('DELETE', '/api/backup/{file:.+}',          ['Bdus\\Controllers\\Backup', 'deleteBackup']);
            $r->addRoute('POST',   '/api/backup/{file:.+}/restore',  ['Bdus\\Controllers\\Backup', 'restoreBackup']);
            $r->addRoute('GET',    '/api/backup/{file:.+}/download', ['Bdus\\Controllers\\Backup', 'downloadBackup']);

            // ── Logs ──────────────────────────────────────────────────────────
            $r->addRoute('GET',  '/api/logs',       ['Bdus\\Controllers\\Debug', 'getLogs']);
            $r->addRoute('POST', '/api/logs/purge', ['Bdus\\Controllers\\Debug', 'purgeLogs']);

            // ── Charts ────────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/charts',                  ['Bdus\\Controllers\\Chart', 'listCharts']);
            $r->addRoute('POST',   '/api/charts',                  ['Bdus\\Controllers\\Chart', 'saveChart']);
            $r->addRoute('POST',   '/api/chart/data',              ['Bdus\\Controllers\\Chart', 'getData']);
            $r->addRoute('POST',   '/api/chart/{id:\d+}/share',    ['Bdus\\Controllers\\Chart', 'shareChart']);
            $r->addRoute('POST',   '/api/chart/{id:\d+}/unshare',  ['Bdus\\Controllers\\Chart', 'unshareChart']);
            $r->addRoute('DELETE', '/api/chart/{id:\d+}',          ['Bdus\\Controllers\\Chart', 'deleteChart']);

            // ── Saved queries ─────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/saved-queries',                    ['Bdus\\Controllers\\SavedQueries', 'listQueries']);
            $r->addRoute('POST',   '/api/saved-queries',                    ['Bdus\\Controllers\\SavedQueries', 'saveQuery']);
            $r->addRoute('POST',   '/api/saved-query/{id:\d+}/share',      ['Bdus\\Controllers\\SavedQueries', 'shareQuery']);
            $r->addRoute('POST',   '/api/saved-query/{id:\d+}/unshare',    ['Bdus\\Controllers\\SavedQueries', 'unshareQuery']);
            $r->addRoute('DELETE', '/api/saved-query/{id:\d+}',            ['Bdus\\Controllers\\SavedQueries', 'deleteQuery']);

            // ── API keys ──────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/api-keys',                 ['Bdus\\Controllers\\Api', 'listKeys']);
            $r->addRoute('POST',   '/api/api-keys',                 ['Bdus\\Controllers\\Api', 'createKey']);
            $r->addRoute('POST',   '/api/api-key/{id:\d+}/revoke',  ['Bdus\\Controllers\\Api', 'revokeKey']);
            $r->addRoute('DELETE', '/api/api-key/{id:\d+}',         ['Bdus\\Controllers\\Api', 'deleteKey']);

            // ── History ───────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/history', ['Bdus\\Controllers\\MyHistory', 'getHistory']);

            // ── Welcome / frontpage ───────────────────────────────────────────
            $r->addRoute('GET', '/api/welcome', ['Bdus\\Controllers\\FrontpageEditor', 'getWelcome']);
            $r->addRoute('PUT', '/api/welcome', ['Bdus\\Controllers\\FrontpageEditor', 'saveWelcome']);

            // ── Print templates ───────────────────────────────────────────────
            $r->addRoute('GET',    '/api/templates',                   ['Bdus\\Controllers\\Templates', 'getTableList']);
            $r->addRoute('GET',    '/api/templates/{tb}',              ['Bdus\\Controllers\\Templates', 'getTemplateList']);
            $r->addRoute('GET',    '/api/template/{tb}/{name}',        ['Bdus\\Controllers\\Templates', 'getTemplate']);
            $r->addRoute('POST',   '/api/template/{tb}/{name}',        ['Bdus\\Controllers\\Templates', 'saveTemplate']);
            $r->addRoute('DELETE', '/api/template/{tb}/{name}',        ['Bdus\\Controllers\\Templates', 'deleteTemplate']);
            $r->addRoute('POST',   '/api/template/{tb}/{name}/rename', ['Bdus\\Controllers\\Templates', 'renameTemplate']);

            // ── Geoface ───────────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/geoface',         ['Bdus\\Controllers\\Geoface', 'getGeoJson']);
            $r->addRoute('POST',   '/api/geoface/feature', ['Bdus\\Controllers\\Geoface', 'saveNew']);
            $r->addRoute('PUT',    '/api/geoface/feature', ['Bdus\\Controllers\\Geoface', 'updateGeometry']);
            $r->addRoute('DELETE', '/api/geoface/feature', ['Bdus\\Controllers\\Geoface', 'eraseGeometry']);

            // ── Vocabularies ──────────────────────────────────────────────────
            $r->addRoute('GET',    '/api/vocabularies',        ['Bdus\\Controllers\\Vocabularies', 'list']);
            $r->addRoute('POST',   '/api/vocabularies',        ['Bdus\\Controllers\\Vocabularies', 'add']);
            $r->addRoute('POST',   '/api/vocabularies/sort',   ['Bdus\\Controllers\\Vocabularies', 'sort']);
            $r->addRoute('PATCH',  '/api/vocabulary/{id:\d+}', ['Bdus\\Controllers\\Vocabularies', 'edit']);
            $r->addRoute('DELETE', '/api/vocabulary/{id:\d+}', ['Bdus\\Controllers\\Vocabularies', 'erase']);

            // ── Search & replace ──────────────────────────────────────────────
            $r->addRoute('GET',  '/api/search-replace/tables',          ['Bdus\\Controllers\\SearchReplace', 'getTableList']);
            $r->addRoute('GET',  '/api/search-replace/{tb}/fields',     ['Bdus\\Controllers\\SearchReplace', 'getFieldList']);
            $r->addRoute('POST', '/api/search-replace',                 ['Bdus\\Controllers\\SearchReplace', 'doReplace']);

            // ── Free SQL ──────────────────────────────────────────────────────
            $r->addRoute('POST', '/api/free-sql/verify', ['Bdus\\Controllers\\FreeSql', 'verifyPassword']);
            $r->addRoute('POST', '/api/free-sql/run',    ['Bdus\\Controllers\\FreeSql', 'runSql']);

            // ── Data import ───────────────────────────────────────────────────
            $r->addRoute('GET',  '/api/import/{tb}/fields',  ['Bdus\\Controllers\\Import', 'getTableFields']);
            $r->addRoute('POST', '/api/import/preview-file', ['Bdus\\Controllers\\Import', 'previewFile']);
            $r->addRoute('POST', '/api/import/preview-photos', ['Bdus\\Controllers\\Import', 'previewPhotos']);
            $r->addRoute('POST', '/api/import/data',         ['Bdus\\Controllers\\Import', 'importData']);
            $r->addRoute('POST', '/api/import/geojson',      ['Bdus\\Controllers\\Import', 'importGeoJson']);
            $r->addRoute('POST', '/api/import/photos',       ['Bdus\\Controllers\\Import', 'importPhotos']);

            // ── Widgets ───────────────────────────────────────────────────────
            $r->addRoute('GET', '/api/widgets',       ['Bdus\\Controllers\\Widget', 'listWidgets']);
            $r->addRoute('GET', '/api/widget/{name}', ['Bdus\\Controllers\\Widget', 'serveWidget']);

            // ── New application wizard ────────────────────────────────────────
            $r->addRoute('GET',  '/api/new-app/status', ['Bdus\\Controllers\\NewApp', 'getStatus']);
            $r->addRoute('POST', '/api/new-app',         ['Bdus\\Controllers\\NewApp', 'create']);

            // ── Zotero integration ────────────────────────────────────────────
            $r->addRoute('GET',    '/api/zotero/libs',              ['Bdus\\Controllers\\Zotero', 'getLibs']);
            $r->addRoute('POST',   '/api/zotero/lib',               ['Bdus\\Controllers\\Zotero', 'addLib']);
            $r->addRoute('DELETE', '/api/zotero/lib/{id:\d+}',      ['Bdus\\Controllers\\Zotero', 'deleteLib']);
            $r->addRoute('GET',    '/api/zotero/search',            ['Bdus\\Controllers\\Zotero', 'search']);
            $r->addRoute('GET',    '/api/zotero/links/{tb}/{id:\d+}', ['Bdus\\Controllers\\Zotero', 'getLinks']);
            $r->addRoute('POST',   '/api/zotero/link',              ['Bdus\\Controllers\\Zotero', 'addLink']);
            $r->addRoute('PATCH',  '/api/zotero/link/{id:\d+}',     ['Bdus\\Controllers\\Zotero', 'editLink']);
            $r->addRoute('DELETE', '/api/zotero/link/{id:\d+}',     ['Bdus\\Controllers\\Zotero', 'deleteLink']);
            $r->addRoute('POST',   '/api/zotero/sync/{tb}/{id:\d+}', ['Bdus\\Controllers\\Zotero', 'syncRecord']);
            $r->addRoute('POST',   '/api/zotero/sync',              ['Bdus\\Controllers\\Zotero', 'syncAll']);
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
