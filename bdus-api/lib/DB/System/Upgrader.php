<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;

use DB\DBInterface;
use Monolog\Logger;

/**
 * Applies the pending MINOR migrations of one application.
 *
 * Single entry point shared by the two callers that may upgrade an app:
 *   - POST /api/upgrade/minor   (an admin, after login)
 *   - bin/upgrade-app.php       (the operator, e.g. `bdus update` in bdus-ops)
 *
 * Both end in Migrate::run(): this class only adds the guard that neither of
 * them may skip — a MAJOR upgrade (v4 → v5) is never run from here, it needs a
 * super admin, a snapshot and the post-upgrade verification (Upgrade::runMajor).
 */
final class Upgrader
{
    /**
     * Migrations still to apply, or null when the app needs a major upgrade
     * (in which case no minor migration may run).
     *
     * @return string[]|null
     */
    public static function pending(DBInterface $db): ?array
    {
        if (Migrate::isMajorUpgradeNeeded()) {
            return null;
        }
        return Migrate::listPending($db);
    }

    /**
     * Runs the pending migrations.
     *
     * @return string[] names of the migrations that were pending (and are now applied)
     * @throws \RuntimeException when a major upgrade is needed instead
     * @throws \Throwable        whatever the failing migration threw (already logged by Migrate::run)
     */
    public static function apply(DBInterface $db, ?Logger $log = null): array
    {
        $pending = self::pending($db);
        if ($pending === null) {
            throw new \RuntimeException('major_upgrade_required');
        }
        if ($pending === []) {
            return [];
        }

        Migrate::run($db, $log);

        return $pending;
    }
}
