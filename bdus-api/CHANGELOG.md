# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.7] - 2021-04-17
### Added
- Added support for literal fieldnames (autojoin disabled) in ShortSQL: prefixed by `^`

### Fixed
- Record\Read::getBackLinks uses literal fieldname in ShortSQL
- Fixed bug with ShortSQL returning the same records many times when search on plugins or geodata is performed

## [4.0.6] - 2021-04-11
### Fixed
- Fixed bug introduces with QueryObject code cleaning, and auto_joining not being enabled bu default
- Fixed bug with plugins first record being marked as core
- Fixed bug with plugins not being saved
- Fixed bug with uid not being set on template files
- Many syntax typos fixed

## [4.0.5] - 2021-04-11
### Added
- Changelog visible in app and markdown parser added to composer

### Fixed
- Fixed bug with Links::showCoreLinks not disabling autojoin for shortSql
- Fixed bug with QueryFromRequest::advSearch non opening bracket
- Fixed issue with legacy saved queries with empty values

## [4.0.4] - 2021-04-05
### Fixed
- Fixed issue with Template::value returning null

## [4.0.3] - 2021-04-05
### Fixed
- Fixed issue with adding new record throwing error
- Bigger text for choose application input in login page

## [4.0.2] - 2021-04-04
### Changed
- Cleaner parameter's definition for \Record\Read: first is always integer, second always string

## [4.0.1] - 2021-04-01
### Changed
- Added vendor/**/docs to .gitignore
- Remove manual correction of WKT coordinates
- Config::__construct throws Exception instaead of silently adding errors
- Updated datatables.net from v1.10.23 to v1.10.24
- Updated datatables.net-bs from v1.10.23 to v1.10.24
- Updated datatables.net-plugins from v1.10.22 to v1.10.24
- Updated jquery from v3.5.1 to v3.6.0
- Updated guzzlehttp/psr7 from v1.7.0 to v1.8.1

### Fixed
- Fixed bug with Links::showCoreLinks silently failing
- Fixed bug with automatic replace of legacy template class span-{n} with col-sm-{n} acivated by default
- Addedd funiq/geophp files to git
- utils::debug was not defined as static and was throwing warning
- Typo fix in Changelog

## [4.0.0] - 2021-03-26
### Added
- Almost complete refactoring of PHP scripts
- Added support for MySQL and PostgreSQL
- Added full support for application creation and full management via GUI
- Added support for ShortSQL in API
- Added Changelog
- Add application validation and error fix
- Added Gulp.js for js and css minification
- Added @fancyapps/fancybox
- Added method Controller::response replacing utils::response
- Added depencency spatie/db-dumper
- New error handling using Monolog
- Composer is used for php dependencies
- Security check before accessing system configuration

### Changed
- Deprecated symm/gisconverter replaced by phayes/geophp
- Read::getBackLinks and Read::getLinks return ShortSQL in where key
- cache and cache/img are required directories
- Changed parameter order in QueryObject::setField. Tb is now last parameter
- Missing JS file throw error 404 in .htaccess
- Updated favicon
- Updated dataTables and Sortable
- Refactored file thumbnails
- Composer updated
- Enhanced error reporting
- License changed to GNU AGPL-3.0
- Image (thumbnail) path is set relatively
- Error reporting is set to none in prod and E_ALL & ~E_WARNING & ~E_NOTICE in dev
- Default error reporting is set to: E_ALL & ~E_WARNING & ~E_NOTICE
- Rewritten .htaccess
- chart.sql renamed to chart.sqltext
- Enhaced inline documentation for constants and index
- Enhaced inline documentation for Bdus\App
- Table creation function also creates service columns for plugin tables
- Change column name regex: allowed underscores and min length is set to 2
- package.json in version control
- OSM tiles loaded over https
- Updated Record\Edit and Record\Persist and added Record\Edit::persist method
- New logo
- Record\Read parameters follow little endian order
- Replaced glyphicon with font-awesome
- Javascript moved to js file in vocabularies module
- jquery-sortable replaced by sortable.js
- Font Awesome updated from 4.3.0 to 4.7.0 and loaded from node
- Select2 updated from 4.0.3 to 4.0.13 and loaded from node
- DataTables updated from 1.9.4 to 1.10.22 and loaded from node
- Composer updated
- Same icon for export feature
- Find and replace looks also in plugins
- New template system used in production
- Record\Read::getPlugins renamed Record\Read::getPlugin
- Record\Read supports id_field
- Declare paramaters in record_ctrl
- New column layout for myTemplate module
- Infinte scroll renamed to Infinite scrolling
- Record\Read uses paramater-less methods
- Record\Read uses internal cache
- Config links uses only drop-down menus for fields
- Refactored Export
- Main config db_engine is set to sqlite, if db file exists and cfg is not set (v3 compatibility issue)
- Refactored backup
- Backup uses spatie/db-dumper
- version.user renamed to version.userid
- Namespaced usage cases of pref
- Namespaced usage cases of utils
- Vocabulary definition renamed to item
- utils::dirContent ignores .git
- New login UI
- utils::is_online does not depend on bradypus.net domain
- Explicitly set class form-control on inputs
- Boostrap 3.3.5 updated latest v3 branch 3.4.1
- JS depencencies as node dev dependencies
- Updated jquery from 2.1.1 to 3.5.1
- pnotify replaced by izitoast
- Translation enhanced
- Removed support for tr::sget replaced by tr::get with second optional argument
- Removed support for tr::show and replaced with echo tr::get
- ReadRecord renamed to \Record\Read
- Controller has not direct access to GET / POST / REQUEST
- Google Analytics id is now an application level option
- Cleaner login ui
- Application dies if project folders are not writtable
- Cleaned GeoFace: load valid local layers automatically: no table settings

