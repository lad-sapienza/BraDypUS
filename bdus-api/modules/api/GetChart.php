<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
use \DB\System\Manage;
use DB\DBInterface;

class GetChart
{
    public static function run(int $id, DBInterface $db)
    {
        $sys_manage = new Manage($db);

        $chart = $sys_manage->getById('bdus_charts', $id);

        if (!$chart || empty($chart) ) {
            throw new \Exception("Chart #{$id} not found");
        }

        return [
            'name' => $chart['name'],
            'id' => $chart['id'],
            'data' => $db->query($chart['sqltext'])
        ];
    }
}
