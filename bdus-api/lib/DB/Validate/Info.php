<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Validate;

class Info
{
    public static function getInfo(Resp $resp, \Config\Config $cfg): void
    {
        $resp->set('info',
            'App name: ' . $cfg->get('main.name')
        );
        $resp->set('info',
            'App default language: ' . $cfg->get('main.lang')
        );
        $resp->set('info',
            'App status: ' . $cfg->get('main.status')
        );
        $resp->set('info',
            'App database engine: ' . $cfg->get('main.db_engine')
        );
    }

}