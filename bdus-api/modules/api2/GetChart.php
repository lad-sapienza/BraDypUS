<?php

/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
class GetChart
{
    public static function run($id)
    {
        if ($id && $id !== 'all') {
            $sql = "SELECT * FROM `" . PREFIX . "charts` WHERE `id` = ?";
            $vals = [ $id ];
            $ch = DB::start()->query($sql, $vals);

            if (!$ch || !is_array($ch) || !is_array($ch[0])) {
                throw new \Exception("Chart #{$id} not found");
            }

            $resp['name'] = $ch[0]['name'];
            $resp['id'] = $ch[0]['id'];

            $resp['data'] = DB::start()->query($ch[0]['query']);
        } elseif ($id === 'all') {
            $sql = "SELECT `id`, `name` FROM `" . PREFIX . "charts` WHERE  1";
            $resp = DB::start()->query($sql, $vals);
        }

        return $resp;
    }
}
