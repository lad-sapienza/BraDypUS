<?php
namespace DB\Validate;

class Info
{
    public static function getInfo(Resp $resp): void
    {
        $resp->set('info',
            'App name: ' . \cfg::main('name')
        );
        $resp->set('info',
            'App default language: ' . \cfg::main('lang')
        );
        $resp->set('info',
            'App status: ' . \cfg::main('status')
        );
        $resp->set('info',
            'App database engine: ' . \cfg::main('db_engine')
        );
    }

}