<?php

namespace DB;


class Alter implements Alter\AlterInterface
{
    private $driver;
    private $sql_arr;

    public function __construct(Alter\AlterInterface $driver)
    {
        $this->driver = $driver;
    }

    public function run(\DB $db)
    {
        // echo '<pre>' . json_encode($this->sql_arr, JSON_PRETTY_PRINT) . '</pre>'; return;
        try {
            $db->dont_version();
            $db->beginTransaction();

            foreach($this->sql_arr as $q) {
                echo "Running: <br>" . $q['sql'] . "<br>" . json_encode($q['values'], JSON_PRETTY_PRINT);

                $db->query($q['sql'], $q['values']);
            }

            $db->commit();
            $db->do_version();
        } catch (\Throwable $th) {

            $db->rollBack();
            $db->do_version();
            throw new Exception($th);
        }
    }


    public function renameTable(string $old, string $new): array
    {
        $this->sql_arr = $this->driver->renameTable($old, $new);
        return $this->sql_arr;
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): array
    {
        $this->sql_arr = $this->driver->renameFld($tb, $old, $new, $fld_type);
        return $this->sql_arr;
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): array
    {
        $this->sql_arr = $this->driver->addFld($tb, $fld_name, $fld_type);
        return $this->sql_arr;
    }


    public function dropFld(string $tb, string $fld_name): array
    {
        $this->driver->dropFld($tb, $fld_name);
        return $this->sql_arr;
    }

}