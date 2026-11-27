<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

use DB\DBInterface;

/**
 * Gives the files that lack one a uuid and puts their binaries under it: the work of migration M060,
 * reusable for rows that reach the database without one (a tool that inserts into `bdus_files` directly,
 * a restore of an old dump). Used by the migration and by `bin/files-backfill-uuid.php`.
 *
 * Idempotent and resumable: only what is missing is done, a binary already under its uuid is never
 * overwritten, a missing binary is counted and skipped, and a rename that fails is counted (the caller
 * decides whether that is an error). The id <-> uuid mapping lives in the database, so renames can be
 * reversed from it.
 */
final class UuidBackfill
{
    /**
     * What a run would do, without doing it.
     *
     * @return array{without_uuid: int, to_rename: int, missing: int}
     */
    public static function plan(DBInterface $db, ?string $projDir): array
    {
        $without = (int) ($db->query("SELECT COUNT(*) AS n FROM bdus_files WHERE uuid IS NULL OR uuid = ''", [], 'read')[0]['n'] ?? 0);
        $rename  = 0;
        $missing = 0;
        if ($projDir !== null && is_dir(Store::dir($projDir))) {
            foreach ($db->query('SELECT id, uuid, ext FROM bdus_files', [], 'read') ?: [] as $r) {
                $paths = self::paths($projDir, (int) $r['id'], (string) $r['uuid'], (string) $r['ext']);
                if ($paths === null) {
                    continue;
                }
                [$old, $new] = $paths;
                if (is_file($new)) {
                    continue;
                }
                is_file($old) ? $rename++ : $missing++;
            }
        }
        return ['without_uuid' => $without, 'to_rename' => $rename, 'missing' => $missing];
    }

    /**
     * Assigns the missing uuids, renames the binaries named by id, and (with a project dir) empties the
     * derived cache that was keyed by id.
     *
     * @return array{assigned: int, renamed: int, missing: int, failed: int}
     */
    public static function run(DBInterface $db, ?string $projDir): array
    {
        $assigned = self::assign($db);
        $renamed = $missing = $failed = 0;

        if ($projDir !== null && is_dir(Store::dir($projDir))) {
            foreach ($db->query('SELECT id, uuid, ext FROM bdus_files', [], 'read') ?: [] as $r) {
                $paths = self::paths($projDir, (int) $r['id'], (string) $r['uuid'], (string) $r['ext']);
                if ($paths === null) {
                    continue; // an extension that cannot be named safely: left as it is
                }
                [$old, $new] = $paths;
                if (is_file($new)) {
                    continue; // already done (resume), never overwritten
                }
                if (!is_file($old)) {
                    $missing++;
                    continue;
                }
                @rename($old, $new) ? $renamed++ : $failed++;
            }
        }
        if ($projDir !== null) {
            self::purgeCache($projDir);
        }

        return ['assigned' => $assigned, 'renamed' => $renamed, 'missing' => $missing, 'failed' => $failed];
    }

    /** @return int number of uuids assigned */
    private static function assign(DBInterface $db): int
    {
        $missing = $db->query("SELECT id FROM bdus_files WHERE uuid IS NULL OR uuid = ''", [], 'read') ?: [];
        if (!$missing) {
            return 0;
        }
        $db->beginTransaction();
        try {
            foreach ($missing as $row) {
                $db->query('UPDATE bdus_files SET uuid = ? WHERE id = ?', [Store::newUuid(), (int) $row['id']], 'boolean');
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return count($missing);
    }

    /** @return array{0: string, 1: string}|null [old path by id, new path by uuid], null for an extension that cannot be named */
    private static function paths(string $projDir, int $id, string $uuid, string $ext): ?array
    {
        $ext = strtolower($ext);
        if ($ext !== '' && preg_match('/^[a-z0-9]+$/D', $ext) !== 1) {
            return null;
        }
        if (!Store::isUuid($uuid)) {
            return null; // not assigned yet (a dry run)
        }
        $dir    = Store::dir($projDir);
        $suffix = $ext === '' ? '' : '.' . $ext;
        return [$dir . $id . $suffix, $dir . $uuid . $suffix];
    }

    /** Derived versions were named after the id: drop them, they are rebuilt on demand. */
    private static function purgeCache(string $projDir): void
    {
        foreach (glob(rtrim($projDir, '/') . '/cache/*/[0-9]*') ?: [] as $f) {
            // `5.webp`, `5.webp.fail`: digits then a dot. A uuid has a dash after its first group.
            if (is_file($f) && preg_match('/^\d+\./', basename($f)) === 1) {
                @unlink($f);
            }
        }
    }
}