### Removed
- Removed support for API version 1 and 2 and versioning
- Removed in-app docs in favour of documentation site (docs.bdus.cloud)
- Removed usage of APP constant outside index.php and constants.php
- Removed usage of DIRECTORY_SEPARATOR
- Reduced use of constants in modules
- Removed constant LOCALE_DIR
- Removed constant MOD_DIR
- Removed constant LIB_DIR
- Dropped support for PROJ_TMP_DIR, replaced by system tmp dir
- Dropped support jquery.insertAtCaret.js
- Dropped support for PHP minification of CSS and JS
- Dropped support images class
- Dropped support in file.php for images class
- Some Notices suppressed
- Dropped support for local session dir
- Removed class cfg
- Removed prefix from table name in permalinks
- Removed print.simpleSum
- Removed support for geodata.geo_el_elips and geodata.geo_el_asl
- Dropped support for multiupload
- Disabled backup restore for pgsql
- Removed BackupMySQL
- Removed support for User class
- Removed support for bootstrap-datepicker for HTML5 input date
- Removed support for bootstrap-slider for HTML5 input range
- Removed unnecessary usage of query PDO::FETCH_NUM
- Removed support for download Harris Matrix
- Dropped support for FTP sync
- Removed support for most recent query
- Removed support for GET/POST/REQUEST access in modules
- Removed support for .html templates. Only .twig extension is supported
- Removed backtick syntax from SQL
- Dropped support for Vocabulary class
- Dropped support for Database import
- Removed support for Meta class
- Removed support for myException
- Removed system constants from DB_connection
- Dropped support for controllers not extending Controller
- Removed supporto for Pelagios Imperium (not available anymore) and updated DARE Imperium Url

### Security
- Conditional logging in DB
- Secured API access


## [3.15.17] - 2021-01-07
### Fixed
- Fixed bug with Vocabulary manager

## [3.15.16] - 2020-08-31
### Fixed
- Fixed bug with QueryBuilder

## [3.15.11] - 2020-08-10
### Fixed
- Fixed issue with countable not initialized

## [3.15.10] - 2020-08-06
### Fixed
- readRecord::getFiles uses JOIN and solves issue with sorting

## [3.15.9] - 2020-07-19
### Changed
- ShortSqlToJson uses ReadRecord::getFull instead of Record::readFull

## [3.15.8] - 2020-07-19
### Fixed
- Fixed bug with API v2 post processing data

## [3.15.7] - 2020-07-18
### Fixed
- RS element of ReadRecord::getFull (api2::read)depends on system cfg

## [3.15.6] - 2020-07-11
### Changed
- Better management of PDO options

## [3.15.5] - 2020-07-11
### Fixed
- Bug fixed with DSN string creation

## [3.15.4] - 2020-07-11
### Added
- Inline docs for ShortSQL

## [3.15.3] - 2020-07-11
### Fixed
- Fixed typo in ShortSQL docs

## [3.15.2] - 2020-07-05
### Fixed
- Fixed minor GUI bug in SqlExpert module

## [3.15.1] - 2020-07-05
### Fixed
- Fixed bug History showing error log

## [3.15.0] - 2020-07-01
### Removed
- Removed support for constants PROJ_CFG_TB, PROJ_CFG_APPDATA, PROJ_CFG_DIR, PROJ_TMPL_DIR, PROJ_EXP_DIR, PROJ_BUP_DIR, PROJ_FILES_DIR, PROJ_GEO_DIR, PROJ_DB.

### Changed
- Enhanced error reporting
- DB connection information for engines other than sqlite are located in app_data.json
- Throwable catched in index and controller

### Fixed
- Fixed bug with password recover


## [3.14.0] - 2020-01-20
- Api2::getUniqueValue uses Short Sql syntax
- Fixed bug with debug module
- Refactor QueryBuilder and added JOIN support
- Api::getUniqueVal extracted as new class
- Api::getVocabulary uses Vocabulary class
- Introduced class SqlFromStr in Api::getVocabulary and Api::Search


## [3.13.11]
### Fixed
- Fixed bug with getFullFiles method

## [3.13.10]
### Fixed
- Fixed bug with duplicate values in api2::getUniqueVal

## [3.13.9] - 2020-06-05
### Fixed
- Fixed bug not allowing new vocabularies to be defined if no vocabularies are available

## [3.13.8] - 2020-05-02
- Removed app_data.search_code

## [3.13.7] - 2020-03-03
### Fixed
- Fixed bug with geoface not pasing table variable

