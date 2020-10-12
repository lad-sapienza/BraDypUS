<?php

namespace DB;

use DB\DB;

class Alter implements Alter\AlterInterface
{
    private $driver;

    public function __construct(DB $db)
    {
        $engine = $db->getEngine();

        if ($engine === 'sqlite'){
            $this->driver = new \DB\Alter\Sqlite($db);
        } elseif($engine === 'mysql'){
            $this->driver = new \DB\Alter\Mysql($db);
        } elseif($engine === 'pgsql'){
            $this->driver = new \DB\Alter\Postgres($db);
        } else {
            throw new \Exception("Unknown database engine: `$engine`");
        }
    }

    public function renameTable(string $old, string $new): bool
    {
        return $this->driver->renameTable($old, $new);
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        if($old === 'id'){
            throw new \Exception("Field `id` cannot be renamed");
        }
        if($old === 'creator'){
            throw new \Exception("Field `creator` cannot be renamed");
        }
        return $this->driver->renameFld($tb, $old, $new, $fld_type);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        return $this->driver->addFld($tb, $fld_name, $fld_type);
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        if($fld_name === 'id'){
            throw new \Exception("Field `id` cannot be dropped");
        }
        if($fld_name === 'creator'){
            throw new \Exception("Field `creator` cannot be dropped");
        }
        return $this->driver->dropFld($tb, $fld_name);
    }

    public function createMinimalTable(string $tb, bool $is_plugin): bool
    {
        return $this->driver->createMinimalTable($tb, $is_plugin);
    }

    public function dropTable(string $tb): bool
    {
        return $this->driver->dropTable($tb);
    }

}