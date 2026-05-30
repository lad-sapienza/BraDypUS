<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class utils
{
    /**
     * Returns an array with a list of files contained in $dir.
     * Ignores `.`, `..`, `.DS_Store`, `.svn`, `.git`, `undefined`.
     * Returns false if $dir does not exist or is empty.
     */
    public static function dirContent(string $dir): array|false
    {
        $ignore = ['.', '..', '.DS_Store', 'undefined', '.svn', '.git'];
        if (!is_dir($dir)) {
            return false;
        }
        $ret = array_diff(scandir($dir), $ignore);
        return empty($ret) ? false : $ret;
    }

    /**
     * Explodes a string by delimiter, trims each part, and removes empty elements.
     */
    public static function csv_explode(string $string, string $delimiter = ','): array
    {
        return array_filter(array_map('trim', explode($delimiter, $string)), 'strlen');
    }

    /**
     * Returns true if another user with $email already exists in bdus_users.
     * Pass $id to exclude the current user (for updates).
     */
    public static function isDuplicateEmail(\DB\DB $db, string $email = '', ?int $id = null): bool
    {
        $manager = new \DB\System\Manage($db);

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
