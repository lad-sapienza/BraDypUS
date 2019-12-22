<?php

/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
class Inspect
{
    public static function run($tb = false)
    {
        if ($tb) {

            // Inspect table
            $stripped_name = str_replace(PREFIX, null, $tb);

            $resp = cfg::tbEl($tb, 'all');
            $resp['stripped_name'] = $stripped_name;

            foreach (cfg::fldEl($tb) as $f) {
                $f['fullname'] = $tb . ':' . $f['name'];
                $resp['fields'][$f['fullname']] = $f;
            }

            // Plugins
            foreach (cfg::tbEl($tb, 'plugin') as $p) {
                foreach (cfg::fldEl($p) as $f) {
                    $f['fullname'] = $p . ':' . $f['name'];
                    $f['label'] = cfg::tbEl($p, 'label') . ': ' . $f['label'];
                    $resp['fields'][$f['fullname']] = $f;
                }
            }
        } else {

            // Inspect all
            foreach (cfg::tbEl('all', 'all') as $t) {
                $stripped_name = str_replace(PREFIX, null, $t['name']);
                $t['stripped_name'] = $stripped_name;

                foreach (cfg::fldEl($t['name']) as $f) {
                    $f['fullname'] = $t['name'] . ':' . $t['name'];
                    $t['fields'][$f['name']] = $f;
                }


                $resp[$stripped_name] = $t;
            }
        }

        return $resp;
    }
}
