<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace API2;
/**
 * Returns configuration info
 * @requires cfg
 * @requires DB
 */
use Config\Config;

class Inspect
{
    public static function Configuration(Config $cfg, string $tb = null)
    {

        if ($tb) {
            // Inspect table
            $stripped_name = str_replace(PREFIX, null, $tb);

            $resp['stripped_name'] = $stripped_name;

            $tb_cfg = $cfg->get("tables.$tb");
            foreach ($tb_cfg as $key => $value) {
                if ($key !== 'fields'){
                    $resp[$key] = $value;
                } else {
                    foreach ($value as $k => $val) {
                        $val['fullname'] = "$tb:$k";
                        $resp['fields']["$tb:$k"] = $val;
                    }
                }
            }
            // Plugins
            $plugins = $cfg->get("tables.$tb.plugin");
            if (is_array($plugins)) {
                foreach ($plugins as $plg) {
                    $plugin_fields = $cfg->get("tables.$plg.fields");
                    if (is_array($plugin_fields ) ) {
                        foreach ($plugin_fields as $f => $f_data) {
                            $f_data['fullname'] = "$plg:$f";
                            $f_data['label'] = $cfg->get("tables.$plg.label") . ':' . $f;
                            $resp['fields']["$plg:$f"] = $f_data;
                        }
                    }
                }
            }
            
        } else {

            $all_cfg = $cfg->get('tables');
            // Inspect all
            foreach ( $all_cfg as $tname => $t ) {
                $stripped_name = str_replace(PREFIX, null, $t['name']);
                $t['stripped_name'] = $stripped_name;

                foreach ($t['fields'] as $f) {
                    $f['fullname'] = $t['name'] . ':' . $t['name'];
                    $t['fields'][$f['name']] = $f;
                }
                $resp[$stripped_name] = $t;
            }
        }
        
        return $resp;
    }
}
