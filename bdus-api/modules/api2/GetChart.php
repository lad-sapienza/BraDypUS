<?php

/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
class GetChart
{
    public static function run($id, \DB $db)
    {
        if ( is_numeric($id) ) {
            $chartid = (int) $id;
        } else {
            $chartid = false;
        }

        $ChartObj = new Charts($db);
        $charts = $ChartObj->getCharts($chartid);
        
        if (!$chartid) {
            return $charts;
        }

        if (!$charts || !is_array($charts) || !is_array($charts[0])) {
            throw new \Exception("Chart #{$id} not found");
        }

        $resp['name'] = $charts[0]['name'];
        $resp['id'] = $charts[0]['id'];
        $resp['data'] = $db->query($charts[0]['query']);

        return $resp;
    }
}
