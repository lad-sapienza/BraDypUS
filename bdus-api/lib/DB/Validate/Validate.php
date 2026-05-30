<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Validate;

use DB\Validate\Info;
use DB\Validate\DumpExists;
use DB\Validate\Filesystem;
use DB\Validate\Resp;
use DB\Validate\DbCfgAlign;

use DB\DBInterface;
use Config\Config;

class Validate
{
    private $db;
    private $resp;
    private $cfg;

    public function __construct(DBInterface $db, Config $cfg)
    {
        $this->db = $db;
        $this->resp = new Resp();
        $this->cfg = $cfg;
        /**
         * Checks run by all():
         * 1. Project root .htaccess protects config.json and .jwt_secret
         * 2. App info (name, status, engine)
         * 3. Backup tool availability
         * 4. System tables exist and have the expected columns
         * 5. Config ↔ DB alignment for user tables (fields, db_type)
         */

    }

    public function all(): array
    {   
        $this->resp->set('head', 'Security checks');
        (new Filesystem($this->resp))->cfgDirProtected(); // checks project root .htaccess (v5)

        $this->resp->set('head', 'Main system information');
        Info::getInfo($this->resp, $this->cfg);

        $this->resp->set( 'info', ($this->db->hasSpatialExtension() ? "Spatial extension available" : "Spatial extension NOT available") );

        DumpExists::check($this->resp, $this->db->getEngine());

        $sys = new SystemTables($this->resp, $this->db);
        $this->resp->set('head', 'Check if system tables are available');
        $sys->checkExist();
        $this->resp->set('head', 'Check if system tables structure is up-to-date');
        $sys->latestStructure();
        
        $db_cfg = new DbCfgAlign($this->resp, $this->db, $this->cfg);
        $this->resp->set('head', 'Checking for db_type in configuration');
        $db_cfg->cfgHasDb_type();
        $this->resp->set('head', 'Configuration and database tables alignement');
        $db_cfg->cfgHasDb();
        $this->resp->set('head', 'Configuration and database fields alignement');
        $db_cfg->cfgColsHasDb();

        return $this->resp->get();
    }

}