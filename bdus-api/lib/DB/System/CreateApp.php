<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;


use DB\DB;
use Config\ToDB;

class CreateApp
{
    private $app;
    private $db;
    private $log = [];
    private $email;
    private $password;
    private $sys_manager;
    private $db_data;

    public function __construct(
        string $name, 
        string $definition, 
        string $your_email, 
        string $your_password, 
        string $db_engine,
        string $db_host = null,
        string $db_port = null,
        string $db_name = null,
        string $db_username = null,
        string $db_password = null
    )
    {
        $this->app = $name;
        $this->email = $your_email;
        $this->password = $your_password;

        $this->validateData( $name, $definition, $your_email, $your_password, $db_engine, $db_host, $db_port, $db_name, $db_username, $db_password );
        
        if (!$this->createDir("projects/$name/db")){
            throw new \Exception("Cannot create directory projects/$name/db");
        }
        $this->db = new DB($name, [
            "db_engine" => $db_engine, 
            "db_path" => "projects/$name/db/bdus.sqlite", 
            "db_host" => $db_host, 
            "db_port" => $db_port, 
            "db_name" => $db_name, 
            "db_username" => $db_username, 
            "db_password" => $db_password]);
        
        $this->sys_manager = new Manage($this->db);

        $this->db_data = [
            "definition"    => $definition, 
            "db_engine"     => $db_engine,
            "db_host"       => $db_host,
            "db_port"       => $db_port,
            "db_name"       => $db_name, 
            "db_username"   => $db_username, 
            "db_password"   => $db_password
        ];

    }

    public function getLog():array
    {
        return $this->log;
    }

    public function createAll()
    {   
        // Create files
        $this->testDB();
        $this->createDirs();
        $this->createTables();
        $this->addUser();
        $this->createConfig();
    }

