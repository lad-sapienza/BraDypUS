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
        // SQLite backup/restore is handled entirely by a native PHP/PDO implementation
        // (backup.php::dumpSqliteNative) — no external binary is required.
        // MySQL and PostgreSQL still depend on their respective CLI dump tools.
        switch ($db_engine) {
            case 'sqlite':
                $resp->set('success',
                    'Backup is available. SQLite dumps are handled natively via PHP/PDO (no external binary required)'
                );
                return;

            case 'mysql':
                $cmd = 'mysqldump';
                break;
            case 'pgsql':
                $cmd = 'pg_dump';
                break;
            default:
                $resp->set('danger', 'Unknown database engine: ' . $db_engine);
                return;
        }

        @exec("which $cmd", $out);
        if (trim(implode($out)) === '') {
            $resp->set('danger',
                "Backup is not available. Executable $cmd was not found"
            );
        } else {
            $resp->set('success',
                "Backup is available. Executable $cmd will be used"
            );
        }
    }
}