## [3.13.6] - 2019-12-13
### Changed
- Api::getVocabulary return max 500 records, sorted by `sort`

## [3.13.5] - 2019-12-03
- Fixed bug with id_from_tb referencing same tables multiple times for same record

## [3.13.4] - 2019-11-19
### Added
- New API method getVocabulary

## [3.13.3] - 2019-10-23
### Added
- Inline docs enhanced & minors fixes in User class

### Fixed
- Fixed issue with incorect initialization of Meta DB
- Fixed minor undefined Constat issue

## [3.13.2] - 2019-08-06
### Fixed
- Fixed minor bug in support for automated internal links in the form of @tb.id or @tb.id[label]

## [3.13.1] - 2019-07-16
### Fixed
- Fixed bug with JOIN statement in read record, api version 2

## [3.13.0] - 2019-07-12
### Added
- It is possible to add reference to internal elements via @{table-name}.{record-id} syntax

## [3.11.3] - 2019-07-10
### Fixed
- Fixed bug with toGeoJson accepting now complex geometry field name

## [3.11.2] - 2019-07-09
### Fixed
- Fixed bug with ReadRecord::getTbRecord

## [3.11.1] - 2019-07-01
### Changed
- In GeoJSON export process single row error does not block entire process

## [3.11.8]
### Removed
- Removed hardcoded prefix divider (__)

## [3.11.7] -  2019-01-23
### Changed
- Files are sorted in ReadRecords

## [3.11.6] - 2019-01-23
### Fixed
- Fixed bug with getFiles in API

## [3.11.5] - 2018-10-21
### Changed
- Plugin records are ordered using sort, if available

### Fixed
- Bug fixed with getUniqueVal in id_from_tb fields

## [3.11.4] - 2018-09-17
- Meta::addVersion is run before edit query is executed

## [3.11.3] - 2018-09-17
### Added
- API inspect outputs also plugin fields

## [3.11.2] - 2018-09-14
### Added
- Added support for getUniqueVal API method
- New API method getChart
- New API method inspect all, and more information returned in view record method
### Changed
- Backlinks return `DISTINCT` values

## [3.11.1] - 2019-06-22
### Changed
- Added support for a more granulated error & history log

## [3.10.5] - 2019-12-13
### Fixed
- Fixed bug with force update

## [3.10.5] - 2019-12-03
### Changed
- Updated info

## [3.10.3] - 2019-12-03
### Added
- Added field setting force_default

## [3.10.2] - 2019-12-03
### Changed
- Def_value is now available also in editing mode

## [3.10.1] - 2019-06-28
### Changed
- In GeoJSON export process single row error does not block entire process

### Fixed
- Fixed bug with version::current

## [3.10.0] - 2019-06-28
### Changed
- Images are down-sized to 1500px by default. The value can be changed in app data

## [3.9.8] - 2019-05-20
### Added
- Added control for sessions folder, created on the fly, if not available

## [3.9.7] - 2019-05-18
### Changed
- Set default field order (id) for tables with order not set

## [3.9.6] - 2019-05-14
### Fixed
- Fixed bug with ambiguous field name in Query::setOrder

## [3.9.5] - 2018-08-29
### Added
- Added support for getChart method
- Added support for preprocess in API
- API's geojson param supports GET & POST
- Encoded query in api supports GET & POST
- Addedd support for geojson export
- API v2 implemented verb inspect
- API v2 implemented and tested read method
- Added support for API v2
- Version info added
- Table label is added to corelinks array
- Added support for backlinks in view record API method
- Added metadata section in the view record object with full information about the referenced table
- Added support for inspect all in API + enhanced docs
- Added Meta::logError & Meta::logException
- New error log module
- Automatically load modules
- Added method Meta::guessTable
- Added support for a more granular error & history log

### Changed
- Empty rows are excluded in advanced search
- Updated support for query_where in response
- Enhanced error reporting in API v2
- Enhanced error reporting in API
- Better control of backlinks
- Input class instace can be Exception or Error
- Enhanced error log, removing redundancy
- getTraceAsString replaced by full trace export
- Insert queries are excluded from versoning
- Page parameter is always converted to integer and is bigger than 0
- Fatal errors are captured and logged in catch group
- Empty queries are not run
- Query can be null
- Added possibility to run modules without need of js files
- Module debug fetches data from Meta table
- error_log replaced by Meta::addErrorLog
- My history gets data from Meta
- DB uses Meta for record history and error log

### Removed
- Removed support for self refering joins
- Empty links and plugins are not returned
- Files are removed from from manual links
- Deleted useless js debug modules
- Dropped support for utils::packlog
- Dropped support for logs packing and user log managed by Meta
- Dropped support for constant PROJ_HISTORY
- Dropped support for history::autopack and history delete

### Fixed
- Fixed bug with chart deletion
- Fixed bug with history file size being compressed
- Minor fixes in autoloader
- Typo fix
- Fixed bug with Where statement repeated
- Inline docs enhanced
- Bug fixed with wrong filter

## [3.9.4] - 2018-06-22
### Fixed
- Fixed bug with history file size being compressed

