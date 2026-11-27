<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use Config\ToDB;
use DB\System\Manage;
use File\Store;

/**
 * Gives every file a random public identifier and renames the binaries after it.
 *
 *  1. Adds `bdus_files.uuid` and `bdus_files.public` (additive columns) and registers their field
 *     config for existing apps.
 *  2. Fills `uuid` (random v4) for every row without one and puts a UNIQUE index on it.
 *  3. When `public` was just added, marks every EXISTING file public: the new flag decides which
 *     files a public preset may show, and today every derived image is public, so nothing visible
 *     on a published site disappears with the upgrade. New files start private.
 *  4. Renames `files/{id}.{ext}` to `files/{uuid}.{ext}`, one file at a time.
 *  5. Empties the derived-image cache (it was keyed by id; it is rebuilt on demand).
 *
 * Idempotent and resumable: a re-run only completes what is missing. The id ↔ uuid mapping lives
 * in the database, so the renames can be reversed from it. A binary missing from disk is skipped
 * (the verifier reports it); a rename that fails (permissions, full disk) makes the migration throw
 * AFTER all the others were tried, so it stays pending and the next run continues.
 *
 * Take a backup before running it on a real application (it renames every file).
 */
class M060_AddUuidToFiles
{
    public const NAME = 'M060_add_uuid_to_files';

    /**
     * @param string|null $projDir Override of the project root (for testing).
     */
    public static function run(Manage $manage, ?string $projDir = null): void
    {
        if (!$manage->tableExists('bdus_files')) {
            return;
        }

        $db = $manage->getDb();

        // 1. Columns
        if (!$manage->columnExists('bdus_files', 'uuid')) {
            $db->exec('ALTER TABLE bdus_files ADD COLUMN uuid TEXT');
        }
        $addedPublic = false;
        if (!$manage->columnExists('bdus_files', 'public')) {
            $db->exec('ALTER TABLE bdus_files ADD COLUMN public INTEGER DEFAULT 0');
            $addedPublic = true;
        }

        // 2. uuid for every row that has none, then the unique index
        $missing = $db->query(
            "SELECT id FROM bdus_files WHERE uuid IS NULL OR uuid = ''",
            [],
            'read'
        ) ?: [];
        if ($missing) {
            $db->beginTransaction();
            try {
                foreach ($missing as $row) {
                    $db->query(
                        'UPDATE bdus_files SET uuid = ? WHERE id = ?',
                        [Store::newUuid(), (int) $row['id']],
                        'boolean'
                    );
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }
        $manage->createIndex('bdus_files', 'bdus_files_uuid_uq', ['uuid'], true);

        // 3. Existing files keep today's behaviour (derived images are public)
        if ($addedPublic) {
            $db->query('UPDATE bdus_files SET public = 1', [], 'boolean');
        }

        // Field config for existing apps (inserted only when missing: a label edited by the user is kept)
        if ($manage->tableExists('bdus_cfg_fields')) {
            foreach ([
                ['name' => 'uuid',   'label' => 'UUID',   'type' => 'text',    'db_type' => 'TEXT',    'readonly' => true],
                ['name' => 'public', 'label' => 'Public', 'type' => 'boolean', 'db_type' => 'INTEGER'],
            ] as $fld) {
                $exists = $db->query(
                    'SELECT id FROM bdus_cfg_fields WHERE table_name = ? AND name = ?',
                    ['bdus_files', $fld['name']],
                    'read'
                );
                if (!$exists) {
                    ToDB::upsertField($db, 'bdus_files', $fld);
                }
            }
        }

        // 4 + 5. Disk
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }

        $failed = self::renameBinaries($db, $root);
        self::purgeCache($root);

        if ($failed > 0) {
            throw new \RuntimeException(
                "M060: {$failed} file(s) could not be renamed to their uuid (check permissions on files/); "
                . 'the migration stays pending, fix the cause and run it again'
            );
        }
    }

    /** @return int Number of renames that failed (a missing source is not a failure). */
    private static function renameBinaries(\DB\DBInterface $db, string $root): int
    {
        $dir = Store::dir($root);
        if (!is_dir($dir)) {
            return 0;
        }

        $failed = 0;
        foreach ($db->query('SELECT id, uuid, ext FROM bdus_files', [], 'read') ?: [] as $row) {
            $ext = strtolower((string) $row['ext']);
            if ($ext !== '' && preg_match('/^[a-z0-9]+$/D', $ext) !== 1) {
                continue; // an extension we cannot name safely: left as it is, the verifier reports it
            }
            $suffix = $ext === '' ? '' : '.' . $ext;
            $old    = $dir . (int) $row['id'] . $suffix;
            $new    = $dir . $row['uuid'] . $suffix;

            if (is_file($new) || !is_file($old)) {
                continue; // already done (resume) or the binary is missing
            }
            if (!@rename($old, $new)) {
                $failed++;
            }
        }

        return $failed;
    }

    /** Derived versions were named after the id: drop them, they are rebuilt on demand. */
    private static function purgeCache(string $root): void
    {
        foreach (glob(rtrim($root, '/') . '/cache/*/[0-9]*') ?: [] as $f) {
            // `5.webp`, `5.webp.fail`: digits then a dot. A uuid has a dash after its first group.
            if (is_file($f) && preg_match('/^\d+\./', basename($f)) === 1) {
                @unlink($f);
            }
        }
    }
}
