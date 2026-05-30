<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use DB\DB;
use DB\System\Manage;

class Utils
{
    /**
     * Returns a filtered list of entries in $dir, excluding filesystem noise
     * (., .., .DS_Store, .git, .svn, undefined).
     * Returns false if $dir does not exist or is empty.
     */
    public static function dirContent(string $dir): array|false
    {
        if (!is_dir($dir)) {
            return false;
        }
        $ignore = ['.', '..', '.DS_Store', 'undefined', '.svn', '.git'];
        $ret    = array_diff(scandir($dir), $ignore);
        return empty($ret) ? false : $ret;
    }

    /**
     * Returns true if another user with $email already exists in bdus_users.
     * Pass $id to exclude the current record (for updates).
     */
    public static function isDuplicateEmail(DB $db, string $email = '', ?int $id = null): bool
    {
        $manager = new Manage($db);
        $partial = ['email = ?'];
        $values  = [$email];

        if ($id) {
            $partial[] = 'id != ?';
            $values[]  = $id;
        }

        $res = $manager->getBySQL(
            'bdus_users',
            implode(' AND ', $partial) . ' LIMIT 1 OFFSET 0',
            $values,
            ['count(*) as tot']
        );

        return ($res[0]['tot'] > 0);
    }
}