## [3.9.3] - 2018-05-07
### Added
- Added support for data preprocess & manipulation in API

## [3.9.2] - 2018-05-07
### Added
- Added support for data postprocess & manupulation in API

### Changed
- History log files are gzipped and backed up if bigger then 1Mb

## [3.9.1] - 2018-05-03
### Added
- Added support for data postprocess & manipulation in API

## [3.8.17] - 2018-05-03
### Changed
- Autoloader supports both php & inc extensions

## [3.8.16] - 2018-05-02
### Added
- Implemented verb inspect in API
- Api support for geoJSON
- Added docs for geoJSON
- Added support for sorting plugins items using the `sort` field

### Changed
- Simplified geoJSON creation

### Fixed
- Fixed bug with multi select fields in plugins


## [3.8.15] - 2018-04-17
- Simplified geoJSON creation

## [3.8.14] - 2018-03-26
- Added support for sorting plugins items using the `sort` field

## [3.8.13] - 2018-03-15
- Fixed bug with multi select fields in plugins

## [3.8.12] - 2018-03-08
- New SQL query for matrixes

## [3.8.11] - 2018-03-08
- All delete errors are logged

## [3.8.10] - 2018-03-01
- Fixed bug with js clone method

## [3.8.9] - 2018-02-11
- API supports limit_start and limit_end parameters
- Per table options show after data is loaded

## [3.8.8] - 2017-12-31
- Fixed bug with vocabulary lit not being editable

## [3.8.7] - 2017-12-13
- Added foreign keys constraint for sqlite

## [3.8.6] - 2017-12-11
- Fixed bug with query pagination
- Fixed bug Multiple records view
- Fixed bug with stratigraphic relationship module non running with multiple records
- Fixed bug with Print all button not working with multiple records


## [3.8.5] - 2017-12-02
- Added support for limiting in queries & API
- Added support for grouping in API
- Added support for grouping in queries

## [3.8.4] - 2017-11-25
- No round corners in layout
- Added table_label in Api
- Integers in app name in api call

## [3.8.3] - 2017-11-13
- Added backlinks support
- Fixed backlink bug

## [3.8.2] - 2017-10-12
- Google maps key moved to main application data

## [3.8.1] - 2017-10-11
- New Imperium (Pelagios & DARE) and AWMC basemaps to Geoface

