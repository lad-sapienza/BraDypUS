<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace UAC;

use DB\DBInterface;

/**
 * Builds the User Access Level (UAL) array from the database.
 *
 * The UAL is the data structure consumed by UAC::setUAL() / UAC::can().
 * Shape:
 *   [
 *     'global'          => 30,          // user's global privilege integer
 *     'myapp__items'    => 20,           // table-level override (no subset)
 *     'myapp__finds'    => [30, 'excavation_id = 5'],  // override + subset WHERE clause
 *   ]
 *
 * If the user_table_privs table does not yet exist (migration not run),
 * the method falls back silently to global-only UAL so the app stays
 * functional during the first boot before the login migration fires.
 */
class Loader
{
    /**
     * Builds and returns the UAL array for the given user.
     *
     * @param int         $userId        User's database id
     * @param int         $globalPriv    User's global privilege level (from users table)
     * @param DBInterface $db
     * @return array
     */
    public static function buildUAL(
        int $userId,
        int $globalPriv,
        DBInterface $db
    ): array {
        $ual = ['global' => $globalPriv];

        try {
            $rows = $db->query(
                "SELECT table_name, privilege, subset
                 FROM bdus_user_table_privs
                 WHERE user_id = ?",
                [$userId],
                'read'
            );

            if (!$rows) {
                return $ual;
            }

            foreach ($rows as $row) {
                $ual[$row['table_name']] = ($row['subset'] !== null && $row['subset'] !== '')
                    ? [(int) $row['privilege'], $row['subset']]
                    : (int) $row['privilege'];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet (migration pending) — global-only UAL is safe.
        }

        return $ual;
    }
}