    private function createConfig()
    {
        // ── 1. Write config.json at the project root ──────────────────────────────
        $appData = [
            "lang"         => "en",
            "name"         => $this->app,
            "definition"   => $this->db_data['definition'],
            "status"       => "on",
            "db_engine"    => $this->db_data['db_engine'],
            "db_host"      => $this->db_data['db_host'],
            "db_port"      => $this->db_data['db_port'],
            "db_name"      => $this->db_data['db_name'],
            "db_username"  => $this->db_data['db_username'],
            "db_password"  => $this->db_data['db_password'],
            "maxImageSize" => "1500",
        ];
        @file_put_contents(
            "projects/$this->app/config.json",
            json_encode($appData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        array_push($this->log, "Configuration file projects/$this->app/config.json created");

        // Protect config.json and .jwt_secret from direct web access via <Files>.
        static::writeProjectHtaccess("projects/{$this->app}");
        array_push($this->log, "projects/{$this->app}/.htaccess protection written");

        // ── 2. Seed bdus_cfg_tables + bdus_cfg_fields with the two built-in system tables ──
        // bdus_files (main table — visible in file manager)
        ToDB::upsertTable($this->db, [
            'name'     => 'bdus_files',
            'label'    => 'Files',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id', 'filename', 'ext', 'keywords'],
        ]);
        foreach ([
            ['name' => 'id',          'label' => 'ID',                      'type' => 'text',     'db_type' => 'INTEGER', 'readonly' => true],
            ['name' => 'creator',     'label' => 'Creator',                  'type' => 'text',     'db_type' => 'INTEGER', 'readonly' => true],
            ['name' => 'filename',    'label' => 'Filename',                 'type' => 'text',     'db_type' => 'TEXT',    'readonly' => true, 'check' => ['not_empty']],
            ['name' => 'ext',         'label' => 'Extension',                'type' => 'text',     'db_type' => 'TEXT',    'readonly' => true, 'check' => ['not_empty']],
            ['name' => 'keywords',    'label' => 'Keywords',                 'type' => 'text',     'db_type' => 'TEXT'],
            ['name' => 'description', 'label' => 'Description',              'type' => 'long_text','db_type' => 'TEXT'],
            ['name' => 'printable',   'label' => 'Printable',                'type' => 'boolean',  'db_type' => 'INTEGER'],
        ] as $fld) {
            ToDB::upsertField($this->db, 'bdus_files', $fld);
        }
        array_push($this->log, "bdus_files config seeded in DB");

        // bdus_geodata (plugin table — linked to user tables)
        ToDB::upsertTable($this->db, [
            'name'      => 'bdus_geodata',
            'label'     => 'Geographical coordinates',
            'is_plugin' => 1,
        ]);
        foreach ([
            ['name' => 'id',         'label' => 'ID',              'type' => 'text', 'db_type' => 'INTEGER', 'readonly' => '1', 'hide' => '1'],
            ['name' => 'table_link', 'label' => 'Linked table',    'type' => 'text', 'db_type' => 'TEXT',    'readonly' => '1', 'hide' => '1'],
            ['name' => 'id_link',    'label' => 'Linked id',       'type' => 'text', 'db_type' => 'INTEGER', 'readonly' => '1', 'hide' => '1'],
            ['name' => 'geometry',   'label' => 'Coordinates (WKT)','type' => 'text'],
        ] as $fld) {
            ToDB::upsertField($this->db, 'bdus_geodata', $fld);
        }
        array_push($this->log, "bdus_geodata config seeded in DB");

        @file_put_contents("projects/{$this->app}/welcome.md", "# " . strtoupper($this->app) . "\n\n# A BraDypUS database");
        array_push($this->log, "Welcome page created");
    }

    /**
     * Write (or overwrite) a deny-all .htaccess in the given directory.
     * Works on Apache 2.2 (Order/Deny) and 2.4 (Require all denied).
     *
     * @deprecated Use writeProjectHtaccess() for new apps (post-M018).
     *             Kept for any legacy self-healing paths that may still reference it.
     */
    public static function writeCfgHtaccess(string $dir): bool
    {
        $content = implode("\n", [
            '# Auto-generated by BraDypUS — do not remove.',
            '# This directory contains sensitive configuration and must not be web-accessible.',
            'Order deny,allow',
            'Deny from all',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
        ]);
        return (bool) @file_put_contents($dir . '/.htaccess', $content);
    }

    /**
     * Write (or overwrite) a <Files>-based .htaccess at the project root.
     * Blocks direct web access to config.json and .jwt_secret only,
     * leaving projects/{app}/files/* freely servable.
     */
    public static function writeProjectHtaccess(string $dir): bool
    {
        $content = implode("\n", [
            '# Auto-generated by BraDypUS — do not remove.',
            '# Protects sensitive files from direct web access.',
            '<Files "config.json">',
            '    Order deny,allow',
            '    Deny from all',
            '    <IfModule mod_authz_core.c>',
            '        Require all denied',
            '    </IfModule>',
            '</Files>',
            '<Files ".jwt_secret">',
            '    Order deny,allow',
            '    Deny from all',
            '    <IfModule mod_authz_core.c>',
            '        Require all denied',
            '    </IfModule>',
            '</Files>',
        ]);
        return (bool) @file_put_contents($dir . '/.htaccess', $content);
    }

    private function addUser()
    {
        $this->sys_manager->addRow('bdus_users', [
            'name' => $this->email,
            'email' => $this->email,
            'password' => password_hash($this->password, PASSWORD_BCRYPT),
            'privilege' => 1,
        ]);
        array_push($this->log, "User data added");
    }

    private function createTables()
    {
        $sys_tables = $this->sys_manager->available_tables;

        foreach ($sys_tables as $tb) {
            $this->sys_manager->createTable($tb);
            array_push($this->log, "Table $tb created");
        }
    }

    private function createDirs()
    {
        foreach ([
            'backups',   // DB dump output
            'export',    // CSV/JSON/XML export output
            'files',     // user-uploaded files
            'geodata',   // custom map layers (geodata/index.json)
        ] as $dir) {
            if (!$this->createDir("projects/$this->app/$dir")){
                throw new \Exception("Cannot create directory projects/$this->app/$dir");
            } else {
                array_push($this->log, "Directory projects/$this->app/$dir created");
            }
        }
    } 

    private function createDir(string $dir):bool
    {
        if (is_dir($dir)){
            return true;
        }
        return @mkdir($dir, 0755, true);
    }


    private function testDB( )
    {
        $res = $this->db->query("SELECT 1");
        // if ($res[0]['four'] === 4) {
        //     array_push($this->log, "database is working fine");
        //     return true;
        // }
        return false;
    }

    private function validateData(
        string $name, 
        string $definition, 
        string $your_email, 
        string $your_password, 
        string $db_engine,
        string $db_host = null,
        string $db_port = null,
        string $db_name = null,
        string $db_username = null,
        string $db_password = null
    )
    {
        if(!$name) throw new \Exception("App name is required");
        if (file_exists("projects/{$name}")){
            throw new \Exception("App name `{$name}` has already been used");
        }
        if(!$definition) throw new \Exception("App definition is required");
        if(!$your_email) throw new \Exception("Your email is required");
        if(!$your_password) throw new \Exception("Your password is required");
        
        if(!$db_engine) throw new \Exception("DB engine is required");
        if ($db_engine !== 'sqlite'){
            if(!$db_host) throw new \Exception("DB host is required for database engine $db_engine");
            if(!$db_port) throw new \Exception("DB port is required for database engine $db_port");
            if(!$db_name) throw new \Exception("DB name is required for database engine $db_name");
            if(!$db_username) throw new \Exception("DB username is required for database engine $db_name");
            if(!$db_password) throw new \Exception("DB password is required for database engine $db_name");
        }

        return true;
    }

}