## [3.7.3] - 2017-10-10
- jQuery change observer replaced with on('change'
- Fixed bug with ambiguous plugin field names

## [3.7.2] - 2017-09-28
- Log file's max size lowered to 3 mb

## [3.7.1] - 2017-09-24
- Added new fullrecords parameter in API
- If GoogleMaps key is missing google layers will not load by default
- Addedd https support for domain db.bradypus.net
- Bug fixed in Query::getResults()



## [3.7.0] - 2017-09-23
- Added support for csv, gpx, kml, wkt, topojson, geojson
- Updated version of Leaflet Omnivore
- Removed support for EPSG other then 4326
- Removed support for openLayers geoface and updated leaflet geoface with support for googlemaps key
- Leaflet updated to v 1.2.0
- Leaflet KML replaced by Leaflet Omnivore
- Leaflet.Draw updated to v.0.4.12 (from v. 0.2.0-dev)
- Leaflet updated to v 1.2 (from v. 0.7.2)
- Geoface2 renamed to geoface
- Removed support for OpenLayers geoface
- Leaflet GoogleMutant replaces Leaflet Google
- Added support for GoogleMaps key


## [3.6.2] - 2017-09-15
- Bug fixed with Advanced search inputs

## [3.6.1] - 2017-09-12
- API: q_encoded accepts fields and join parameters
- API: added the possibility to list output fields in url
- Added sqlExpert method to API
- Field list is get fromQuery object

## [3.5.11] - 2017-09-09
- Fixed bug with enhanced inputs not extending to 100% width
- menuValues::getValues groups values for get_values_from_tb

## [3.5.10] - 2017-09-05
- Bug fixed with plugins having custom template file (index was not spread to the whole template)

## [3.5.9] - 2017-08-18
- Aborted XHR calls do not throw errors anymore

## [3.5.8] - 2017-07-24
- Geodata can be entered as plugin data
- Added direction: rtl option for html.fld for right-to-left scripts

## [3.5.7] - 2017-07-10
- New API documentation
- Disabled advanced and sqlExpert queries. Encoded should be used instead of them
- A cleaner e more (inline) documented version of the API is available
- Removed php_value upload_max_filesize and php_value post_max_size 16M from .htaccess file

## [3.5.6] - 2017-06-21
- Bug fixed with advanced search (select2 inputs) interface

## [3.5.5] - 2017-06-28
- New template function Fieled::value to get nude value of a field

## [3.5.4] - 2017-06-21
- Bug fixes: Record::getAllPlugins was not loading plugins due to a comparison error
- Removed custom uid template parameter
- Added join support

## [3.5.3] - 2017-06-14
- Bug fixes: select inputs have now Bootstrap's class form-control

## [3.5.2] - 2017-06-12
- New feature: advanced query ui supports auto-population for fields id_from_tb
- New feature: advance query works now also for fields populated by id form other tables
- Fields marked as id_from_tb show now correct values instead of ids in read/edit mode
- All select input have a blank option as first value
- Bug fixed with combobox not accepting user custom values

## [3.5.1] - 2017-06-05
- Bug fixed with menuValues not setting the ids correctly

## [3.5.0] - 2017-06-02
- New backlinks functionality, for records referred by 1-n plugins
- All dropdown menus are handled via AJAX
- Select2 updated to version 4.0.3
- Added new QueryBuilder class
- Added Controller::json method to echo json data with correct header


## [3.4.7] - 2017-05-13
- First draft of API markdown docs
- Addedd AWMS and Imperium basemaps to GeoFace
- Record::delete and Record::fullFiles is value wrapped in single quotes

## [3.4.6] - 2017-04-26
- Bug fixed with userlinks using first cell of row for row id. The tr#id is used now
- Userlink actions linked to buttons in UI are now namespaced: multiple actions were attached to same button

## [3.4.5] - 2017-02-04
- Field help is now tooltip content not title
- Plugin templates have automatic unique id template variable

## [3.4.4] - 2017-01-08
- Text linkify function considers also https protocol

## [3.4.3] - 2016-09-29
- Plugin fields in Advanced query UI bear the name of the plugin

## [3.4.2] - 2016-09-19
- Minor bug fix in main CSS file
- JS & CSS compressors and outputers use sha256 algorithm to check changes in files

## [3.4.1] - 2016-09-05
- Fixed translation error in Vocabularies module
- Bug fixed with Record::loadAllPlugins
- A more detailed error report in class Query
- API uses Access-Control-Allow-Origin: *instead of jsonp

## [3.4.0] - 2016-09-03
- Minor bug with regex check: empty values are not tested
- New function: the virtual keyboard is now an option of main application, by default turned off
- New function: new search input in home page to easily find a function
- Debug mode is handled via DEBUG_ON constant
- Translation strings are not stored in SESSION variable anymore
- Configuration data are not stored in SESSION variable anymore
- Added docx, xlsx and pptx to known file extensions

## [3.3.9] - 2016-05-26
- Fixed bug with spaces and other special chars used in fields which content is used in RS module

## [3.3.8] - 2016-04-02
- Fixed minor graphical issue: image thumbnails without proper margin

## [3.3.7] - 2016-03-14
- Minified js and css are refer to main version number when loaded
- Bug fixed: date-picker on cloned inputs was not being destroyed and reinitialized

## [3.3.6] - 2016-02-03
- Session files older than 24h will be deleted on logout

## [3.3.5] - 2016-02-02
- Bug fixed with error in deleting user

## [3.3.4] - 2015-12-27
- Minor graphical improvements in Geodata Import Module

## [3.3.3] - 2015-07-05
- ID columns are hidden from result tables if id column is not used as user column (is hidden in cfg file)

## [3.3.2] - 2015-07-05
- FTP sync enhancement

## [3.3.1] - 2015-07-05
- Twig upgraded to v. 1.18.2
- Font Awesome updated to version 4.3.0
- Twitter Bootstrap updated to v. 3.3.5
- Production ready javascript included in git
- Bug fixed with icon path
- Added to git production ready css file

## [3.3.0] - 2015-07-05
- Added new utility class for project structure check
- Added support for Italian in getIP module (windows only)
- Minor style fixes in multiupload module
- Removed composer
- Removed rjkip/ftp-php from composer
- Removed symm/gisconverter from composer
- Removed ojejorge/less.php from composer
- Removed Twig from composer
- Upgraded Twig to v. 1.16.2
- Upgraded oyejorge/less.php to v1.7.0.2

## [3.2.11] - 2014-12-17
- Bug fixed with Matrix error report

## [3.2.10] - 2014-10-12
- API accepts new optional GET parameter: records_per_page (default value: 30)

## [3.2.9] - 2014-07-29
- Bug fixed with javascript compressing tool

## [3.2.8] - 2014-07-27
- Bug fixed with user privilege management in login module

## [3.2.7] - 2014-06-27
- Font Awesome updated to version 4.1.0

## [3.2.6] - 2014-06-27
- Bootstrap updated to version 3.2

## [3.2.5] - 2014-06-27
- Bug fixed with offline and frozen apps

## [3.2.4] - 2014-06-27
- Removed offline status option

## [3.2.3] - 2014-06-22
- Bug fixed with user template setting

## [3.2.2] - 2014-06-12
- Bug fixed with reset password module

## [3.2.1] - 2014-05-18
- Bug fixed with user data editing module
- Bug fixed with default value field setting not saving properly in the database
- Bug fixed with advanced search templates, still looking for .html templates
- Bug fixed with result UI component: Harris' Matrix icon was not showing
- Bug fixed with Field::permalink. Main div element was not closed
- Bug fixed with utils::recursiveFilter

## [3.2.0] - 2014-05-03
- New feature: added the possibility to upload and link to existing records external geoJSON files
- Minify_CSS_Compressor deprecated. oyejorge/less.php is now used for LESS compilation and CSS minification
- jQuery updated to v 2.1.1
3.2.0[] =  "New mode for handling compressed javascript files
- Leaflet updated to v 0.7.2
- Leaflet.Draw updated to v. 0.2.0-dev
- Font Awesome updated to v. 4.0.3
- Changed all .html extensions in modules template files to .twig
- Updated all code headers with new license file

## [3.1.27] - 2014-05-03
- Code inline documentation
- utils::recursiveFilter accepts now a second, optional, argument to use as filter function
- Log compression is performed also on cookie authentication
- User information is now logged also on cookie authentication

## [3.1.26] - 2014-04-27
- Bug fixed with record::showResults method, ignoring select_one parameter

## [3.1.25] - 2014-04-27
- Minor bug fixed with Geoface 2 onclick popups: no link is showed in the popup window

## [3.1.24] - 2014-04-27
- Query::getResults returns more complete results about records

## [3.1.23] - 2014-04-27
- Minor bug solved on login module: app name was not correctly transmitted

## [3.1.22]
- Geojson layers in Geoface 2 module show now popup infowindows on click

## [3.1.21] - 2014-03-25
- Bug fixed with API module and RS plugin

## [3.1.20] - 2014-03-22
### Changed
- jQuery updated to v.2.1.0
- Twitter Bootstrap updated to v.3.1.1

## [3.1.19]
### Fixed
- Compatibility fixes: multiupload template files have now .twig extension
- Minor code layout fixes
- Bug fixed with show saved query results triggered by hash data

## [3.1.18]
### Added
- uid variable available in all application templates
### Fixed
- Minor code layout fixes in Controller
- Minor layout fixes in Backup module
- Bug fixed with myHistory modal rendering

## [3.1.17]
### Added
- New API function: #/app/table/{id} hash show record by id #/app/table/{id}/id shows record by id field

## [3.1.16]
### Changed
- Vector layer of GeoFace (2) is now rendered using circles, not icons
- Minor UI and code layout fixes
- New versioning system

## [3.1.15]
### Fixed
- Minor layout fix in Chart module
- Bug fixed with user permissions (R&W own records users)
- Minor typo fix
- Bug fixed with multiselect fieldtype in read mode (dynamic linking)


## [3.1.14]
### Fixed
- Bug fixed with date format


## [3.1.13]
### Fixed
- Bug fixed with main record controller: ID and ID field were confused


## [3.1.12]
### Changed
- Multiselect values are now processed separately in read and edit mode

## [3.1.11]
- Bug multiple record read mode (last record was missing)

## [3.1.10]
- Bug fixed wit boolean field types
- Links now show link to Harris' Matrix, if destination table has Stratigraphic Relationship plugin activated

## [3.1.9]
- Removed XML metadata XSD files

## [3.1.8]
- Bug fixed with print all function (read multiple records)
- Controller::render() is initialized with autoescape set to false by default
- Replaced, when possible, manual initialization of Twig with Controller::render() method

## [3.1.7]
- Print style enhancment. Bug fixed with images height and added new css classes in Field to hide in print mode services as geoface, links, permalink, etc.

## [3.1.6]
- Geodata Field element uses now Geoface2 instead of geoface
- Bug fixed with geoface2, not using filter sql
- makeproject class is now part of the core installation

## [3.1.5]
- Critical bug in plugin (template) management: plugins could not be edited or deleted!
- New function: admin users have superadmin privilege in offline installation

## [3.1.4]
- New function: if any field as defined the new cfg value, active_link, in read mode it's content will be active, a link to a query result with similar records will be shown

## [3.1.3]
- Chart builder UI enhancements and new function: Function is now defined for each Bar, not for each chart.

## [3.1.2]
- Inline documentation enhancment to api.js
- Bug fix with dot creation in (Harris) Matrix main class
- Bug fix with matrix svg height in FF (now is set via javascript)
- RS table columns now have equal width

## [3.1.1]
- Bug fix with record/result show query (rev.329)
- Enhanced dynamic linking in read mode from fields valued to other records

## [3.1.0]
- Bug fixed: urlencoded query was nor urlencoded!
- Composer dependency update check (no update performed)
- Local repository name change: v3_final is now v3
- FTP connection is now handled via Composer
- Export GUI used TBS, instead of inline style
- Bug fixed with GeoFace 2 (Leflet). Map initialized with empty DB vector layer had no view /zoom set
- Bug fixed with GeoFace 2 (Leflet). Second initialization did not work
- Bug fixed with Chrome not submitting forms on Enter pressed
- New function in Sync: change online application status.
- Matrix icon changed to font awsome sitemap
- New function: Bi-directiontal sync (super_admin privilege required; available only in offline mode; config FTP connection required)
- Short version of utils::is_online
- Comment added to utils::canUser()
- GET, POST and REQUEST parameters are now optional in class initialization
- Comments added to core.message()
- Composer file added
- New Geoface module added
- External libraries are now managed with Composer
- External libraries are now managed with Composer
- Add new relation form is inline again (BS 3 fix)
- When a relation is successfully added the input will be cleared
- Harris Matrix builder now supports equal relationship
- Minor typo fix
- Error text is displayed in BS alert
- api.record.read makes difference now in tab title in cade of use of ID and ID_FIELD.
- Prefix also is removed from tab title
- Record::addRS removes whitespaces before and after each element to add in database (trim)
- Fixed bug with non existing records
- Updated external code list
- New Harris Matrix module
- Matrix has a new Font Awesome icon
- Bootstrap 3 compatibility and translation fixes
- DB::query's can return a single column, by setting $type to an integer (the offset -0 based- of the column to return)
- New comment for core.getJSON method
- Added new migration script (AD project)
- Added new migration script (AD project)
- Controller::render supports TWIG extension for modules templates
- Bug fixed with fields config: get_values_from_tb will not always check for id_field (plugin tables do not have id_field)
- Local geodata files are loaded only if they are geojson
- Minor typo fixing
- Fixed some typos
- More inline comments
- Record::addGeodata returns last added id, instead of boolean value
- utils::response has now a new argument used to pass default key-values in response
- Removed console log on dialog close
- BS v3 compatibility issue fixed (navbar-default class added to map toolbar)
- Version displayed in login page
- Test module moved in frontpage's Options section
- Bug fixed with combobox and select+ cloned elements; options were doubled.
- method Field::cell($nr) added to interface and commented
- BS Glyphicon instead of Font Awesome icon for multiupload
- BS v3 compatibility issue fixed
- Bug solved with vocabularies control class: method had parameters, now removed
- Bug solved with Advance query brackets: cloned bracked did not have any action defined
- All instances of col-lg-* replaced with col-sm-* (or col-md-*)
- Bootstrap 3 minor comptaibility fixes
- Production divided from development and test environment for js files
- Production divided from development and test environment for css&less files
- Production divided from development and test environment for css&less files
- Bootstrap update
- Production divided from development and test environment for css&less files
- Panel component fixes (div.panel now needs panel-default)
- Bug fixed: rs field was named id_field
- Upgraded to jQuery 2.0.3 (from 1.9.0)
- Minor typo fix
- New tbs_editor module, with new, more readeable, layout
- Solved bug with modal (black background still visible after modal was closed)
- Enlarged input colmn
- Jumbotron application name is now contained in Div.visible.xs
- Bug fixed: backups created from utils::packLog did not have log extension
- Added support for Twig's debug function, in debug mode
- Bug fixed and new function added to myClone
- Bug with jquery ajax solved.
- Compatibility fix for BS 3 (rc2)
- Problem with overflow-x solved
- Bootstrap Update (v3 RC2)
- Upated Info page with new libraries and paths to licenses texts
- PHP-SQL-PARSER moved in dev, not used yet
- New function: All system logs are automatically packed (gzipped), backed-up and emptied if bigger than 5MB, when user logs in
- Resetted test module
- Login style enhancment
- Updated todo list
- Icon play near application name is hidden in small devices
- New feature in Advanced search: get list of used values if needed
- New responsive layout of advanced and expert search
- Removed any reference of col-lg-*; col-sm-* in Bootstrap 3-rc2 is sufficient
- Added Aplication name and description in home page, in phone view
- Updated to Twitter Bootstrap v3 rc2 and some small bug fixing
- Datatables compatible now with Twitter Bootstrap 3
- Using now uncompressed DataTables
- Fixed Datepicker
- Fixed Slider
- Some more changes to meet TB3
- jQuery-UI sorting replaced
- jGrowl replaced
- Boostrap LESS adopted
- All instances of span* replaced
- Many instances of button.btn fixed
- All instances of .icon fixed
- Deleted referenced to jQuery-UI, css, js and images

## [3.0.56]
- Minor bug fixing
- New (superAdmin) function: empty application cache

## [3.0.55]
- Removed deprecated core.myDia()

## [3.0.54]
- Minor bug fixing in record > show

## [3.0.53]
- New re-organized GUI for file & image display & management (Field::image_thumbs)

## [3.0.52]
- api.upload deprecated and removed (commented out)
- api.filUpload accepts new option: button_text

## [3.0.51]
- New functionality: files can be uploaded and automatically linked to records in Edit Record page.

## [3.0.50]
- New functionality: now plugins can have a layout (based on Twig)

## [3.0.49]
- English locale minor fixes

## [3.0.48]
- New function can be used in template to show complex, sql based, sums of fields of from other tables somehow related (see filtering options) to the current record in the current table: <sqlSum tb="other_tb_name" fld="other_table_field_name_whose_values_will_be_summed" filter="some_filtering_options_refer_to_doc_for_more_info" />

## [3.0.47]
- New function can be used in template to show simple sums of fields of the current record in the current table: <simpleSum fields="field1, field2, etc" />

## [3.0.46]
- Minor bug fixed: htmlComboSelect can now have 0 menu values

## [3.0.45]
- Bug fixed cookie authentication

## [3.0.44]
- Bug fixed with sqlite character encoding in migration script

## [3.0.43]
- Documentation enhancment and minor bug fixing with application migration system

## [3.0.42]
- API supports now JSONP instead of JSON cross-domain calls

## [3.0.41]
- Documentation improvement

## [3.0.40]
- Query::getResults now can return full records (with links and images)

## [3.0.39]
- Bug fixed with htmlMultiSelect without values.

## [3.0.38]
- Removed any support for jquery-ui dialog

## [3.0.37]
- Removed jquery.dialogextend.js

## [3.0.36]
- 

## [3.0.35]
- Bug fixed with edit form submission

## [3.0.34]
- New system email GUI

## [3.0.33]
- Bug fixed with cookie authentication (cookie was used by default)
- Bug fixed with cookie authentication (cookie was used by default)

## [3.0.32]
- Javascript core.myDia deprecated!

## [3.0.31]
- Changed myImport and removed core.myDia

## [3.0.30]
- Controller::render now supports Twig's debug functon in debug mode
- Translate function is now automatically added in Controller::render()
- Search&replace functionality does not use anymure jqueryUI dialog

## [3.0.29]
- Locale update
- CSS Bug fixed (real time form control messages)
- New feature added: duplicate records (excluding plugin data and manual links)

## [3.0.28]
- Bug fixed with edits and multiple tabs opened
- minor layout issue

## [3.0.27]
- Bug fixed with new records. Last ID is returned directly from recod object
- Autocomplete on is the default value in forms
- Bug fixed with Fast serach and sql Expert (url en/decode)
- Bug fixed with Advanced query, not empty option
- Bug fixed with search/replace function
- Added new core functionality: API for external access to the data
- Table name added in Advanced search and SQL Expert result tab

## [3.0.26]
- 

## [3.0.25]
- New functionality: preview fields can be configured by each user on users' preference panel

## [3.0.24]
- Online version of Harris matrix creation now relys on BraDypUS public Graphviz API (https://github.com/jbogdani/phpGraphviz)

## [3.0.23]
- New functionality: user can set number of most recent records to show in result view in user's preferences panel

## [3.0.22]
- app_data_editor uses template system
- api.requireRestart uses bootstrap modal instead of jqueryUI dialog

## [3.0.21]
- Bug Fixing: logout did not work if DD data was not initialized properly. New method added that do not use not the DB.
- Bug Fixing: RS links were not styled as links (underline)

## [3.0.20]
- GeoFace accepts x/y user input data and browser (GPS on mobile device) location data.

## [3.0.19]
- (dev) TODO updated
- (dev) TODO updated

## [3.0.18]
- Enhanced and tested Run Free SQL
- Bugfixing in api.fileUpload
- Bugfixing in DB: duQuery now catches PDO exceptions and throws myException
- (dev) TODO update
- (dev) New geolocation (browser) concept

## [3.0.17]
- Index did not included updated reference to fineupload
- Bug in cookie data loading: User preferences was not saved in cookie variable.

## [3.0.16]
- Valums fileuploader updated to fine-uploader 3.4.0
- All file upload references use now a single javascript API (api.fileUpload) and a fingli php processor API (file_ctrl::upload)
- Controller file_ctr extends Controller

## [3.0.15]
- Upload file bug: when a file thet is not an image was loaded(eg. pdf file), No icon was shown in the preview!

## [3.0.14]
- CSS fix: a and span.a in content fields is shown as underlined

## [3.0.13]
- Multiupload procedure uses class Record to save links ad data in the database
- Translation update

## [3.0.12]
- In multiupload procedure links can be added by users using GUI

## [3.0.11]
- On add link result view double click on record (read) is disabled

## [3.0.10]
- Toogle select was repeated two times in Result's navbar menu

## [3.0.9]
- New Charts GUI and API (no myDia)

## [3.0.8]
- New Charts GUI and API (no myDia)
- New multiupload GUI (no myDia)

## [3.0.7]
- layout loaded option has as unique parameter the jQuery object where content is inserted

## [3.0.6]
- Saved Queries and search modules does not use anymore myDia

## [3.0.5]
### Changed
- Locale update
- Debug module does not use any more deprecated myDia

## [3.0.4]
### Changed
- New login GUI, modal-less, for easier visualization on tablet/phone

## [3.0.3]
### Added
- getJSON accepts multi-type get parameters (string, array, plain objects)

### Changed
- Debug info can be send as POST or GET (REQUEST is used instead of GET)
- Render method accepts $data = false
- core.runModule now has a third parameter, loaded, a function that is run after init method is called

### Fixed
- Translation updated
- List of apps in login screen is not a javsacriot function, but a html template
- Compatibility mode for systems missing MySQL
- Login module enhancment: all login functionality is now handled by this module.

## [3.0.2]
### Added
- New functionality: save preferences in database

## [3.0.1]
### Added
- New functionality: remain connected
- Important: record_ctrl now works with request data (both post & get) not only with get
- Combo field type added
- LESS instead of CSS
- Bootstrap update
- uddiyana migration
- Uddiyana migration
- layout.tabs andlayout.dialog accepts plain object as param method
- javascript mini removed from svn
- New tbs_editor GUI (tab based)
- New flds_editore GUI (tab based)
### Fixed
- Minor debug and todo fixes
- Login styling transfered to CSS
- Image preview enhancment (keep image ratio)
- makeProject enhancement: bug fixing and migrateApp method add, for easily migrating existing apps

## [3.0.0]
- New 3 version
