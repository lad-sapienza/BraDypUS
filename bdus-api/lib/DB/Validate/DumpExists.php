<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Validate;

class DumpExists
{
    public static function Check(Resp $resp, string $db_engine): void
    {
        switch( $db_engine ) {
            case 'mysql':
                $cmd = 'mysqldump';
            break;
            case 'pgsql':
                $cmd = 'pg_dump';
            break;
            case 'sqlite':
                $cmd = 'sqlite3';
            break;
        }

        if ($cmd) {
            @exec("which $cmd", $out);
            if (trim(implode($out)) === ''){
                $resp->set('danger',
                    "Backup is not available. Executable $cmd was not found"
                );
            } else {
                $resp->set('success',
                    "Backup is available. Executable $cmd will be used"
                );
            }
        } else {
            $resp->set('danger',
                'Unknown database engine: ' . $db_engine
            );
        }
    }
}