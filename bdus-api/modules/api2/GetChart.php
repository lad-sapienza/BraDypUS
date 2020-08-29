<?php

/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
use \DB\System\Manage;

class GetChart
{
    public static function run(int $id, \DB\DB\DBInterface $db, string $prefix)
    {
        $sys_manage = new Manage($db, $prefix);

        $chart = $sys_manage->getById('charts', $id);

        if (!$chart || !empty($chart) ) {
            throw new \Exception("Chart #{$id} not found");
        }

        return [
            'name' => $chart['name'],
            'id' => $chart['id'],
            'data' => $db->query($chart['sql'])
        ];
    }
}
